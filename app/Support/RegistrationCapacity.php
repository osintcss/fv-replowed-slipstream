<?php

namespace App\Support;

use App\Exceptions\RegistrationCapacityReached;
use App\Models\User;

final class RegistrationCapacity
{
    public const MAX_USERS = 300;

    public static function isFull(): bool
    {
        return User::query()->count() >= self::MAX_USERS;
    }

    /**
     * Check the cap while holding a lock shared by all registration flows.
     * Call this from inside a database transaction immediately before create.
     */
    public static function ensureAvailable(): void
    {
        // Lock a stable existing row so concurrent registrations serialize
        // before counting and inserting. Production always has at least one
        // account; SQLite serializes the write transaction itself.
        User::query()->orderBy('id')->lockForUpdate()->first();

        if (self::isFull()) {
            throw new RegistrationCapacityReached();
        }
    }
}
