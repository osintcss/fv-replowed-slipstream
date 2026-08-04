<?php

namespace App\Console\Commands;

use App\Models\WorldObject;
use App\Support\CraftingCottages;
use Illuminate\Console\Command;

class RepairCraftingCottages extends Command
{
    protected $signature = 'crafting:repair-cottages {--dry-run : List affected objects without changing them}';

    protected $description = 'Convert market-proxy crafting buildings in world state to functional crafting cottages';

    public function handle(): int
    {
        $marketItems = array_values(array_filter(array_map(
            static fn (array $cottage) => $cottage['marketItem'] ?? null,
            CraftingCottages::all()
        )));
        $functionalItems = array_map(
            static fn (array $cottage) => $cottage['functionalItem'],
            CraftingCottages::all()
        );

        $objects = WorldObject::query()
            ->where('deleted', false)
            ->where(function ($query) use ($marketItems, $functionalItems) {
                $query->whereIn('item_name', $marketItems)
                    ->orWhere(function ($query) use ($functionalItems) {
                        $query->whereIn('item_name', $functionalItems)
                            ->where(function ($query) {
                                $query->whereNull('class_name')
                                    ->orWhere('class_name', '!=', 'CraftingCottageBuilding')
                                    ->orWhere('state', 'built_0');
                            });
                    });
            })
            ->orderBy('world_id')
            ->orderBy('object_id')
            ->get();

        if ($objects->isEmpty()) {
            $this->info('No market-proxy crafting cottages need repair.');

            return self::SUCCESS;
        }

        foreach ($objects as $object) {
            $cottage = CraftingCottages::forMarketItem($object->item_name)
                ?? CraftingCottages::forFunctionalItem($object->item_name);

            if ($cottage === null) {
                continue;
            }

            $needsStateRepair = $object->state === 'built_0';
            $targetItemName = $cottage['functionalItem'];
            $targetState = $needsStateRepair || CraftingCottages::forMarketItem($object->item_name) !== null
                ? 'built'
                : $object->state;

            $this->line(sprintf(
                'world=%d object=%d item=%s -> %s, state=%s -> %s',
                $object->world_id,
                $object->object_id,
                $object->item_name,
                $targetItemName,
                $object->state ?? '(null)',
                $targetState ?? '(null)',
            ));

            if (! $this->option('dry-run')) {
                $object->forceFill([
                    'item_name' => $targetItemName,
                    'class_name' => 'CraftingCottageBuilding',
                    'state' => $targetState,
                ])->save();
            }
        }

        $this->info($this->option('dry-run')
            ? "{$objects->count()} crafting cottage(s) would be repaired."
            : "Repaired {$objects->count()} crafting cottage(s). Reload the game to receive the repaired world state.");

        return self::SUCCESS;
    }
}
