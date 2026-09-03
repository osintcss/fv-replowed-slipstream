<?php

use App\Models\CraftingInventory;
use App\Models\CraftingQueue;
use App\Models\CraftingSkill;
use App\Models\CraftingRecipeState;
use App\Models\MarketStall;
use App\Models\UserWorld;
use App\Models\WorldObject;
use App\Support\CraftingCottages;

function getCraftTypeFromCottageName(?string $itemName): ?string
{
    if ($itemName === null || $itemName === '') {
        return null;
    }

    return CraftingCottages::craftTypeForItem($itemName);
}

function getRecipeQueueForCraftType(int $uid, string $craftType): array
{
    $queue = [];

    $rows = CraftingQueue::where('uid', $uid)
        ->where('craft_type', $craftType)
        ->where('status', 'active')
        ->orderBy('start_ts')
        ->get();

    foreach ($rows as $row) {
        $entry = getRecipeQueueEnvelope($uid, (string) $row->recipe_id, $row);
        if ($entry !== null) {
            $queue[] = $entry;
        }
    }

    return $queue;
}

function getCraftLevelForType(int $uid, string $craftType): int
{
    $skill = CraftingSkill::where('uid', $uid)
        ->where('craft_type', $craftType)
        ->first();

    return $skill ? (int) $skill->level : 1;
}

/**
 * Flash loads crafting.xml.gz, whose catalog is substantially larger than
 * the convenient uncompressed fallback file. Server crafting state must use
 * the same catalog or a Craftshop window can receive states for only a small
 * subset of the recipes it renders.
 */
function getCraftingConfigXml(): ?\SimpleXMLElement
{
    static $xml = null;
    static $loaded = false;

    if ($loaded) {
        return $xml;
    }
    $loaded = true;

    $basePath = $_SERVER['DOCUMENT_ROOT'] . "/farmville/xml/gz/v855038/crafting.xml";
    $compressed = @file_get_contents($basePath . '.gz');
    if ($compressed !== false) {
        $contents = @gzuncompress($compressed);
        if ($contents !== false) {
            $parsed = @simplexml_load_string($contents);
            if ($parsed !== false) {
                return $xml = $parsed;
            }
        }
    }

    if (file_exists($basePath)) {
        $parsed = @simplexml_load_file($basePath);
        if ($parsed !== false) {
            return $xml = $parsed;
        }
    }

    return null;
}

function getRecipeById($recipeId) {
    static $recipes = null;

    if ($recipes === null) {
        $xml = getCraftingConfigXml();
        if (!$xml) return null;

        $recipes = array();
        foreach ($xml->recipes->CraftingRecipe as $recipe) {
            $id = (string) $recipe['id'];
            $r = array(
                'id' => $id,
                'name' => (string) $recipe->name,
                'craft' => (string) $recipe->craft,
                'SkillLevelRequired' => (int) $recipe->SkillLevelRequired,
                'InitialRecipeLevel' => (int) $recipe->InitialRecipeLevel,
                'MinutesToCook' => (int) $recipe->MinutesToCook,
                'RushCostCoins' => (int) $recipe->RushCostCoins,
                'RushCostCash' => (int) $recipe->RushCostCash,
                'Deprecated' => (int) $recipe->Deprecated,
            );

            if (isset($recipe->Reward)) {
                $reward = $recipe->Reward;
                $r['OnMake'] = array(
                    'recipeXp' => (int) ($reward->OnMake['recipeXp'] ?? 0),
                    'playerXp' => (int) ($reward->OnMake['playerXp'] ?? 0),
                );
                if (isset($reward->OnFinish)) {
                    $r['OnFinish'] = array(
                        'itemCode' => (string) ($reward->OnFinish['itemCode'] ?? ''),
                        'sellQty' => (int) ($reward->OnFinish['sellQty'] ?? 0),
                        'giftQty' => (int) ($reward->OnFinish['giftQty'] ?? 0),
                        // Keep this distinction: legacy Craftshop recipes
                        // with a share loot table omit both quantities and
                        // imply one finished consumable.
                        'hasGiftQty' => isset($reward->OnFinish['giftQty']),
                    );
                }
                if (isset($reward->OnSell)) {
                    $r['OnSell'] = array(
                        'recipeXp' => (int) ($reward->OnSell['recipeXp'] ?? 0),
                    );
                }
                if (isset($reward->OnUse)) {
                    $r['OnUse'] = array(
                        'fuel' => isset($reward->OnUse['fuel'])
                            ? (float) $reward->OnUse['fuel']
                            : 1.0,
                        'itemCode' => (string) ($reward->OnUse['itemCode'] ?? ''),
                        'giftQty' => isset($reward->OnUse['giftQty'])
                            ? (int) $reward->OnUse['giftQty']
                            : 1,
                    );
                }
            }

            $r['Ingredients'] = array();
            if (isset($recipe->Ingredients)) {
                foreach ($recipe->Ingredients->Ingredient as $ing) {
                    $r['Ingredients'][] = array(
                        'itemCode' => (string) $ing['itemCode'],
                        'quantityRequired' => (int) $ing['quantityRequired'],
                    );
                }
            }

            $recipes[$id] = $r;
        }
    }

    return $recipes[$recipeId] ?? null;
}

