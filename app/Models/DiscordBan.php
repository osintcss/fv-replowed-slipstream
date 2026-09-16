<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscordBan extends Model
{
    protected $fillable = [
        'discord_id',
        'banned_at',
    ];

    protected function casts(): array
    {
        return [
            'banned_at' => 'datetime',
        ];
    }

    public static function contains(string $discordId): bool
    {
        return static::query()->where('discord_id', $discordId)->exists();
    }
}
