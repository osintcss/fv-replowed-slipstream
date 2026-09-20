<?php

require_once AMFPHP_ROOTPATH . "Helpers/constants.php";
require_once AMFPHP_ROOTPATH . "Helpers/general_functions.php";
require_once AMFPHP_ROOTPATH . "Helpers/logger.php";
require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";
require_once AMFPHP_ROOTPATH . "Helpers/market_transactions.php";
require_once AMFPHP_ROOTPATH . "Helpers/quest_progress.php";
require_once AMFPHP_ROOTPATH . "Helpers/crafting_helper.php";

use App\Support\WorldPersistence;
use App\Models\Item;
use App\Models\WorldActionReceipt;
use App\Models\WorldObject;
use App\Support\GarageEquipmentCatalog;
use App\Support\WorldCurrencyService;
use Illuminate\Support\Facades\DB;

class EquipmentWorldService
{

    /**
     * Persist one vehicle-part upgrade selected from a Garage.
     *
     * TAddPartToEquipmentInGarage sends the Garage object ID, the current
     * `itemCode:numParts` key, and whether the part came from Giftbox. Flash
     * has already changed its local dictionary; the server must replace one
     * authoritative old entry with the incremented key in the same transaction
     * as the cash/gift deduction.
     */
    public static function onAddPartToEquipmentInGarage($playerObj, $request, $market)
    {
        $uid = $playerObj->getUid();
        $params = self::requestValue($request, 'params', []);
        $buildingId = is_array($params) && isset($params[0]) && is_numeric($params[0])
            ? (int) $params[0] : 0;
        $key = is_array($params) && is_string($params[1] ?? null)
            ? $params[1] : '';
        $isGift = self::flashBoolean(is_array($params) ? ($params[2] ?? false) : false, false);

        if ($buildingId <= 0
            || !preg_match('/^([^:\x00-\x20]{1,64}):(\\d{1,5})$/D', $key, $matches)) {
            return self::garagePartResponse(false, 'Invalid Garage equipment key.');
        }

        $itemCode = $matches[1];
        $oldParts = (int) $matches[2];
        $newParts = $oldParts + 1;
        $item = Item::findByCode($itemCode);
        if (!GarageEquipmentCatalog::matches($item, $itemCode)) {
            return self::garagePartResponse(false, 'Invalid Garage equipment.');
        }

        $maxParts = GarageEquipmentCatalog::maxParts($itemCode);
        if ($maxParts !== null && $newParts > $maxParts) {
            return self::garagePartResponse(false, 'Equipment is fully upgraded.');
        }

        $vehiclePart = getItemByName('vehiclepart', 'db');
        $vehiclePartCode = is_array($vehiclePart) ? (string) ($vehiclePart['code'] ?? '') : '';
        $cashCost = is_array($vehiclePart) ? (int) ($vehiclePart['cash'] ?? 0) : 0;
        if ($vehiclePartCode === '' || $cashCost <= 0) {
            return self::garagePartResponse(false, 'Vehicle part catalog entry is unavailable.');
        }

        $worldType = getCurrentWorldType($uid);
        $requestKey = self::equipmentActionIdempotencyKey($request, 'garage_part');
        $result = WorldPersistence::transaction(
            $uid,
            $worldType,
            function (int $worldId) use (
                $uid,
                $buildingId,
                $itemCode,
                $oldParts,
                $newParts,
                $isGift,
                $vehiclePartCode,
                $cashCost,
                $requestKey,
            ) {
                $garage = WorldObject::query()
                    ->where('world_id', $worldId)
                    ->where('object_id', $buildingId)
                    ->where('class_name', 'GarageBuilding')
                    ->where('deleted', false)
                    ->lockForUpdate()
                    ->first();
                if ($garage === null) {
                    throw new \RuntimeException('Garage no longer exists.');
                }

                if ($requestKey !== null) {
                    $receipt = WorldActionReceipt::query()
                        ->where('uid', (string) $uid)
                        ->where('action', 'garage_part')
                        ->where('request_key', $requestKey)
                        ->lockForUpdate()
                        ->first();
                    if ($receipt !== null) {
                        $response = is_array($receipt->response) ? $receipt->response : [];
                        $response['replayed'] = true;
                        return $response;
                    }
                }

                $contents = GarageEquipmentCatalog::normalizeContents($garage->contents);
                $oldIndex = null;
                $newIndex = null;
                foreach ($contents as $index => $content) {
                    if (($content['itemCode'] ?? null) !== $itemCode) {
                        continue;
                    }

                    $parts = GarageEquipmentCatalog::entryParts($content);
                    if ($parts === $oldParts && ($content['numItem'] ?? 0) > 0) {
                        $oldIndex = $index;
                    }
                    if ($parts === $newParts && ($content['numItem'] ?? 0) > 0) {
                        $newIndex = $index;
                    }
                }

                // If the first response was committed but lost in transit,
                // the old key is gone and the new key is already authoritative.
                // Treat that state as an idempotent success rather than charging
                // or consuming a second part.
                if ($oldIndex === null) {
                    if ($newIndex !== null) {
                        return [
                            'id' => $buildingId,
                            'success' => true,
                            'rewardUrl' => '',
                            'equipmentKey' => $itemCode . ':' . $newParts,
                            'replayed' => true,
                        ];
                    }
                    throw new \RuntimeException('Garage equipment is no longer at the requested level.');
                }

                if ($isGift) {
                    // The preceding optimistic TUseConsumable is deliberately
                    // deferred by ConsumableActionHandler. Spend the gift only
                    // when this transaction successfully moves the vehicle.
                    if (!consumeGiftboxItemLocked($uid, $vehiclePartCode, 1)) {
                        throw new \RuntimeException('Vehicle part gift is unavailable.');
                    }
                } elseif (!UserResources::removeCash($uid, $cashCost)) {
                    throw new \RuntimeException('Not enough cash for vehicle part.');
                }

                $oldCount = (int) ($contents[$oldIndex]['numItem'] ?? 0);
                if ($oldCount <= 1) {
                    unset($contents[$oldIndex]);
                } else {
                    $contents[$oldIndex]['numItem'] = $oldCount - 1;
                }

                if ($newIndex !== null) {
                    $contents[$newIndex]['numItem'] = (int) ($contents[$newIndex]['numItem'] ?? 0) + 1;
                } else {
                    $contents[] = [
                        'itemCode' => $itemCode,
                        'numItem' => 1,
                        'numParts' => $newParts,
                    ];
                }

                $garage->contents = array_values($contents);
                $garage->save();

                $response = [
                    'id' => $buildingId,
                    'success' => true,
                    'rewardUrl' => '',
                    'equipmentKey' => $itemCode . ':' . $newParts,
                ];
                if ($requestKey !== null) {
                    WorldActionReceipt::query()->create([
                        'uid' => (string) $uid,
                        'action' => 'garage_part',
                        'request_key' => $requestKey,
                        'response' => $response,
                    ]);
                }

                return $response;
            },
        );

        return self::garagePartResponse(
            is_array($result) && ($result['success'] ?? false),
            is_array($result) ? ($result['error'] ?? null) : 'Garage upgrade could not be saved.',
            is_array($result) ? $result : [],
        );
    }

