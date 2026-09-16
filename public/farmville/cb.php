<?php

declare(strict_types=1);

/*
 * Crash Busters report receiver.
 *
 * The legacy Flash client POSTs URL-encoded JSON in a field named `data`.
 * This endpoint deliberately does not authenticate or execute anything from
 * the report: it is diagnostic input only. The client cannot keep a secret in
 * a decompiled SWF, so abuse resistance comes from strict bounds, an allowlist,
 * redaction, rate limiting, and bounded local storage.
 */

const CB_MAX_BODY_BYTES = 131072;
const CB_MAX_RECORD_BYTES = 49152;
const CB_MAX_LOG_BYTES = 10485760;
const CB_RATE_WINDOW_SECONDS = 60;
const CB_GLOBAL_RATE_LIMIT = 240;
const CB_IP_RATE_LIMIT = 60;
const CB_CLIENT_RATE_LIMIT = 10;

header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    cb_finish(405);
}

$contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
if (is_string($contentLength) && ctype_digit($contentLength)
    && (int) $contentLength > CB_MAX_BODY_BYTES) {
    cb_finish(413);
}

$rawBody = file_get_contents('php://input', false, null, 0, CB_MAX_BODY_BYTES + 1);
if ($rawBody === false || strlen($rawBody) > CB_MAX_BODY_BYTES) {
    cb_finish(413);
}

/* Count malformed attempts too, so the endpoint cannot be used as a cheap
 * parser/logging oracle. The key uses only server-observed request metadata. */
