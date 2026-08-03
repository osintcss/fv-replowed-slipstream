<?php
require_once AMFPHP_ROOTPATH . "Helpers/crafting_helper.php";
require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";
require_once AMFPHP_ROOTPATH . "Helpers/quest_progress.php";
require_once AMFPHP_ROOTPATH . "Helpers/general_functions.php";

use App\Helpers\JsonHelper;
use App\Models\CraftingQueue;
use App\Models\CraftingSkill;

class CraftingService
{
    /**
     * TCottageHistorySeen's callback reads data.responseCode. Persist the
     * acknowledgement so a cottage history prompt does not reappear after a
     * reload, while leaving recipe/queue state untouched.
     */
    public static function onMarkCottageHistorySeen($playerObj, $request, $market = null)
    {
        $craftType = (string) ($request->params[0] ?? '');
        if ($craftType !== '') {
            $raw = get_meta($playerObj->getUid(), 'crafting_history_seen');
            $seen = @unserialize($raw);
            $seen = is_array($seen) ? $seen : [];
            $seen[$craftType] = true;
            set_meta($playerObj->getUid(), 'crafting_history_seen', serialize($seen));
        }

        return ['data' => ['responseCode' => 0]];
    }

    public static function onBeginRecipe($playerObj, $request, $market)
    {
        $data = array();
        $recipeId = $request->params[0] ?? null;
        $ovenSlot = (int) ($request->params[1] ?? -1);

        if (!$recipeId) return $data;

        $uid = $playerObj->getUid();
        $recipe = getRecipeById($recipeId);
        if (!$recipe) return $data;

        foreach ($recipe['Ingredients'] as $ing) {
            if (!removeFromInventory($uid, $ing['itemCode'], $ing['quantityRequired'])) {
                return $data;
            }
        }

        $startTs = time();
        $finishTs = $startTs + ($recipe['MinutesToCook'] * 60);
        $craftType = $recipe['craft'];
        $worldType = getCurrentWorldType($uid);

        CraftingQueue::addRecipe($uid, $recipeId, $craftType, $ovenSlot, $startTs, $finishTs, $worldType);

        $recipeXp = $recipe['OnMake']['recipeXp'] ?? 0;
        if ($recipeXp > 0) {
            addRecipeXp($uid, $recipeId, $recipeXp);
            addCraftSkillXp($uid, $craftType, $recipeXp);
        }

        $playerXp = $recipe['OnMake']['playerXp'] ?? 0;
        if ($playerXp > 0) {
            UserResources::addXp($uid, $playerXp);
        }

        $data["data"] = array(
            "start_ts" => $startTs,
            "finish_ts" => $finishTs,
        );

        return $data;
    }

    public static function onClaimFinishedRecipes($playerObj, $request, $market)
    {
        $data = array();
        $recipeId = $request->params[0] ?? null;
        $ovenSlot = isset($request->params[1]) ? (int) $request->params[1] : -1;

        $uid = $playerObj->getUid();
        $now = time();
        $worldType = getCurrentWorldType($uid);
        $worldId = getWorldId($uid, $worldType);

        $finishedRecipes = CraftingQueue::getFinished($uid, $recipeId, $now);

        $claimedByCraftType = [];

        foreach ($finishedRecipes as $queueItem) {
            $recipe = getRecipeById($queueItem->recipe_id);
            if ($recipe && isset($recipe['OnFinish']) && $recipe['OnFinish']['itemCode']) {
                $qty = max(1, $recipe['OnFinish']['sellQty'] + $recipe['OnFinish']['giftQty']);
                addToInventory($uid, $recipe['OnFinish']['itemCode'], $qty, "stall");

                $itemData = getItemByName($recipe['OnFinish']['itemCode'], "db");
                if (!$itemData) {
                    $itemData = getItemByCode($recipe['OnFinish']['itemCode']);
                }
                if ($itemData) {
                    processMastery($uid, $itemData, $qty);
                }

                $craftType = $queueItem->craft_type;
                if (!isset($claimedByCraftType[$craftType])) {
                    $claimedByCraftType[$craftType] = [];
                }
                $claimedByCraftType[$craftType][] = [
                    'recipeId' => $queueItem->recipe_id,
                    'recipeName' => $recipe['name'] ?? $queueItem->recipe_id,
                    'itemCode' => $recipe['OnFinish']['itemCode'],
                    'quantity' => $qty,
                    'timestamp' => (int) (microtime(true) * 1000),
                ];
            }

            if ($recipe) {
                trackRecipeProgress($uid, $queueItem->recipe_id, $recipe);
            }

            $queueItem->complete();
        }

        if ($worldId && !empty($claimedByCraftType)) {
            self::updateCottageHistory($worldId, $claimedByCraftType, $uid, $worldType);
        }

        $data["data"] = array();
        return $data;
    }

