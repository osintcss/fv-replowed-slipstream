<?php

namespace App\Console\Commands;

use App\Models\UserWorld;
use App\Models\WorldObject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RestoreFarmSize extends Command
{
    protected $signature = 'world:restore-farm-size {backup : Backup manifest path relative to local storage} {--dry-run : Report the restoration without saving it}';

    protected $description = 'Restore a farm size and object positions from a farm-size-cap backup manifest';

    public function handle(): int
    {
        $path = (string) $this->argument('backup');
        if (!Storage::disk('local')->exists($path)) {
            $this->error("Backup manifest not found: {$path}");
            return self::FAILURE;
        }

        try {
            $backup = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->error("Invalid backup manifest: {$exception->getMessage()}");
            return self::FAILURE;
        }

        $worldId = (int) ($backup['worldId'] ?? 0);
        $uid = (string) ($backup['uid'] ?? '');
        $size = $backup['originalSize'] ?? [];
        $moves = $backup['moves'] ?? [];
        if ($worldId < 1 || $uid === '' || count($size) !== 2 || !is_array($moves)) {
            $this->error('Backup manifest is missing required world, size, or move data.');
            return self::FAILURE;
        }

        $world = UserWorld::whereKey($worldId)->where('uid', $uid)->first();
        if (!$world) {
            $this->error("World {$worldId} for uid {$uid} no longer exists.");
            return self::FAILURE;
        }

        foreach ($moves as $move) {
            if (!isset($move['id'], $move['from']) || count($move['from']) !== 2
                || !WorldObject::whereKey($move['id'])->where('world_id', $world->id)->exists()) {
                $this->error("Object validation failed for backup entry ".($move['id'] ?? 'unknown').'. No changes were made.');
                return self::FAILURE;
            }
        }

        $this->line("Restoring uid={$uid} world={$world->id}: {$world->sizeX}x{$world->sizeY} -> {$size[0]}x{$size[1]}; restoring ".count($moves).' object positions.');
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($world, $moves, $size): void {
            foreach ($moves as $move) {
                WorldObject::whereKey($move['id'])->update([
                    'position_x' => (int) $move['from'][0],
                    'position_y' => (int) $move['from'][1],
                ]);
            }
            $world->update(['sizeX' => (int) $size[0], 'sizeY' => (int) $size[1]]);
        });

        $this->info("Restored world {$world->id}. The backup manifest was retained at storage/app/private/{$path}.");
        return self::SUCCESS;
    }
}