/**
 * Match the Flash client's CraftConfigSettings.getRecipeByProductItemCode().
 * Crafted-good storage keys contain the product code plus recipe level (for
 * example, "@v:1"), while the recipe catalog identifies the product in its
 * OnFinish itemCode attribute.
 */
function getRecipeByProductItemCode(string $itemCode): ?array
{
    static $recipesByProduct = null;

    if ($itemCode === '') {
        return null;
    }

    if ($recipesByProduct === null) {
        $recipesByProduct = [];
        $xml = getCraftingConfigXml();
        if (!$xml) {
            return null;
        }

        foreach ($xml->recipes->CraftingRecipe as $recipeXml) {
            $recipeId = (string) $recipeXml['id'];
            $recipe = getRecipeById($recipeId);
            $productItemCode = (string) ($recipe['OnFinish']['itemCode'] ?? '');
            if ($productItemCode !== '' && !isset($recipesByProduct[$productItemCode])) {
                $recipesByProduct[$productItemCode] = $recipe;
            }
        }
    }

    return $recipesByProduct[$itemCode] ?? null;
}

/**
 * Return the base fuel amount for a crafted good at its recipe level. This
 * follows CraftConfigSettings.getFuelRewardForGood(): recipe-specific levels
 * take precedence, then craft-type levels, then the global recipe levels.
 */
function getCraftingRecipeFuel(string $recipeId, string $craftType, int $level): float
{
    $xml = getCraftingConfigXml();
    if (!$xml) {
        return 0.0;
    }

    $recipeXml = null;
    foreach ($xml->recipes->CraftingRecipe as $candidate) {
        if ((string) $candidate['id'] === $recipeId) {
            $recipeXml = $candidate;
            break;
        }
    }

    $levels = [];
    if ($recipeXml && isset($recipeXml->recipeLevels)) {
        $levels = $recipeXml->recipeLevels->level;
    }

    if (count($levels) === 0) {
        foreach ($xml->craftSkills->craftSkill as $craftSkill) {
            if ((string) $craftSkill['id'] === $craftType) {
                if (isset($craftSkill->recipeLevels)) {
                    $levels = $craftSkill->recipeLevels->level;
                }
                break;
            }
        }
    }

    if (count($levels) === 0 && isset($xml->recipeLevels)) {
        $levels = $xml->recipeLevels->level;
    }

    $fuel = null;
    $maxFuel = null;
    foreach ($levels as $levelXml) {
        $levelNumber = (string) ($levelXml['num'] ?? '');
        if ($levelNumber === 'max') {
            if (isset($levelXml['fuel']) && is_numeric((string) $levelXml['fuel'])) {
                $maxFuel = (float) $levelXml['fuel'];
            }
            continue;
        }

        if ((int) $levelNumber === $level
            && isset($levelXml['fuel'])
            && is_numeric((string) $levelXml['fuel'])) {
            $fuel = (float) $levelXml['fuel'];
            break;
        }
    }

    if ($fuel === null) {
        $fuel = $maxFuel ?? 0.0;
    }

    return max(0.0, $fuel);
}

