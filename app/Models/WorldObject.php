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

        $obj->id = $this->object_id;
        $obj->className = $this->class_name;
        $obj->itemName = $this->item_name;
        $obj->position = (object)[
            'x' => $this->position_x,
            'y' => $this->position_y,
            'z' => $this->position_z,
        ];
        $obj->direction = $this->direction;
        $obj->state = $this->state;
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

        if ($this->class_name === 'CraftingCottageBuilding') {
            $this->enrichCraftingCottageData($obj, $uid);
        }

        if ($this->class_name === 'FeatureBuilding') {
            $this->enrichFeatureBuildingStorageData($obj);
        }

        if ($this->class_name === 'StorageBuilding' || $this->class_name === 'InventoryCellar') {
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

        $data = [
            'world_id' => $worldId,
            'object_id' => $obj->id ?? 0,
            'class_name' => $obj->className ?? 'Unknown',
            'item_name' => $obj->itemName ?? null,
            'position_x' => $posX,
            'position_y' => $posY,
            'position_z' => $posZ,
            'direction' => $obj->direction ?? 0,
            'state' => $obj->state ?? null,
            'deleted' => $obj->deleted ?? false,
            'temp_id' => $obj->tempId ?? -1,
            'instance_data_store_key' => $obj->instanceDataStoreKey ?? null,
            'components' => JsonHelper::safeEncode($obj->components ?? null),
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

        // The Crafting Silo's item definition starts at expansion level one,
        // which grants its initial ten ingredient slots. The placement object
        // sent by Flash can omit that field (or send zero), and persisting it
        // verbatim makes CraftingSiloWindow calculate a capacity of zero.
        if ($data['item_name'] === 'craftingsilo') {
            $data['expansion_level'] = max(1, (int) $data['expansion_level']);
        }

        return $data;
    }
}
