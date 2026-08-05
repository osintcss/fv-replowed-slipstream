<?php

namespace App\Console\Commands;

use App\Models\WorldObject;
use Illuminate\Console\Command;

class RepairCraftingSilos extends Command
{
    protected $signature = 'crafting:repair-silos {--dry-run : List affected silos without changing them}';

    protected $description = 'Restore the completed state and initial capacity for placed Crafting Silos';

    public function handle(): int
    {
        $silos = WorldObject::query()
            ->where('deleted', false)
            ->where('item_name', 'craftingsilo')
            ->where(function ($query) {
                $query->whereNull('expansion_level')
                    ->orWhere('expansion_level', '<', 1)
                    ->orWhereNull('state')
                    ->orWhere('state', '')
                    ->orWhere('state', 'bare');
            })
            ->orderBy('world_id')
            ->orderBy('object_id')
            ->get();

        foreach ($silos as $silo) {
            $this->line(sprintf(
                'world=%d object=%d state=%s -> grown, expansionLevel=%s -> 1',
                $silo->world_id,
                $silo->object_id,
                $silo->state === null ? 'null' : $silo->state,
                $silo->expansion_level === null ? 'null' : (string) $silo->expansion_level,
            ));

            if (! $this->option('dry-run')) {
                $silo->forceFill([
                    'state' => 'grown',
                    'expansion_level' => max(1, (int) $silo->expansion_level),
                ])->save();
            }
        }

        $this->info($this->option('dry-run')
            ? "{$silos->count()} Crafting Silo(s) would be repaired."
            : "Repaired {$silos->count()} Crafting Silo(s). Reload the game to receive the repaired world state.");

        return self::SUCCESS;
    }
}
