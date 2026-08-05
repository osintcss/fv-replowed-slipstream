<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairDuplicateWorldObjects extends Command
{
    protected $signature = 'world:repair-duplicates
                            {--apply : Delete duplicate active objects after reporting them}
                            {--world= : Restrict the scan to one world ID}';

    protected $description = 'Find and optionally remove active world objects stacked at one anchor';

    public function handle(): int
    {
        $groups = DB::table('world_objects')
            ->select('world_id', 'class_name', 'position_x', 'position_y', 'position_z', DB::raw('COUNT(*) AS copies'))
            ->where('deleted', false)
            ->whereNotNull('position_x')
            ->whereNotNull('position_y')
            ->when($this->option('world'), fn ($query, $worldId) => $query->where('world_id', $worldId))
            ->groupBy('world_id', 'class_name', 'position_x', 'position_y', 'position_z')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('world_id')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No active objects share a class and world anchor.');
            return self::SUCCESS;
        }

        $removed = 0;
        foreach ($groups as $group) {
            $rows = DB::table('world_objects')
                ->where('world_id', $group->world_id)
                ->where('class_name', $group->class_name)
                ->where('position_x', $group->position_x)
                ->where('position_y', $group->position_y)
                ->where('position_z', $group->position_z)
                ->where('deleted', false)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get(['id', 'object_id', 'item_name']);

            $keep = $rows->shift();
            $discardIds = $rows->pluck('id')->all();
            $this->line(sprintf(
                'world=%s %s/%s at (%s,%s,%s): retain object=%s row=%s; duplicates=%s',
                $group->world_id,
                $group->class_name,
                $keep->item_name ?? '(none)',
                $group->position_x,
                $group->position_y,
                $group->position_z,
                $keep->object_id,
                $keep->id,
                implode(',', $discardIds)
            ));

            if ($this->option('apply') && $discardIds !== []) {
                $removed += DB::table('world_objects')->whereIn('id', $discardIds)->delete();
            }
        }

        if ($this->option('apply')) {
            $this->info("Removed {$removed} duplicate world-object rows.");
        } else {
            $this->warn('Dry run only. Re-run with --apply to remove the reported duplicates.');
        }

        return self::SUCCESS;
    }
}