/**
 * Serialize an active queue row in the shape consumed by
 * RecipeItem.fromPhpObject(). Both TInitUser and TBeginRecipe feed this exact
 * object into CraftingState, so compact database-row shapes are not valid
 * client responses.
 */
function getRecipeQueueEnvelope(string|int $uid, string $recipeId, $queueItem = null): ?array
{
    $recipe = getRecipeById($recipeId);
    if ($recipe === null) {
        return null;
    }

    $recipeState = CraftingRecipeState::where('uid', $uid)
        ->where('recipe_id', $recipeId)
        ->first();
    $recipeLevel = max(1, (int) ($recipeState->level ?? $recipe['InitialRecipeLevel'] ?? 1));

    $ingredients = array_map(static function (array $ingredient): array {
        return array(
            '@attributes' => array(
                'itemCode' => (string) $ingredient['itemCode'],
                'quantityRequired' => (int) $ingredient['quantityRequired'],
            ),
        );
    }, $recipe['Ingredients']);

    // RecipeItem.fromPhpObject expects each of these to be an Object. An empty
    // PHP array is encoded as an AMF array instead, which becomes undefined
    // when the client calls hasOwnProperty on it. Use a harmless non-attribute
    // member when the client should fall back to its locally loaded XML data.
    if (count($ingredients) === 0) {
        $ingredients[] = array('fallback' => true);
    }

    $rewards = array();
    if (isset($recipe['OnMake'])) {
        $rewards['OnMake'] = array(
            '@attributes' => array(
                'recipeXp' => (int) ($recipe['OnMake']['recipeXp'] ?? 0),
                'playerXp' => (int) ($recipe['OnMake']['playerXp'] ?? 0),
            ),
        );
    }
    if (isset($recipe['OnFinish'])) {
        $rewards['OnFinish'] = array(
            '@attributes' => array(
                'itemCode' => (string) ($recipe['OnFinish']['itemCode'] ?? ''),
                'sellQty' => (int) ($recipe['OnFinish']['sellQty'] ?? 0),
                'giftQty' => (int) ($recipe['OnFinish']['giftQty'] ?? 0),
            ),
        );
    }
    if (isset($recipe['OnSell'])) {
        $rewards['OnSell'] = array(
            '@attributes' => array(
                'recipeXp' => (int) ($recipe['OnSell']['recipeXp'] ?? 0),
            ),
        );
    }
    if (count($rewards) === 0) {
        $rewards['fallback'] = array('fallback' => true);
    }

    return array(
        'id' => (string) $recipeId,
        'recipeInitialRecipeLevel' => $recipeLevel,
        'ovenSlot' => (int) ($queueItem->oven_slot ?? -1),
        'worldType' => (string) ($queueItem->world_type ?? ''),
        'start_ts' => (int) ($queueItem->start_ts ?? 0),
        'finish_ts' => (int) ($queueItem->finish_ts ?? 0),
        'recipe' => array(
            'name' => (string) $recipe['name'],
            'craft' => (string) $recipe['craft'],
            'SkillLevelRequired' => (int) $recipe['SkillLevelRequired'],
            'RushCostCoins' => (int) $recipe['RushCostCoins'],
            'RushCostCash' => (int) $recipe['RushCostCash'],
            'MinutesToCook' => (int) $recipe['MinutesToCook'],
            'Deprecated' => (int) $recipe['Deprecated'],
            'Reward' => $rewards,
            // No @attributes makes RecipeItem retain the image from crafting.xml.
            'image' => array('fallback' => true),
            'Ingredients' => array('Ingredient' => $ingredients),
        ),
    );
}

