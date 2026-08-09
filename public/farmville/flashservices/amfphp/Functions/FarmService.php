<?php
require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";
require_once AMFPHP_ROOTPATH . "Helpers/crafting_helper.php";

use App\Models\UserMeta;

class FarmService
{
    
    public static function expandFarm($playerObj, $request, $market)
    {
        $itemName = $request->params[0] ?? null;
        $currency = $request->params[1] ?? null;
        $uid = $playerObj->getUid();

        if (!$itemName || !$currency) {
            return self::expandFarmError($uid, $itemName, $currency, 'Missing expansion item or currency.');
        }

        $item = getItemByName($itemName, "db");
        if (!$item || !isset($item["squares"])) {
            return self::expandFarmError($uid, $itemName, $currency, 'Expansion item is unavailable.');
        }

        $newSize = (int) $item["squares"];
        if ($newSize <= 0) {
            return self::expandFarmError($uid, $itemName, $currency, 'Expansion item has an invalid farm size.');
        }

        if ($currency === "cash") {
            // Farm expansions are represented by paired catalog entries: for
            // example, farm14 is the coin option and farm14_cash is the Farm
            // Cash option. Some client revisions send the base item along
            // with the selected currency, so resolve that paired entry here.
            $cashItem = $item;
            if ((int) ($cashItem["cash"] ?? 0) <= 0) {
                $cashItem = getItemByName($itemName . "_cash", "db") ?: $cashItem;
            }

            // A paired entry must describe the same expansion, not merely
            // provide a cash price for an unrelated catalog item.
            if ((int) ($cashItem["squares"] ?? 0) !== $newSize) {
                return self::expandFarmError($uid, $itemName, $currency, 'Expansion cash option is invalid.');
            }

            $cost = (int) ($cashItem["cash"] ?? 0);
            if ($cost <= 0) {
                return self::expandFarmError($uid, $itemName, $currency, 'Expansion item has no cash price.');
            }
            if (!UserResources::removeCash($uid, $cost)) {
                return self::expandFarmError($uid, $itemName, $currency, 'Not enough cash to expand the farm.');
            }
        } else {
            $cost = (int) ($item["cost"] ?? 0);
            if ($cost <= 0) {
                return self::expandFarmError($uid, $itemName, $currency, 'Expansion item has no coin price.');
            }
            if (!UserResources::removeGold($uid, $cost)) {
                return self::expandFarmError($uid, $itemName, $currency, 'Not enough coins to expand the farm.');
            }
        }

        try {
            $world = $playerObj->expandWorld($newSize, $newSize);
        } catch (\Throwable $e) {
            if ($currency === "cash") {
                UserResources::addCash($uid, $cost);
            } else {
                UserResources::addGold($uid, $cost);
            }

            return self::expandFarmError($uid, $itemName, $currency, 'Failed to save the farm expansion.');
        }

        return ["data" => $world];
    }

