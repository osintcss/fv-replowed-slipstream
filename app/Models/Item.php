<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Item extends Model
{
    protected $table = 'items';

    // The imported legacy catalog has no created_at/updated_at columns.
    public $timestamps = false;

    protected $fillable = [
        'name',
        'code',
        'data',
    ];

    public function getItemDataAttribute(): mixed
    {
        if (empty($this->data)) {
            return null;
        }
        return @unserialize($this->data);
    }

    public static function findByName(string $name): mixed
    {
        if (empty($name)) {
            return false;
        }

        static $nameCache = [];

        if (array_key_exists($name, $nameCache)) {
            return $nameCache[$name];
        }

        $cacheKey = "item:name:{$name}";
        $data = Cache::remember($cacheKey, 3600, function () use ($name) {
            $item = static::where('name', $name)->first();
            if (!$item) {
                return false;
            }
            return $item->itemData;
        });

        $nameCache[$name] = $data;
        return $data;
    }

    public static function findByCode(string $code): mixed
    {
        if (empty($code)) {
            return false;
        }

        static $codeCache = [];

        if (array_key_exists($code, $codeCache)) {
            return $codeCache[$code];
        }

        $cacheKey = "item:code:{$code}";
        $data = Cache::remember($cacheKey, 3600, function () use ($code) {
            $item = static::whereRaw('BINARY code = ?', [$code])->first();
            if (!$item) {
                return false;
            }
            return $item->itemData;
        });

        $codeCache[$code] = $data;
        return $data;
    }

    public static function clearCache(): void
    {
        Cache::flush();
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
