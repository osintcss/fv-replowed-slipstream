<?php

declare(strict_types=1);

/*
 * The Flash client posts its own runtime exceptions here.  Returning a 404
 * discards the only useful diagnostic when a client-side feature fails (for
 * example, when a locale SWF cannot be initialized).  Keep this endpoint
 * intentionally inert: it records a bounded, redacted report and returns no
 * application data to the caller.
 */

$rawReport = file_get_contents('php://input') ?: '';
$payload = $_POST['data'] ?? '';

if (!is_string($payload) || $payload === '') {
    parse_str($rawReport, $formData);
    $payload = $formData['data'] ?? $rawReport;
}

$decodedPayload = is_string($payload) ? json_decode($payload, true) : null;

if (is_array($decodedPayload)) {
    // These two fields can be very large and do not describe the exception.
    unset($decodedPayload['experimentData'], $decodedPayload['browserInfo']);

    array_walk_recursive($decodedPayload, static function (&$value, $key): void {
        if (preg_match('/password|passwd|token|session|cookie/i', (string) $key)) {
            $value = '[redacted]';
        }
    });

    $report = json_encode($decodedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
} else {
    // Avoid placing credentials or session material in the container log.
    $report = preg_replace(
        '/(password|passwd|token|session(?:id)?|cookie)\s*([=:])\s*[^\s&"\']+/i',
        '$1$2[redacted]',
        (string) $payload
    ) ?? '';
}

$report = substr(str_replace(["\r", "\n"], [' ', ' '], $report), 0, 8_000);
error_log('[FarmVille Flash exception] ' . ($report !== '' ? $report : '[empty report]'));

http_response_code(204);
header('Cache-Control: no-store');
