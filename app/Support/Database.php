<?php

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Boundary for game persistence operations.
 *
 * Laravel's connection is request-scoped and manages its own lifecycle, so
 * callers must not open or close ad-hoc mysqli connections. This wrapper
 * preserves ordinary return values (including null for "not found") and
 * makes actual database failures explicit and consistently diagnosable.
 */
final class Database
{
    public static function run(string $operation, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException|PDOException $exception) {
            throw new DatabaseOperationException(
                "Database operation failed: {$operation}",
                previous: $exception,
            );
        }
    }

    public static function transaction(string $operation, Closure $callback, int $attempts = 1): mixed
    {
        return self::run(
            $operation,
            static fn () => DB::transaction($callback, $attempts),
        );
    }
}