    private static function expandFarmError($uid, $itemName, $currency, string $reason): array
    {
        Logger::error('FarmService', "expandFarm rejected: uid={$uid}, item={$itemName}, currency={$currency}, reason={$reason}");

        return [
            'errorType' => 1,
            'errorData' => $reason,
        ];
    }

    
    public static function buyTheme($playerObj, $request, $market)
    {
        $data = array();
        $itemName = $request->params[0] ?? null;
        if (!$itemName) return $data;

        $uid = $playerObj->getUid();
        $item = getItemByName($itemName, "db");
        if (!$item) return $data;

        $cashCost = (int) ($item["cash"] ?? 0);
        $goldCost = (int) ($item["cost"] ?? 0);

        if ($cashCost > 0) {
            if (!UserResources::removeCash($uid, $cashCost)) return $data;
        } elseif ($goldCost > 0) {
            if (!UserResources::removeGold($uid, $goldCost)) return $data;
        }

        set_meta($uid, "theme_" . getCurrentWorldType($uid), $itemName);

        $data["data"] = array("timeExpires" => time() + 999999999);

        return $data;
    }

    
    public static function changeTheme($playerObj, $request, $market)
    {
        $data = array();
        $itemName = $request->params[0] ?? null;
        if (!$itemName) return $data;

        $uid = $playerObj->getUid();
        set_meta($uid, "theme_" . getCurrentWorldType($uid), $itemName);

        $data["data"] = array("success" => true);

        return $data;
    }

    
    public static function buyFuel($playerObj, $request, $market)
    {
        $data = array();
        $itemName = $request->params[0] ?? null;
        $isGift = $request->params[1] ?? false;
        if (!$itemName) return $data;

        $uid = $playerObj->getUid();
        $item = getItemByName($itemName, "db");
        if (!$item) return $data;

        $count = (float) ($item["count"] ?? 0);
        if ($count <= 0) return $data;

        if (!$isGift) {
            $cashCost = (int) ($item["cash"] ?? 0);
            $goldCost = (int) ($item["cost"] ?? 0);
            if ($cashCost > 0) {
                if (!UserResources::removeCash($uid, $cashCost)) return $data;
            } elseif ($goldCost > 0) {
                if (!UserResources::removeGold($uid, $goldCost)) return $data;
            }
        }

        UserMeta::where('uid', $uid)
            ->update([
                'energy' => \DB::raw("LEAST(energy + FLOOR({$count} * energyMax), 2147483647)")
            ]);

        $data["data"] = array();

        return $data;
    }

    
    public static function buyTurboChargers($playerObj, $request, $market)
    {
        $data = array();
        $itemName = $request->params[0] ?? null;
        if (!$itemName) return $data;

        $uid = $playerObj->getUid();
        $item = getItemByName($itemName, "db");
        if (!$item) return $data;

        $cashCost = (int) ($item["cash"] ?? 0);
        $goldCost = (int) ($item["cost"] ?? 0);

        if ($cashCost > 0) {
            if (!UserResources::removeCash($uid, $cashCost)) return $data;
        } elseif ($goldCost > 0) {
            if (!UserResources::removeGold($uid, $goldCost)) return $data;
        }

        $count = (int) ($item["count"] ?? 0);
        $current = (int) (get_meta($uid, "turboChargers") ?: 0);
        set_meta($uid, "turboChargers", (string) ($current + $count));

        $data["data"] = array();

        return $data;
    }

    
    public static function buyConsumablePackage($playerObj, $request, $market)
    {
        $data = array();
        $itemName = $request->params[0] ?? null;
        if (!$itemName) return $data;

        $uid = $playerObj->getUid();
        $item = getItemByName($itemName, "db");
        if (!$item) return $data;

        $cashCost = (int) ($item["cash"] ?? 0);
        $goldCost = (int) ($item["cost"] ?? 0);

        if ($cashCost > 0) {
            if (!UserResources::removeCash($uid, $cashCost)) return $data;
        } elseif ($goldCost > 0) {
            if (!UserResources::removeGold($uid, $goldCost)) return $data;
        }

        if (isset($item["itemPackage"])) {
            $itemPackage = $item["itemPackage"];
            $packagedItemName = $itemPackage->value ?? null;
            $packagedAmount = (int) ($itemPackage->amount ?? 1);

            if ($packagedItemName) {
                $packagedItem = getItemByName($packagedItemName, "db");
                if ($packagedItem && isset($packagedItem["rewards"])) {
                    $rewards = $packagedItem["rewards"];
                    $rewardData = $rewards->reward ?? null;
                    if ($rewardData && ($rewardData->type ?? '') === 'item_grant') {
                        $grantItemCode = $rewardData->value ?? null;
                        $grantQuantity = (int) ($rewardData->quantity ?? 1) * $packagedAmount;
                        if ($grantItemCode) {
                            addGiftByCode($uid, $grantItemCode, $grantQuantity);
                        }
                    }
                }
            }
        }

        $data["data"] = array();

        return $data;
    }

    
    public static function useUnwitherConsumable($playerObj, $request, $market)
    {
        $data = array();
        $uid = $playerObj->getUid();
        $worldType = getCurrentWorldType($uid);
        $worldId = getWorldId($uid, $worldType);

        if (!$worldId) {
            $data["data"] = ["success" => false, "error" => "No world found"];
            return $data;
        }

        $unwitheredCount = 0;
        $currentTimeMs = getCurrentTimeMs();

        $plots = \App\Models\WorldObject::where('world_id', $worldId)
            ->where('class_name', 'Plot')
            ->where('state', PLOT_STATE_PLANTED)
            ->whereNotNull('item_name')
            ->where('plant_time', '>', 0)
            ->where('deleted', false)
            ->get();

        foreach ($plots as $plot) {
            $itemData = getItemByName($plot->item_name, "db");
            if (!$itemData || !isset($itemData["growTime"])) {
                continue;
            }

            $growTimeDays = (float) $itemData["growTime"];
            $growTimeMs = calculateGrowTimeMs($growTimeDays);
            $witherTimeMs = $growTimeMs;
            $plantTime = $plot->plant_time;

            if ($currentTimeMs >= ($plantTime + $growTimeMs + $witherTimeMs)) {
                $newPlantTime = calculateFullyGrownPlantTime($growTimeDays);

                \App\Models\WorldObject::where('id', $plot->id)
                    ->update([
                        'state' => PLOT_STATE_GROWN,
                        'plant_time' => $newPlantTime
                    ]);

                $unwitheredCount++;
            }
        }

        invalidateWorldCache($uid, $worldType);

        $data["data"] = ["success" => true, "unwitheredCount" => $unwitheredCount];
        return $data;
    }

    
    public static function onPurchaseBushel($playerObj, $request, $market)
    {
        $data = array();
        $bushelCode = $request->params[0] ?? null;
        $quantity = (int) ($request->params[2] ?? 1);

        if ($bushelCode && $quantity > 0) {
            $uid = $playerObj->getUid();
            addToInventory($uid, $bushelCode, $quantity, "silo");
        }

        $data["data"] = array();
        return $data;
    }

    
    public static function onPurchaseSiloBushel($playerObj, $request, $market)
    {
        $data = array();
        $bushelCode = $request->params[0] ?? null;
        $quantity = (int) ($request->params[1] ?? 1);

        if ($bushelCode && $quantity > 0) {
            $uid = $playerObj->getUid();
            addToInventory($uid, $bushelCode, $quantity, "silo");
        }

        $data["data"] = array();
        return $data;
    }

    
    public static function onPurchaseRecipeBundle($playerObj, $request, $market)
    {
        $data = array();
        $recipeId = $request->params[0] ?? null;
        $amount = (int) ($request->params[1] ?? 1);

        if ($recipeId && $amount > 0) {
            $uid = $playerObj->getUid();
            $recipe = getRecipeById($recipeId);
            if ($recipe) {
                foreach ($recipe['Ingredients'] as $ing) {
                    addToInventory($uid, $ing['itemCode'], $ing['quantityRequired'] * $amount, "silo");
                }
            }
        }

        $data["data"] = array();
        return $data;
    }

    
    public static function buyUnlimitedAltGraphicLicense($playerObj, $request, $market)
    {
        $data = array();
        $itemName = $request->params[0] ?? null;
        if (!$itemName) return $data;

        $uid = $playerObj->getUid();
        set_meta($uid, "altgfx_" . $itemName, "1");

        $data["data"] = array();

        return $data;
    }

    
    public static function saveIcons($playerObj, $request, $market)
    {
        $data = array();
        $iconsData = $request->params[0] ?? null;
        if ($iconsData === null) return $data;

        $uid = $playerObj->getUid();
        set_meta($uid, "iconCodes", serialize($iconsData));

        $data["data"] = array();

        return $data;
    }

    
    public static function takePhoto($playerObj, $request, $market)
    {
        $data = array();
        $data["data"] = array();
        return $data;
    }

    
    public static function getFuelForCoinsRewardUrl($playerObj, $request, $market)
    {
        $data = array();
        $data["data"] = array("fuelForCoinsReward" => null);
        return $data;
    }
}
