<?php

namespace App\Support;

use App\Models\PlayerMeta;
use App\Models\UserMeta;
use App\Models\WorldObject;
use Illuminate\Support\Facades\DB;

/**
 * Handles the server-side half of the Flash `use` world action.
 *
 * Flash updates some consumables optimistically, then sends the generic
 * WorldService.performAction("use") request. Keeping the storage decrement,
 * resource grant, and special-world effects here makes that transaction
 * independently testable without changing the legacy AMF entry point.
 */
final class ConsumableActionHandler
{
    public static function handle($playerObj, $request, $extraParams): array
    {
        $uid = $playerObj->getUid();
        $savedItem = $request->params[1] ?? null;
        $itemName = self::flashValue($savedItem, 'itemName');
        $itemCode = self::flashValue($savedItem, 'itemCode');
        if (!is_string($itemCode) || $itemCode === '') {
            $itemCode = self::flashValue($savedItem, 'code');
        }

        $item = is_string($itemName) && $itemName !== ''
            ? \getItemByName($itemName, 'db')
            : false;
        if (!is_array($item) && is_string($itemCode) && $itemCode !== '') {
            $item = \getItemByCode($itemCode);
        }
        if (is_object($item)) {
            $item = (array) $item;
        }
        if (is_array($item) && isset($item['code']) && $item['code'] !== '') {
            // The client-provided code is only a lookup hint. The catalogue
            // owns the code so a name/code mismatch cannot redirect rewards.
            $itemCode = (string) $item['code'];
        }
        if (!is_string($itemCode) || $itemCode === '') {
            return ['success' => false, 'consumed' => 0, 'error' => 'Consumable has no storage code.'];
        }

        $isGift = self::flashBoolean(self::flashValue($extraParams, 'isGift', true), true);
        $isFree = self::flashBoolean(self::flashValue($extraParams, 'isFree', false), false);
        $storageId = (int) self::flashValue($extraParams, 'storageId', \GIFTBOX_ID);
        $itemCount = (int) self::flashValue($extraParams, 'itemCount', 1);
        if ($itemCount <= 0) {
            return ['success' => false, 'consumed' => 0, 'error' => 'Invalid consumable quantity.'];
        }

        $targetUser = self::flashValue($extraParams, 'targetUser', $uid);
        $isOwnWorldUse = $targetUser === null
            || (string) $targetUser === ''
            || (string) $targetUser === (string) $uid;

        $storageIsPersisted = in_array($storageId, [
            \GIFTBOX_ID,
            (int) \GIFTBOX_STORAGE_KEY,
            \HOME_INVENTORY_ID,
            \PERSONAL_CRAFTING_INVENTORY_ID,
        ], true);
        // Market/free uses have no persisted source to consume. Preserve
        // their existing client-side behavior while making storage-backed
        // uses durable.
        if ($isFree || !$storageIsPersisted || (!$isGift && $storageId === \GIFTBOX_ID)) {
            return ['success' => true, 'consumed' => 0];
        }

        try {
            $transactionResult = DB::transaction(function () use (
                $uid,
                $item,
                $itemCode,
                $itemCount,
                $storageId,
                $isOwnWorldUse,
            ) {
                if (in_array($storageId, [\GIFTBOX_ID, (int) \GIFTBOX_STORAGE_KEY], true)) {
                    PlayerMeta::query()
                        ->where('uid', $uid)
                        ->where('meta_key', 'giftbox')
                        ->lockForUpdate()
                        ->first();
                    PlayerMeta::clearCache($uid, 'giftbox');
                } elseif ($storageId === \HOME_INVENTORY_ID) {
                    PlayerMeta::query()
                        ->where('uid', $uid)
                        ->where('meta_key', 'inventory_storage')
                        ->lockForUpdate()
                        ->first();
                    PlayerMeta::clearCache($uid, 'inventory_storage');
                }

                // Match the lock order used by the fuel path (storage first,
                // then user resources) to avoid deadlocks between concurrent
                // Giftbox actions.
                $userMeta = UserMeta::query()
                    ->where('uid', $uid)
                    ->lockForUpdate()
                    ->first();
                if (!$userMeta || !\consumeStoredItem($uid, $itemCode, $itemCount, $storageId)) {
                    return false;
                }

                \UserResources::invalidateCache($uid);
                $resourceDeltas = self::consumableResourceDeltas($item, $itemCount, $uid);
                if (($resourceDeltas['gold'] ?? 0) !== 0
                    || ($resourceDeltas['xp'] ?? 0) !== 0
                    || ($resourceDeltas['cash'] ?? 0) !== 0) {
                    $updated = \UserResources::batchUpdate(
                        $uid,
                        $resourceDeltas['gold'],
                        $resourceDeltas['xp'],
                        $resourceDeltas['cash'],
                    );
                    if (!$updated) {
                        throw new \RuntimeException('Player resource update was not applied.');
                    }
                }

                $unwitheredCount = 0;
                if ($isOwnWorldUse && ($item['name'] ?? '') === 'consume_unwither') {
                    $unwitheredCount = self::restoreWitheredPlots($uid);
                }

                return [
                    'consumed' => $itemCount,
                    'goldAdded' => $resourceDeltas['gold'],
                    'xpAdded' => $resourceDeltas['xp'],
                    'cashAdded' => $resourceDeltas['cash'],
                    'unwitheredCount' => $unwitheredCount,
                ];
            });
        } catch (\Throwable $e) {
            \Logger::error('World', sprintf(
                'Consumable use failed: uid=%s, code=%s, reason=%s',
                $uid,
                $itemCode,
                $e->getMessage(),
            ));
            if (in_array($storageId, [\GIFTBOX_ID, (int) \GIFTBOX_STORAGE_KEY], true)) {
                PlayerMeta::clearCache($uid, 'giftbox');
            } elseif ($storageId === \HOME_INVENTORY_ID) {
                PlayerMeta::clearCache($uid, 'inventory_storage');
            }
            \UserResources::invalidateCache($uid);
            $transactionResult = false;
        }

        if ($transactionResult === false) {
            return ['success' => false, 'consumed' => 0, 'error' => 'Consumable is no longer available.'];
        }

        return array_merge(
            ['success' => true],
            $transactionResult,
        );
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

    /** AMF booleans normally arrive as bools, but tolerate legacy strings. */
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

    /** Apply the server-side half of the Flash CUnwither consumable. */
    private static function restoreWitheredPlots($uid): int
    {
        $worldType = \getCurrentWorldType($uid);
        $worldId = \getWorldId($uid, $worldType);
        if (!$worldId) {
            return 0;
        }

        $unwitheredCount = 0;
        $plots = WorldObject::query()
            ->where('world_id', $worldId)
            ->where('class_name', 'Plot')
            ->where('state', \PLOT_STATE_PLANTED)
            ->whereNotNull('item_name')
            ->where('plant_time', '>', 0)
            ->where('deleted', false)
            ->get();

        foreach ($plots as $plot) {
            $itemData = \getItemByName($plot->item_name, 'db');
            if (!is_array($itemData) || !isset($itemData['growTime'])) {
                continue;
            }

            if (\getEffectivePlotState($plot, $uid, $worldType) !== \PLOT_STATE_WITHERED) {
                continue;
            }

            $updated = WorldObject::query()
                ->where('id', $plot->id)
                ->where('state', \PLOT_STATE_PLANTED)
                ->update([
                    'state' => \PLOT_STATE_GROWN,
                    'plant_time' => \calculateFullyGrownPlantTime((float) $itemData['growTime']),
                ]);
            $unwitheredCount += $updated;
        }

        \invalidateWorldCache($uid, $worldType);

        return $unwitheredCount;
    }

    /** Return authoritative balance deltas for reward consumables. */
    private static function consumableResourceDeltas($item, int $itemCount, $uid): array
    {
        if (!is_array($item)) {
            return ['gold' => 0, 'xp' => 0, 'cash' => 0];
        }

        $className = strtolower(trim((string) ($item['className'] ?? '')));
        if ($className === 'ccoins') {
            return [
                'gold' => self::positiveItemAmount($item['coins'] ?? 0) * $itemCount,
                'xp' => 0,
                'cash' => 0,
            ];
        }

        if ($className === 'cxp') {
            return [
                'gold' => 0,
                'xp' => self::positiveItemAmount($item['xp'] ?? 0) * $itemCount,
                'cash' => 0,
            ];
        }

        if ($className === 'ccash') {
            return [
                'gold' => 0,
                'xp' => 0,
                'cash' => self::positiveItemAmount($item['cash'] ?? 0) * $itemCount,
            ];
        }

        if ($className === 'cxpbook') {
            return [
                'gold' => 0,
                'xp' => self::xpBookAmount($uid, $itemCount),
                'cash' => 0,
            ];
        }

        return ['gold' => 0, 'xp' => 0, 'cash' => 0];
    }

    private static function positiveItemAmount($value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    /** CXPBook fills the XP gap to the next level for each book in sequence. */
    private static function xpBookAmount($uid, int $itemCount): int
    {
        $currentXp = \UserResources::getXp($uid);
        $xpToAdd = 0;

        for ($i = 0; $i < $itemCount; $i++) {
            $currentLevel = \UserResources::getLevelForXp($currentXp);
            $nextLevelXp = \UserResources::getXpForLevel($currentLevel + 1);
            $neededXp = max(0, min(\UserResources::XP_MAX, $nextLevelXp) - $currentXp);
            if ($neededXp <= 0) {
                break;
            }

            $xpToAdd += $neededXp;
            $currentXp += $neededXp;
        }

        return $xpToAdd;
    }
}
