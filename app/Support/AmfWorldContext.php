<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Request-local world binding for an authenticated AMF batch.
 *
 * The legacy game stores the last viewed world in one account-wide metadata
 * row. That is useful as a fallback for old clients, but it cannot identify
 * two simultaneous browser/game sessions. FlashService initializes this
 * context from the signed AMF token before constructing any handlers.
 */
final class AmfWorldContext
{
    private static ?string $uid = null;

    private static ?string $worldType = null;

    public static function begin(string $uid, ?string $worldType): void
    {
        self::clear();

        if ($worldType !== null) {
            self::set($uid, $worldType);
        }
    }

    public static function set(string $uid, string $worldType): void
    {
        if ($uid === '' || ! preg_match('/^[0-9]{1,32}$/D', $uid)) {
            throw new InvalidArgumentException('Invalid AMF world-context UID.');
        }

        if ($worldType === '' || strlen($worldType) > 64 || ! preg_match('/^[a-z][a-z0-9_]*$/D', $worldType)) {
            throw new InvalidArgumentException('Invalid AMF world-context world type.');
        }

        self::$uid = $uid;
        self::$worldType = $worldType;
    }

    public static function current(string $uid): ?string
    {
        return self::$uid === $uid ? self::$worldType : null;
    }

    public static function clear(): void
    {
        self::$uid = null;
        self::$worldType = null;
    }
}
