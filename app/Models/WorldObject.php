<?php

namespace App\Models;

use App\Helpers\JsonHelper;
use App\Helpers\ObjectHelper;
use App\Models\CraftingQueue;
use App\Models\CraftingSkill;
use App\Support\CraftingCottages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class WorldObject extends Model
{
    protected $table = 'world_objects';

    protected $fillable = [
        'world_id',
        'object_id',
        'class_name',
        'item_name',
        'position_x',
        'position_y',
        'position_z',
        'direction',
        'state',
        'deleted',
        'temp_id',
        'instance_data_store_key',
        'components',
        'plant_time',
        'build_time',
        'is_big_plot',
        'is_jumbo',
        'is_produce_item',
        'contents',
        'expansion_level',
        'expansion_parts',
        'equipment_parts_count',
        'message',
        'message_id',
        'author_id',
        'host_id',
        'message_timestamp',
    ];

    protected $casts = [
        'object_id' => 'integer',
        'position_x' => 'integer',
        'position_y' => 'integer',
        'position_z' => 'integer',
        'direction' => 'integer',
        'deleted' => 'boolean',
        'temp_id' => 'integer',
        'components' => 'object',
        'plant_time' => 'integer',
        'build_time' => 'integer',
        'is_big_plot' => 'boolean',
        'is_jumbo' => 'boolean',
        'is_produce_item' => 'boolean',
        'contents' => 'array',
        'expansion_level' => 'integer',
        'expansion_parts' => 'object',
        'equipment_parts_count' => 'integer',
        'message_id' => 'integer',
        'message_timestamp' => 'float',
    ];

    public function world(): BelongsTo
    {
        return $this->belongsTo(UserWorld::class, 'world_id');
    }


    public function scopeActive($query)
    {
        return $query->where('deleted', false);
    }

    public function scopeAtPosition($query, int $x, int $y)
    {
        return $query->where('position_x', $x)->where('position_y', $y);
    }

    public function scopeOfClass($query, string $className)
    {
        return $query->where('class_name', $className);
    }

    public function scopeOfItem($query, string $itemName)
    {
        return $query->where('item_name', $itemName);
    }

    public function scopeInState($query, string $state)
    {
        return $query->where('state', $state);
    }

    public static function getForWorld(int $worldId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('world_id', $worldId)
            ->active()
            ->orderBy('id')
            ->get();
    }

    public static function softDeleteAtPosition(int $worldId, int $posX, int $posY): int
    {
        return static::where('world_id', $worldId)
            ->atPosition($posX, $posY)
            ->update(['deleted' => true]);
    }

    public static function updateAtPosition(int $worldId, int $posX, int $posY, array $data): int
    {
        return static::where('world_id', $worldId)
            ->atPosition($posX, $posY)
            ->update($data);
    }

    public static function findAtPosition(int $worldId, int $posX, int $posY): ?self
    {
        return static::where('world_id', $worldId)
            ->atPosition($posX, $posY)
            ->active()
            ->first();
    }

    public function toFlashObject(?int $uid = null): \stdClass
    {
        $obj = new \stdClass();
        [$itemName, $className, $state] = self::normalizeLegacyAnimalBreedingBuilding(
            $this->item_name,
            $this->class_name,
            $this->state,
        );

        $obj->id = $this->object_id;
        $obj->className = $className;
        $obj->itemName = $itemName;
        $obj->position = (object)[
            'x' => $this->position_x,
            'y' => $this->position_y,
            'z' => $this->position_z,
        ];
        $obj->direction = $this->direction;
        $state = self::normalizeLegacyAnimalBreedingState(
            $itemName,
            $className,
            $state,
        );
        $obj->state = self::normalizeLegacyTreeState($className, $state);
        $obj->deleted = $this->deleted;
        $obj->tempId = $this->temp_id;
        $obj->instanceDataStoreKey = $this->instance_data_store_key;
        $obj->components = $this->components ?? (object)[];
        $obj->plantTime = $this->plant_time;
        $obj->buildTime = $this->build_time;
        $obj->isBigPlot = $this->is_big_plot;
        $obj->isJumbo = $this->is_jumbo;
        $obj->isProduceItem = $this->is_produce_item;
        $contents = $this->contents;
        $obj->contents = is_array($contents) ? $contents : JsonHelper::safeDecode($contents, true, []);
        $obj->expansionLevel = $this->expansion_level;
        $expansionParts = $this->expansion_parts;
        $obj->expansionParts = is_object($expansionParts) ? $expansionParts : JsonHelper::safeDecode($expansionParts, false, new \stdClass());
        $obj->m_equipmentPartsCount = $this->equipment_parts_count;
        $obj->message = $this->message;
        $obj->messageId = $this->message_id;
        $obj->authorId = $this->author_id;
        $obj->hostId = $this->host_id;
        $obj->timestamp = $this->message_timestamp;

        if ($className === 'CraftingCottageBuilding') {
            $this->enrichCraftingCottageData($obj, $uid);
        }

        // Animal-breeding pens use their own construction-building class,
        // rather than FeatureBuilding, even after they are built. They still
        // inherit Flash's storage contract: `contents`, `storageMetadata`,
        // and `featuredItems` must be present at the top level on reload.
        // Leaving those values nested only in components makes a Dino Lab
        // appear empty after a refresh even though the dinosaur was saved.
        if (
            $className === 'FeatureBuilding'
            || str_starts_with((string) $itemName, 'animal_breeding_')
        ) {
            $this->enrichFeatureBuildingStorageData($obj);
        }

        // Ordinary orchards retain their OrchardConstructionBuilding class
        // after construction.  Like storage and crafting buildings, it
        // inherits the construction renderer and needs an explicit completed
        // flag on world reload; otherwise Flash draws only its placement
        // shadow despite state="built".
        if (in_array($className, [
            'StorageBuilding',
            'InventoryCellar',
            'OrchardConstructionBuilding',
            // Chicken Coops inherit HarvestableStorageBuilding's construction
            // renderer. A fully populated coop can otherwise be serialized
            // as an unfinished footprint/shadow after a reload.
            'ChickenCoopBuilding',
        ], true)) {
            $this->enrichStorageBuildingData($obj);
        }

        return $obj;
    }

    private function enrichCraftingCottageData(\stdClass $obj, ?int $uid): void
    {
        try {
            $craftType = self::getCraftTypeFromItemName($this->item_name);

            // Crafting cottages share the construction contract used by
            // storage buildings. Without this explicit flag the legacy Flash
            // client treats an otherwise built cottage as its placement
            // footprint and renders only the shadow.
            $obj->isFullyBuilt = ($this->state !== 'construction');

            $components = $this->components;
            if (!is_object($components)) {
                $components = new \stdClass();
            }

            $obj->cottageName = $components->cottageName ?? '';
            $obj->finishedRecipes = $components->finishedRecipes ?? new \stdClass();
            $obj->transactionHistory = $components->transactionHistory ?? [];
            $obj->historyLastViewedTS = $components->historyLastViewedTS ?? 0;
            $obj->historyXPGain = $components->historyXPGain ?? 0;
            $obj->pendingLevelUpFeed = $components->pendingLevelUpFeed ?? null;

            // Legacy Craftshops were persisted without cottage component
            // data. The crafting-window gate requires a positive founding
            // timestamp; plant_time is the faithful fallback for an imported
            // completed workshop.
            $foundingTS = (int) ($components->foundingTS ?? 0);
            if ($foundingTS <= 0) {
                $foundingTS = (int) ($this->build_time ?: $this->plant_time);
            }
            $obj->foundingTS = $foundingTS > 0 ? $foundingTS : (int) (microtime(true) * 1000);

            if ($uid !== null && $craftType !== null) {
                $obj->recipeQueue = self::fetchRecipeQueue($uid, $craftType);
                $obj->craftLevel = self::fetchCraftLevel($uid, $craftType);
            } else {
                $obj->recipeQueue = [];
                $obj->craftLevel = 1;
            }
        } catch (\Exception $e) {
            Log::error('CraftingCottageBuilding enrichment failed: ' . $e->getMessage());

            $obj->cottageName = '';
            $obj->finishedRecipes = new \stdClass();
            $obj->transactionHistory = [];
            $obj->historyLastViewedTS = 0;
            $obj->historyXPGain = 0;
            $obj->pendingLevelUpFeed = null;
            $obj->foundingTS = 0;
            $obj->recipeQueue = [];
            $obj->craftLevel = 1;
        }
    }

    private function enrichStorageBuildingData(\stdClass $obj): void
    {
        $obj->isFullyBuilt = ($this->state !== 'construction');

        $components = $this->components;
        if (!is_object($components)) {
            $components = new \stdClass();
        }
        $obj->paintColor = $components->paintColor ?? null;
    }

    /**
     * Feature buildings such as animal pens inherit the Flash storage
     * implementation, but identify themselves as FeatureBuilding in world
     * state. The Flash client expects these fields at the top level rather
     * than nested under components.
     */
    private function enrichFeatureBuildingStorageData(\stdClass $obj): void
    {
        $obj->isFullyBuilt = ($this->state !== 'construction');

        $components = $this->components;
        if (!is_object($components)) {
            $components = new \stdClass();
        }

        $obj->paintColor = $components->paintColor ?? null;
        $obj->storageMetadata = $components->storageMetadata ?? new \stdClass();
        $obj->featuredItems = $components->featuredItems ?? new \stdClass();

        // Older habitat saves have their animals safely in `contents` but no
        // featured-slot map. FeatureBuilding renders its occupants only from
        // featuredItems on load, so reconstruct a deterministic map for the
        // reload response. New writes use the explicit setFeaturedItem action
        // and persist this same structure.
        if (
            (
                str_starts_with((string) $this->item_name, 'animal_breeding_')
                || in_array($this->item_name, [
                    'babybunnyhutch_finished',
                    'xuk_sheep_pen_finished',
                    'flower_garden_finished',
                ], true)
            )
            &&
            is_object($obj->featuredItems)
            && count(get_object_vars($obj->featuredItems)) === 0
            && is_array($obj->contents)
            && !empty($obj->contents)
        ) {
            $featuredItems = new \stdClass();
            $slot = 0;
            foreach ($obj->contents as $content) {
                $itemCode = is_object($content)
                    ? ($content->itemCode ?? null)
                    : ($content['itemCode'] ?? null);
                $count = is_object($content)
                    ? (int) ($content->numItem ?? 0)
                    : (int) ($content['numItem'] ?? 0);

                for ($i = 0; $itemCode !== null && $i < max(0, $count); $i++) {
                    $featuredItems->{(string) $slot++} = (object) [
                        'itemCode' => (string) $itemCode,
                        'metaHash' => (string) $itemCode . ':',
                    ];
                }
            }
            $obj->featuredItems = $featuredItems;
        }
    }

    public static function getCraftTypeFromItemName(?string $itemName): ?string
    {
        return CraftingCottages::craftTypeForItem($itemName);
    }

    private static function fetchRecipeQueue(int $uid, string $craftType): array
    {
        try {
            $rows = CraftingQueue::where('uid', $uid)
                ->where('craft_type', $craftType)
                ->where('status', 'active')
                ->orderBy('start_ts')
                ->get();

            // CraftingCottageBuilding.loadObject feeds every entry directly
            // to RecipeItem.fromPhpObject(). The old compact database shape
            // (recipeId, craftType, timestamps) therefore crashes the Flash
            // client as soon as an active cottage is attached to the world.
            // Share the TInitUser/TBeginRecipe AMF serializer instead.
            if (!function_exists('getRecipeQueueEnvelope')) {
                Log::warning('Crafting queue serializer is unavailable');
                return [];
            }

            $queue = [];
            foreach ($rows as $row) {
                $entry = getRecipeQueueEnvelope($uid, (string) $row->recipe_id, $row);
                if ($entry !== null) {
                    $queue[] = $entry;
                }
            }

            return $queue;
        } catch (\Exception $e) {
            Log::warning('fetchRecipeQueue failed: ' . $e->getMessage());
            return [];
        }
    }

    private static function fetchCraftLevel(int $uid, string $craftType): int
    {
        try {
            $skill = CraftingSkill::where('uid', $uid)
                ->where('craft_type', $craftType)
                ->first();

            return $skill ? (int) $skill->level : 1;
        } catch (\Exception $e) {
            Log::warning('fetchCraftLevel failed: ' . $e->getMessage());
            return 1;
        }
    }

    public static function fromFlashObject(\stdClass $obj, int $worldId): array
    {
        [$posX, $posY, $posZ] = ObjectHelper::getPosition($obj);
        $components = $obj->components ?? null;
        [$itemName, $className, $state] = self::normalizeLegacyAnimalBreedingBuilding(
            $obj->itemName ?? null,
            $obj->className ?? 'Unknown',
            $obj->state ?? null,
        );

        // FeatureBuilding reload data keeps storage fields at the object top
        // level. A subsequent world update can echo those fields without a
        // nested components object; retain them when persisting so a harvest
        // or instant-grow cannot erase featured animals from a completed pen.
        if ($className === 'FeatureBuilding' || str_starts_with((string) $itemName, 'animal_breeding_')) {
            if (!is_object($components)) {
                $components = is_array($components) ? (object) $components : new \stdClass();
            }

            foreach (['featuredItems', 'storageMetadata', 'paintColor'] as $field) {
                if (property_exists($obj, $field) && !property_exists($components, $field)) {
                    $components->{$field} = $obj->{$field};
                }
            }
        }

        $data = [
            'world_id' => $worldId,
            'object_id' => $obj->id ?? 0,
            'class_name' => $className,
            'item_name' => $itemName,
            'position_x' => $posX,
            'position_y' => $posY,
            'position_z' => $posZ,
            'direction' => $obj->direction ?? 0,
            'state' => self::normalizeLegacyTreeState(
                $className,
                self::normalizeLegacyAnimalBreedingState(
                    $itemName,
                    $className,
                    $state,
                ),
            ),
            'deleted' => $obj->deleted ?? false,
            'temp_id' => $obj->tempId ?? -1,
            'instance_data_store_key' => $obj->instanceDataStoreKey ?? null,
            'components' => JsonHelper::safeEncode($components),
            'plant_time' => $obj->plantTime ?? 0,
            'build_time' => $obj->buildTime ?? 0,
            'is_big_plot' => $obj->isBigPlot ?? false,
            'is_jumbo' => $obj->isJumbo ?? false,
            'is_produce_item' => $obj->isProduceItem ?? false,
            'contents' => JsonHelper::safeEncode($obj->contents ?? null),
            'expansion_level' => $obj->expansionLevel ?? 1,
            'expansion_parts' => JsonHelper::safeEncode($obj->expansionParts ?? null),
            'equipment_parts_count' => $obj->m_equipmentPartsCount ?? 0,
            'message' => ObjectHelper::extractScalar($obj->message ?? null),
            'message_id' => ObjectHelper::extractScalar($obj->messageId ?? null),
            'author_id' => ObjectHelper::extractScalar($obj->authorId ?? null),
            'host_id' => ObjectHelper::extractScalar($obj->hostId ?? null),
            'message_timestamp' => isset($obj->timestamp) ? $obj->timestamp : null,
        ];

        // The Crafting Silo is a completed FeatureBuilding in this client.
        // A market placement can arrive as the generic "bare" object, which
        // lets it render but makes Craftshop treat it as absent. Normalize it
        // to the completed state when persisting, and retain level one so its
        // initial ten ingredient slots are available.
        if ($data['item_name'] === 'craftingsilo') {
            $data['expansion_level'] = max(1, (int) $data['expansion_level']);
            if ($data['state'] === null || $data['state'] === '' || $data['state'] === 'bare') {
                $data['state'] = 'grown';
            }
        }

        return $data;
    }

    /**
     * Older saves can represent completed Animal Breeding buildings as a
     * crop-style "grown" object. The preserved FeatureBuilding client has no
     * matching grown visual state, even when featured items are populated,
     * and renders only its ground shadow. Modern placements use "bare".
     */
    private static function normalizeLegacyAnimalBreedingState(
        ?string $itemName,
        ?string $className,
        ?string $state,
    ): ?string {
        if (
            ! is_string($itemName)
            || ! self::isLegacyCompletedAnimalBreedingBuilding($itemName)
            || $className !== 'FeatureBuilding'
            || $state !== 'grown'
        ) {
            return $state;
        }

        return 'bare';
    }

    /**
     * These item names predate the common `animal_breeding_` naming family,
     * but use the same completed FeatureBuilding and storage contract.
     */
    private static function isLegacyCompletedAnimalBreedingBuilding(string $itemName): bool
    {
        return (str_starts_with($itemName, 'animal_breeding_') && str_ends_with($itemName, '_finished'))
            || in_array($itemName, [
                'babybunnyhutch_finished',
                'flower_garden_finished',
                'xhworchard_featurebuilding_finished',
                'xuk_sheep_pen_finished',
            ], true);
    }

    /**
     * Trees and Chicken Coops use the harvestable-resource lifecycle states
     * `bare` and `ripe`. Earlier generic saves wrote the crop-style `grown`
     * value, for which their Flash renderers cannot select an image and show
     * only the placement shadow. Limit the repair to those exact classes;
     * other buildings intentionally use different state vocabularies.
     */
    private static function normalizeLegacyTreeState(?string $className, ?string $state): ?string
    {
        return in_array($className, ['Tree', 'ChickenCoopBuilding'], true) && $state === 'grown'
            ? 'ripe'
            : $state;
    }

    /**
     * A completed animal-breeding pen must become its finished FeatureBuilding
     * resource. Older saves could retain the construction resource in its
     * terminal `built` state; it renders an enclosure, but its construction
     * class cannot restore the dinosaurs stored in the completed lab/pen.
     */
    private static function normalizeLegacyAnimalBreedingBuilding(
        ?string $itemName,
        ?string $className,
        ?string $state,
    ): array {
        if (
            ! is_string($itemName)
            || ! str_starts_with($itemName, 'animal_breeding_')
            || str_ends_with($itemName, '_finished')
            // Pet Runs and several regional pens use a specific construction
            // class (for example AnimalPetrunConstructionBuilding), rather
            // than the generic AnimalBreedingPenConstructionBuilding.  All
            // of those classes represent the same terminal construction
            // contract when paired with an animal_breeding_* resource.
            || ! is_string($className)
            || ! preg_match('/^Animal.+ConstructionBuilding$/', $className)
            || $state !== 'built'
        ) {
            return [$itemName, $className, $state];
        }

        return ["{$itemName}_finished", 'FeatureBuilding', 'bare'];
    }
}
