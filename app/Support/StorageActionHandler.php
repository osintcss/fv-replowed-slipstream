<?php

namespace App\Support;

use App\Models\WorldActionReceipt;
use App\Models\WorldObject;

/**
 * Persists the storage side of TStoreItem/TInventoryStore actions.
 *
 * WorldService still owns the AMF action dispatch, while this handler owns
 * the storage-specific branches and their persistence contracts.  Keeping
 * the handler response-compatible is important because Flash uses the
 * completion fields returned for construction and expansion actions.
 */
final class StorageActionHandler
{
    public static function handle($playerObj, $request, $extraParams): array
    {
        $data = ['id' => 0, 'data' => ['id' => 0]];
        $buildingObj = $request->params[1];
        $uid = $playerObj->getUid();
        $storeWorldType = \getCurrentWorldType($uid);

        self::resolveActionObjectId($playerObj, $buildingObj, $storeWorldType);

        if (!$extraParams) {
            return $data;
        }

        $storedItemName = $extraParams->storedItemName ?? null;
        $storedItemCode = $extraParams->storedItemCode ?? null;
        $numToStore = (int) ($extraParams->numToStore ?? 1);
        $storageTarget = isset($extraParams->target) ? (int) $extraParams->target : null;
        $buildingId = $buildingObj->id ?? null;
        $buildingItemName = $buildingObj->itemName ?? null;
        $buildingClassName = $buildingObj->className ?? null;
        $isGiftboxStore = self::flashBoolean($extraParams->isGift ?? false, false)
            && $storageTarget !== \HOME_INVENTORY_ID;
        $hasNoStandaloneSource = (int) ($extraParams->resource ?? 0) <= 0
            && (int) ($extraParams->cameFromLocation ?? 0) <= 0;
        $authoritativeBuildingClassName = $buildingClassName;
        if (!$isGiftboxStore && $hasNoStandaloneSource && $buildingId) {
            $authoritativeBuildingClassName = WorldObject::query()
                ->where('world_id', \getWorldId($uid, $storeWorldType))
                ->where('object_id', (int) $buildingId)
                ->where('deleted', false)
                ->value('class_name') ?? $buildingClassName;
        }
        $isDirectGaragePurchase = !$isGiftboxStore
            && $hasNoStandaloneSource
            && $authoritativeBuildingClassName === 'GarageBuilding';
        $storeIdempotencyKey = ($isGiftboxStore || $isDirectGaragePurchase)
            ? self::actionIdempotencyKey($request, \ACTION_STORE)
            : null;

        // Market-stall expansion parts are not normal storage contents.
        // Flash has already added them to its FeatureExpansionState and sends
        // this store action only to make that progress durable.
        if ($buildingId
            && ($buildingClassName === 'MarketStallBuilding'
                || $buildingItemName === 'marketstall')
            && self::isMarketStallExpansionPart($storedItemName)) {
            $marketPartResult = self::storeMarketStallExpansionPart(
                $uid,
                $storeWorldType,
                (int) $buildingId,
                (string) $storedItemName,
                is_string($storedItemCode) ? $storedItemCode : null,
                $numToStore,
                self::flashBoolean($extraParams->isGift ?? false, false),
                $storeIdempotencyKey,
            );

            if ($marketPartResult === false) {
                return self::error('Could not store market-stall expansion part');
            }

            $data['data'] = $marketPartResult;
            return $data;
        }

        $isExpansionPartItem = false;
        $buildingItemData = null;
        $partData = null;

        if ($buildingId && $buildingItemName && $storedItemName) {
            $authoritativeBuilding = WorldObject::query()
                ->where('world_id', \getWorldId($uid, $storeWorldType))
                ->where('object_id', (int) $buildingId)
                ->where('deleted', false)
                ->first(['item_name', 'expansion_level']);
            $authoritativeBuildingName = $authoritativeBuilding->item_name
                ?? $buildingItemName;
            $buildingItemData = \getItemByName($authoritativeBuildingName, 'db');
            if ($buildingItemData && \hasExpandFeature($buildingItemData)) {
                $currentLevel = max(1, (int) ($authoritativeBuilding->expansion_level ?? 1));
                $partData = \isExpansionPart($buildingItemData, $currentLevel, $storedItemName);
                $isExpansionPartItem = $partData !== null;
            }
        }

        if ($isExpansionPartItem && $partData) {
            $expansionPartResult = self::storeExpansionPart(
                $uid,
                $storeWorldType,
                (int) $buildingId,
                (string) $storedItemName,
                is_string($storedItemCode) ? $storedItemCode : null,
                $numToStore,
                $isGiftboxStore,
                $storeIdempotencyKey,
            );

            if ($expansionPartResult === false) {
                return self::error('Could not store expansion part');
            }

            $data['data'] = $expansionPartResult;
            return $data;
        }

        // TInventoryStore identifies its destination with target=-2 and sends
        // the resource itself as the action object. TStoreItem has no target
        // and sends a real StorageBuilding. They must not share a path.
        $storeResult = $storageTarget === \HOME_INVENTORY_ID
            ? $playerObj->storeInHomeInventory($extraParams)
            : $playerObj->storeItem(
                $buildingObj,
                $extraParams,
                $isGiftboxStore,
                $storeIdempotencyKey,
            );

        if (!$storeResult) {
            return self::error('Could not store item');
        }

        // Quest progress is only recorded after the storage write succeeds,
        // so a rejected store neither removes an item nor advances a quest.
        if (empty($storeResult['replayed'])) {
            \trackStoreProgress(
                $uid,
                $storedItemCode ?? ($storeResult['itemCode'] ?? ''),
                max(1, $numToStore),
            );
        }

        $data['data'] = [
            'id' => $storeResult['id'] ?? 0,
            'success' => true,
        ];

        // A construction store becomes complete as soon as the final
        // configured part is committed. Player::storeItem performs that
        // transition inside the same persistence transaction; return the
        // normal Flash completion envelope so the client replaces its local
        // frame with the finished building instead of disabling its menu.
        $completion = is_array($storeResult['completion'] ?? null)
            ? $storeResult['completion'] : null;
        if ($completion !== null && empty($storeResult['replayed'])) {
            $reward = $completion['gift']
                ?? ($completion['finishedReward'] ?? null);
            if (is_string($reward) && $reward !== '') {
                \addGiftByName(
                    $uid,
                    $reward,
                    1,
                    $uid,
                    self::constructionRewardExtraData($reward),
                );
            }

            $data['data']['finishedName'] = $completion['finishedName'];
            $data['data']['finishedClassName'] = $completion['finishedClassName'];
            $data['data']['finishedState'] = $completion['finishedState'];
            $data['data']['gift'] = $reward;
        }

        $creditItems = [
            'shovel_item_01'           => 'InventoryCellar',
            'shovel_item_20'           => 'InventoryCellar',
            'shovel_itempack'          => 'InventoryCellar',
            'beehive_bee'              => 'beehive',
            'beehive_queen'            => 'beehive',
            'beehive_bee_5'            => 'beehive',
            'halloween_candy_5pack'    => 'halloweenBasket',
            'haitibackpack_itempack_5' => 'haitiBackpack',
        ];

        if ($storedItemName && isset($creditItems[$storedItemName])) {
            $featureName = $creditItems[$storedItemName];
            $itemData = \getItemByName($storedItemName, 'db');
            $creditCount = ($itemData && isset($itemData['count']))
                ? (int) $itemData['count'] : 1;

            \addFeatureCredit(
                $uid,
                \getCurrentWorldType($uid),
                $featureName,
                $creditCount * $numToStore,
            );

            if ($itemData) {
                $cashCost = (int) ($itemData['cash'] ?? 0);
                if ($cashCost > 0) {
                    \UserResources::removeCash($uid, $cashCost * $numToStore);
                }
            }
        }

        return $data;
    }