    /** Persist the cash purchase used when a player has no Garage yet. */
    public static function onGiftVehiclePart($playerObj, $request, $market)
    {
        $uid = $playerObj->getUid();
        $vehiclePart = getItemByName('vehiclepart', 'db');
        $vehiclePartCode = is_array($vehiclePart) ? (string) ($vehiclePart['code'] ?? '') : '';
        $cashCost = is_array($vehiclePart) ? (int) ($vehiclePart['cash'] ?? 0) : 0;
        $requestKey = self::equipmentActionIdempotencyKey($request, 'gift_vehicle_part');

        if ($vehiclePartCode === '' || $cashCost <= 0) {
            return ['data' => ['success' => false]];
        }

        try {
            $response = DB::transaction(function () use ($uid, $vehiclePartCode, $cashCost, $requestKey) {
                if ($requestKey !== null) {
                    $receipt = WorldActionReceipt::query()
                        ->where('uid', (string) $uid)
                        ->where('action', 'gift_vehicle_part')
                        ->where('request_key', $requestKey)
                        ->lockForUpdate()
                        ->first();
                    if ($receipt !== null) {
                        $response = is_array($receipt->response) ? $receipt->response : [];
                        $response['replayed'] = true;
                        return $response;
                    }
                }

                if (!addGiftboxItemLocked($uid, $vehiclePartCode, 1)) {
                    throw new \RuntimeException('Giftbox is unavailable.');
                }
                if (!UserResources::removeCash($uid, $cashCost)) {
                    throw new \RuntimeException('Not enough cash for vehicle part.');
                }

                $response = ['success' => true];
                if ($requestKey !== null) {
                    WorldActionReceipt::query()->create([
                        'uid' => (string) $uid,
                        'action' => 'gift_vehicle_part',
                        'request_key' => $requestKey,
                        'response' => $response,
                    ]);
                }

                return $response;
            });

            return ['data' => $response];
        } catch (\Throwable $e) {
            Logger::error('EquipmentWorldService', sprintf(
                'Vehicle part purchase failed: uid=%s reason=%s',
                $uid,
                $e->getMessage(),
            ));

            return ['data' => ['success' => false]];
        }
    }

