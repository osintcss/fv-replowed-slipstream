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
        $state = self::normalizeLegacyOrchardState(
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
        $obj->contents = self::normalizeConstructionContents(
            $itemName,
            $className,
            $obj->state,
            is_array($contents) ? $contents : JsonHelper::safeDecode($contents, true, []),
        );
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
            // Pigpens extend AnimalStorageBuilding -> StorageBuilding.  They
            // are not FeatureBuildings, so without this explicit completed
            // flag the client reloads a finished pen as an unfinished
            // expansion and asks for parts again.
            'PigpenBuilding',
            // An uncompleted Pig Pen is also a StorageBuilding. It must send
            // isFullyBuilt=false on reload; otherwise an empty contents list
            // makes Flash promote it to `built` and render the completed pen
            // even though the server still has a construction object.
            'PigpenConstructionBuilding',
            // Chicken Coops inherit HarvestableStorageBuilding's construction
            // renderer. A fully populated coop can otherwise be serialized
            // as an unfinished footprint/shadow after a reload.
            'ChickenCoopBuilding',
        ], true)) {
            $this->enrichStorageBuildingData($obj);
        }

        // Mutable animal crates are per-instance breeding rewards. Their
        // item name identifies only the crate tier; the adult animal and
        // accumulated feed are stored in the metadata transferred from the
        // source Pet Run at placement time. Flash expects adultCode at the
        // top level when it reconstructs the crate after a reload.
        if ($className === 'MutableAnimalCrate') {
            $this->enrichMutableAnimalCrateData($obj);
        }

        // Breeding babies are placed from Giftbox with their DNA at the
        // object top level. MutableAnimalBaby.loadObject reads the same
        // top-level contract after reload, so retain it independently of the
        // generic building fields.
        if (in_array($className, ['MutableAnimal', 'MutableAnimalBaby'], true)) {
            $components = is_object($this->components) ? $this->components : new \stdClass();
            $mutableState = $components->mutableAnimalState ?? null;
            // Before adult breeding rewards were handled explicitly, PHP's
            // string-to-object cast stored their raw DNA under `scalar`.
            // Recover that already-saved envelope on reload so affected new
            // sheep regain their actual pattern rather than the default coat.
            if ((!is_object($mutableState) || !is_object($mutableState->dna ?? null))
                && is_string($components->scalar ?? null)) {
                $legacyDna = JsonHelper::safeDecode($components->scalar, false, null);
                if (is_object($legacyDna)
                    && isset($legacyDna->G, $legacyDna->B, $legacyDna->P)) {
                    $mutableState = (object) ['dna' => $legacyDna];
                }
            }
            // Older breeding rewards were placed before their DNA envelope
            // was persisted. Keep those legacy babies actionable after a
            // reload instead of handing Flash a null state (which makes
            // Complete/Feed no-op and causes the baby to revert visually).
            if ($className === 'MutableAnimalBaby'
                && (!is_object($mutableState) || !is_object($mutableState->dna ?? null))) {
                $mutableState = (object) ['dna' => $this->fallbackMutableAnimalBabyDna($itemName)];
            }
            if (is_object($mutableState)) {
                $obj->mutableAnimalState = $mutableState;
            }
        }

        // MutableAnimalBaby inherits ConstructionBuilding.  Its Flash
        // click handler only opens the feed/complete menu when the object is
        // in `construction` or `built` state and is not fully built.  Older
        // reloads converted `built` to `bare`, which made a partially fed
        // piglet silently unclickable after refresh.  Keep the construction
        // contract explicit for both legacy `bare` rows and normal rows.
        if ($className === 'MutableAnimalBaby') {
            if ($obj->state === 'bare' || $obj->state === '' || $obj->state === null) {
                $obj->state = 'construction';
            }
            $obj->isFullyBuilt = false;
        }

        return $obj;
    }

    private function fallbackMutableAnimalBabyDna(string $itemName): \stdClass
    {
        // Legacy lamb/piglet rows do not retain the original gender. Use a
        // stable female fallback so the recovered object can complete into a
        // valid adult instead of changing outcome on every reload.
        $gender = 'F';

        return (object) [
            'N' => '',
            'U' => '',
            'G' => $gender,
            'B' => (object) ['H' => ['0', '0'], 'S' => ['8', '8'], 'V' => ['8', '8']],
            'P' => (object) ['T' => ['a'], 'H' => ['0', '0'], 'S' => ['8', '8'], 'V' => ['8', '8']],
        ];
    }

    private function enrichMutableAnimalCrateData(\stdClass $obj): void
    {
        $components = is_object($this->components) ? $this->components : new \stdClass();
        $rawMetadata = $components->mutableAnimalCrateMetadata ?? null;
        $metadata = is_string($rawMetadata)
            ? JsonHelper::safeDecode($rawMetadata, false, new \stdClass())
            : (is_object($rawMetadata) ? $rawMetadata : new \stdClass());

        if (is_string($metadata->adultCode ?? null) && $metadata->adultCode !== '') {
            $obj->adultCode = $metadata->adultCode;
        }
        if (is_array($metadata->storageContent ?? null) && empty($obj->contents)) {
            $obj->contents = $metadata->storageContent;
        }
        if (($metadata->fullyBuilt ?? false) === true) {
            $obj->isFullyBuilt = true;
        }
    }

    /**
     * Flash's FeaturedRenderFMutableObject expects each mutable-animal
     * storage entry to be the raw JSON DNA string.  Older writes stored the
     * TStoreItem wrapper ({@code {"type":"..."}}) as an AMF object; Flash
     * stringifies that object as "[object Object]" and then fails with
     * JSONTokenizer "Unexpected o" while loading the farm.
     */
    private static function normalizeStorageMetadata($raw): \stdClass
    {
        $source = is_object($raw) ? $raw : new \stdClass();
        $metadata = new \stdClass();
        foreach (get_object_vars($source) as $key => $entries) {
            if (!is_array($entries)) {
                $entries = [$entries];
            }
            $normalized = [];
            foreach ($entries as $entry) {
                if (is_object($entry)) {
                    if (isset($entry->type) && is_string($entry->type)) {
                        $entry = $entry->type;
                    } else {
                        $entry = json_encode($entry);
                    }
                } elseif (is_array($entry)) {
                    if (isset($entry['type']) && is_string($entry['type'])) {
                        $entry = $entry['type'];
                    } else {
                        $entry = json_encode($entry);
                    }
                }
                if (is_string($entry)) {
                    $trimmed = trim($entry);
                    // MutableAnimalState.createFromPhpString only accepts a
                    // JSON object. Discard stale "Array"/"[object Object]"
                    // values rather than sending them into Flash's decoder.
                    if ($trimmed === '' || $trimmed === 'null') {
                        $normalized[] = $entry;
                    } elseif ($trimmed[0] === '{' && is_array(json_decode($trimmed, true))) {
                        $normalized[] = $trimmed;
                    }
                }
            }

            // Mutable-animal renderers look up storage by the exact
            // itemCode:DNA-hash key from featuredItems. Older rows used only
            // itemCode:, so promote valid entries while retaining a fallback
            // bucket for values whose DNA cannot be decoded.
            $baseCode = explode(':', (string) $key, 2)[0];
            foreach ($normalized as $entry) {
                $targetKey = (string) $key;
                if ($baseCode !== '' && str_ends_with((string) $key, ':')) {
                    $dna = json_decode($entry, true);
                    $hash = self::mutableAnimalStateHash($dna);
                    if ($hash !== null) {
                        $targetKey = $baseCode . ':' . $hash;
                    }
                }
                $existing = $metadata->{$targetKey} ?? [];
                $existing = is_array($existing) ? $existing : [];
                $existing[] = $entry;
                $metadata->{$targetKey} = $existing;
            }
        }
        return $metadata;
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
     * StorageBuilding adds an item's defaultItem entries when a building is
     * first placed. Its compact Flash save object intentionally omits those
     * entries, so construction sites otherwise reload with m_count=0. That
     * makes ConstructionBuilding.displayStorageDialog() return before the
     * Ask for Parts window can open. Keep the server boundary equivalent to
     * Flash by materializing the configured starter part for construction
     * objects on both read and write paths.
     */
    private static function normalizeConstructionContents(
        ?string $itemName,
        ?string $className,
        ?string $state,
        $contents,
    ): array {
        $contents = is_array($contents) ? array_values($contents) : [];
        if ($state !== 'construction'
            || !is_string($className)
            || !str_ends_with($className, 'ConstructionBuilding')
            || !is_string($itemName)
            || $itemName === '') {
            return $contents;
        }

        $constructionItem = Item::findByName($itemName);
        if (!is_array($constructionItem)) {
            return $contents;
        }

        $defaultPart = $constructionItem['defaultItem'] ?? null;
        if (is_object($defaultPart)) {
            $defaultPart = get_object_vars($defaultPart);
        }
        if (!is_array($defaultPart)) {
            return $contents;
        }

        $defaultName = $defaultPart['name'] ?? null;
        if (!is_string($defaultName) || $defaultName === '') {
            return $contents;
        }

        $partItem = Item::findByName($defaultName);
        if (is_object($partItem)) {
            $partItem = get_object_vars($partItem);
        }
        $defaultCode = is_array($partItem) ? ($partItem['code'] ?? null) : null;
        if (!is_string($defaultCode) || $defaultCode === '') {
            return $contents;
        }

        $needed = max(1, (int) ($defaultPart['amount'] ?? 1));
        $matchingIndexes = [];
        $current = 0;
        foreach ($contents as $index => $content) {
            $code = is_object($content)
                ? ($content->itemCode ?? null)
                : ($content['itemCode'] ?? null);
            if ($code !== $defaultCode) {
                continue;
            }

            $matchingIndexes[] = $index;
            $current += max(0, (int) (is_object($content)
                ? ($content->numItem ?? 0)
                : ($content['numItem'] ?? 0)));
        }

        if ($current >= $needed) {
            return $contents;
        }

        $missing = $needed - $current;
        if ($matchingIndexes !== []) {
            $index = $matchingIndexes[0];
            if (is_object($contents[$index])) {
                $contents[$index]->numItem = (int) ($contents[$index]->numItem ?? 0) + $missing;
            } else {
                $contents[$index]['numItem'] = (int) ($contents[$index]['numItem'] ?? 0) + $missing;
            }
        } else {
            $contents[] = [
                'itemCode' => $defaultCode,
                'numItem' => $needed,
            ];
        }

        return $contents;
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
        $obj->storageMetadata = self::normalizeStorageMetadata($components->storageMetadata ?? new \stdClass());
        $obj->featuredItems = $components->featuredItems ?? new \stdClass();
        // AnimalBreedingState is a FeatureBuilding component, but Flash
        // reads it from the world object itself.  The breeding queue must
        // therefore cross the persistence boundary as top-level data.
        $obj->extraDataState = $components->extraDataState ?? new \stdClass();

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

        // Older writes could retain a slot map, but with the generic
        // "itemCode:" hash that identifies no particular mutable-animal DNA
        // record. Rehydrate those hashes from storageMetadata on every load;
        // this repairs already-affected sheep without requiring the player to
        // remove and re-store each animal.
        $obj->featuredItems = self::rehydrateMutableAnimalFeaturedHashes(
            $obj->featuredItems,
            $obj->storageMetadata,
        );
    }

    private static function rehydrateMutableAnimalFeaturedHashes($featuredItems, $storageMetadata): \stdClass
    {
        $featuredItems = is_object($featuredItems) ? $featuredItems : new \stdClass();
        $storageMetadata = is_object($storageMetadata) ? $storageMetadata : new \stdClass();
        $candidates = [];

        foreach (get_object_vars($storageMetadata) as $metadataKey => $entries) {
            $itemCode = explode(':', (string) $metadataKey, 2)[0];
            if ($itemCode === '') {
                continue;
            }
            $entries = is_array($entries) ? $entries : [$entries];
            foreach ($entries as $entry) {
                if (is_object($entry) && isset($entry->type) && is_string($entry->type)) {
                    $entry = $entry->type;
                }
                if (is_string($entry)) {
                    $entry = json_decode($entry, true);
                } elseif (is_object($entry)) {
                    $entry = get_object_vars($entry);
                }
                $hash = self::mutableAnimalStateHash($entry);
                if ($hash !== null) {
                    $candidates[$itemCode][] = $itemCode . ':' . $hash;
                }
            }
        }

        $available = [];
        foreach ($candidates as $hashes) {
            foreach ($hashes as $hash) {
                $available[$hash] = ($available[$hash] ?? 0) + 1;
            }
        }
        foreach (get_object_vars($featuredItems) as $entry) {
            $itemCode = is_object($entry) ? ($entry->itemCode ?? null) : ($entry['itemCode'] ?? null);
            $metaHash = is_object($entry) ? ($entry->metaHash ?? null) : ($entry['metaHash'] ?? null);
            if (is_string($itemCode) && is_string($metaHash)
                && $metaHash !== '' && $metaHash !== $itemCode . ':') {
                if (($available[$metaHash] ?? 0) > 0) {
                    --$available[$metaHash];
                }
            }
        }

        foreach (get_object_vars($featuredItems) as $slot => $entry) {
            $itemCode = is_object($entry) ? ($entry->itemCode ?? null) : ($entry['itemCode'] ?? null);
            if (!is_string($itemCode) || $itemCode === '') {
                continue;
            }
            $metaHash = is_object($entry) ? ($entry->metaHash ?? null) : ($entry['metaHash'] ?? null);
            if (is_string($metaHash) && $metaHash !== '' && $metaHash !== $itemCode . ':') {
                continue;
            }
            foreach (($candidates[$itemCode] ?? []) as $candidate) {
                if (($available[$candidate] ?? 0) <= 0) {
                    continue;
                }
                if (is_object($entry)) {
                    $entry->metaHash = $candidate;
                } else {
                    $entry['metaHash'] = $candidate;
                }
                $featuredItems->{$slot} = $entry;
                --$available[$candidate];
                break;
            }
        }

        return $featuredItems;
    }

    private static function mutableAnimalStateHash($dna): ?string
    {
        if (!is_array($dna) || !isset($dna['G'], $dna['B'], $dna['P'])) {
            return null;
        }

        $state = (string) $dna['G'];
        foreach (['B', 'P'] as $trait) {
            foreach (['H', 'S', 'V'] as $channel) {
                $values = $dna[$trait][$channel] ?? ['', ''];
                $state .= ($values[0] ?? '') . ',' . ($values[1] ?? '');
            }
            if ($trait === 'P') {
                $state .= $dna['P']['T'][0] ?? '';
            }
        }

        return substr(md5($state), 0, 8);
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

            foreach (['featuredItems', 'storageMetadata', 'paintColor', 'extraDataState'] as $field) {
                if (!property_exists($obj, $field)) {
                    continue;
                }

                // Flash commonly echoes a complete FeatureBuilding with an
                // empty components envelope while carrying the authoritative
                // storage/slot state at the object top level. Prefer a
                // meaningful top-level value, but do not let an empty echo
                // erase metadata that was already present in components.
                if (!property_exists($components, $field)
                    || (!self::hasMeaningfulValue($components->{$field})
                        && self::hasMeaningfulValue($obj->{$field}))) {
                    $components->{$field} = $obj->{$field};
                }
            }
        }

        if (in_array($className, ['MutableAnimal', 'MutableAnimalBaby'], true)) {
            if (!is_object($components)) {
                $components = is_array($components) ? (object) $components : new \stdClass();
            }
            if (property_exists($obj, 'mutableAnimalState')) {
                $components->mutableAnimalState = $obj->mutableAnimalState;
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
                self::normalizeLegacyOrchardState(
                    $itemName,
                    $className,
                    self::normalizeLegacyAnimalBreedingState(
                        $itemName,
                        $className,
                        $state,
                    ),
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
            'contents' => JsonHelper::safeEncode(self::normalizeConstructionContents(
                $itemName,
                $className,
                $state,
                $obj->contents ?? [],
            )),
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

    private static function hasMeaningfulValue($value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value) || is_object($value)) {
            return count((array) $value) > 0;
        }

        return true;
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
     * Ordinary completed orchards use OrchardFeatureBuilding's `bare`/
     * `ripe` lifecycle. Older world snapshots could write the crop-style
     * `grown` state, which has no OrchardFeatureBuilding renderer and leaves
     * only the placement shadow visible after reload.
     */
    private static function normalizeLegacyOrchardState(
        ?string $itemName,
        ?string $className,
        ?string $state,
    ): ?string {
        if (
            $itemName !== 'orchard_featurebuilding_finished'
            || $className !== 'OrchardFeatureBuilding'
            || $state !== 'grown'
        ) {
            return $state;
        }

        return 'bare';
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