    /** Preserve the construction reward data contract used by Flash. */
    public static function constructionRewardExtraData(string $itemName): ?\stdClass
    {
        if ($itemName !== 'pigpen_male_light_green') {
            return null;
        }

        // This is the Pig Pen's documented starter Green Boar variant from
        // AnimalBreeding.xml. Its plain pattern is available at level one.
        return (object) [
            'N' => 'pigpen_male_light_green',
            'G' => 'M',
            'B' => (object) ['H' => ['66', '66'], 'S' => ['c', 'c'], 'V' => ['c', 'c']],
            'P' => (object) ['H' => ['66', '66'], 'S' => ['c', 'c'], 'V' => ['c', 'c'], 'T' => ['a']],
        ];
    }

    /** Return the legacy AMF failure envelope for a rejected store. */
    private static function error(string $message): array
    {
        return [
            'id' => 0,
            'data' => [
                'id' => 0,
                'success' => false,
                'error' => $message,
            ],
        ];
    }

    /** Read a named value from an AMF object or associative array. */
    private static function flashValue($source, string $key, $default = null)
    {
        if (is_object($source)) {
            return property_exists($source, $key) ? $source->{$key} : $default;
        }
        if (is_array($source)) {
            return array_key_exists($key, $source) ? $source[$key] : $default;
        }
        return $default;
    }