    private static function updateCottageHistory($worldId, $claimedByCraftType, $uid, $worldType)
    {
        $cottages = \App\Models\WorldObject::where('world_id', $worldId)
            ->where('class_name', 'CraftingCottageBuilding')
            ->where('deleted', false)
            ->get()
            ->keyBy(function ($cottage) {
                return strtolower($cottage->item_name ?? '');
            });

        foreach ($claimedByCraftType as $craftType => $claimedItems) {
            $cottageItemName = \App\Support\CraftingCottages::functionalItemForCraftType($craftType)
                ?? ('crafting' . strtolower($craftType));

            $cottage = $cottages->get($cottageItemName);

            if (!$cottage) {
                continue;
            }

            $components = $cottage->components;
            if (is_string($components)) {
                $components = JsonHelper::safeDecode($components, false, new \stdClass());
            } elseif (!is_object($components) || $components === null) {
                $components = new \stdClass();
            }

            if (!isset($components->finishedRecipes) || !is_object($components->finishedRecipes)) {
                $components->finishedRecipes = new \stdClass();
            }

            foreach ($claimedItems as $item) {
                $key = (string) $item['timestamp'];
                $components->finishedRecipes->$key = (object) $item;
            }

            if (!isset($components->transactionHistory) || !is_array($components->transactionHistory)) {
                $components->transactionHistory = [];
            }

            foreach ($claimedItems as $item) {
                $components->transactionHistory[] = $item;
            }

            if (count($components->transactionHistory) > 50) {
                $components->transactionHistory = array_slice($components->transactionHistory, -50);
            }

            $cottage->components = $components;
            $cottage->save();
        }

        invalidateWorldCache($uid, $worldType);
    }

    public static function onCancelRecipe($playerObj, $request, $market)
    {
        $data = array();
        $recipeId = $request->params[0] ?? null;
        $serverStartTS = (int) ($request->params[1] ?? 0);

        if (!$recipeId) return $data;

        $uid = $playerObj->getUid();

        $queueItem = CraftingQueue::findActive($uid, $recipeId, $serverStartTS);

        if (!$queueItem) return $data;

        $queueItem->delete();

        $recipe = getRecipeById($recipeId);
        if ($recipe) {
            foreach ($recipe['Ingredients'] as $ing) {
                addToInventory($uid, $ing['itemCode'], $ing['quantityRequired']);
            }
        }

        $data["data"] = array();
        return $data;
    }

    public static function onRushRecipe($playerObj, $request, $market)
    {
        $data = array();
        $recipeId = $request->params[0] ?? null;
        $serverStartTS = (int) ($request->params[1] ?? 0);
        $withCoins = $request->params[2] ?? false;
        $ovenSlot = isset($request->params[3]) ? (int) $request->params[3] : -1;

        if (!$recipeId) return $data;

        $uid = $playerObj->getUid();
        $recipe = getRecipeById($recipeId);
        if (!$recipe) return $data;

        if ($withCoins && $recipe['RushCostCoins'] > 0) {
            if (!UserResources::removeGold($uid, $recipe['RushCostCoins'])) return $data;
        } else {
            $cashCost = $recipe['RushCostCash'];
            if ($cashCost > 0) {
                if (!UserResources::removeCash($uid, $cashCost)) return $data;
            }
        }

        $now = time();
        CraftingQueue::rushRecipe($uid, $recipeId, $serverStartTS, $now);

        $data["data"] = array();
        return $data;
    }

    public static function onRushRecipeWithTime($playerObj, $request, $market)
    {
        return self::onRushRecipe($playerObj, $request, $market);
    }

