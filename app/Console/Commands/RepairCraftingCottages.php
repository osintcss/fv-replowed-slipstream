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
                    ->orWhereIn('item_name', $functionalItems);
            })
            ->orderBy('world_id')
            ->orderBy('object_id')
            ->get()
            ->filter(function (WorldObject $object): bool {
                $cottage = CraftingCottages::forMarketItem($object->item_name)
                    ?? CraftingCottages::forFunctionalItem($object->item_name);
                if ($cottage === null) {
                    return false;
                }

                $contract = CraftingCottages::worldContractForItem($cottage['functionalItem']);

                $components = is_object($object->components) ? $object->components : new \stdClass();
                $hasCottageMetadata = (int) ($components->foundingTS ?? 0) > 0;

                return $contract !== null && (
                    $object->item_name !== $cottage['functionalItem']
                    || $object->class_name !== $contract['className']
                    || $object->state !== $contract['state']
                    || ! $hasCottageMetadata
                );
            })
            ->values();

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

            $targetItemName = $cottage['functionalItem'];
            $contract = CraftingCottages::worldContractForItem($targetItemName);
            $targetClassName = $contract['className'];
            $targetState = $contract['state'];

            $components = is_object($object->components) ? $object->components : new \stdClass();
            $previousFoundingTS = (int) ($components->foundingTS ?? 0);
            if ($previousFoundingTS <= 0) {
                $components->foundingTS = (int) ($object->build_time ?: $object->plant_time);
                if ($components->foundingTS <= 0) {
                    $components->foundingTS = (int) (microtime(true) * 1000);
                }
            }
            $components->cottageName = $components->cottageName ?? '';
            $components->finishedRecipes = $components->finishedRecipes ?? new \stdClass();
            $components->transactionHistory = $components->transactionHistory ?? [];
            $components->historyLastViewedTS = $components->historyLastViewedTS ?? 0;
            $components->historyXPGain = $components->historyXPGain ?? 0;
            $components->pendingLevelUpFeed = $components->pendingLevelUpFeed ?? null;

            $this->line(sprintf(
                'world=%d object=%d item=%s -> %s, class=%s -> %s, state=%s -> %s, foundingTS=%d -> %d',
                $object->world_id,
                $object->object_id,
                $object->item_name,
                $targetItemName,
                $object->class_name,
                $targetClassName,
                $object->state ?? '(null)',
                $targetState ?? '(null)',
                $previousFoundingTS,
                $components->foundingTS,
            ));

            if (! $this->option('dry-run')) {
                $object->forceFill([
                    'item_name' => $targetItemName,
                    'class_name' => $targetClassName,
                    'state' => $targetState,
                    'components' => $components,
                ])->save();
            }
        }

        $this->info($this->option('dry-run')
            ? "{$objects->count()} crafting cottage(s) would be repaired."
            : "Repaired {$objects->count()} crafting cottage(s). Reload the game to receive the repaired world state.");

        return self::SUCCESS;
    }
}
