<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quest extends Model
{
    protected $table = 'quests';

    protected $fillable = [
        'name',
        'category',
        'priority',
        'replay',
        'skip',
        'kill_quest',
        'mem_store_id',
        'prereqs',
        'children',
        'tasks',
        'rewards',
        'frontend',
        'friend_reward',
        'data',
    ];

    public function getQuestDataAttribute(): mixed
    {
        if (empty($this->data)) {
            return null;
        }
        return @unserialize($this->data);
    }

    public static function findByName(string $name): mixed
    {
        static $cache = [];

        if (isset($cache[$name])) {
            return $cache[$name];
        }

        $quest = static::where('name', $name)->first();
        $data = $quest ? $quest->questData : false;
        $cache[$name] = $data;
        return $data;
    }

    public static function getByCategory(string $category): array
    {
        static $cache = [];

        if (isset($cache[$category])) {
            return $cache[$category];
        }

        $quests = static::where('category', $category)
            ->orderBy('id')
            ->get()
            ->map(fn($q) => $q->questData)
            ->filter()
            ->values()
            ->toArray();

        $cache[$category] = $quests;
        return $quests;
    }

    public static function getAvailableStoryQuests(int $limit = 100): array
    {
        return static::where('category', 'story')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn($q) => $q->questData)
            ->filter()
            ->values()
            ->toArray();
    }
}
