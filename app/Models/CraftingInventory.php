<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CraftingInventory extends Model
{
    protected $table = 'crafting_inventory';

    public $timestamps = false;

    protected $fillable = [
        'uid',
        'item_code',
        'quantity',
        'storage_type',
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    public static function getForUser(string|int $uid): array
    {
        return static::where('uid', $uid)
            ->get()
            ->mapWithKeys(fn($item) => [$item->item_code => $item->quantity])
            ->toArray();
    }

    public static function addItem(string|int $uid, string $itemCode, int $quantity): bool
    {
        if ($quantity <= 0) {
            return false;
        }

        $item = static::firstOrCreate(
            ['uid' => $uid, 'item_code' => $itemCode],
            ['quantity' => 0]
        );
        static::whereKey($item->getKey())->increment('quantity', $quantity);

        return true;
    }

    public static function removeItem(string|int $uid, string $itemCode, int $quantity): bool
    {
        if ($quantity <= 0) {
            return false;
        }

        $affected = static::where('uid', $uid)
            ->where('item_code', $itemCode)
            ->where('quantity', '>=', $quantity)
            ->update(['quantity' => \DB::raw("quantity - {$quantity}")]);

        if ($affected > 0) {
            static::where('uid', $uid)
                ->where('item_code', $itemCode)
                ->where('quantity', '<=', 0)
                ->delete();
        }

        return $affected > 0;
    }

    public static function setQuantity(string|int $uid, string $itemCode, int $quantity): bool
    {
        if ($quantity <= 0) {
            static::where('uid', $uid)->where('item_code', $itemCode)->delete();
            return true;
        }

        static::updateOrCreate(
            ['uid' => $uid, 'item_code' => $itemCode],
            ['quantity' => $quantity]
        );

        return true;
    }
}
