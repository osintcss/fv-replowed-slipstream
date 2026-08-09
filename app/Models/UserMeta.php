<?php

namespace App\Models;

use App\Support\Database;
use Illuminate\Database\Eloquent\Model;

class UserMeta extends Model
{
    protected $table = 'usermeta';

    protected $fillable = [
        'uid', 'firstName', 'lastName', 'profile_picture', 'xp', 'cash', 'gold', 'energyMax', 'energy', 'seenFlags', 'isNew', 'firstDay'
    ];

    protected $casts = [
        'xp' => 'integer',
        'cash' => 'integer',
        'gold' => 'integer',
        'energy' => 'integer',
        'energyMax' => 'integer',
        'isNew' => 'boolean',
    ];

    public const GOLD_MAX = 999_999_999;
    public const CASH_MAX = 99_999;
    public const XP_MAX = 2_147_400_000;

    private static array $resourceCache = [];

    public function user()
    {
        return $this->belongsTo(User::class, 'uid');
    }

    public static function loadResources(string|int $uid): array
    {
        if (isset(self::$resourceCache[$uid])) {
            return self::$resourceCache[$uid];
        }

        $meta = Database::run(
            'load player resources',
            static fn () => static::where('uid', $uid)->first(['gold', 'cash', 'xp']),
        );

        if (!$meta) {
            $data = ['gold' => 0, 'cash' => 0, 'xp' => 0];
            self::$resourceCache[$uid] = $data;
            return $data;
        }

        $data = [
            'gold' => (int) $meta->gold,
            'cash' => (int) $meta->cash,
            'xp' => (int) $meta->xp,
        ];

        self::$resourceCache[$uid] = $data;
        return $data;
    }

    public static function addResource(string|int $uid, int $amount, string $field, int $max): bool
    {
        if ($amount <= 0) {
            return $amount === 0;
        }

        $affected = Database::run(
            'add player resource',
            static fn () => static::where('uid', $uid)
                ->update([
                    $field => \DB::raw("LEAST({$field} + {$amount}, {$max})")
                ]),
        );

        static::invalidateCache($uid);
        return $affected > 0;
    }

    public static function removeResource(string|int $uid, int $amount, string $field): bool
    {
        if ($amount <= 0) {
            return $amount === 0;
        }

        $affected = Database::run(
            'remove player resource',
            static fn () => static::where('uid', $uid)
                ->where($field, '>=', $amount)
                ->update([
                    $field => \DB::raw("{$field} - {$amount}")
                ]),
        );

        if ($affected > 0) {
            static::invalidateCache($uid);
        }
        return $affected > 0;
    }

    public static function batchUpdateResources(string|int $uid, int $goldDelta = 0, int $xpDelta = 0, int $cashDelta = 0): bool
    {
        if ($goldDelta === 0 && $xpDelta === 0 && $cashDelta === 0) {
            return true;
        }

        $goldMax = self::GOLD_MAX;
        $xpMax = self::XP_MAX;
        $cashMax = self::CASH_MAX;

        $affected = Database::run(
            'batch update player resources',
            static fn () => static::where('uid', $uid)
                ->update([
                    'gold' => \DB::raw("GREATEST(0, LEAST(gold + {$goldDelta}, {$goldMax}))"),
                    'xp' => \DB::raw("GREATEST(0, LEAST(xp + {$xpDelta}, {$xpMax}))"),
                    'cash' => \DB::raw("GREATEST(0, LEAST(cash + {$cashDelta}, {$cashMax}))"),
                ]),
        );

        static::invalidateCache($uid);
        return $affected >= 0;
    }

    public static function invalidateCache(string|int $uid): void
    {
        unset(self::$resourceCache[$uid]);
    }
}