function getCraftTypeLevels() {
    static $levels = null;

    if ($levels === null) {
        $xml = getCraftingConfigXml();
        if (!$xml || !isset($xml->craftTypeLevels)) return array();

        $levels = array();
        foreach ($xml->craftTypeLevels->level as $lvl) {
            $num = (int) $lvl['num'];
            $levels[$num] = array(
                'xp' => (int) $lvl['xp'],
                'gold' => (int) $lvl['gold'],
                'cash' => (int) $lvl['cash'],
                'recipeSlots' => (int) $lvl['recipeSlots'],
            );
        }
    }

    return $levels;
}

function getCraftingInventory($uid, $storageType = null) {
    $items = array();
    if (!is_numeric($uid)) return $items;

    $query = CraftingInventory::where('uid', $uid)->where('quantity', '>', 0);
    if ($storageType !== null) {
        $query->where('storage_type', $storageType);
    }

    $rows = $query->get();
    foreach ($rows as $row) {
        $items[] = array(
            "itemCode" => $row->item_code,
            "quantity" => (int) $row->quantity,
            "price" => null
        );
    }

    return $items;
}

/**
 * Serialize completed cottage products for FarmGameWorld.CRAFTEDGOODS_ID.
 * The Flash client constructs CraftedItem instances from this storage data,
 * whose keys include the recipe level (for example, "cheese:2").
 */
function getCraftedGoodsStorageData($uid): array {
    if (!is_numeric($uid)) {
        return [];
    }

    $storage = [];
    foreach (CraftingInventory::where('uid', $uid)
        ->where('storage_type', 'crafted')
        ->where('quantity', '>', 0)
        ->get() as $item) {
        $storage[(string) $item->item_code] = [(int) $item->quantity, [], []];
    }

    return $storage;
}

function addCraftedGood($uid, string $itemCode, int $recipeLevel, int $quantity): bool {
    if ($itemCode === '' || $quantity <= 0) {
        return false;
    }

    return addToInventory($uid, $itemCode . ':' . max(1, $recipeLevel), $quantity, 'crafted');
}

/**
 * Return the capacity granted by Crafting Silos placed in the player's active
 * world. The Flash client receives this value on every TFarmTransaction (the
 * init-user response included) and otherwise defaults its silo capacity to
 * zero, even when a Crafting Silo object is present in world data.
 */
function getCraftingSiloCapacity($uid, $worldType = null): int {
    if (!is_numeric($uid)) {
        return 0;
    }

    $worldType = $worldType ?: getCurrentWorldType($uid);
    $worldId = getWorldId($uid, $worldType);
    if (!$worldId) {
        return 0;
    }

    $item = \App\Models\Item::findByName('craftingsilo');
    if (is_array($item)) {
        $item = (object) $item;
    }
    if (!is_object($item)) {
        return 0;
    }

    $baseCapacity = max(0, (int) ($item->capacity ?? 0));
    $upgradeCapacities = [];
    $features = $item->features->feature ?? [];
    if (!is_array($features)) {
        $features = [$features];
    }
    foreach ($features as $feature) {
        if (!is_object($feature) || ($feature->name ?? null) !== 'expand') {
            continue;
        }
        $upgrades = $feature->upgrade ?? [];
        if (!is_array($upgrades)) {
            $upgrades = [$upgrades];
        }
        foreach ($upgrades as $upgrade) {
            if (is_object($upgrade) && isset($upgrade->level, $upgrade->capacity)) {
                $upgradeCapacities[(int) $upgrade->level] = (int) $upgrade->capacity;
            }
        }
    }

    return WorldObject::where('world_id', $worldId)
        ->where('item_name', 'craftingsilo')
        ->where('deleted', false)
        ->get(['expansion_level'])
        ->sum(function ($silo) use ($baseCapacity, $upgradeCapacities) {
            $capacity = $baseCapacity;
            $level = max(1, (int) $silo->expansion_level);
            foreach ($upgradeCapacities as $upgradeLevel => $upgradeCapacity) {
                if ($upgradeLevel <= $level) {
                    $capacity = max($capacity, $upgradeCapacity);
                }
            }
            return $capacity;
        });
}