    private static function garagePartResponse(bool $success, ?string $error = null, array $response = []): array
    {
        $data = array_merge([
            'success' => $success,
            'rewardUrl' => '',
        ], $response);
        if (!$success && $error !== null) {
            $data['error'] = $error;
        }

        return ['data' => $data];
    }

    private static function requestValue($source, string $key, $default = null)
    {
        if (is_object($source)) {
            return property_exists($source, $key) ? $source->{$key} : $default;
        }
        if (is_array($source)) {
            return array_key_exists($key, $source) ? $source[$key] : $default;
        }

        return $default;
    }

    private static function flashBoolean($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no'], true);
        }

        return $default;
    }

    private static function equipmentActionIdempotencyKey($request, string $action): ?string
    {
        $sequence = self::requestValue($request, 'sequence');
        $sequenceId = self::requestValue($request, 'sequenceID');
        if ($sequence === null || $sequenceId === null) {
            return null;
        }

        $params = self::requestValue($request, 'params', []);
        $payload = [
            'action' => $action,
            'sequence' => (string) $sequence,
            'sequenceID' => (string) $sequenceId,
            'params' => $params,
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return is_string($encoded) ? hash('sha256', $encoded) : null;
    }

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

        if ($action === ACTION_PLOW) {
            Logger::debug('PlowAudit', 'Equipment plow received', [
                'uid' => (string) $uid,
                'world_type' => getCurrentWorldType($uid),
                'requested_plots' => $plotCount,
            ]);
        }

        // A duplicate coordinate in one plow sweep is one visible plot, not
        // two independent actions.  Charge plows after their deduplicated
        // world write succeeds; the other equipment actions retain their
        // established request-count fuel handling below.
        $energyRemoved = false;
        if ($action !== ACTION_PLOW && $plotCount > 0) {
            $energyRemoved = UserResources::removeEnergy($uid, $plotCount);
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
        $harvestRewards = [];

        $modifiedObjects = [];
        $newObjects = [];
        $skippedPositions = [];
        // Keep the server-side audit trail positional.  Flash can issue many
        // small equipment requests while a player sweeps a large field; the
        // coordinates let us distinguish a rejected write from a plot that
        // was never submitted by the client.
        $acceptedPositions = [];
        $seenPlowPositions = [];

        foreach ($plotBundle as $key => $plotData) {
            if (is_array($plotData)) {
                $plotData = (object) $plotData;
            }

            if ($action === ACTION_PLOW) {
                $posX = isset($plotData->position) ? ($plotData->position->x ?? ($plotData->position['x'] ?? null)) : null;
                $posY = isset($plotData->position) ? ($plotData->position->y ?? ($plotData->position['y'] ?? null)) : null;

                if ($posX !== null && $posY !== null) {
                    $positionKey = $posX . ',' . $posY;
                    if (isset($seenPlowPositions[$positionKey])) {
                        // Preserve the response-array index expected by
                        // TEquipmentAction, but do not run a second local
                        // mutation for the same square.  Without this guard
                        // the first entry is queued for INSERT and the second
                        // is queued for UPDATE, causing the atomic write to
                        // roll back because that row does not yet exist.
                        $results[] = null;
                        $skippedPositions[] = [
                            'x' => $posX,
                            'y' => $posY,
                            'reason' => 'duplicate_plow_position',
                        ];
                        continue;
                    }
                    $seenPlowPositions[$positionKey] = true;
                }

                $plotObj = self::createPlotObject($plotData);
                if ($plotObj === null) {
                    $results[] = null;
                    $skippedPositions[] = ['reason' => 'invalid_plot_payload'];
                    continue;
                }

                $foundKey = findByPosition($positionIndex, $posX, $posY);

                if ($foundKey !== null) {
                    $existingPlot = $world["objectsArray"][$foundKey];
                    $existingState = getEffectivePlotState(
                        $existingPlot,
                        $uid,
                        $currentWorldType,
                    );

                    if (in_array($existingState, [PLOT_STATE_FALLOW, PLOT_STATE_WITHERED], true)) {
                        $existingPlot->state = PLOT_STATE_PLOWED;
                        if ($existingState === PLOT_STATE_WITHERED) {
                            // The persisted row still carries the old crop
                            // because withering is derived by Flash. Clearing
                            // it here prevents the crop from reappearing after
                            // the equipment plow is reloaded.
                            $existingPlot->itemName = null;
                            $existingPlot->plantTime = 0;
                        }
                        $modifiedObjects[] = $existingPlot;
                        $plowCount++;
                        $acceptedPositions[] = [
                            'x' => $posX,
                            'y' => $posY,
                            'object_id' => $existingPlot->id,
                            'operation' => 'update',
                            'source_state' => $existingState,
                        ];

                        $results[] = array(
                            "id" => $existingPlot->id,
                            "data" => array("id" => $existingPlot->id)
                        );
                    } elseif ($existingState === PLOT_STATE_PLOWED) {
                        // Flash may resend an acknowledged equipment sweep
                        // after reconnecting.  It has already applied this
                        // square locally, so acknowledge the replay without
                        // writing it again or treating it as a paid plow.
                        // A truthy result lets the client discard its stale
                        // queued operation; $plowCount deliberately remains
                        // unchanged so coins, XP, fuel, quests, and world
                        // score cannot be applied a second time.
                        $existingId = $world["objectsArray"][$foundKey]->id;
                        $results[] = array(
                            "id" => $existingId,
                            "data" => array("id" => $existingId, "stale" => true)
                        );
                        $skippedPositions[] = [
                            'x' => $posX,
                            'y' => $posY,
                            'reason' => 'already_plowed_replay',
                            'object_id' => $existingId,
                        ];
                    } else {
                        $results[] = null;
                        $skippedPositions[] = [
                            'x' => $posX,
                            'y' => $posY,
                            'reason' => 'plot_not_plowable',
                            'state' => $existingState,
                        ];
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
                        $skippedPositions[] = ['x' => $posX, 'y' => $posY, 'reason' => 'no_available_object_id'];
                        continue;
                    }

                    $plotObj->id = $newId;
                    $usedIds[$newId] = true;
                    $world["objectsArray"][] = $plotObj;
                    $newObjects[] = $plotObj;
                    $plowCount++;
                    $acceptedPositions[] = [
                        'x' => $posX,
                        'y' => $posY,
                        'object_id' => $plotObj->id,
                        'operation' => 'insert',
                    ];

                    $newKey = count($world["objectsArray"]) - 1;
                    $posKey = $posX . "," . $posY;
                    $positionIndex[$posKey] = $newKey;

                    $results[] = array(
                        "id" => $plotObj->id,
                        "data" => array("id" => $plotObj->id)
                    );
                } else {
                    $results[] = null;
                    $skippedPositions[] = ['x' => $posX, 'y' => $posY, 'reason' => 'missing_world_plot'];
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
                            $currentState = getEffectivePlotState(
                                $foundPlot,
                                $uid,
                                $currentWorldType,
                            );

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
                            // A null itemName is intentional for ordinary
                            // plots: it removes the crop sprite after harvest.
                            if (array_key_exists('itemName', $postHarvest)) {
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
                            $currentState = getEffectivePlotState(
                                $foundPlot,
                                $uid,
                                $currentWorldType,
                            );

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
                        $acceptedPositions[] = [
                            'x' => $posX,
                            'y' => $posY,
                            'fromState' => $foundPlot->state ?? null,
                        ];
                        $results[] = array(
                            "id" => $foundPlot->id,
                            "data" => array("id" => $foundPlot->id)
                        );
                    } elseif ($action !== ACTION_COMBINE) {
                        // Flash removes a plot for every truthy response entry.
                        // Never acknowledge an action which the authoritative
                        // state rejected (for example an immature or stale
                        // crop), otherwise it appears harvested until reload.
                        $results[] = null;
                        $skippedPositions[] = [
                            'x' => $posX,
                            'y' => $posY,
                            'state' => $foundPlot->state ?? null,
                        ];
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

        // Validate expansion-currency costs before changing the world object.
        // This closes the bulk-action equivalent of the single-action race:
        // an empty Jade/Coconuts balance cannot produce a persisted crop.
        $preflightWorldCurrencyDeltas = [];
        if ($plowCount > 0) {
            $preflightPlow = MarketTransactions::calculatePlowDeltas($plowCount, $currentWorldType);
            foreach ($preflightPlow['worldCurrencyDeltas'] as $unit => $delta) {
                $preflightWorldCurrencyDeltas[$unit] = ($preflightWorldCurrencyDeltas[$unit] ?? 0) + $delta;
            }
        }
        if ($plantCount > 0 && $itemName) {
            $preflightBuy = MarketTransactions::calculateBuyDeltas($itemName, $plantCount, null, $currentWorldType);
            foreach ($preflightBuy['worldCurrencyDeltas'] as $unit => $delta) {
                $preflightWorldCurrencyDeltas[$unit] = ($preflightWorldCurrencyDeltas[$unit] ?? 0) + $delta;
            }
        }
        if (!empty($harvestedItems)) {
            $preflightHarvest = MarketTransactions::calculateHarvestDeltas($harvestedItems, $currentWorldType);
            foreach ($preflightHarvest['worldCurrencyDeltas'] as $unit => $delta) {
                $preflightWorldCurrencyDeltas[$unit] = ($preflightWorldCurrencyDeltas[$unit] ?? 0) + $delta;
            }
        }

        $worldPersisted = WorldCurrencyService::canApplyDeltas($uid, $preflightWorldCurrencyDeltas);
        if (!$worldPersisted) {
            if ($energyRemoved) {
                UserResources::addEnergy($uid, $plotCount);
            }
            Logger::warning('EquipmentWorldService', 'Bulk action rejected for insufficient world currency', [
                'uid' => (string) $uid,
                'world_type' => $currentWorldType,
                'deltas' => $preflightWorldCurrencyDeltas,
            ]);
            $modifiedObjects = [];
            $newObjects = [];
        }

        if ($worldPersisted && (!empty($modifiedObjects) || !empty($newObjects))) {
            $worldPersisted = WorldPersistence::persistEquipmentChanges(
                $uid,
                $currentWorldType,
                $modifiedObjects,
                $newObjects,
            );
            if (!$worldPersisted) {
                Logger::error('EquipmentWorldService', "Failed to persist equipment changes for uid=$uid");
            }
        }

        if ($action === ACTION_PLOW) {
            Logger::debug('PlowAudit', $worldPersisted ? 'Equipment plow committed' : 'Equipment plow persistence failed', [
                'uid' => (string) $uid,
                'world_type' => $currentWorldType,
                'accepted_positions' => $acceptedPositions,
                'skipped_positions' => $skippedPositions,
            ]);
        }

        Logger::debug(
            'EquipmentWorldService',
            sprintf(
                'Bulk action uid=%s action=%s requested=%d accepted=%d skipped=%d',
                $uid,
                $action,
                $plotCount,
                count($modifiedObjects) + count($newObjects),
                count($skippedPositions),
            ),
            [
                'acceptedPositions' => $acceptedPositions,
                'skippedPositions' => $skippedPositions,
            ],
        );

        // Flash optimistically removes every plot included in its equipment
        // animation. If the authoritative write failed, acknowledge none of
        // them and do not award the corresponding resources or quest events.
        // A later reload must never be the first indication that a harvest
        // was rejected by the server.
        if (!$worldPersisted) {
            $plowCount = 0;
            $plantCount = 0;
            $harvestedItems = [];

            if ($action === ACTION_COMBINE) {
                $emptyResults = array_fill(0, $plotCount, null);
                $combineHarvestResults = $emptyResults;
                $combinePlowResults = $emptyResults;
                $combinePlaceResults = $emptyResults;
            } else {
                $results = array_fill(0, $plotCount, null);
            }
        }

        if ($worldPersisted && $action === ACTION_PLOW && $plowCount > 0) {
            UserResources::removeEnergy($uid, $plowCount);
        }

        // Bulk equipment actions take a different server route than individual
        // WorldService actions. Persist quest progress here only after the
        // affected plots have been saved, so a reload receives the same state.
        if ($worldPersisted) {
            if (!empty($harvestedItems)) {
                try {
                    $harvestRewards = (new MarketTransactions($uid))
                        ->grantHarvestRewardsBatch($harvestedItems);
                } catch (\Throwable $e) {
                    Logger::error('EquipmentWorldService', "Harvest reward error: " . $e->getMessage());
                }
            }

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
        $worldCurrencyDeltas = [];
        $plotActionXpDelta = 0;
        $resourceUpdateSucceeded = false;
        $masteryItemCounts = [];

        try {
            if ($plowCount > 0) {
                $plowDeltas = MarketTransactions::calculatePlowDeltas($plowCount, $currentWorldType);
                $totalGoldDelta += $plowDeltas['goldDelta'];
                $totalXpDelta += $plowDeltas['xpDelta'];
                foreach ($plowDeltas['worldCurrencyDeltas'] as $unit => $delta) {
                    $worldCurrencyDeltas[$unit] = ($worldCurrencyDeltas[$unit] ?? 0) + $delta;
                }
                $plotActionXpDelta += $plowDeltas['xpDelta'];
                Logger::debug('EquipmentWorldService', "Plow deltas: gold={$plowDeltas['goldDelta']}, xp={$plowDeltas['xpDelta']}, world=" . json_encode($plowDeltas['worldCurrencyDeltas']));
            }

            if ($plantCount > 0 && $itemName) {
                $buyDeltas = MarketTransactions::calculateBuyDeltas($itemName, $plantCount, null, $currentWorldType);
                $totalGoldDelta += $buyDeltas['goldDelta'];
                $totalXpDelta += $buyDeltas['xpDelta'];
                $totalCashDelta += $buyDeltas['cashDelta'];
                foreach ($buyDeltas['worldCurrencyDeltas'] as $unit => $delta) {
                    $worldCurrencyDeltas[$unit] = ($worldCurrencyDeltas[$unit] ?? 0) + $delta;
                }
                $plotActionXpDelta += $buyDeltas['xpDelta'];
                Logger::debug('EquipmentWorldService', "Buy deltas for $itemName x$plantCount: gold={$buyDeltas['goldDelta']}, xp={$buyDeltas['xpDelta']}, cash={$buyDeltas['cashDelta']}, world=" . json_encode($buyDeltas['worldCurrencyDeltas']));
            }

            if (!empty($harvestedItems)) {
                $harvestDeltas = MarketTransactions::calculateHarvestDeltas($harvestedItems, $currentWorldType);
                $totalGoldDelta += $harvestDeltas['goldDelta'];
                $totalXpDelta += $harvestDeltas['xpDelta'];
                foreach ($harvestDeltas['worldCurrencyDeltas'] as $unit => $delta) {
                    $worldCurrencyDeltas[$unit] = ($worldCurrencyDeltas[$unit] ?? 0) + $delta;
                }
                $masteryItemCounts = $harvestDeltas['itemCounts'];
                Logger::debug('EquipmentWorldService', "Harvest deltas: gold={$harvestDeltas['goldDelta']}, xp={$harvestDeltas['xpDelta']}, world=" . json_encode($harvestDeltas['worldCurrencyDeltas']));
            }

            Logger::debug('EquipmentWorldService', "Calling resource update: uid=$uid, gold=$totalGoldDelta, xp=$totalXpDelta, cash=$totalCashDelta, world=" . json_encode($worldCurrencyDeltas));
            $resourceUpdateSucceeded = (bool) DB::transaction(function () use ($uid, $worldCurrencyDeltas, $totalGoldDelta, $totalXpDelta, $totalCashDelta): bool {
                if (!WorldCurrencyService::applyDeltas($uid, $worldCurrencyDeltas, 'equipment.batch')) {
                    return false;
                }

                return (bool) UserResources::batchUpdate($uid, $totalGoldDelta, $totalXpDelta, $totalCashDelta);
            });
            Logger::debug('EquipmentWorldService', "resource update result: " . ($resourceUpdateSucceeded ? 'true' : 'false'));

            foreach ($masteryItemCounts as $masteryItemName => $count) {
                $itemData = getItemByName($masteryItemName, "db");
                if ($itemData) {
                    processMastery($uid, $itemData, $count);
                }
            }
        } catch (\Throwable $e) {
            Logger::error('EquipmentWorldService', 'Resource update failed: ' . $e->getMessage());
        }

        if ($worldPersisted && $resourceUpdateSucceeded && $plotActionXpDelta > 0) {
            $worldScore = awardPlotActionWorldScore($uid, $currentWorldType, $plotActionXpDelta);
            if ($worldScore !== null) {
                Logger::debug('EquipmentWorldService', "Awarded Emerald Valley score: uid=$uid delta=$plotActionXpDelta score=$worldScore");
            }
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

        if ($worldPersisted && !empty($harvestedItems)) {
            $actionDrops = recordHarvestBushelDrops($uid, array_count_values($harvestedItems));
            if (!empty($actionDrops)) {
                $data['metadata'] = ($data['metadata'] ?? []) + ['ActionDrops' => $actionDrops];
            }
        }

        if ($worldPersisted && !empty($harvestRewards)) {
            $data['metadata'] = ($data['metadata'] ?? []) + [
                'HarvestRewards' => $harvestRewards,
            ];
            $data['storageData'] = [
                GIFTBOX_STORAGE_KEY => buildGiftBoxStorageData($uid),
            ];
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
