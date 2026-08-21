<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\UserWorld;
use App\Models\WorldObject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CapFarmSize extends Command
{
    protected $signature = 'world:cap-farm-size {--uid= : Migrate one player} {--all : Migrate every oversized farm} {--size=98 : Maximum width and height} {--dry-run : Report the relocation without saving it}';

    protected $description = 'Shrink oversized farms without deleting objects by packing out-of-bounds objects into free in-bounds tiles';

    public function handle(): int
    {
        $cap = (int) $this->option('size');
        if ($cap < 1) {
            $this->error('The farm-size cap must be positive.');
            return self::FAILURE;
        }

        $uid = $this->option('uid');
        if (!$uid && !$this->option('all')) {
            $this->error('Pass --uid=<player id> or --all.');
            return self::FAILURE;
        }

        $query = UserWorld::query()->where('type', 'farm')
            ->where(function ($query) use ($cap): void {
                $query->where('sizeX', '>', $cap)->orWhere('sizeY', '>', $cap);
            });
        if ($uid) {
            $query->where('uid', (string) $uid);
        }

        $worlds = $query->orderBy('id')->get();
        if ($worlds->isEmpty()) {
            $this->info('No oversized farm worlds require migration.');
            return self::SUCCESS;
        }

        foreach ($worlds as $world) {
            $this->migrateWorld($world, $cap, (bool) $this->option('dry-run'));
        }

        return self::SUCCESS;
    }

    private function migrateWorld(UserWorld $world, int $cap, bool $dryRun): void
    {
        $objects = WorldObject::where('world_id', $world->id)->where('deleted', false)->orderBy('id')->get();
        $occupancy = [];
        $relocations = [];

        // Keep valid objects in place first. Objects that would extend beyond
        // the target boundary are treated as relocations too.
        foreach ($objects as $object) {
            [$width, $height] = $this->footprint($object->item_name);
            if ($this->fits((int) $object->position_x, (int) $object->position_y, $width, $height, $cap)) {
                $this->occupy($occupancy, (int) $object->position_x, (int) $object->position_y, $width, $height);
            } else {
                $relocations[] = [$object, $width, $height];
            }
        }

        $moves = [];
        foreach ($relocations as [$object, $width, $height]) {
            $position = $this->findFreePosition($occupancy, $width, $height, $cap);
            if ($position === null) {
                throw new \RuntimeException("Farm {$world->id} has no safe in-bounds position for object {$object->id} ({$object->item_name}).");
            }
            [$x, $y] = $position;
            $moves[] = [
                'id' => $object->id,
                'objectId' => $object->object_id,
                'itemName' => $object->item_name,
                'from' => [(int) $object->position_x, (int) $object->position_y],
                'to' => [$x, $y],
            ];
            $this->occupy($occupancy, $x, $y, $width, $height);
        }

        $this->line(sprintf(
            'uid=%s world=%d: %dx%d -> %dx%d; relocating %d of %d active objects%s.',
            $world->uid,
            $world->id,
            $world->sizeX,
            $world->sizeY,
            $cap,
            $cap,
            count($moves),
            $objects->count(),
            $dryRun ? ' (dry run)' : '',
        ));

        if ($dryRun) {
            return;
        }

        $backup = [
            'createdAt' => now()->toIso8601String(),
            'uid' => $world->uid,
            'worldId' => $world->id,
            'originalSize' => [(int) $world->sizeX, (int) $world->sizeY],
            'targetSize' => [$cap, $cap],
            'moves' => $moves,
        ];
        $backupPath = sprintf('farm-size-cap-backups/%s-world-%d-%s.json', $world->uid, $world->id, now()->format('Ymd-His'));

        DB::transaction(function () use ($world, $cap, $moves, $backupPath, $backup): void {
            // Persist a reversible relocation manifest before altering rows.
            Storage::disk('local')->put($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            foreach ($moves as $move) {
                WorldObject::whereKey($move['id'])->update([
                    'position_x' => $move['to'][0],
                    'position_y' => $move['to'][1],
                ]);
            }
            $world->update(['sizeX' => $cap, 'sizeY' => $cap]);
        });

        $this->info("Migrated world {$world->id}; backup manifest: storage/app/{$backupPath}");
    }

    /** @return array{int,int} */
    private function footprint(?string $itemName): array
    {
        $item = $itemName ? Item::findByName($itemName) : null;
        $width = max(1, (int) ($item['sizeX'] ?? 1));
        $height = max(1, (int) ($item['sizeY'] ?? 1));
        return [$width, $height];
    }

    private function fits(int $x, int $y, int $width, int $height, int $cap): bool
    {
        return $x >= 0 && $y >= 0 && $x + $width <= $cap && $y + $height <= $cap;
    }

    private function findFreePosition(array $occupancy, int $width, int $height, int $cap): ?array
    {
        for ($y = 0; $y <= $cap - $height; $y++) {
            for ($x = 0; $x <= $cap - $width; $x++) {
                $free = true;
                for ($dy = 0; $dy < $height && $free; $dy++) {
                    for ($dx = 0; $dx < $width; $dx++) {
                        if (isset($occupancy[($y + $dy).':'.($x + $dx)])) {
                            $free = false;
                            break;
                        }
                    }
                }
                if ($free) {
                    return [$x, $y];
                }
            }
        }
        return null;
    }

    private function occupy(array &$occupancy, int $x, int $y, int $width, int $height): void
    {
        for ($dy = 0; $dy < $height; $dy++) {
            for ($dx = 0; $dx < $width; $dx++) {
                $occupancy[($y + $dy).':'.($x + $dx)] = true;
            }
        }
    }
}