function addToInventory($uid, $itemCode, $quantity, $storageType = "silo") {
    if (!is_numeric($uid) || $quantity <= 0) return false;

    // Do not assign a DB expression through an Eloquent model attribute here.
    // `quantity` is integer-cast, so Eloquent attempts to cast the Expression
    // before it sends the update and the Flash request fails.  Create the row
    // with a real value, then use the query builder's atomic increment.
    $inventory = CraftingInventory::firstOrCreate(
        ['uid' => $uid, 'item_code' => $itemCode, 'storage_type' => $storageType],
        ['quantity' => 0]
    );
    CraftingInventory::whereKey($inventory->getKey())->increment('quantity', (int) $quantity);

    return true;
}

/**
 * The shipped client registers this exact action-drop name from
 * gameSettings.xml.  It tracks harvests separately for each crop and grants
 * a bushel after every 50 harvests (unless a runtime multiplier overrides
 * that threshold).  Keep the durable counter server-side: Flash only uses
 * the returned value to update its local progress display.
 */
function getBushelHarvestCounts($uid): array {
    if (!is_numeric($uid)) {
        return [];
    }

    $raw = get_meta($uid, 'bushel_harvest_counts');
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $counts = json_decode($raw, true);
    if (!is_array($counts)) {
        return [];
    }

    $normalized = [];
    foreach ($counts as $itemCode => $count) {
        if (is_string($itemCode) && $itemCode !== '' && is_numeric($count)) {
            $normalized[$itemCode] = max(0, (int) $count);
        }
    }

    return $normalized;
}

/**
 * Persist crop-harvest progress and return the ActionDrops payload consumed
 * by BushelReportActionDropHandler in the shipped SWF.  Only seed crops are
 * in the configured bushelReport action-drop task; animals and trees have
 * separate crafting rules and must not be granted through this path.
 *
 * @param array<string, int> $harvestedItemCounts item name => accepted harvest count
 * @return array<string, array>|array{} ActionDrops map, keyed by action-drop name
 */
function recordHarvestBushelDrops($uid, array $harvestedItemCounts): array {
    if (!is_numeric($uid) || empty($harvestedItemCounts)) {
        return [];
    }

    $counts = getBushelHarvestCounts($uid);
    $report = [];
    $newHarvestQuantities = [];
    $threshold = 50;

    foreach ($harvestedItemCounts as $itemName => $harvestCount) {
        $harvestCount = (int) $harvestCount;
        if (!is_string($itemName) || $itemName === '' || $harvestCount <= 0) {
            continue;
        }

        $itemData = getItemByName($itemName, 'db');
        if (!is_array($itemData) || ($itemData['type'] ?? null) !== 'seed') {
            continue;
        }

        $cropItemCode = $itemData['code'] ?? null;
        $bushelItemCode = $itemData['bushelItemCode'] ?? null;
        if (!is_string($cropItemCode) || $cropItemCode === ''
            || !is_string($bushelItemCode) || $bushelItemCode === '') {
            continue;
        }

        // Do not create an inventory row for an invalid or retired bushel.
        $bushelItem = getItemByCode($bushelItemCode);
        if (!is_array($bushelItem) || ($bushelItem['type'] ?? null) !== 'bushel') {
            continue;
        }

        $total = ($counts[$cropItemCode] ?? 0) + $harvestCount;
        $bushelsAwarded = intdiv($total, $threshold);
        $counts[$cropItemCode] = $total % $threshold;
        $newHarvestQuantities[] = [
            'itemCode' => $cropItemCode,
            'quantity' => $counts[$cropItemCode],
        ];

        if ($bushelsAwarded <= 0 || !addToInventory($uid, $bushelItemCode, $bushelsAwarded)) {
            continue;
        }

        // CraftingManager.recordBushelFound() reads these fields and updates
        // the appropriate local (market-stall or silo) bucket immediately.
        $report[] = [
            'foundBushel' => [
                'bushelCode' => $bushelItemCode,
                'bushelsAddedToInventory' => $bushelsAwarded,
                'bushelsAddedToSharedReward' => 0,
                'bushelAddedToStall' => false,
            ],
        ];
    }

    if (empty($newHarvestQuantities)) {
        return [];
    }

    set_meta($uid, 'bushel_harvest_counts', json_encode($counts));
    // The AS3 handler treats this as an array with a named property.
    $report['newHarvestQuantities'] = $newHarvestQuantities;

    return [
        'bushelReport' => [
            'dropTypeFuncResult' => $report,
        ],
    ];
}