    /** AMF booleans may arrive as bools, numbers, or legacy strings. */
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

    /**
     * Flash retries a failed AMF batch with the same sequence and sequenceID.
     * Include the request payload so unrelated sessions cannot collide when
     * sequence numbers restart.
     */
    private static function actionIdempotencyKey($request, string $action): ?string
    {
        $sequence = self::flashValue($request, 'sequence');
        $sequenceId = self::flashValue($request, 'sequenceID');
        if ($sequence === null || $sequenceId === null) {
            return null;
        }

        $params = self::flashValue($request, 'params', []);
        $payload = [
            'action' => $action,
            'sequence' => (string) $sequence,
            'sequenceID' => (string) $sequenceId,
            'object' => is_array($params) ? ($params[1] ?? null) : null,
            'options' => is_array($params) ? ($params[2] ?? null) : null,
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($encoded)) {
            return null;
        }

        return hash('sha256', $encoded);
    }

    /** Keep object-ID actions compatible with Flash's same-batch temp IDs. */
    private static function resolveActionObjectId($playerObj, $object, string $worldType): int
    {
        $originalId = isset($object->id) && is_numeric($object->id) ? (int) $object->id : 0;
        $resolvedId = $playerObj->resolveFlashObjectId($object, $worldType);
        if ($resolvedId !== null && $resolvedId !== $originalId) {
            $object->id = $resolvedId;
            return $resolvedId;
        }

        return $originalId;
    }

    /** Identify the three Market Stall FeatureExpansionState resources. */
    private static function isMarketStallExpansionPart(?string $itemName): bool
    {
        return in_array($itemName, [
            'stall_awning',
            'stall_basket',
            'stall_pricecard',
        ], true);
    }

    /** Persist one or more market-stall expansion resources atomically. */
    private static function storeMarketStallExpansionPart(
        $uid,
        string $worldType,
        int $buildingId,
        string $itemName,
        ?string $itemCode,
        int $quantity,
        bool $isGift,
        ?string $idempotencyKey = null,
    ): array|false {
        $partItemData = \getItemByName($itemName, 'db');
        if (!$partItemData && is_string($itemCode) && $itemCode !== '') {
            $partItemData = \getItemByCode($itemCode);
        }
        if (!$partItemData || empty($partItemData['code'])) {
            return false;
        }

        $partCode = (string) $partItemData['code'];
        if (is_string($itemCode) && $itemCode !== '' && $itemCode !== $partCode) {
            return false;
        }
        $quantity = max(1, min(999, $quantity));
        $cashCost = !$isGift
            ? max(0, (int) ($partItemData['cash'] ?? 0)) * $quantity
            : 0;

        $result = WorldPersistence::transaction(
            $uid,
            $worldType,
            function (int $worldId) use (
                $uid,
                $buildingId,
                $itemName,
                $partCode,
                $quantity,
                $cashCost,
                $isGift,
                $idempotencyKey,
            ): array|false {
                $building = WorldObject::query()
                    ->where('world_id', $worldId)
                    ->where('object_id', $buildingId)
                    ->where('deleted', false)
                    ->lockForUpdate()
                    ->first();

                if ($building === null
                    || ($building->class_name !== 'MarketStallBuilding'
                        && $building->item_name !== 'marketstall')) {
                    return false;
                }

                if ($isGift && $idempotencyKey !== null) {
                    $receipt = WorldActionReceipt::query()
                        ->where('uid', (string) $uid)
                        ->where('action', 'store')
                        ->where('request_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($receipt !== null) {
                        $savedResponse = is_array($receipt->response) ? $receipt->response : [];
                        $savedResponse['replayed'] = true;
                        return $savedResponse;
                    }
                }

                if ($isGift && !\consumeGiftboxItemLocked($uid, $partCode, $quantity)) {
                    return false;
                }

                if ($cashCost > 0 && !\UserResources::removeCash($uid, $cashCost)) {
                    return false;
                }

                $parts = $building->expansion_parts;
                if (is_string($parts)) {
                    $decodedParts = json_decode($parts, true);
                    $parts = is_array($decodedParts) ? $decodedParts : [];
                }
                if (is_object($parts)) {
                    $parts = get_object_vars($parts);
                }
                if (!is_array($parts)) {
                    $parts = [];
                }

                $currentCount = max(0, (int) ($parts[$partCode] ?? 0));
                $parts[$partCode] = min(999, $currentCount + $quantity);
                $building->expansion_parts = (object) $parts;
                $building->save();

                $result = [
                    'id' => $buildingId,
                    'success' => true,
                    'storedItemName' => $itemName,
                    'storedItemCode' => $partCode,
                    'quantity' => $quantity,
                ];

                if ($isGift && $idempotencyKey !== null) {
                    WorldActionReceipt::query()->create([
                        'uid' => (string) $uid,
                        'action' => 'store',
                        'request_key' => $idempotencyKey,
                        'response' => $result,
                    ]);
                }

                return $result;
            },
        );

        return $result === false ? false : $result;
    }

