<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CraftingSkill extends Model
{
    protected $table = 'crafting_skills';

    public $timestamps = false;

    protected $fillable = [
        'uid',
        'craft_type',
        'xp',
        'level',
    ];

    protected $casts = [
        'xp' => 'integer',
        'level' => 'integer',
    ];

    public static function getSkill(string|int $uid, string $craftType): ?static
    {
        return static::where('uid', $uid)
            ->where('craft_type', $craftType)
            ->first();
    }

    public static function getAllForUser(string|int $uid): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('uid', $uid)->get();
    }

    public static function addXp(string|int $uid, string $craftType, int $xp): static
    {
        $skill = static::firstOrCreate(
            ['uid' => $uid, 'craft_type' => $craftType],
            ['level' => 1, 'xp' => 0]
        );
        static::whereKey($skill->getKey())->increment('xp', $xp);

        $skill->refresh();
        return $skill;
    }

    public static function levelUp(string|int $uid, string $craftType, int $newLevel): static
    {
        return static::updateOrCreate(
            ['uid' => $uid, 'craft_type' => $craftType],
            ['level' => $newLevel]
        );
    }

    public static function getLevel(string|int $uid, string $craftType): int
    {
        $skill = static::getSkill($uid, $craftType);
        return $skill ? $skill->level : 0;
    }
}
