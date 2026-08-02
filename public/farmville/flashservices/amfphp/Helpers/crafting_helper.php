<?php
require_once AMFPHP_ROOTPATH . "Helpers/globals.php";

use App\Models\CraftingInventory;
use App\Models\CraftingQueue;
use App\Models\CraftingSkill;
use App\Models\CraftingRecipeState;
use App\Models\MarketStall;
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
        $queue[] = [
            "recipeId" => $row->recipe_id,
            "craftType" => $row->craft_type,
            "ovenSlot" => (int) $row->oven_slot,
            "startTS" => (int) $row->start_ts,
            "finishTS" => (int) $row->finish_ts,
            "worldType" => $row->world_type,
        ];
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

function getRecipeById($recipeId) {
    static $recipes = null;

    if ($recipes === null) {
        $xmlPath = $_SERVER['DOCUMENT_ROOT'] . "/farmville/xml/gz/v855038/crafting.xml";
        if (!file_exists($xmlPath)) return null;

        $xml = simplexml_load_file($xmlPath);
        if (!$xml) return null;

        $recipes = array();
        foreach ($xml->CraftingRecipe as $recipe) {
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
                    );
                }
                if (isset($reward->OnSell)) {
                    $r['OnSell'] = array(
                        'recipeXp' => (int) ($reward->OnSell['recipeXp'] ?? 0),
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

function getCraftTypeLevels() {
    static $levels = null;

    if ($levels === null) {
        $xmlPath = $_SERVER['DOCUMENT_ROOT'] . "/farmville/xml/gz/v855038/crafting.xml";
        if (!file_exists($xmlPath)) return array();

        $xml = simplexml_load_file($xmlPath);
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

function addToInventory($uid, $itemCode, $quantity, $storageType = "silo") {
    if (!is_numeric($uid) || $quantity <= 0) return false;

    CraftingInventory::updateOrCreate(
        ['uid' => $uid, 'item_code' => $itemCode, 'storage_type' => $storageType],
        ['quantity' => \DB::raw("COALESCE(quantity, 0) + {$quantity}")]
    );

    return true;
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
        $entry = array(
            "recipeId" => $row->recipe_id,
            "craftType" => $row->craft_type,
            "ovenSlot" => (int) $row->oven_slot,
            "startTS" => (int) $row->start_ts,
            "finishTS" => (int) $row->finish_ts,
            "worldType" => $row->world_type,
        );

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
        "craftTypeStates" => array(),
        "recipeStates" => array(),
    );
    if (!is_numeric($uid)) return $state;

    $skills = CraftingSkill::where('uid', $uid)->get();
    foreach ($skills as $row) {
        $state["craftTypeStates"][$row->craft_type] = array(
            "level" => (int) $row->level,
            "xp" => (int) $row->xp,
        );
    }

    $recipeStates = CraftingRecipeState::where('uid', $uid)->get();
    foreach ($recipeStates as $row) {
        $state["recipeStates"][$row->recipe_id] = array(
            "level" => (int) $row->level,
            "xp" => (int) $row->xp,
            "isUnlocked" => (int) $row->is_unlocked,
        );
    }

    return $state;
}

function addCraftSkillXp($uid, $craftType, $xpAmount) {
    if (!is_numeric($uid) || $xpAmount <= 0) return;

    CraftingSkill::updateOrCreate(
        ['uid' => $uid, 'craft_type' => $craftType],
        ['level' => \DB::raw("COALESCE(level, 1)"), 'xp' => \DB::raw("COALESCE(xp, 0) + {$xpAmount}")]
    );
}

function addRecipeXp($uid, $recipeId, $xpAmount) {
    if (!is_numeric($uid) || $xpAmount <= 0) return;

    CraftingRecipeState::updateOrCreate(
        ['uid' => $uid, 'recipe_id' => $recipeId],
        ['level' => \DB::raw("COALESCE(level, 1)"), 'xp' => \DB::raw("COALESCE(xp, 0) + {$xpAmount}"), 'is_unlocked' => 1]
    );
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
