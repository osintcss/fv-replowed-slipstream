<?php

namespace App\Support;

use App\Models\WorldObject;

/**
 * Explicit boundary for durable world-object mutations made by legacy Flash
 * services.  A full snapshot replacement is intentionally separate from
 * targeted writes because it can overwrite a concurrent action.
 */
final class WorldPersistence
{
    public static function replaceSnapshot($uid, string $worldType, array $world, string $reason): bool
    {
        if (!isset($world['objectsArray']) || !is_array($world['objectsArray'])) {
            \Logger::error('World', "Refused snapshot replacement without objects: uid={$uid} type={$worldType} reason={$reason}");

            return false;
        }

        \Logger::debug('World', "Replacing full world snapshot: uid={$uid} type={$worldType} reason={$reason}");

        return \saveWorld($uid, $worldType, $world);
    }

    public static function updateObject($uid, string $worldType, object $object): bool
    {
        $worldId = \getWorldId($uid, $worldType);
        if ($worldId === null) {
            return false;
        }

        $updated = \updateWorldObjectFull($worldId, $object);
        if ($updated) {
            \invalidateWorldCache($uid, $worldType);
        }

        return $updated;
    }

    public static function deleteObject($uid, string $worldType, int $objectId): bool
    {
        $worldId = \getWorldId($uid, $worldType);
        if ($worldId === null || $objectId <= 0) {
            return false;
        }

        $affected = WorldObject::query()
            ->where('world_id', $worldId)
            ->where('object_id', $objectId)
            ->where('deleted', false)
            ->update(['deleted' => true]);

        if ($affected === 1) {
            \invalidateWorldCache($uid, $worldType);
        }

        return $affected === 1;
    }

    public static function deleteAtPosition($uid, string $worldType, int $positionX, int $positionY): bool
    {
        $worldId = \getWorldId($uid, $worldType);
        if ($worldId === null) {
            return false;
        }

        $deleted = \deleteWorldObjectByPosition($worldId, $positionX, $positionY);
        if ($deleted) {
            \invalidateWorldCache($uid, $worldType);
        }

        return $deleted;
    }

    public static function insertObject($uid, string $worldType, object $object): bool
    {
        $worldId = \getWorldId($uid, $worldType);
        if ($worldId === null) {
            return false;
        }

        $inserted = \insertWorldObject($worldId, $object);
        if ($inserted) {
            \invalidateWorldCache($uid, $worldType);
        }

        return $inserted;
    }

    public static function updateByPosition($uid, string $worldType, array $objects): bool
    {
        $worldId = \getWorldId($uid, $worldType);
        if ($worldId === null) {
            return false;
        }

        $updated = \updateWorldObjectsByPosition($worldId, $objects);
        if ($updated) {
            \invalidateWorldCache($uid, $worldType);
        }

        return $updated;
    }

    public static function updateConditionally($uid, string $worldType, array $changes): array
    {
        $worldId = \getWorldId($uid, $worldType);
        if ($worldId === null) {
            return ['success' => false, 'updated' => 0, 'skipped' => count($changes), 'updatedObjectIds' => []];
        }

        $result = \updateWorldObjectsConditionally($worldId, $changes);
        if (!empty($result['success'])) {
            \invalidateWorldCache($uid, $worldType);
        }

        return $result;
    }
}