function removeFromInventory($uid, $itemCode, $quantity, $storageType = "silo") {
    if (!is_numeric($uid) || $quantity <= 0) return false;

    $affected = CraftingInventory::where('uid', $uid)
        ->where('item_code', $itemCode)
        ->where('storage_type', $storageType)
        ->where('quantity', '>=', $quantity)
        ->update(['quantity' => \DB::raw("quantity - {$quantity}")]);

    return $affected > 0;
}

function getRecipeQueue($uid) {
    $queue = array();
    if (!is_numeric($uid)) return $queue;

    $rows = CraftingQueue::where('uid', $uid)
        ->where('status', 'active')
        ->orderBy('start_ts')
        ->get();

    foreach ($rows as $row) {
        $entry = getRecipeQueueEnvelope($uid, (string) $row->recipe_id, $row);
        if ($entry === null) {
            continue;
        }

        $ct = $row->craft_type;
        if (!isset($queue[$ct])) {
            $queue[$ct] = array();
        }
        $queue[$ct][] = $entry;
    }

    return $queue;
}

function getCraftingSkillState($uid) {
    $state = array(
        // CraftingState.initCraftingStateFromInitUser reads this exact array
        // shape. An associative craftTypeStates map is silently ignored by
        // Flash, leaving cottages at image level -1 and without slot data.
        "craftTypes" => array(),
        "recipeStates" => array(),
    );
    if (!is_numeric($uid)) return $state;

    $craftTypes = [];
    $skills = CraftingSkill::where('uid', $uid)->get();
    foreach ($skills as $row) {
        $craftType = (string) $row->craft_type;
        $craftTypes[$craftType] = array(
            "type" => $craftType,
            "level" => max(1, (int) $row->level),
            "xp" => (int) $row->xp,
            // The legacy client initializes from xp, then assigns exp.
            // Provide both spellings so imported and new accounts agree.
            "exp" => (int) $row->xp,
        );
    }

    // A player can already own a cottage from an import or an older server
    // version before a crafting_skills row exists. Its Flash constructor
    // needs a level-one state during initUser to choose built_0 and calculate
    // its recipe slots, so derive that harmless default from world state.
    $worldIds = UserWorld::where('uid', $uid)->pluck('id');
    $placedItems = $worldIds->isEmpty()
        ? collect()
        : WorldObject::whereIn('world_id', $worldIds)
            ->where('deleted', false)
            ->pluck('item_name');

    foreach ($placedItems as $itemName) {
        $craftType = CraftingCottages::craftTypeForItem($itemName);
        if ($craftType !== null && !isset($craftTypes[$craftType])) {
            $craftTypes[$craftType] = [
                "type" => $craftType,
                "level" => 1,
                "xp" => 0,
                "exp" => 0,
            ];

            // Keep this bootstrap durable. Newly placed cottages create this
            // row through gameplay; imported and legacy cottages need it at
            // their first initUser response instead.
            CraftingSkill::firstOrCreate(
                ['uid' => $uid, 'craft_type' => $craftType],
                ['level' => 1, 'xp' => 0]
            );
        }
    }

    $state["craftTypes"] = array_values($craftTypes);

    $recipeStates = [];
    foreach (CraftingRecipeState::where('uid', $uid)->get() as $row) {
        $recipeStates[(string) $row->recipe_id] = array(
            // RecipeState.fromPhpObject reads these ActionScript member
            // names, not the database/API-friendly equivalents.
            "m_type" => (string) $row->recipe_id,
            "m_level" => max(1, (int) $row->level),
            "m_experience" => (int) $row->xp,
            "m_isUnlocked" => (bool) $row->is_unlocked,
        );
    }

    // The original server returns a state for every recipe presented by the
    // cottage UI. A restored account has no rows until it crafts something;
    // without these initial states CraftingMainRecipeSlot dereferences null
    // while populating the window. Build the same initial records from the
    // archived crafting catalog, while preserving any persisted progress.
    $craftLevels = array_column($craftTypes, 'level', 'type');
    if ($craftLevels !== []) {
        $craftingXml = getCraftingConfigXml();
        if ($craftingXml !== null) {
            foreach ($craftingXml->recipes->CraftingRecipe as $recipe) {
                $craftType = (string) $recipe->craft;
                $recipeId = (string) $recipe['id'];
                // RecipeState lookup redirects recipes that share mastery to
                // this separate ID. Craftshop uses this heavily (for example
                // rtK1 -> K1), so storing only the visible recipe ID leaves
                // CraftshopWindow with a null RecipeState.
                $stateRecipeId = trim((string) $recipe->sharedMastery);
                if ($stateRecipeId === '') {
                    $stateRecipeId = $recipeId;
                }

                if ($recipeId === '' || !isset($craftLevels[$craftType]) || isset($recipeStates[$stateRecipeId])) {
                    continue;
                }

                $requiredLevel = max(1, (int) $recipe->SkillLevelRequired);
                $recipeStates[$stateRecipeId] = [
                    "m_type" => $stateRecipeId,
                    "m_level" => max(1, (int) $recipe->InitialRecipeLevel),
                    "m_experience" => 0,
                    "m_isUnlocked" => $requiredLevel <= $craftLevels[$craftType],
                ];

                CraftingRecipeState::firstOrCreate(
                    ['uid' => $uid, 'recipe_id' => $stateRecipeId],
                    [
                        'level' => max(1, (int) $recipe->InitialRecipeLevel),
                        'xp' => 0,
                        'is_unlocked' => $requiredLevel <= $craftLevels[$craftType],
                    ]
                );
            }
        }
    }

    $state["recipeStates"] = array_values($recipeStates);

    return $state;
}

