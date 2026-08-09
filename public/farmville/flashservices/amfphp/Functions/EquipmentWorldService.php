<?php

require_once AMFPHP_ROOTPATH . "Helpers/constants.php";
require_once AMFPHP_ROOTPATH . "Helpers/general_functions.php";
require_once AMFPHP_ROOTPATH . "Helpers/logger.php";
require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";
require_once AMFPHP_ROOTPATH . "Helpers/market_transactions.php";
require_once AMFPHP_ROOTPATH . "Helpers/quest_progress.php";

class EquipmentWorldService
{

    public static function onUseEquipment($playerObj, $request, $market)
    {
        $data = array();
        $results = array();

        $combineHarvestResults = [];
        $combinePlowResults = [];
        $combinePlaceResults = [];

        $action = $request->params[0] ?? null;
        $equipmentBundle = $request->params[1] ?? null;
        $plotBundle = $request->params[2] ?? null;
        $itemName = $request->params[3] ?? null;

        if (!$action || !$plotBundle) {
            $data["data"] = [];
            return $data;
        }

        if (is_object($plotBundle)) {
            $plotBundle = get_object_vars($plotBundle);
        }

        $plotCount = count($plotBundle);

        $uid = $playerObj->getUid();

        if ($plotCount > 0) {
            UserResources::removeEnergy($uid, $plotCount);
        }

        $currentWorldType = getCurrentWorldType($uid);
        $world = getWorldByType($uid, $currentWorldType);
        $worldId = $world['worldId'] ?? getWorldId($uid, $currentWorldType);

        $positionIndex = buildPositionIndex($world["objectsArray"]);

        $usedIds = [];
        if ($action === ACTION_PLOW) {
            foreach ($world["objectsArray"] as $obj) {
                if (isset($obj->id) && $obj->id > 0 && $obj->id < TEMP_ID_THRESHOLD) {
                    $usedIds[$obj->id] = true;
                }
            }
        }

        $plowCount = 0;
        $plantCount = 0;
        $harvestedItems = [];

        $modifiedObjects = [];
        $newObjects = [];

        foreach ($plotBundle as $key => $plotData) {
            if (is_array($plotData)) {
                $plotData = (object) $plotData;
            }

            if ($action === ACTION_PLOW) {
                $plotObj = self::createPlotObject($plotData);
                if ($plotObj === null) {
                    $results[] = null;
                    continue;
                }

                $posX = isset($plotData->position) ? ($plotData->position->x ?? ($plotData->position['x'] ?? null)) : null;
                $posY = isset($plotData->position) ? ($plotData->position->y ?? ($plotData->position['y'] ?? null)) : null;

                $foundKey = findByPosition($positionIndex, $posX, $posY);

                if ($foundKey !== null) {
                    $existingState = $world["objectsArray"][$foundKey]->state ?? '';

                    if ($existingState === PLOT_STATE_FALLOW || $existingState === PLOT_STATE_PLOWED) {
                        $world["objectsArray"][$foundKey]->state = PLOT_STATE_PLOWED;
                        $modifiedObjects[] = $world["objectsArray"][$foundKey];
                        $plowCount++;

                        $results[] = array(
                            "id" => $world["objectsArray"][$foundKey]->id,
                            "data" => array("id" => $world["objectsArray"][$foundKey]->id)
                        );
                    } else {
                        $results[] = null;
                    }
                } elseif ($plotObj->id >= TEMP_ID_THRESHOLD) {
                    $newId = null;
                    $maxSafeId = TEMP_ID_THRESHOLD - 1;
                    for ($i = 1; $i <= $maxSafeId; $i++) {
                        if (!isset($usedIds[$i])) {
                            $newId = $i;
                            break;
                        }
                    }

                    if ($newId === null) {
                        $results[] = null;
                        continue;
                    }

                    $plotObj->id = $newId;
                    $usedIds[$newId] = true;
                    $world["objectsArray"][] = $plotObj;
                    $newObjects[] = $plotObj;
                    $plowCount++;

                    $newKey = count($world["objectsArray"]) - 1;
                    $posKey = $posX . "," . $posY;
                    $positionIndex[$posKey] = $newKey;

                    $results[] = array(
                        "id" => $plotObj->id,
                        "data" => array("id" => $plotObj->id)
                    );
                } else {
                    $results[] = null;
                }
                continue;
            }

            if (isPositionBasedAction($action) && isset($plotData->position)) {
                $posX = $plotData->position->x ?? ($plotData->position['x'] ?? null);
                $posY = $plotData->position->y ?? ($plotData->position['y'] ?? null);

                $foundKey = findByPosition($positionIndex, $posX, $posY);

                if ($foundKey !== null) {
                    $foundPlot = $world["objectsArray"][$foundKey];
                    $className = $foundPlot->className ?? 'Plot';
                    $wasModified = false;

                    switch ($action) {
                        case ACTION_PLANT:
                            $world["objectsArray"][$foundKey]->state = PLOT_STATE_PLANTED;
                            $world["objectsArray"][$foundKey]->itemName = $itemName;
                            $world["objectsArray"][$foundKey]->plantTime = getCurrentTimeMs();
                            $plantCount++;
                            $wasModified = true;
                            break;

                        case ACTION_HARVEST:
                            $currentState = $foundPlot->state ?? '';
                            if ($currentState === PLOT_STATE_PLANTED) {
                                $cropItemName = $foundPlot->itemName ?? null;
                                $plantTime = $foundPlot->plantTime ?? 0;

                                if ($cropItemName && $plantTime > 0) {
                                    $itemData = getItemByName($cropItemName, "db");
                                    if ($itemData && isset($itemData["growTime"])) {
                                        $growTimeDays = (float) $itemData["growTime"];
                                        $growTimeMs = calculateGrowTimeMs($growTimeDays);
                                        $witherTimeMs = $growTimeMs;
                                        $currentTimeMs = getCurrentTimeMs();

                                        $hasRingProtection = isWitherProtectionActive($uid, $currentWorldType);

                                        if ($currentTimeMs >= ($plantTime + $growTimeMs + $witherTimeMs) && !$hasRingProtection) {
                                            $currentState = PLOT_STATE_WITHERED;
                                        } elseif ($currentTimeMs >= ($plantTime + $growTimeMs)) {
                                            $currentState = PLOT_STATE_GROWN;
                                        }
                                    }
                                }
                            }

                            if ($currentState !== PLOT_STATE_GROWN && $currentState !== HARVESTABLE_STATE_BARE) {
                                break;
                            }

                            $harvestedItemName = $foundPlot->itemName ?? null;
                            if ($harvestedItemName) {
                                $harvestedItems[] = $harvestedItemName;
                            }

                            $postHarvest = getPostHarvestState($className);
                            $world["objectsArray"][$foundKey]->state = $postHarvest['state'];
                            $world["objectsArray"][$foundKey]->plantTime = $postHarvest['plantTime'];
                            if (isset($postHarvest['itemName'])) {
                                $world["objectsArray"][$foundKey]->itemName = $postHarvest['itemName'];
                            }
                            if (isset($postHarvest['isJumbo'])) {
                                $world["objectsArray"][$foundKey]->isJumbo = $postHarvest['isJumbo'];
                            }
                            $wasModified = true;
                            break;

                        case ACTION_REMOVE:
                            $world["objectsArray"][$foundKey]->state = PLOT_STATE_FALLOW;
                            $world["objectsArray"][$foundKey]->itemName = null;
                            $world["objectsArray"][$foundKey]->plantTime = 0;
                            $wasModified = true;
                            break;

                        case ACTION_COMBINE:
                            $currentState = $foundPlot->state ?? '';

                            if ($currentState === PLOT_STATE_PLANTED) {
                                $cropItemName = $foundPlot->itemName ?? null;
                                $plantTime = $foundPlot->plantTime ?? 0;

                                if ($cropItemName && $plantTime > 0) {
                                    $itemData = getItemByName($cropItemName, "db");
                                    if ($itemData && isset($itemData["growTime"])) {
                                        $growTimeDays = (float) $itemData["growTime"];
                                        $growTimeMs = calculateGrowTimeMs($growTimeDays);
                                        $witherTimeMs = $growTimeMs;
                                        $currentTimeMs = getCurrentTimeMs();

                                        $hasRingProtection = isWitherProtectionActive($uid, $currentWorldType);

                                        if ($currentTimeMs >= ($plantTime + $growTimeMs + $witherTimeMs) && !$hasRingProtection) {
                                            $currentState = PLOT_STATE_WITHERED;
                                        } elseif ($currentTimeMs >= ($plantTime + $growTimeMs)) {
                                            $currentState = PLOT_STATE_GROWN;
                                        }
                                    }
                                }
                            }

                            if ($currentState === PLOT_STATE_PLANTED) {
                                $combineHarvestResults[] = null;
                                $combinePlowResults[] = null;
                                $combinePlaceResults[] = null;
                                break;
                            }

                            $plotResult = ["id" => $foundPlot->id, "data" => ["id" => $foundPlot->id]];

                            if ($currentState === PLOT_STATE_GROWN) {
                                $harvestedItemName = $foundPlot->itemName ?? null;
                                if ($harvestedItemName) {
                                    $harvestedItems[] = $harvestedItemName;
                                }
                                $combineHarvestResults[] = $plotResult;
                                $combinePlowResults[] = $plotResult;
                                $plowCount++;
                            } else {
                                $combineHarvestResults[] = null;
                                if ($currentState === PLOT_STATE_FALLOW) {
                                    $combinePlowResults[] = $plotResult;
                                    $plowCount++;
                                } else {
                                    $combinePlowResults[] = null;
                                }
                            }

                            $world["objectsArray"][$foundKey]->state = PLOT_STATE_PLANTED;
                            $world["objectsArray"][$foundKey]->itemName = $itemName;
                            $world["objectsArray"][$foundKey]->plantTime = getCurrentTimeMs();
                            $world["objectsArray"][$foundKey]->isJumbo = false;
                            $plantCount++;
                            $combinePlaceResults[] = $plotResult;
                            $wasModified = true;
                            break;

                        case ACTION_WATER:
                            break;
                    }

                    if ($wasModified) {
                        $modifiedObjects[] = $world["objectsArray"][$foundKey];
                    }

                    if ($action !== ACTION_COMBINE) {
                        $results[] = array(
                            "id" => $foundPlot->id,
                            "data" => array("id" => $foundPlot->id)
                        );
                    }
                } else {
                    if ($action === ACTION_COMBINE) {
                        $combineHarvestResults[] = null;
                        $combinePlowResults[] = null;
                        $combinePlaceResults[] = null;
                    } else {
                        $results[] = null;
                    }
                }
            }
        }

        $worldPersisted = true;

        if (!empty($modifiedObjects)) {
            if (!updateWorldObjectsByPosition($worldId, $modifiedObjects)) {
                Logger::error('EquipmentWorldService', "Failed to update world objects for uid=$uid");
                $worldPersisted = false;
            }
        }

        if (!empty($newObjects)) {
            if (!insertWorldObjects($worldId, $newObjects)) {
                Logger::error('EquipmentWorldService', "Failed to insert new world objects for uid=$uid");
                $worldPersisted = false;
            }
        }

        if (!empty($modifiedObjects) || !empty($newObjects)) {
            invalidateWorldCache($uid, $currentWorldType);
        }

        // Bulk equipment actions take a different server route than individual
        // WorldService actions. Persist quest progress here only after the
        // affected plots have been saved, so a reload receives the same state.
        if ($worldPersisted) {
            $questUpdates = [];

            if ($plowCount > 0) {
                $questUpdates = array_merge($questUpdates, trackPlowProgress($uid, $plowCount));
            }

            if ($plantCount > 0 && $itemName) {
                $itemData = getItemByName($itemName, "db");
                $questUpdates = array_merge(
                    $questUpdates,
                    trackPlantProgress($uid, $itemName, $itemData ?: [], $plantCount)
                );
            }

            foreach (array_count_values($harvestedItems) as $harvestedItemName => $harvestCount) {
                $itemData = getItemByName($harvestedItemName, "db");
                $questUpdates = array_merge(
                    $questUpdates,
                    trackHarvestProgress($uid, [], $harvestedItemName, $itemData ?: [], $harvestCount)
                );
            }

            if (!empty($questUpdates)) {
                Logger::debug('EquipmentWorldService', 'Quest progress saved: ' . json_encode($questUpdates));
            }
        }

        $totalGoldDelta = 0;
        $totalXpDelta = 0;
        $totalCashDelta = 0;
        $masteryItemCounts = [];

        try {
            if ($plowCount > 0) {
                $plowDeltas = MarketTransactions::calculatePlowDeltas($plowCount);
                $totalGoldDelta += $plowDeltas['goldDelta'];
                $totalXpDelta += $plowDeltas['xpDelta'];
                Logger::debug('EquipmentWorldService', "Plow deltas: gold={$plowDeltas['goldDelta']}, xp={$plowDeltas['xpDelta']}");
            }

            if ($plantCount > 0 && $itemName) {
                $buyDeltas = MarketTransactions::calculateBuyDeltas($itemName, $plantCount);
                $totalGoldDelta += $buyDeltas['goldDelta'];
                $totalXpDelta += $buyDeltas['xpDelta'];
                $totalCashDelta += $buyDeltas['cashDelta'];
                Logger::debug('EquipmentWorldService', "Buy deltas for $itemName x$plantCount: gold={$buyDeltas['goldDelta']}, xp={$buyDeltas['xpDelta']}, cash={$buyDeltas['cashDelta']}");
            }

            if (!empty($harvestedItems)) {
                $harvestDeltas = MarketTransactions::calculateHarvestDeltas($harvestedItems);
                $totalGoldDelta += $harvestDeltas['goldDelta'];
                $totalXpDelta += $harvestDeltas['xpDelta'];
                $masteryItemCounts = $harvestDeltas['itemCounts'];
                Logger::debug('EquipmentWorldService', "Harvest deltas: gold={$harvestDeltas['goldDelta']}, xp={$harvestDeltas['xpDelta']}");
            }

            Logger::debug('EquipmentWorldService', "Calling batchUpdate: uid=$uid, gold=$totalGoldDelta, xp=$totalXpDelta, cash=$totalCashDelta");
            $batchResult = UserResources::batchUpdate($uid, $totalGoldDelta, $totalXpDelta, $totalCashDelta);
            Logger::debug('EquipmentWorldService', "batchUpdate result: " . ($batchResult ? 'true' : 'false'));

            foreach ($masteryItemCounts as $masteryItemName => $count) {
                $itemData = getItemByName($masteryItemName, "db");
                if ($itemData) {
                    processMastery($uid, $itemData, $count);
                }
            }
        } catch (\Throwable $e) {
        }

        if ($action === ACTION_COMBINE) {
            $data["data"] = [
                "harvest" => ["data" => $combineHarvestResults],
                "plow" => ["data" => $combinePlowResults],
                "place" => ["data" => $combinePlaceResults]
            ];
        } else {
            $data["data"] = $results;
        }
        return $data;
    }

    
    private static function createPlotObject($plotData)
    {
        if (is_array($plotData)) {
            $plotData = (object) $plotData;
        }

        if (!is_object($plotData)) {
            return null;
        }

        $plotObj = new \stdClass();
        $plotObj->id = $plotData->id ?? (TEMP_ID_THRESHOLD + 1);

        if (isset($plotData->position)) {
            if (is_object($plotData->position)) {
                $plotObj->position = $plotData->position;
            } elseif (is_array($plotData->position)) {
                $plotObj->position = (object) $plotData->position;
            }
        }

        $plotObj->state = PLOT_STATE_PLOWED;
        $plotObj->className = 'Plot';
        $plotObj->itemName = null;
        $plotObj->plantTime = 0;

        if (isset($plotData->direction)) {
            $plotObj->direction = $plotData->direction;
        }
        if (isset($plotData->isBigPlot)) {
            $plotObj->isBigPlot = $plotData->isBigPlot;
        }

        return $plotObj;
    }
}
