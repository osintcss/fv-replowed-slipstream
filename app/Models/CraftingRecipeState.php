<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CraftingRecipeState extends Model
{
    protected $table = 'crafting_recipe_states';

    public $timestamps = false;

    protected $fillable = [
        'uid',
        'recipe_id',
        'level',
        'xp',
        'times_crafted',
        'is_unlocked',
    ];

    protected $casts = [
        'xp' => 'integer',
        'times_crafted' => 'integer',
        'level' => 'integer',
        'is_unlocked' => 'boolean',
    ];

    public static function getState(string|int $uid, string $recipeId): ?static
    {
        return static::where('uid', $uid)
            ->where('recipe_id', $recipeId)
            ->first();
    }

    public static function getAllForUser(string|int $uid): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('uid', $uid)->get();
    }

    public static function addXp(string|int $uid, string $recipeId, int $xp): static
    {
        $state = static::firstOrCreate(
            ['uid' => $uid, 'recipe_id' => $recipeId],
            ['level' => 1, 'xp' => 0, 'is_unlocked' => true]
        );
        static::whereKey($state->getKey())->increment('xp', $xp);

        $state->refresh();
        return $state;
    }

    public static function incrementCrafted(string|int $uid, string $recipeId): static
    {
        return static::updateOrCreate(
            ['uid' => $uid, 'recipe_id' => $recipeId],
            ['times_crafted' => \DB::raw("COALESCE(times_crafted, 0) + 1")]
        );
    }
}