function addCraftSkillXp($uid, $craftType, $xpAmount) {
    if (!is_numeric($uid) || $xpAmount <= 0) return;

    $skill = CraftingSkill::firstOrCreate(
        ['uid' => $uid, 'craft_type' => $craftType],
        ['level' => 1, 'xp' => 0]
    );
    CraftingSkill::whereKey($skill->getKey())->increment('xp', (int) $xpAmount);
}

function addRecipeXp($uid, $recipeId, $xpAmount) {
    if (!is_numeric($uid) || $xpAmount <= 0) return;

    $recipeState = CraftingRecipeState::firstOrCreate(
        ['uid' => $uid, 'recipe_id' => $recipeId],
        ['level' => 1, 'xp' => 0, 'is_unlocked' => true]
    );
    CraftingRecipeState::whereKey($recipeState->getKey())->increment('xp', (int) $xpAmount);
}

function getStallsByUids($uids) {
    if (empty($uids)) return array();

    $now = time();

    $rows = MarketStall::whereIn('uid', $uids)
        ->where('is_configured', 1)
        ->where('date_closed', '>', $now)
        ->get(['uid', 'stall_object_id', 'bushel_item_code', 'inventory', 'date_closed']);

    $stalls = array();
    foreach ($rows as $row) {
        $stall = $row->toArray();
        $stall['inventory'] = json_decode($stall['inventory'], true) ?: [];
        $stalls[] = $stall;
    }

    return $stalls;
}