    public static function onCraftInstantRecipe($playerObj, $request, $market)
    {
        $data = array();
        $recipeId = $request->params[0] ?? null;

        if (!$recipeId) return $data;

        $uid = $playerObj->getUid();
        $recipe = getRecipeById($recipeId);
        if (!$recipe) return $data;

        foreach ($recipe['Ingredients'] as $ing) {
            if (!removeFromInventory($uid, $ing['itemCode'], $ing['quantityRequired'])) {
                return $data;
            }
        }

        $cashCost = $recipe['RushCostCash'];
        if ($cashCost > 0) {
            if (!UserResources::removeCash($uid, $cashCost)) {
                foreach ($recipe['Ingredients'] as $ing) {
                    addToInventory($uid, $ing['itemCode'], $ing['quantityRequired']);
                }
                return $data;
            }
        }

        if (isset($recipe['OnFinish']) && $recipe['OnFinish']['itemCode']) {
            $qty = max(1, $recipe['OnFinish']['sellQty'] + $recipe['OnFinish']['giftQty']);
            addToInventory($uid, $recipe['OnFinish']['itemCode'], $qty, "stall");

            $itemData = getItemByName($recipe['OnFinish']['itemCode'], "db");
            if (!$itemData) {
                $itemData = getItemByCode($recipe['OnFinish']['itemCode']);
            }
            if ($itemData) {
                processMastery($uid, $itemData, $qty);
            }
        }

        $recipeXp = $recipe['OnMake']['recipeXp'] ?? 0;
        if ($recipeXp > 0) {
            addRecipeXp($uid, $recipeId, $recipeXp);
            addCraftSkillXp($uid, $recipe['craft'], $recipeXp);
        }

        $playerXp = $recipe['OnMake']['playerXp'] ?? 0;
        if ($playerXp > 0) {
            UserResources::addXp($uid, $playerXp);
        }

        $data["data"] = array();
        return $data;
    }

    public static function onBuyCraftUpgrade($playerObj, $request, $market)
    {
        $data = array();
        $craftType = $request->params[0] ?? null;
        $isRushUpgrade = $request->params[1] ?? false;

        if (!$craftType) return $data;

        $uid = $playerObj->getUid();

        $currentLevel = CraftingSkill::getLevel($uid, $craftType);
        if ($currentLevel === 0) $currentLevel = 1;
        $nextLevel = $currentLevel + 1;

        $levels = getCraftTypeLevels();
        if (!isset($levels[$nextLevel])) return $data;

        $levelData = $levels[$nextLevel];

        if ($isRushUpgrade) {
            $cashCost = $levelData['cash'];
            if ($cashCost > 0) {
                if (!UserResources::removeCash($uid, $cashCost)) return $data;
            }
        } else {
            $goldCost = $levelData['gold'];
            if ($goldCost > 0) {
                if (!UserResources::removeGold($uid, $goldCost)) return $data;
            }
        }

        CraftingSkill::levelUp($uid, $craftType, $nextLevel);

        $data["data"] = array();
        return $data;
    }

    public static function onExchangeBushels($playerObj, $request, $market)
    {
        $data = array();
        $data["data"] = array();
        return $data;
    }

    public static function onBuyCraftedGoods($playerObj, $request, $market)
    {
        $data = array();
        $data["data"] = array(
            "buyResponse" => array(
                "buyResults" => array()
            )
        );
        return $data;
    }

    const RESPONSE_SUCCESS = 0;
    const RESPONSE_INVALID_PARAMS = 1;
    const RESPONSE_WORLD_NOT_FOUND = 2;
    const RESPONSE_COTTAGE_NOT_FOUND = 3;
    const RESPONSE_INVALID_PRICE = 4;
    const RESPONSE_INVALID_PRODUCT_CODE = 5;

    public static function onSetCraftedGoodPrice($playerObj, $request, $market)
    {
        $data = array();
        $cottageId = isset($request->params[0]) ? (int) $request->params[0] : 0;
        $productCode = $request->params[1] ?? null;
        $newPrice = isset($request->params[2]) ? (int) $request->params[2] : 0;

        if ($cottageId <= 0 || !$productCode) {
            $data["data"] = array("responseCode" => self::RESPONSE_INVALID_PARAMS);
            return $data;
        }

        if ($newPrice < 0 || $newPrice > 99999999) {
            Logger::warning('CraftingService', "Invalid price attempted: uid={$playerObj->getUid()}, price=$newPrice");
            $data["data"] = array("responseCode" => self::RESPONSE_INVALID_PRICE);
            return $data;
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $productCode)) {
            Logger::warning('CraftingService', "Invalid product code: uid={$playerObj->getUid()}, code=$productCode");
            $data["data"] = array("responseCode" => self::RESPONSE_INVALID_PRODUCT_CODE);
            return $data;
        }

        $uid = $playerObj->getUid();
        $worldType = getCurrentWorldType($uid);
        $worldId = getWorldId($uid, $worldType);

        if (!$worldId) {
            $data["data"] = array("responseCode" => self::RESPONSE_WORLD_NOT_FOUND);
            return $data;
        }

        $cottage = \App\Models\WorldObject::where('world_id', $worldId)
            ->where('object_id', $cottageId)
            ->where('class_name', 'CraftingCottageBuilding')
            ->where('deleted', false)
            ->first();