$rateResult = cb_rate_limit(
    (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
    (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown')
);
if ($rateResult === false) {
    header('Retry-After: ' . CB_RATE_WINDOW_SECONDS);
    cb_finish(429);
}
if ($rateResult === null) {
    /* If the limiter cannot be safely persisted, fail closed rather than
     * accepting an unlimited stream that could fill the diagnostic log. */
    cb_finish(503);
}

$payload = $_POST['data'] ?? null;
if (!is_string($payload) || $payload === '') {
    $trimmedBody = ltrim($rawBody);
    if ($trimmedBody !== '' && $trimmedBody[0] === '{') {
        $payload = $rawBody;
    } else {
        $formData = [];
        parse_str($rawBody, $formData);
        $payload = $formData['data'] ?? null;
    }
}

if (!is_string($payload) || $payload === '' || strlen($payload) > CB_MAX_BODY_BYTES) {
    cb_finish(400);
}

try {
    $decoded = json_decode($payload, true, 12, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
} catch (JsonException) {
    cb_finish(400);
}

if (!is_array($decoded)) {
    cb_finish(400);
}

$signedParams = $decoded['signedParams'] ?? null;
$clientFingerprint = null;
if (array_key_exists('signedParams', $decoded)) {
    $signedParamsJson = json_encode(
        $signedParams,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    $clientFingerprint = is_string($signedParamsJson) && $signedParamsJson !== ''
        ? substr(hash('sha256', $signedParamsJson), 0, 16)
        : null;
}

$allowedFields = [
    'browserInfo',
    'capabilities',
    'client_giftbox',
    'flashPlayerType',
    'lastTransactionError',
    'lastTransactionFunc',
    'lastUncaughtClientException',
    'revision',
    'sentTransactions',
    'sessionTime',
    'transactions',
    'ui_detail',
    'ui_trace',
    'version',
];

$budget = 1200;
$report = [];
foreach ($allowedFields as $field) {
    if (array_key_exists($field, $decoded)) {
        $report[$field] = cb_sanitize($decoded[$field], $field, 0, $budget);
    }
}

$reportId = bin2hex(random_bytes(12));
$record = [
    'report_id' => $reportId,
    'received_at' => gmdate('c'),
    'client_fingerprint' => $clientFingerprint,
    'report' => $report,
];

$recordJson = cb_encode($record);
if ($recordJson === null) {
    cb_finish(400);
}

/* Drop the least useful large traces first if a malicious but valid report
 * still reaches the record bound. The transaction/error fields are retained. */
foreach (['client_giftbox', 'ui_trace', 'sentTransactions', 'transactions'] as $field) {
    if (strlen($recordJson) <= CB_MAX_RECORD_BYTES) {
        break;
    }
    unset($record['report'][$field]);
    $recordJson = cb_encode($record);
    if ($recordJson === null) {
        cb_finish(400);
    }
}

if (strlen($recordJson) > CB_MAX_RECORD_BYTES) {
    $record['report'] = array_intersect_key($record['report'], array_flip([
        'browserInfo',
        'capabilities',
        'flashPlayerType',
        'lastTransactionError',
        'lastTransactionFunc',
        'revision',
        'sessionTime',
        'ui_detail',
        'version',
    ]));
    $recordJson = cb_encode($record);
    if ($recordJson === null || strlen($recordJson) > CB_MAX_RECORD_BYTES) {
        cb_finish(400);
    }
}

if (!cb_append_record($recordJson . "\n")) {
    error_log('[FarmVille Crash Busters] report persistence failed id=' . $reportId);
    cb_finish(503);
}

cb_finish(204);

/** @return never */
function cb_finish(int $status): never
{
    http_response_code($status);
    exit;
}

function cb_storage_directory(): string
{
    return dirname(__DIR__, 2) . '/storage/framework/cache';
}

function cb_log_path(): string
{
    return dirname(__DIR__, 2) . '/storage/logs/crashbusters.log';
}

function cb_rate_limit(string $remoteAddress, string $userAgent): ?bool
{
    $directory = cb_storage_directory();
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        return null;
    }

    $lock = @fopen($directory . '/crashbusters-rate.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return null;
    }

    try {
        $window = intdiv(time(), CB_RATE_WINDOW_SECONDS);
        $statePath = $directory . '/crashbusters-rate.json';
        $stateJson = @file_get_contents($statePath);
        $state = is_string($stateJson) ? json_decode($stateJson, true) : null;

        if (!is_array($state) || (int) ($state['window'] ?? -1) !== $window) {
            $state = [
                'window' => $window,
                'global' => 0,
                'ips' => [],
                'clients' => [],
            ];
        }

        $ipKey = substr(hash('sha256', $remoteAddress), 0, 24);
        $clientKey = substr(hash('sha256', $remoteAddress . '|' . substr($userAgent, 0, 256)), 0, 24);
        $state['global'] = (int) ($state['global'] ?? 0) + 1;
        $state['ips'][$ipKey] = (int) ($state['ips'][$ipKey] ?? 0) + 1;
        $state['clients'][$clientKey] = (int) ($state['clients'][$clientKey] ?? 0) + 1;

        $allowed = $state['global'] <= CB_GLOBAL_RATE_LIMIT
            && $state['ips'][$ipKey] <= CB_IP_RATE_LIMIT
            && $state['clients'][$clientKey] <= CB_CLIENT_RATE_LIMIT;

        $encodedState = json_encode($state, JSON_THROW_ON_ERROR);
        $stateHandle = @fopen($statePath, 'c+');
        if ($stateHandle === false || !flock($stateHandle, LOCK_EX)) {
            if (is_resource($stateHandle)) {
                fclose($stateHandle);
            }
            return null;
        }

        ftruncate($stateHandle, 0);
        rewind($stateHandle);
        $written = fwrite($stateHandle, $encodedState);
        fflush($stateHandle);
        flock($stateHandle, LOCK_UN);
        fclose($stateHandle);

        return $written === strlen($encodedState) ? $allowed : null;
    } catch (Throwable) {
        return null;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function cb_append_record(string $line): bool
{
    $directory = dirname(cb_log_path());
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        return false;
    }

    $lock = @fopen($directory . '/crashbusters-log.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        return false;
    }

    try {
        $logPath = cb_log_path();
        if (is_file($logPath) && (int) @filesize($logPath) >= CB_MAX_LOG_BYTES) {
            @rename($logPath, $logPath . '.1');
        }

        $handle = @fopen($logPath, 'ab');
        if ($handle === false) {
            return false;
        }
        $written = fwrite($handle, $line);
        fflush($handle);
        fclose($handle);

        if (is_file($logPath)) {
            @chmod($logPath, 0640);
        }

        return $written === strlen($line);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function cb_encode(mixed $value): ?string
{
    try {
        return json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        return null;
    }
}

function cb_sanitize(mixed $value, string|int|null $key, int $depth, int &$budget): mixed
{
    if ($budget-- <= 0) {
        return '[truncated]';
    }

    $keyString = is_string($key) ? $key : '';
    if ($keyString !== '' && preg_match(
        '/(?:pass(?:word|wd)?|token|secret|cookie|session[_-]?id|auth(?:entication|orization)?|credential|signed[_-]?params|private[_-]?key)/i',
        $keyString
    )) {
        return '[redacted]';
    }

    if (is_string($value)) {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', ' ', $value) ?? '';
        return strlen($value) > 2048 ? substr($value, 0, 2048) . '…' : $value;
    }

    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
        return $value;
    }

    if (!is_array($value)) {
        return '[unsupported]';
    }

    if ($depth >= 8) {
        return '[truncated]';
    }

    $result = [];
    $count = 0;
    foreach ($value as $childKey => $childValue) {
        if ($count++ >= 100) {
            $result['_truncated'] = true;
            break;
        }

        if (is_int($childKey)) {
            $safeKey = (string) $childKey;
        } else {
            $safeKey = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $childKey) ?? '';
            $safeKey = substr($safeKey, 0, 64);
        }

        if ($safeKey === '') {
            continue;
        }
        $result[$safeKey] = cb_sanitize($childValue, $safeKey, $depth + 1, $budget);
    }

    return $result;
}
