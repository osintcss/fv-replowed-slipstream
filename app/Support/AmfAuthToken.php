<?php

namespace App\Support;

use LogicException;
use RuntimeException;

final class AmfAuthToken
{
    private const VERSION = 1;

    private const CLOCK_SKEW_SECONDS = 60;

    private const MAX_TOKEN_LENGTH = 2048;

    public static function issue(string $uid, ?int $issuedAt = null): string
    {
        self::assertUid($uid);

        $issuedAt ??= time();
        $payload = [
            'v' => self::VERSION,
            'uid' => $uid,
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::ttlSeconds(),
            'jti' => bin2hex(random_bytes(16)),
        ];

        $encodedPayload = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encodedPayload, self::key(), true);

        return $encodedPayload.'.'.self::base64UrlEncode($signature);
    }

    public static function verify(string $token, ?int $now = null): string
    {
        $now ??= time();

        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            self::reject();
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            self::reject();
        }

        [$encodedPayload, $encodedSignature] = $parts;
        $providedSignature = self::base64UrlDecode($encodedSignature);
        $expectedSignature = hash_hmac('sha256', $encodedPayload, self::key(), true);

        if (strlen($providedSignature) !== strlen($expectedSignature)
            || ! hash_equals($expectedSignature, $providedSignature)) {
            self::reject();
        }

        try {
            $payload = json_decode(self::base64UrlDecode($encodedPayload), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            self::reject();
        }

        if (! is_array($payload)
            || ($payload['v'] ?? null) !== self::VERSION
            || ! is_string($payload['uid'] ?? null)
            || ! is_int($payload['iat'] ?? null)
            || ! is_int($payload['exp'] ?? null)
            || ! is_string($payload['jti'] ?? null)) {
            self::reject();
        }

        self::assertUid($payload['uid']);

        if (! preg_match('/^[a-f0-9]{32}$/D', $payload['jti'])
            || $payload['iat'] > $now + self::CLOCK_SKEW_SECONDS
            || $payload['exp'] <= $now
            || $payload['exp'] <= $payload['iat']
            || ($payload['exp'] - $payload['iat']) > self::ttlSeconds()) {
            self::reject();
        }

        return $payload['uid'];
    }

    public static function verifyForClaimedUid(string $token, string $claimedUid, ?int $now = null): string
    {
        $authenticatedUid = self::verify($token, $now);

        if ($claimedUid === '' || ! hash_equals($authenticatedUid, $claimedUid)) {
            self::reject();
        }

        return $authenticatedUid;
    }

    private static function key(): string
    {
        $configuredKey = trim((string) config('amf.auth_key', ''));
        $key = $configuredKey;

        if (str_starts_with($configuredKey, 'base64:')) {
            $decoded = base64_decode(substr($configuredKey, 7), true);
            $key = is_string($decoded) ? $decoded : '';
        }

        if (strlen($key) < 32) {
            throw new LogicException('AMF_AUTH_KEY must contain at least 32 bytes of key material.');
        }

        return $key;
    }

    private static function ttlSeconds(): int
    {
        $ttl = (int) config('amf.token_ttl_seconds', 36000);

        if ($ttl < 300 || $ttl > 86400) {
            throw new LogicException('AMF_TOKEN_TTL_SECONDS must be between 300 and 86400.');
        }

        return $ttl;
    }

    private static function assertUid(string $uid): void
    {
        if (! preg_match('/^[0-9]{1,32}$/D', $uid)) {
            self::reject();
        }
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || ! preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            self::reject();
        }

        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value.str_repeat('=', $padding), '-_', '+/'), true);

        if (! is_string($decoded)) {
            self::reject();
        }

        return $decoded;
    }

    private static function reject(): never
    {
        throw new RuntimeException('Unauthorized AMF request.');
    }
}