        if (!$cottage) {
            Logger::warning('CraftingService', "Cottage not found: uid=$uid, cottageId=$cottageId");
            $data["data"] = array("responseCode" => self::RESPONSE_COTTAGE_NOT_FOUND);
            return $data;
        }

        $components = $cottage->components;
        if (is_string($components)) {
            $components = JsonHelper::safeDecode($components, false, new \stdClass());
        } elseif (!is_object($components) || $components === null) {
            $components = new \stdClass();
        }

        if (!isset($components->prices) || !is_object($components->prices)) {
            $components->prices = new \stdClass();
        }

        $components->prices->$productCode = $newPrice;

        $cottage->components = $components;
        $cottage->save();

        invalidateWorldCache($uid, $worldType);

        $data["data"] = array("responseCode" => self::RESPONSE_SUCCESS);
        return $data;
    }

    public static function onSetCottageName($playerObj, $request, $market)
    {
        $data = array();
        $cottageId = isset($request->params[0]) ? (int) $request->params[0] : 0;
        $newName = $request->params[1] ?? '';

        if ($cottageId <= 0) {
            $data["data"] = array("responseCode" => self::RESPONSE_INVALID_PARAMS);
            return $data;
        }

        $newName = trim($newName);
        $newName = preg_replace('/[^\p{L}\p{N}\s\-\'\.!]/u', '', $newName);
        $newName = substr($newName, 0, 50);

        $uid = $playerObj->getUid();
        $worldType = getCurrentWorldType($uid);
        $worldId = getWorldId($uid, $worldType);

        if (!$worldId) {
            $data["data"] = array("responseCode" => self::RESPONSE_WORLD_NOT_FOUND);
            return $data;
        }

        $cottage = \App\Models\WorldObject::where('world_id', $worldId)
            ->where('object_id', $cottageId)
            ->where('class_name', 'CraftingCottageBuilding')
            ->where('deleted', false)
            ->first();

        if (!$cottage) {
            Logger::warning('CraftingService', "Cottage not found for rename: uid=$uid, cottageId=$cottageId");
            $data["data"] = array("responseCode" => self::RESPONSE_COTTAGE_NOT_FOUND);
            return $data;
        }

        $components = $cottage->components;
        if (is_string($components)) {
            $components = JsonHelper::safeDecode($components, false, new \stdClass());
        } elseif (!is_object($components) || $components === null) {
            $components = new \stdClass();
        }

        $components->cottageName = $newName;

        $cottage->components = $components;
        $cottage->save();

        invalidateWorldCache($uid, $worldType);

        $data["data"] = array("responseCode" => self::RESPONSE_SUCCESS);
        return $data;
    }

    public static function onMarkHistoryViewed($playerObj, $request, $market)
    {
        $data = array();
        $cottageId = isset($request->params[0]) ? (int) $request->params[0] : 0;

        if ($cottageId <= 0) {
            $data["data"] = array("responseCode" => self::RESPONSE_INVALID_PARAMS);
            return $data;
        }

        $uid = $playerObj->getUid();
        $worldType = getCurrentWorldType($uid);
        $worldId = getWorldId($uid, $worldType);

        if (!$worldId) {
            $data["data"] = array("responseCode" => self::RESPONSE_WORLD_NOT_FOUND);
            return $data;
        }

        $cottage = \App\Models\WorldObject::where('world_id', $worldId)
            ->where('object_id', $cottageId)
            ->where('class_name', 'CraftingCottageBuilding')
            ->where('deleted', false)
            ->first();

        if (!$cottage) {
            Logger::warning('CraftingService', "Cottage not found for history view: uid=$uid, cottageId=$cottageId");
            $data["data"] = array("responseCode" => self::RESPONSE_COTTAGE_NOT_FOUND);
            return $data;
        }

        $components = $cottage->components;
        if (is_string($components)) {
            $components = JsonHelper::safeDecode($components, false, new \stdClass());
        } elseif (!is_object($components) || $components === null) {
            $components = new \stdClass();
        }

        $components->historyLastViewedTS = (int) (microtime(true) * 1000);

        $cottage->components = $components;
        $cottage->save();

        invalidateWorldCache($uid, $worldType);

        $data["data"] = array("responseCode" => self::RESPONSE_SUCCESS);
        return $data;
    }

    public static function onUseCraftedGood($playerObj, $request, $market)
    {
        $data = array();
        $itemKey = $request->params[0] ?? null;

        if ($itemKey) {
            $uid = $playerObj->getUid();
            removeFromInventory($uid, $itemKey, 1, "stall");
        }

        $data["data"] = array();
        return $data;
    }

    public static function onGetAddCraftingCrewReward($playerObj, $request, $market)
    {
        $data = array();
        $data["data"] = array();
        return $data;
    }

    public static function onShareBushels($playerObj, $request, $market)
    {
        $data = array();
        $data["data"] = array();
        return $data;
    }

    public static function onShareNoDeductBushels($playerObj, $request, $market)
    {
        $data = array();
        $data["data"] = array();
        return $data;
    }

    public static function onGetBushelRequestFeed($playerObj, $request, $market)
    {
        $data = array();
        $data["data"] = array();
        return $data;
    }

    public static function onClaimFreeCottage($playerObj, $request, $market)
    {
        $data = array();
        $data["data"] = array();
        return $data;
    }

    public static function onCompleteCottageTutorial($playerObj, $request, $market)
    {
        $data = array();
        $data["data"] = array();
        return $data;
    }

    public static function onClaimReward($playerObj, $request, $market)
    {
        $data = array();
        $data["data"] = array();
        return $data;
    }

    public static function onRefreshMarketView($playerObj, $request, $market)
    {
        $data = array();
        $uid = $playerObj->getUid();

        $neighbors = get_meta($uid, 'current_neighbors');
        $neighborUids = $neighbors ? (@unserialize($neighbors) ?: []) : [];
        $allUids = array_merge([$uid], $neighborUids);

        $stalls = getStallsByUids($allUids);

        $stallsByUid = array();
        foreach ($stalls as $stall) {
            $stallUid = $stall['uid'];
            if (!isset($stallsByUid[$stallUid])) {
                $stallsByUid[$stallUid] = array();
            }
            foreach ($stall['inventory'] as $item) {
                $stallsByUid[$stallUid][] = $item;
            }
        }

        $marketStalls = array();
        $ages = array();
        $now = time();
        foreach ($stallsByUid as $stallUid => $inventory) {
            $marketStalls[] = array("uid" => (string) $stallUid, "in" => $inventory);
            $ages[] = array("uid" => (string) $stallUid, "lastUpdated" => $now);
        }

        $data["data"] = array(
            "marketStalls" => $marketStalls,
            "craftedGoods" => array(),
            "craftingSkills" => array(),
            "ages" => $ages,
            "lastUpdated" => $now
        );
        return $data;
    }

    public static function onRefreshUserOffering($playerObj, $request, $market)
    {
        $data = array();
        $targetUid = $request->params[0] ?? null;

        if (!$targetUid) {
            $data["data"] = array();
            return $data;
        }

        $stalls = getStallsForUser($targetUid);

        $inventory = array();
        foreach ($stalls as $stall) {
            foreach ($stall['inventory'] as $item) {
                $inventory[] = $item;
            }
        }

        $data["data"] = array(
            "uid" => (string) $targetUid,
            "in" => $inventory,
            "lastUpdated" => time()
        );
        return $data;
    }

    public static function onReconfigureStall($playerObj, $request, $market)
    {
        $data = array();
        $uid = $playerObj->getUid();
        $stallObjectId = (int) ($request->params[0] ?? 0);
        $bushelItemCode = $request->params[1] ?? null;

        if ($stallObjectId > 0 && $bushelItemCode) {
            configureStall($uid, $stallObjectId, $bushelItemCode);
        }

        $data["data"] = array();
        return $data;
    }

    public static function onCloseStall($playerObj, $request, $market)
    {
        $data = array();
        $uid = $playerObj->getUid();
        $stallObjectId = (int) ($request->params[0] ?? 0);

        if ($stallObjectId > 0) {
            closeStall($uid, $stallObjectId);
        }

        $data["data"] = array("success" => true);
        return $data;
    }

    public static function onClaimMarketStallItem($playerObj, $request, $market)
    {
        $data = array();
        $claimerUid = $playerObj->getUid();
        $stallOwnerUid = $request->params[0] ?? null;
        $bushelItemCode = $request->params[1] ?? null;

        $responseCode = 3; 
        if ($stallOwnerUid && $bushelItemCode) {
            $responseCode = claimStallItem($claimerUid, $stallOwnerUid, $bushelItemCode);
        }

        $data["data"] = array("responseCode" => $responseCode);
        return $data;
    }

    public static function onGetMarketStallRewardUrl($playerObj, $request, $market)
    {
        $data = array();
        $data["data"] = array("rewardUrl" => "");
        return $data;
    }
}
