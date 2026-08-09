<?php

require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";
require_once AMFPHP_ROOTPATH . "Helpers/constants.php";
require_once AMFPHP_ROOTPATH . "Helpers/logger.php";
require_once AMFPHP_ROOTPATH . "Helpers/quest_progress.php";

use App\Helpers\JsonHelper;
use App\Support\CraftingCottages;

class WorldService
{
    const LOG = 'World';

    public static function performAction($playerObj, $request, $market)
    {
        $data = array("id" => 0, "data" => array("id" => 0));
        $action = $request->params[0];

        $extraParams = (isset($request->params[2]) && is_array($request->params[2]) && isset($request->params[2][0]))
            ? $request->params[2][0] : null;

        $energyCost = 0;
        if ($extraParams !== null && isset($extraParams->energyCost)) {
            $energyCost = (int) $extraParams->energyCost;
        }

        if ($energyCost > 0) {
            $uid = $playerObj->getUid();
            UserResources::removeEnergy($uid, $energyCost);
        }

        switch ($action) {
            case ACTION_PLANT:
                $marketPurchaseObj = $request->params[1];
                $plantObj = clone $marketPurchaseObj;
                $cottage = CraftingCottages::normalizeMarketPlacement($plantObj);
                $className = $plantObj->className ?? '';
                $isStorageWithdrawal = $extraParams !== null
                    ? (int) ($extraParams->isStorageWithdrawal ?? 0) : 0;
                // Flash identifies the giftbox itself as -6 in storageData.
                // Older requests used the legacy -1 source ID, so accept
                // both formats when removing an item placed from the box.
                $isGiftboxWithdrawal = in_array($isStorageWithdrawal, [
                    GIFTBOX_ID,
                    (int) GIFTBOX_STORAGE_KEY,
                ], true);
                $isBuildingWithdrawal = $isStorageWithdrawal > 0;
                $isInventoryWithdrawal = $isStorageWithdrawal === HOME_INVENTORY_ID;
                $withdrawnInventoryItemCode = null;
                $withdrawnInventoryExtraData = null;
                $withdrawnBuildingItemCode = null;

                // Remove home/inventory items before creating their world
                // object. The previous post-placement withdrawal silently
                // did nothing when item lookup or storage data was missing,
                // which allowed the same animal to be placed repeatedly.
                if ($isInventoryWithdrawal) {
                    $itemData = getItemByName($plantObj->itemName ?? '', 'db');
                    $itemCode = $itemData['code'] ?? null;
                    $inventory = $itemCode ? getInventoryStorage($playerObj->getUid()) : [];
                    $available = $itemCode ? (int) ($inventory[$itemCode][0] ?? 0) : 0;

                    if (!$itemCode || $available < 1) {
                        Logger::error(self::LOG, sprintf(
                            'Rejected storage placement: uid=%s source=%d item=%s code=%s available=%d',
                            $playerObj->getUid(),
                            $isStorageWithdrawal,
                            $plantObj->itemName ?? '',
                            $itemCode ?? 'unknown',
                            $available
                        ));

                        return [
                            'id' => 0,
                            'data' => ['id' => 0, 'success' => false, 'error' => 'Item is not available in storage'],
                        ];
                    }

                    $withdrawnInventoryItemCode = $itemCode;
                    $withdrawnInventoryExtraData = withdrawFromInventoryStorage(
                        $playerObj->getUid(),
                        $itemCode
                    );
                } elseif ($isBuildingWithdrawal) {
                    $itemData = getItemByName($plantObj->itemName ?? '', 'db');
                    $itemCode = $itemData['code'] ?? null;

                    if (!$itemCode || !$playerObj->withdrawStoredItem($isStorageWithdrawal, $itemCode)) {
                        Logger::error(self::LOG, sprintf(
                            'Rejected building-storage placement: uid=%s buildingId=%d item=%s code=%s',
                            $playerObj->getUid(),
                            $isStorageWithdrawal,
                            $plantObj->itemName ?? '',
                            $itemCode ?? 'unknown'
                        ));

                        return [
                            'id' => 0,
                            'data' => ['id' => 0, 'success' => false, 'error' => 'Item is not available in this building'],
                        ];
                    }

                    $withdrawnBuildingItemCode = $itemCode;
                }
                $retId = $playerObj->setWorld($plantObj, $action);

                // An unsuccessful placement must not consume the item.
                if ($isInventoryWithdrawal && $retId <= 0) {
                    addToInventoryStorage(
                        $playerObj->getUid(),
                        $withdrawnInventoryItemCode,
                        1,
                        $withdrawnInventoryExtraData
                    );

                    return [
                        'id' => 0,
                        'data' => ['id' => 0, 'success' => false, 'error' => 'Could not place item'],
                    ];
                }

                if ($isBuildingWithdrawal && $retId <= 0) {
                    $playerObj->restoreStoredItem($isStorageWithdrawal, $withdrawnBuildingItemCode);

                    return [
                        'id' => 0,
                        'data' => ['id' => 0, 'success' => false, 'error' => 'Could not place item'],
                    ];
                }

                if ($cottage !== null) {
                    Logger::log(self::LOG, sprintf(
                        'Converted crafting market item %s to functional cottage %s (objectId=%d)',
                        $marketPurchaseObj->itemName ?? '',
                        $cottage['functionalItem'],
                        $retId
                    ));
                }

                // Placing an item already owned in a storage box must not be
                // processed as a new market purchase.
                if ($isStorageWithdrawal === 0) {
                    try {
                        $currency = ($extraParams !== null && isset($extraParams->currency))
                            ? (string) $extraParams->currency : null;
                        $market->newTransaction($action, $marketPurchaseObj, $currency);
                    } catch (\Throwable $e) {
                        Logger::error('WorldService', "Plant transaction error: " . $e->getMessage());
                    }
                }

                if ($extraParams) {
                    if ($isGiftboxWithdrawal) {
                        $placedItemName = $plantObj->itemName ?? null;
                        if ($placedItemName && $retId > 0) {
                            $uid = $playerObj->getUid();
                            $itemData = getItemByName($placedItemName, "db");
                            if ($itemData && isset($itemData["code"])) {
                                $giftboxExtraData = withdrawGiftboxItem($uid, $itemData["code"]);

                                if ($giftboxExtraData) {
                                    $worldType = getCurrentWorldType($uid);
                                    $worldId = getWorldId($uid, $worldType);
                                    
                                    if ($worldId) {
                                        $placedObj = \App\Models\WorldObject::where('world_id', $worldId)
                                            ->where('object_id', $retId)
                                            ->where('deleted', false)
                                            ->first();
                                        
                                        if ($placedObj) {
                                            $components = $placedObj->components;
                                            if (is_string($components)) {
                                                $components = JsonHelper::safeDecode($components, false, new \stdClass());
                                            } elseif (!is_object($components) || $components === null) {
                                                $components = new \stdClass();
                                            }
                                            
                                            $extraDataObj = is_object($giftboxExtraData) ? $giftboxExtraData : (object)$giftboxExtraData;
                                            foreach ($extraDataObj as $key => $value) {
                                                $components->$key = $value;
                                            }
                                            
                                            if (!isset($components->active) && stripos($placedItemName, 'unwitherring') !== false) {
                                                $components->active = true;
                                            }
                                            
                                            $placedObj->components = $components;
                                            $placedObj->save();

                                            invalidateWorldCache($uid, $worldType);
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                // Craftshop has its own FeatureBuilding visual state, while
                // the other cottages use CraftingCottageBuilding. Both need
                // the common crafting metadata when first placed.
                if ($cottage !== null && $retId > 0) {
                    $uid = $playerObj->getUid();
                    $worldType = getCurrentWorldType($uid);
                    $worldId = getWorldId($uid, $worldType);

                    if ($worldId) {
                        $placedCottage = \App\Models\WorldObject::where('world_id', $worldId)
                            ->where('object_id', $retId)
                            ->where('deleted', false)
                            ->first();

                        if ($placedCottage) {
                            $components = $placedCottage->components;
                            if (is_string($components)) {
                                $components = JsonHelper::safeDecode($components, false, new \stdClass());
                            } elseif (!is_object($components) || $components === null) {
                                $components = new \stdClass();
                            }

                            if (!isset($components->foundingTS) || $components->foundingTS == 0) {
                                $components->foundingTS = (int) ($plantObj->plantTime ?? 0);
                                if ($components->foundingTS <= 0) {
                                    $components->foundingTS = (int) (microtime(true) * 1000);
                                }
                            }

                            if (!isset($components->cottageName)) {
                                $components->cottageName = '';
                            }
                            if (!isset($components->finishedRecipes)) {
                                $components->finishedRecipes = new \stdClass();
                            }
                            if (!isset($components->transactionHistory)) {
                                $components->transactionHistory = [];
                            }
                            if (!isset($components->historyLastViewedTS)) {
                                $components->historyLastViewedTS = 0;
                            }
                            if (!isset($components->historyXPGain)) {
                                $components->historyXPGain = 0;
                            }
                            if (!isset($components->pendingLevelUpFeed)) {
                                $components->pendingLevelUpFeed = null;
                            }

                            $placedCottage->components = $components;
                            $placedCottage->save();

                            invalidateWorldCache($uid, $worldType);
                        }
                    }
                }

                $plantedItemName = $plantObj->itemName ?? null;
                if ($plantedItemName) {
                    $uid = $playerObj->getUid();
                    $itemData = getItemByName($plantedItemName, "db");
                    trackPlantProgress($uid, $plantedItemName, $itemData ?: []);
                }

                $data["id"] = $retId;
                $data["data"] = array("id" => $retId);
                break;

            case ACTION_PLOW:
                $retId = $playerObj->setWorld($request->params[1], $action);

                try {
                    $currency = ($extraParams !== null && isset($extraParams->currency))
                        ? (string) $extraParams->currency : null;
                    $market->newTransaction($action, $request->params[1], $currency);
                } catch (\Throwable $e) {
                    Logger::error('WorldService', "Plow transaction error: " . $e->getMessage());
                }

                $uid = $playerObj->getUid();
                trackPlowProgress($uid, 1);

                $data["id"] = $retId;
                $data["data"] = array("id" => $retId);
                break;

            case ACTION_MOVE:
            case ACTION_CLEAR:
            case ACTION_CLEAR_WITHERED:
                $retId = $playerObj->setWorld($request->params[1], $action);
                $data["id"] = $retId;
                $data["data"] = array("id" => $retId);
                break;

            case ACTION_SELL:
                $uid = $playerObj->getUid();
                $clientObj = $request->params[1];

                $currentWorldType = getCurrentWorldType($uid);
                $world = getWorldByType($uid, $currentWorldType);
                $positionIndex = buildPositionIndex($world["objectsArray"] ?? []);

                $posX = isset($clientObj->position) ? ($clientObj->position->x ?? ($clientObj->position['x'] ?? null)) : null;
                $posY = isset($clientObj->position) ? ($clientObj->position->y ?? ($clientObj->position['y'] ?? null)) : null;

                $foundKey = findByPosition($positionIndex, $posX, $posY);
                $serverItemName = null;

                if ($foundKey !== null && isset($world["objectsArray"][$foundKey])) {
                    $serverObj = $world["objectsArray"][$foundKey];
                    $serverItemName = $serverObj->itemName ?? null;
                    $clientItemName = $clientObj->itemName ?? null;

                    if ($clientItemName !== null && $serverItemName !== null && $clientItemName !== $serverItemName) {
                        Logger::warning('WorldService', "Sell mismatch: uid=$uid, pos=($posX,$posY), client=$clientItemName, server=$serverItemName");
                    }
                }

                $retId = $playerObj->setWorld($clientObj, $action);

                if ($retId !== false && $serverItemName) {
                    $secureSellObj = clone $clientObj;
                    $secureSellObj->itemName = $serverItemName;

                    try {
                        $currency = ($extraParams !== null && isset($extraParams->currency))
                            ? (string) $extraParams->currency : null;
                        $market->newTransaction($action, $secureSellObj, $currency);
                    } catch (\Throwable $e) {
                        Logger::error('WorldService', "Sell transaction error: " . $e->getMessage());
                    }
                }

                $data["id"] = $retId;
                $data["data"] = array("id" => $retId);
                break;

            case ACTION_HARVEST:
                $uid = $playerObj->getUid();
                $clientObj = $request->params[1];
                $transactionResult = null;

                $currentWorldType = getCurrentWorldType($uid);
                $world = getWorldByType($uid, $currentWorldType);
                $positionIndex = buildPositionIndex($world["objectsArray"] ?? []);

                $posX = isset($clientObj->position) ? ($clientObj->position->x ?? ($clientObj->position['x'] ?? null)) : null;
                $posY = isset($clientObj->position) ? ($clientObj->position->y ?? ($clientObj->position['y'] ?? null)) : null;

                $foundKey = findByPosition($positionIndex, $posX, $posY);
                $serverItemName = null;

                if ($foundKey !== null && isset($world["objectsArray"][$foundKey])) {
                    $serverObj = $world["objectsArray"][$foundKey];
                    $serverItemName = $serverObj->itemName ?? null;

                    $clientItemName = $clientObj->itemName ?? null;
                    if ($clientItemName !== null && $serverItemName !== null && $clientItemName !== $serverItemName) {
                        Logger::warning('WorldService', "Harvest mismatch: uid=$uid, pos=($posX,$posY), client=$clientItemName, server=$serverItemName");
                    }
                }

                $retId = $playerObj->setWorld($clientObj, $action);

                if ($retId !== false && $serverItemName) {
                    $secureHarvestObj = clone $clientObj;
                    $secureHarvestObj->itemName = $serverItemName;

                    try {
                        $currency = ($extraParams !== null && isset($extraParams->currency))
                            ? (string) $extraParams->currency : null;
                        $transactionResult = $market->newTransaction($action, $secureHarvestObj, $currency);
                    } catch (\Throwable $e) {
                        Logger::error('WorldService', "Harvest transaction error: " . $e->getMessage());
                    }

                    $itemData = getItemByName($serverItemName, "db");
                    trackHarvestProgress($uid, (array) $secureHarvestObj, $serverItemName, $itemData ?: []);
                }

                $data["id"] = $retId;
                $data["data"] = array("id" => $retId);

                if (is_array($transactionResult) && !empty($transactionResult['masteryLevelUp'])) {
                    $levelUp = $transactionResult['masteryLevelUp'];
                    $data["data"]["goals"] = [[
                        "type" => "Mastery",
                        "code" => $levelUp['itemCode'],
                        "difficulty" => $levelUp['newLevel'],
                        "link" => ""
                    ]];
                }
                break;

            case ACTION_INSTANT_GROW:
                $uid = $playerObj->getUid();
                $currentWorldType = getCurrentWorldType($uid);
                $world = getWorldByType($uid, $currentWorldType);
                $modified = false;
                $modifiedCount = 0;

                $typeCounts = [
                    'Plot' => 0,
                    'Tree' => 0,
                    'Animal' => 0,
                    'Bloom/Building' => 0,
                ];

                $typeMask = ($extraParams !== null && isset($extraParams->type))
                    ? (int) $extraParams->type : 15;

                if (!empty($world) && isset($world["objectsArray"])) {
                    foreach ($world["objectsArray"] as $key => $obj) {
                        $plantTime = $obj->plantTime ?? null;
                        $itemName = $obj->itemName ?? null;
                        $className = $obj->className ?? "";
                        $state = $obj->state ?? null;

                        if ($plantTime === null || $itemName === null) {
                            continue;
                        }

                        $isTargetType = false;
                        $typeMatched = "";

                        if (($typeMask & 1) && stripos($className, 'Plot') !== false && $state === "planted") {
                            $isTargetType = true;
                            $typeMatched = "Plot";
                        } elseif (($typeMask & 2) && stripos($className, 'Tree') !== false) {
                            $isTargetType = true;
                            $typeMatched = "Tree";
                        } elseif (($typeMask & 4) && stripos($className, 'Animal') !== false && stripos($className, 'LonelyAnimal') === false) {
                            $isTargetType = true;
                            $typeMatched = "Animal";
                        } elseif (($typeMask & 8) && (stripos($className, 'Building') !== false || stripos($className, 'Bloom') !== false)) {
                            $isTargetType = true;
                            $typeMatched = "Bloom/Building";
                        }

                        if (!$isTargetType) {
                            continue;
                        }

                        $itemData = getItemByName($itemName, "db");
                        if (!$itemData || !isset($itemData["growTime"])) {
                            continue;
                        }

                        $growTimeDays = (float) $itemData["growTime"];

                        $newPlantTime = calculateFullyGrownPlantTime($growTimeDays);
                        $world["objectsArray"][$key]->plantTime = $newPlantTime;

                        $newState = getInstantGrowState($className, $state);
                        if ($newState !== null) {
                            $world["objectsArray"][$key]->state = $newState;
                        }

                        $modified = true;
                        $modifiedCount++;
                        $typeCounts[$typeMatched]++;
                    }

                    $totalCost = 0;
                    if ($typeCounts['Plot'] > 0) {
                        $totalCost += INSTAGROW_COST_CROP;
                    }
                    if ($typeCounts['Tree'] > 0) {
                        $totalCost += INSTAGROW_COST_TREE;
                    }
                    if ($typeCounts['Animal'] > 0) {
                        $totalCost += INSTAGROW_COST_ANIMAL;
                    }
                    if ($typeCounts['Bloom/Building'] > 0) {
                        $totalCost += INSTAGROW_COST_BLOOM;
                    }

                    if ($modified) {
                        if (!saveWorld($uid, $currentWorldType, $world)) {
                            throw new \Exception("Failed to save world (instant grow) for uid=$uid");
                        }
                        if ($totalCost > 0) {
                            UserResources::removeCash($uid, $totalCost);
                        }
                    }
                }

                $data["data"] = array("id" => 0);
                break;

            case ACTION_STORE:
                $buildingObj = $request->params[1];
                if ($extraParams) {
                    $storedItemName = $extraParams->storedItemName ?? null;
                    $storedItemCode = $extraParams->storedItemCode ?? null;
                    $numToStore = (int) ($extraParams->numToStore ?? 1);
                    $storageTarget = isset($extraParams->target) ? (int) $extraParams->target : null;
                    $buildingId = $buildingObj->id ?? null;
                    $buildingItemName = $buildingObj->itemName ?? null;

                    $isExpansionPartItem = false;
                    $buildingItemData = null;
                    $partData = null;

                    if ($buildingId && $buildingItemName && $storedItemName) {
                        $buildingItemData = getItemByName($buildingItemName, "db");
                        if ($buildingItemData && hasExpandFeature($buildingItemData)) {
                            $uid = $playerObj->getUid();
                            $currentWorldType = getCurrentWorldType($uid);
                            $currWorld = getWorldByType($uid, $currentWorldType);

                            foreach ($currWorld["objectsArray"] as $obj) {
                                if (isset($obj->id) && $obj->id == $buildingId) {
                                    $currentLevel = (int)($obj->expansionLevel ?? 1);
                                    $partData = isExpansionPart($buildingItemData, $currentLevel, $storedItemName);
                                    if ($partData) {
                                        $isExpansionPartItem = true;
                                    }
                                    break;
                                }
                            }
                        }
                    }

                    $storeResult = null;
                    if (!$isExpansionPartItem) {
                        // TInventoryStore identifies its destination with
                        // target=-2 and sends the resource itself as the
                        // action object. TStoreItem has no target and sends a
                        // real StorageBuilding. They must not share a path.
                        $storeResult = $storageTarget === HOME_INVENTORY_ID
                            ? $playerObj->storeInHomeInventory($extraParams)
                            : $playerObj->storeItem($buildingObj, $extraParams);

                        if (!$storeResult) {
                            return [
                                'id' => 0,
                                'data' => [
                                    'id' => 0,
                                    'success' => false,
                                    'error' => 'Could not store item',
                                ],
                            ];
                        }

                        // Quest progress is only recorded after the storage
                        // write succeeds, so a rejected store neither removes
                        // an item nor advances a quest.
                        trackStoreProgress(
                            $playerObj->getUid(),
                            $storedItemCode ?? ($storeResult['itemCode'] ?? ''),
                            max(1, $numToStore)
                        );

                        $data['data'] = [
                            'id' => $storeResult['id'] ?? 0,
                            'success' => true,
                        ];
                    }

                    $creditItems = [
                        "shovel_item_01"            => "InventoryCellar",
                        "shovel_item_20"            => "InventoryCellar",
                        "shovel_itempack"           => "InventoryCellar",
                        "beehive_bee"               => "beehive",
                        "beehive_queen"             => "beehive",
                        "beehive_bee_5"             => "beehive",
                        "halloween_candy_5pack"     => "halloweenBasket",
                        "haitibackpack_itempack_5"  => "haitiBackpack",
                    ];

                    if ($storedItemName && isset($creditItems[$storedItemName])) {
                        $uid = $playerObj->getUid();
                        $currentWorldType = getCurrentWorldType($uid);
                        $featureName = $creditItems[$storedItemName];

                        $itemData = getItemByName($storedItemName, "db");
                        $creditCount = ($itemData && isset($itemData['count'])) ? (int) $itemData['count'] : 1;

                        addFeatureCredit($uid, $currentWorldType, $featureName, $creditCount * $numToStore);

                        if ($itemData) {
                            $cashCost = (int) ($itemData['cash'] ?? 0);
                            if ($cashCost > 0) {
                                UserResources::removeCash($uid, $cashCost * $numToStore);
                            }
                        }
                    }

                    if ($isExpansionPartItem && $partData) {
                        $uid = $playerObj->getUid();
                        $currentWorldType = getCurrentWorldType($uid);
                        $currWorld = getWorldByType($uid, $currentWorldType);

                        $buildingKey = null;
                        foreach ($currWorld["objectsArray"] as $key => $obj) {
                            if (isset($obj->id) && $obj->id == $buildingId) {
                                $buildingKey = $key;
                                break;
                            }
                        }

                        if ($buildingKey !== null) {
                            $building = $currWorld["objectsArray"][$buildingKey];
                            $currentLevel = (int)($building->expansionLevel ?? 1);

                            $partItemData = getItemByName($storedItemName, "db");
                            $partCode = ($partItemData && isset($partItemData['code']))
                                ? $partItemData['code']
                                : $storedItemCode;

                            if ($partCode) {
                                $isGift = $extraParams->isGift ?? false;
                                if (!$isGift && $partItemData) {
                                    $cashCost = (int)($partItemData['cash'] ?? 0);
                                    if ($cashCost > 0) {
                                        $totalCost = $cashCost * $numToStore;
                                        UserResources::removeCash($uid, $totalCost);
                                    }
                                }

                                if (!isset($building->expansionParts)) {
                                    $building->expansionParts = new \stdClass();
                                }

                                $currentCount = 0;
                                if (is_object($building->expansionParts) && isset($building->expansionParts->$partCode)) {
                                    $currentCount = (int)$building->expansionParts->$partCode;
                                }

                                $needed = (int)($partData->need ?? 10);
                                $newCount = min($currentCount + $numToStore, $needed);
                                $building->expansionParts->$partCode = $newCount;

                                if (checkExpansionComplete($building, $buildingItemData)) {
                                    $building->expansionLevel = $currentLevel + 1;
                                    $building->expansionParts = new \stdClass();
                                }

                                $currWorld["objectsArray"][$buildingKey] = $building;
                                if (!saveWorld($uid, $currentWorldType, $currWorld)) {
                                    throw new \Exception("Failed to save world (store expansion) for uid=$uid");
                                }
                            }
                        }
                    }
                }
                break;

            case ACTION_SET_MULTIPLE_FEATURED_ITEMS:
                $buildingObj = $request->params[1] ?? null;
                $featuredItems = $extraParams->featuredItems ?? null;
                $buildingId = (int) ($buildingObj->id ?? 0);
                $uid = $playerObj->getUid();

                if ($buildingId > 0 && is_object($featuredItems)) {
                    $currentWorldType = getCurrentWorldType($uid);
                    $currWorld = getWorldByType($uid, $currentWorldType);

                    foreach ($currWorld['objectsArray'] as $key => $building) {
                        if ((int) ($building->id ?? 0) !== $buildingId) {
                            continue;
                        }

                        if (!isset($building->components) || !is_object($building->components)) {
                            $building->components = new \stdClass();
                        }
                        $building->components->featuredItems = $featuredItems;
                        $currWorld['objectsArray'][$key] = $building;

                        if (!saveWorld($uid, $currentWorldType, $currWorld)) {
                            throw new \Exception("Failed to save featured items for uid=$uid buildingId=$buildingId");
                        }
                        break;
                    }
                }

                // TSetMultipleFeaturedItems only uses this field when a caller
                // supplied a callback, but returning the canonical data keeps
                // the AMF response aligned with the Flash transaction.
                $data['data'] = ['featuredItems' => $featuredItems ?? new \stdClass()];
                break;

            case ACTION_NEIGHBOR_ACT:
                $plotObj = $request->params[1];
                $actParams = (isset($request->params[2]) && is_array($request->params[2]) && isset($request->params[2][0]))
                    ? $request->params[2][0] : null;

                $hostId = $actParams->hostId ?? null;
                $actionType = $actParams->actionType ?? null;
                
                Logger::debug('NeighborAction', "ACTION_NEIGHBOR_ACT: hostId=$hostId, actionType=$actionType");

                $data["data"] = array(
                    "staleFarm" => "false",
                    "goodieBagRewardItemCode" => null,
                    "fertilizeRewardLink" => null,
                    "fuelDiscovery" => 0,
                    "fuelRewardLink" => null,
                    "itemFoundName" => null,
                    "itemShareName" => null,
                    "itemFoundDialogText" => null,
                    "itemFoundRewardUrl" => null,
                    "itemFoundFeedBundle" => null
                );

                if ($hostId && $actionType && $plotObj) {
                    $uid = $playerObj->getUid();
                    $plotId = $plotObj->id ?? null;

                        switch ($actionType) {
                        case NEIGHBOR_ACTION_FERT:
                        case ACTION_PLOW:
                        case NEIGHBOR_ACTION_UNWITHER:
                        case ACTION_HARVEST:
                            UserResources::addXp($uid, 1);
                            UserResources::addGold($uid, 10);
                            break;
                        case NEIGHBOR_ACTION_FEED_CHICKENS:
                        case NEIGHBOR_ACTION_TRICK:
                            UserResources::addXp($uid, 1);
                            break;
                    }

                    incrementNeighborAction($uid, $hostId, $actionType);

                    if ($plotId !== null) {
                        $hostWorldType = get_meta($hostId, "currentWorldType") ?: "farm";
                        $hostWorld = getWorldByType($hostId, $hostWorldType);

                        if (!empty($hostWorld) && isset($hostWorld["objectsArray"])) {
                            $modified = false;

                            foreach ($hostWorld["objectsArray"] as $key => $obj) {
                                if (isset($obj->id) && $obj->id == $plotId) {
                                    switch ($actionType) {
                                        case NEIGHBOR_ACTION_FERT:
                                            $hostWorld["objectsArray"][$key]->isJumbo = true;
                                            $modified = true;
                                            break;
                                        case NEIGHBOR_ACTION_UNWITHER:
                                            $currentState = $obj->state ?? '';
                                            $itemName = $obj->itemName ?? null;
                                            $plantTime = $obj->plantTime ?? 0;

                                            if ($currentState === PLOT_STATE_PLANTED && $itemName && $plantTime > 0) {
                                                $itemData = getItemByName($itemName, "db");
                                                if ($itemData && isset($itemData["growTime"])) {
                                                    $growTimeDays = (float) $itemData["growTime"];
                                                    $growTimeMs = calculateGrowTimeMs($growTimeDays);
                                                    $witherTimeMs = $growTimeMs;
                                                    $currentTimeMs = getCurrentTimeMs();

                                                    $hasRingProtection = isWitherProtectionActive($hostId, $hostWorldType);

                                                    if ($currentTimeMs >= ($plantTime + $growTimeMs + $witherTimeMs) && !$hasRingProtection) {
                                                        $currentState = PLOT_STATE_WITHERED;
                                                    } elseif ($currentTimeMs >= ($plantTime + $growTimeMs)) {
                                                        $currentState = PLOT_STATE_GROWN;
                                                    }
                                                }
                                            }

                                            if ($currentState === PLOT_STATE_WITHERED) {
                                                $hostWorld["objectsArray"][$key]->state = PLOT_STATE_GROWN;
                                                if ($itemName) {
                                                    $itemData = $itemData ?? getItemByName($itemName, "db");
                                                    if ($itemData && isset($itemData["growTime"])) {
                                                        $growTimeDays = (float) $itemData["growTime"];
                                                        $hostWorld["objectsArray"][$key]->plantTime = calculateFullyGrownPlantTime($growTimeDays);
                                                    }
                                                }
                                                $modified = true;
                                            }
                                            break;
                                        case ACTION_PLOW:
                                            if (isset($obj->state) && $obj->state === PLOT_STATE_FALLOW) {
                                                $hostWorld["objectsArray"][$key]->state = PLOT_STATE_PLOWED;
                                                $modified = true;
                                            }
                                            break;
                                    }
                                    break;
                                }
                            }

                            if ($modified) {
                                if (!saveWorld($hostId, $hostWorldType, $hostWorld)) {
                                    throw new \Exception("Failed to save host world (neighbor action) for hostId=$hostId");
                                }
                            }
                        }
                    }
                }
                break;

            case ACTION_REDEEM_NEIGHBOR_FERTILIZE:
                $data["data"] = array("id" => 0);
                break;

            case ACTION_PLACE_MESSAGE:
                $signObj = $request->params[1] ?? null;
                $hostId = $signObj->hostId ?? null;
                $authorId = $signObj->authorId ?? null;

                if (!$hostId || !$signObj) {
                    $data["data"] = array("id" => 0, "messageId" => 0, "messageText" => "");
                    break;
                }

                $messageText = $signObj->message ?? "";
                $hostWorldType = get_meta($hostId, "currentWorldType") ?: "farm";
                $hostWorld = getWorldByType($hostId, $hostWorldType);

                if (empty($hostWorld) || !isset($hostWorld["objectsArray"])) {
                    $data["data"] = array("id" => 0, "messageId" => 0, "messageText" => "");
                    break;
                }

                $usedIds = [];
                foreach ($hostWorld["objectsArray"] as $obj) {
                    if (isset($obj->id) && $obj->id > 0 && $obj->id < TEMP_ID_THRESHOLD) {
                        $usedIds[$obj->id] = true;
                    }
                }
                $newSignId = null;
                $maxSafeId = TEMP_ID_THRESHOLD - 1;
                for ($i = 1; $i <= $maxSafeId; $i++) {
                    if (!isset($usedIds[$i])) {
                        $newSignId = $i;
                        break;
                    }
                }
                if ($newSignId === null) {
                    $data["data"] = array("id" => 0, "messageId" => 0, "messageText" => "");
                    break;
                }
                $newSign = clone $signObj;
                $newSign->id = (int) $newSignId;
                $newSign->deleted = false;
                $newSign->tempId = (int) -1;

                $hostWorld["objectsArray"][] = $newSign;
                $msgMgrObj = $hostWorld["messageManager"] ?? null;
                $messageManager = ["messages" => [], "allowSendEmails" => true];
                if (is_object($msgMgrObj)) {
                    $messages = [];
                    if (isset($msgMgrObj->messages) && is_array($msgMgrObj->messages)) {
                        foreach ($msgMgrObj->messages as $m) {
                            $messages[] = is_object($m) ? (array) $m : $m;
                        }
                    }
                    $messageManager["messages"] = $messages;
                    $messageManager["allowSendEmails"] = $msgMgrObj->allowSendEmails ?? true;
                } elseif (is_array($msgMgrObj)) {
                    $messageManager = $msgMgrObj;
                    if (!isset($messageManager["messages"])) {
                        $messageManager["messages"] = [];
                    }
                }

                $maxMsgId = 0;
                foreach ($messageManager["messages"] as $msg) {
                    $msgId = $msg["id"] ?? 0;
                    if ($msgId > $maxMsgId) {
                        $maxMsgId = $msgId;
                    }
                }
                $newMessageId = $maxMsgId + 1;
                $messageManager["messages"][] = [
                    "id" => (int) $newMessageId,
                    "message" => (string) $messageText,
                    "authorId" => (string) $authorId,
                    "objectId" => (int) $newSignId,
                    "isNew" => true,
                    "timestamp" => (int) time()
                ];

                $newSign->messageId = (int) $newMessageId;
                saveWorldWithMessages($hostId, $hostWorldType, $hostWorld, $messageManager);

                $data["id"] = $newSignId;
                $data["data"] = array(
                    "id" => $newSignId,
                    "messageId" => $newMessageId,
                    "messageText" => $messageText
                );
                break;

            case ACTION_DELETE_MESSAGE_SIGN:
                $signObj = $request->params[1] ?? null;
                $hostId = $signObj->hostId ?? null;
                $signId = $signObj->id ?? null;
                $messageId = $signObj->messageId ?? null;

                if (!$hostId || !$signId) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $hostWorldType = get_meta($hostId, "currentWorldType") ?: "farm";
                $hostWorld = getWorldByType($hostId, $hostWorldType);

                if (empty($hostWorld) || !isset($hostWorld["objectsArray"])) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $found = false;
                foreach ($hostWorld["objectsArray"] as $key => $obj) {
                    if (isset($obj->id) && $obj->id == $signId && isset($obj->className) && $obj->className === 'MessageSign') {
                        unset($hostWorld["objectsArray"][$key]);
                        $found = true;
                        break;
                    }
                }
                $hostWorld["objectsArray"] = array_values($hostWorld["objectsArray"]);
                $msgMgrObj = $hostWorld["messageManager"] ?? null;
                $messageManager = ["messages" => [], "allowSendEmails" => true];
                if (is_object($msgMgrObj)) {
                    $messages = [];
                    if (isset($msgMgrObj->messages) && is_array($msgMgrObj->messages)) {
                        foreach ($msgMgrObj->messages as $m) {
                            $messages[] = is_object($m) ? (array) $m : $m;
                        }
                    }
                    $messageManager["messages"] = $messages;
                    $messageManager["allowSendEmails"] = $msgMgrObj->allowSendEmails ?? true;
                } elseif (is_array($msgMgrObj)) {
                    $messageManager = $msgMgrObj;
                    if (!isset($messageManager["messages"])) {
                        $messageManager["messages"] = [];
                    }
                }

                if ($messageId) {
                    foreach ($messageManager["messages"] as $msgKey => $msg) {
                        if (($msg["id"] ?? 0) == $messageId) {
                            unset($messageManager["messages"][$msgKey]);
                            break;
                        }
                    }
                    $messageManager["messages"] = array_values($messageManager["messages"]);
                }

                saveWorldWithMessages($hostId, $hostWorldType, $hostWorld, $messageManager);
                $data["data"] = array("success" => $found);
                break;

            case ACTION_EXPAND_WITH_CURRENCY:
                $expandObj = $request->params[1];
                $objId = $expandObj->id ?? 0;
                $itemName = $expandObj->itemName ?? "NULL";

                if ($objId <= 0) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $uid = $playerObj->getUid();
                $worldType = getCurrentWorldType($uid);
                $world = getWorldByType($uid, $worldType);

                if (empty($world) || !isset($world["objectsArray"])) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $found = false;
                foreach ($world["objectsArray"] as $key => &$obj) {
                    if (isset($obj->id) && $obj->id == $objId) {
                        $currentLevel = isset($obj->expansionLevel) ? (int)$obj->expansionLevel : 1;
                        $obj->expansionLevel = $currentLevel + 1;
                        $obj->expansionParts = new \stdClass();
                        $found = true;
                        break;
                    }
                }
                unset($obj);

                if (!$found) {
                    $data["data"] = array("success" => false);
                    break;
                }

                if (!saveWorld($uid, $worldType, $world)) {
                    throw new \Exception("Failed to save world (expand with currency) for uid=$uid");
                }
                $data["data"] = array("success" => true);
                break;

            case ACTION_COMPLETE_NOW:
                $expandObj = $request->params[1];
                $objId = $expandObj->id ?? 0;
                $itemName = $expandObj->itemName ?? "NULL";
                $currency = $extraParams->currency ?? null;

                if ($objId <= 0) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $uid = $playerObj->getUid();
                $worldType = getCurrentWorldType($uid);
                $world = getWorldByType($uid, $worldType);

                if (empty($world) || !isset($world["objectsArray"])) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $buildingKey = null;
                $building = null;
                foreach ($world["objectsArray"] as $key => $obj) {
                    if (isset($obj->id) && $obj->id == $objId) {
                        $buildingKey = $key;
                        $building = $obj;
                        break;
                    }
                }

                if ($buildingKey === null || !$building) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $buildingItemData = getItemByName($itemName, "db");
                if (!$buildingItemData || !hasExpandFeature($buildingItemData)) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $currentLevel = (int)($building->expansionLevel ?? 1);
                $upgradeData = getExpansionUpgradeData($buildingItemData, $currentLevel);

                if (!$upgradeData || !isset($upgradeData->part)) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $totalCashCost = 0;
                $parts = is_array($upgradeData->part) ? $upgradeData->part : [$upgradeData->part];
                $expansionParts = $building->expansionParts ?? new \stdClass();

                foreach ($parts as $part) {
                    if (!isset($part->name) || !isset($part->need)) continue;

                    $partItem = getItemByName($part->name, "db");
                    if (!$partItem) continue;

                    $partCode = $partItem['code'] ?? $part->name;
                    $partCash = (int)($partItem['cash'] ?? 1);
                    $needed = (int)$part->need;
                    $collected = 0;

                    if (is_object($expansionParts) && isset($expansionParts->$partCode)) {
                        $collected = (int)$expansionParts->$partCode;
                    } elseif (is_array($expansionParts) && isset($expansionParts[$partCode])) {
                        $collected = (int)$expansionParts[$partCode];
                    }

                    $remaining = max(0, $needed - $collected);
                    $totalCashCost += $remaining * $partCash;
                }

                if ($currency === 'cash' && $totalCashCost > 0) {
                    UserResources::removeCash($uid, $totalCashCost);
                }

                $world["objectsArray"][$buildingKey]->expansionLevel = $currentLevel + 1;
                $world["objectsArray"][$buildingKey]->expansionParts = new \stdClass();

                if (!saveWorld($uid, $worldType, $world)) {
                    throw new \Exception("Failed to save world (complete now) for uid=$uid");
                }
                $data["data"] = array("success" => true);
                break;

            case ACTION_OPEN:
                $presentObj = $request->params[1];
                $objId = $presentObj->id ?? 0;
                $objItemName = $presentObj->itemName ?? null;

                if ($objId <= 0 || !$objItemName) {
                    $data["data"] = array("error" => "invalid_object");
                    break;
                }

                $uid = $playerObj->getUid();
                $worldType = getCurrentWorldType($uid);
                $worldId = getWorldId($uid, $worldType);

                if (!$worldId) {
                    $data["data"] = array("error" => "no_world");
                    break;
                }

                $dbPresent = \App\Models\WorldObject::where('world_id', $worldId)
                    ->where('object_id', $objId)
                    ->where('deleted', false)
                    ->first();
                
                $components = null;
                if ($dbPresent && $dbPresent->components) {
                    $components = $dbPresent->components;
                    if (is_string($components)) {
                        $components = JsonHelper::safeDecode($components, false);
                    }
                }

                if (!$components && isset($presentObj->components)) {
                    $components = $presentObj->components;
                }

                $openResult = resolveOpenableItem($objItemName, $components, $uid);

                if (!$openResult || !$openResult['resultItem']) {
                    $data["data"] = array("error" => "unsupported_present");
                    break;
                }

                $resultItem = $openResult['resultItem'];
                $extraItemData = $openResult['extraItemData'] ?? null;

                $posX = isset($presentObj->position) ? ($presentObj->position->x ?? null) : null;
                $posY = isset($presentObj->position) ? ($presentObj->position->y ?? null) : null;

                if ($posX !== null && $posY !== null) {
                    deleteWorldObjectByPosition($worldId, (int)$posX, (int)$posY);
                }

                $senderId = $extraItemData['sender'] ?? $uid;
                addGiftByName($uid, $resultItem, 1, $senderId, $extraItemData);

                invalidateWorldCache($uid, $worldType);

                $data["data"] = [
                    "item" => $resultItem,
                    "giftSenderId" => $senderId,
                    "extraItemData" => $extraItemData
                ];
                break;

            case ACTION_UPGRADE_STORAGE:
                $buildingObj = $request->params[1] ?? null;
                $buildingId = $buildingObj->id ?? 0;

                if ($buildingId <= 0) {
                    $data["data"] = ["error" => "invalid_building"];
                    break;
                }

                $uid = $playerObj->getUid();
                $worldType = getCurrentWorldType($uid);
                $worldId = getWorldId($uid, $worldType);

                if (!$worldId) {
                    $data["data"] = ["error" => "no_world"];
                    break;
                }

                $existingUpgradeJson = get_meta($uid, 'upgradeStatus');
                if ($existingUpgradeJson) {
                    $existingUpgrade = JsonHelper::safeDecode($existingUpgradeJson, true, []);
                    if ($existingUpgrade && ($existingUpgrade['isActive'] ?? false)) {
                        $data["data"] = ["error" => "upgrade_already_active"];
                        break;
                    }
                }

                $building = \App\Models\WorldObject::where('world_id', $worldId)
                    ->where('object_id', $buildingId)
                    ->where('deleted', false)
                    ->first();

                if (!$building) {
                    $data["data"] = ["error" => "building_not_found"];
                    break;
                }

                if (!in_array($building->class_name, ['StorageBuilding', 'InventoryCellar'])) {
                    $data["data"] = ["error" => "invalid_building_type"];
                    break;
                }

                $helperURL = "upgrade_{$buildingId}_{$uid}_{$worldType}";

                $nowMs = getCurrentTimeMs();
                $upgradeStatus = [
                    'isActive' => true,
                    'buildingId' => $buildingId,
                    'worldType' => $worldType,
                    'numHelped' => 0,
                    'helpers' => [],
                    'lastPosted' => $nowMs,
                    'expires' => $nowMs + (86400 * 1000),
                ];
                set_meta($uid, 'upgradeStatus', JsonHelper::safeEncode($upgradeStatus));

                $data["data"] = [
                    "helperURL" => $helperURL,
                    "buildingId" => $buildingId,
                    "worldType" => $worldType,
                    "numHelped" => 0,
                    "helperList" => [],
                    "lastPosted" => $upgradeStatus['lastPosted'],
                    "expires" => $upgradeStatus['expires'],
                    "clientUpdated" => $nowMs,
                ];
                break;

            case ACTION_PURCHASE_STORAGE_UPGRADE:
                $buildingObj = $request->params[1] ?? null;
                $buildingId = $buildingObj->id ?? 0;

                if ($buildingId <= 0) {
                    $data["data"] = ["error" => "invalid_building"];
                    break;
                }

                $uid = $playerObj->getUid();
                $worldType = getCurrentWorldType($uid);
                $worldId = getWorldId($uid, $worldType);

                if (!$worldId) {
                    $data["data"] = ["error" => "no_world"];
                    break;
                }

                $building = \App\Models\WorldObject::where('world_id', $worldId)
                    ->where('object_id', $buildingId)
                    ->where('deleted', false)
                    ->first();

                if (!$building) {
                    $data["data"] = ["error" => "building_not_found"];
                    break;
                }

                if (!in_array($building->class_name, ['StorageBuilding', 'InventoryCellar'])) {
                    $data["data"] = ["error" => "invalid_building_type"];
                    break;
                }

                $itemName = $building->item_name;
                $itemData = getItemByName($itemName, "db");
                $currentLevel = $building->expansion_level ?? 1;

                $upgradeCost = 5;

                $currentCash = UserResources::getCash($uid);
                if ($currentCash < $upgradeCost) {
                    $data["data"] = ["error" => "insufficient_cash"];
                    break;
                }

                UserResources::removeCash($uid, $upgradeCost);

                $newLevel = $currentLevel + 1;
                $building->expansion_level = $newLevel;
                $building->expansion_parts = null;
                $building->save();

                set_meta($uid, 'upgradeStatus', '');

                invalidateWorldCache($uid, $worldType);

                $data["data"] = [
                    "success" => true,
                    "newLevel" => $newLevel,
                    "helperURL" => null,
                    "worldType" => $worldType,
                ];
                break;

            case ACTION_CANCEL_STORAGE_UPGRADE:
                $uid = $playerObj->getUid();

                set_meta($uid, 'upgradeStatus', '');

                $data["data"] = ["success" => true];
                break;

            case ACTION_GET_STORAGE_INFO:
                $buildingObj = $request->params[1] ?? null;
                $buildingId = $buildingObj->id ?? 0;
                $uid = $playerObj->getUid();

                $upgradeStatusJson = get_meta($uid, 'upgradeStatus');
                $upgradeStatus = ($upgradeStatusJson && $upgradeStatusJson !== '')
                    ? JsonHelper::safeDecode($upgradeStatusJson, true)
                    : null;

                if ($upgradeStatus && isset($upgradeStatus['buildingId']) && $upgradeStatus['buildingId'] == $buildingId) {
                    $data["data"] = [
                        "helperURL" => "upgrade_{$buildingId}_{$uid}_{$upgradeStatus['worldType']}",
                        "buildingId" => $upgradeStatus['buildingId'],
                        "worldType" => $upgradeStatus['worldType'],
                        "numHelped" => $upgradeStatus['numHelped'] ?? 0,
                        "helperList" => $upgradeStatus['helpers'] ?? [],
                        "lastPosted" => $upgradeStatus['lastPosted'] ?? 0,
                        "expires" => $upgradeStatus['expires'] ?? 0,
                        "clientUpdated" => getCurrentTimeMs(),
                    ];
                } else {
                    $data["data"] = [
                        "buildingId" => $buildingId,
                        "isActive" => false,
                    ];
                }
                break;
        }
        return $data;
    }

    public static function loadOwnWorld($playerObj, $request, $market = null)
    {
        $loadType = $request->params[0] == "" ? 'farm' : $request->params[0];
        $travelWorld = getWorldByType($playerObj->getUid(), $loadType);
        $data["data"] = array(
            "user" => array(
                "currentWorldType" => $travelWorld["type"],
                "worldSummaryData" => array(
                    $travelWorld["type"] => array(
                        "firstLoaded" => strtotime($travelWorld['creation']),
                        "lastLoaded" => strtotime(date("Y-m-d h:i:s"))
                    )

                ),
                "player" => array(
                    "featureCredits" => getFeatureCreditsForClient($playerObj->getUid())
                )
            ),
            "world" => $travelWorld
        );

        set_meta($playerObj->getUid(), 'currentWorldType', $travelWorld["type"]);

        return $data;
    }

    public static function loadNeighborWorld($playerObj, $request){
        $neighborUid = $request->params[0];
        $travelWorld = getWorldByType($neighborUid);
        $neighborWorldType = get_meta($neighborUid, "currentWorldType") ?: "farm";

        $data["data"] = array(
            "user" => array(
                "ugcItemData" => [],
                "instanceDataStore" => [],
                "currentWorldType" => $neighborWorldType
            ),
            "world" => $travelWorld
        );

        return $data;
    }
}
