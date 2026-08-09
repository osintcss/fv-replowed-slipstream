<?php

namespace App\Models;

use App\Support\Database;
use Illuminate\Database\Eloquent\Model;

class PlayerMeta extends Model
{
    protected $table = 'playermeta';

    protected $fillable = [
        'uid', 'meta_key', 'meta_value'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'uid');
    }

    public static function getValue(string|int $uid, string $metaKey): string|false
    {
        if (!isset($GLOBALS['_meta_cache'])) {
            $GLOBALS['_meta_cache'] = [];
        }

        $cacheKey = "{$uid}:{$metaKey}";

        if (isset($GLOBALS['_meta_cache'][$cacheKey])) {
            return $GLOBALS['_meta_cache'][$cacheKey];
        }

        $meta = Database::run(
            'find player metadata',
            static fn () => static::where('uid', $uid)
                ->where('meta_key', $metaKey)
                ->first(),
        );

        $value = $meta ? $meta->meta_value : false;
        $GLOBALS['_meta_cache'][$cacheKey] = $value;

        return $value;
    }

    public static function setValue(string|int $uid, string $metaKey, string $metaValue): bool
    {
        if (!is_string($metaValue)) {
            return false;
        }

        Database::run(
            'save player metadata',
            static fn () => static::updateOrCreate(
                ['uid' => $uid, 'meta_key' => $metaKey],
                ['meta_value' => $metaValue],
            ),
        );

        if (!isset($GLOBALS['_meta_cache'])) {
            $GLOBALS['_meta_cache'] = [];
        }

        $cacheKey = "{$uid}:{$metaKey}";
        $GLOBALS['_meta_cache'][$cacheKey] = $metaValue;

        return true;
    }

    public static function clearCache(string|int|null $uid = null, ?string $metaKey = null): void
    {
        if ($uid && $metaKey) {
            unset($GLOBALS['_meta_cache']["{$uid}:{$metaKey}"]);
        } else {
            $GLOBALS['_meta_cache'] = [];
        }
    }
}