    /** Persist a generic expansion part and consume a Giftbox source atomically. */
    private static function storeExpansionPart(
        $uid,
        string $worldType,
        int $buildingId,
        string $itemName,
        ?string $itemCode,
        int $quantity,
        bool $isGift,
        ?string $idempotencyKey = null,
    ): array|false {
        $partItemData = \getItemByName($itemName, 'db');
        if (!$partItemData && is_string($itemCode) && $itemCode !== '') {
            $partItemData = \getItemByCode($itemCode);
        }
        if (!is_array($partItemData) || empty($partItemData['code'])) {
            return false;
        }

        $partCode = (string) $partItemData['code'];
        if (is_string($itemCode) && $itemCode !== '' && $itemCode !== $partCode) {
            return false;
        }

        $quantity = max(1, min(999, $quantity));
        $cashCostPerItem = !$isGift
            ? max(0, (int) ($partItemData['cash'] ?? 0))
            : 0;

        return WorldPersistence::transaction(
            $uid,
            $worldType,
            function (int $worldId) use (
                $uid,
                $buildingId,
                $itemName,
                $partCode,
                $quantity,
                $cashCostPerItem,
                $isGift,
                $idempotencyKey,
            ): array|false {
                $building = WorldObject::query()
                    ->where('world_id', $worldId)
                    ->where('object_id', $buildingId)
                    ->where('deleted', false)
                    ->lockForUpdate()
                    ->first();

                if ($building === null) {
                    return false;
                }

                if ($isGift && $idempotencyKey !== null) {
                    $receipt = WorldActionReceipt::query()
                        ->where('uid', (string) $uid)
                        ->where('action', 'store')
                        ->where('request_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($receipt !== null) {
                        $savedResponse = is_array($receipt->response) ? $receipt->response : [];
                        $savedResponse['replayed'] = true;
                        return $savedResponse;
                    }
                }

                $buildingItemData = \getItemByName((string) $building->item_name, 'db');
                $currentLevel = max(1, (int) ($building->expansion_level ?? 1));
                $partData = is_array($buildingItemData) && \hasExpandFeature($buildingItemData)
                    ? \isExpansionPart($buildingItemData, $currentLevel, $itemName)
                    : null;
                if (!is_object($partData) || !isset($partData->need)) {
                    return false;
                }

                $parts = $building->expansion_parts;
                if (is_string($parts)) {
                    $decodedParts = json_decode($parts, true);
                    $parts = is_array($decodedParts) ? $decodedParts : [];
                }
                if (is_object($parts)) {
                    $parts = get_object_vars($parts);
                }
                if (!is_array($parts)) {
                    $parts = [];
                }

                $currentCount = max(0, (int) ($parts[$partCode] ?? 0));
                $needed = max(1, (int) $partData->need);
                $quantityToApply = min($quantity, max(0, $needed - $currentCount));
                if ($quantityToApply <= 0) {
                    return false;
                }

                if ($isGift && !\consumeGiftboxItemLocked($uid, $partCode, $quantityToApply)) {
                    return false;
                }

                $cashCost = $cashCostPerItem * $quantityToApply;
                if ($cashCost > 0 && !\UserResources::removeCash($uid, $cashCost)) {
                    return false;
                }

                $parts[$partCode] = $currentCount + $quantityToApply;
                $candidate = (object) [
                    'expansionLevel' => $currentLevel,
                    'expansionParts' => (object) $parts,
                ];
                $completed = is_array($buildingItemData)
                    && \checkExpansionComplete($candidate, $buildingItemData);
                if ($completed) {
                    $building->expansion_level = $currentLevel + 1;
                    $parts = [];
                }
                $building->expansion_parts = (object) $parts;
                $building->save();

                $result = [
                    'id' => $buildingId,
                    'success' => true,
                    'storedItemName' => $itemName,
                    'storedItemCode' => $partCode,
                    'quantity' => $quantityToApply,
                    'expansionLevel' => (int) $building->expansion_level,
                ];

                if ($isGift && $idempotencyKey !== null) {
                    WorldActionReceipt::query()->create([
                        'uid' => (string) $uid,
                        'action' => 'store',
                        'request_key' => $idempotencyKey,
                        'response' => $result,
                    ]);
                }

                return $result;
            },
        );
    }
}