function getStallByObjectId($uid, $stallObjectId) {
    $row = MarketStall::where('uid', $uid)
        ->where('stall_object_id', $stallObjectId)
        ->first();

    if ($row) {
        $stall = $row->toArray();
        $stall['inventory'] = json_decode($stall['inventory'], true) ?: [];
        return $stall;
    }
    return null;
}

function getStallsForUser($uid) {
    $now = time();

    $rows = MarketStall::where('uid', $uid)
        ->where('is_configured', 1)
        ->where('date_closed', '>', $now)
        ->get(['uid', 'stall_object_id', 'bushel_item_code', 'inventory', 'date_closed']);

    $stalls = array();
    foreach ($rows as $row) {
        $stall = $row->toArray();
        $stall['inventory'] = json_decode($stall['inventory'], true) ?: [];
        $stalls[] = $stall;
    }

    return $stalls;
}

function configureStall($uid, $stallObjectId, $bushelItemCode) {
    $stallDuration = 86400;
    $dateClosed = time() + $stallDuration;

    $playerBushels = getCraftingInventory($uid, "silo");
    $bushelQty = 0;
    foreach ($playerBushels as $item) {
        if ($item['itemCode'] === $bushelItemCode) {
            $bushelQty = $item['quantity'];
            break;
        }
    }

    $inventory = array();
    $toMove = min($bushelQty, 25);
    for ($i = 0; $i < $toMove; $i++) {
        $inventory[] = array("ic" => $bushelItemCode, "ts" => $dateClosed);
    }

    if ($toMove > 0) {
        removeFromInventory($uid, $bushelItemCode, $toMove, "silo");
    }

    $inventoryJson = json_encode($inventory);

    MarketStall::updateOrCreate(
        ['uid' => $uid, 'stall_object_id' => $stallObjectId],
        [
            'bushel_item_code' => $bushelItemCode,
            'is_configured' => 1,
            'date_closed' => $dateClosed,
            'inventory' => $inventoryJson,
        ]
    );

    return true;
}

function closeStall($uid, $stallObjectId) {
    MarketStall::where('uid', $uid)
        ->where('stall_object_id', $stallObjectId)
        ->update(['is_configured' => 0, 'inventory' => null]);

    return true;
}

function claimStallItem($claimerUid, $stallOwnerUid, $bushelItemCode) {
    $neighbors = get_meta($claimerUid, 'current_neighbors');
    $neighborUids = $neighbors ? (@unserialize($neighbors) ?: []) : [];
    if (!in_array($stallOwnerUid, $neighborUids)) {
        return 2;
    }

    $stalls = getStallsForUser($stallOwnerUid);
    $targetStall = null;
    foreach ($stalls as $stall) {
        foreach ($stall['inventory'] as $item) {
            if ($item['ic'] === $bushelItemCode) {
                $targetStall = $stall;
                break 2;
            }
        }
    }

    if (!$targetStall) {
        return 3;
    }

    $now = time();
    $found = false;
    $newInventory = array();
    foreach ($targetStall['inventory'] as $item) {
        if (!$found && $item['ic'] === $bushelItemCode) {
            if ($item['ts'] < $now) {
                return 1;
            }
            $found = true;
            continue;
        }
        $newInventory[] = $item;
    }

    if (!$found) {
        return 3;
    }

    $inventoryJson = json_encode($newInventory);
    $stallId = (int) $targetStall['stall_object_id'];
    MarketStall::where('uid', $stallOwnerUid)
        ->where('stall_object_id', $stallId)
        ->update(['inventory' => $inventoryJson]);

    addToInventory($claimerUid, $bushelItemCode, 1);

    return 0;
}
