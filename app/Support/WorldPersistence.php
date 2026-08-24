<?php

namespace App\Support;

use App\Models\WorldObject;
use App\Models\UserWorld;
use Illuminate\Support\Facades\DB;

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

    /** Persist a message-sign object and its world-level message state together. */
    public static function createMessageSign($uid, string $worldType, object $sign, array $messageManager): bool
    {
        return self::transaction($uid, $worldType, function (int $worldId) use ($sign, $messageManager): bool {
            $data = WorldObject::fromFlashObject($sign, $worldId);
            $now = now();
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            WorldObject::query()->upsert(
                [$data],
                ['world_id', 'object_id'],
                array_values(array_diff(array_keys($data), ['world_id', 'object_id', 'created_at']))
            );

            return UserWorld::query()
                ->where('id', $worldId)
                ->update(['messageManager' => serialize($messageManager)]) === 1;
        }) === true;
    }

    /** Delete a message sign and update its world-level message state atomically. */
    public static function deleteMessageSign($uid, string $worldType, int $signId, array $messageManager): bool
    {
        return self::transaction($uid, $worldType, function (int $worldId) use ($signId, $messageManager): bool {
            $deleted = WorldObject::query()
                ->where('world_id', $worldId)
                ->where('object_id', $signId)
                ->where('class_name', 'MessageSign')
                ->where('deleted', false)
                ->update(['deleted' => true]);

            if ($deleted !== 1) {
                return false;
            }

            return UserWorld::query()
                ->where('id', $worldId)
                ->update(['messageManager' => serialize($messageManager)]) === 1;
        }) === true;
    }

    /** Atomically persist all effects of one equipment request. */
    public static function persistEquipmentChanges($uid, string $worldType, array $modifiedObjects, array $newObjects): bool
    {
        return self::transaction($uid, $worldType, function (int $worldId) use ($modifiedObjects, $newObjects): bool {
            foreach ($modifiedObjects as $object) {
                [$positionX, $positionY] = \App\Helpers\ObjectHelper::getPosition($object);
                if ($positionX === null || $positionY === null) {
                    throw new \RuntimeException('Equipment object has no position');
                }

                $updated = WorldObject::query()
                    ->where('world_id', $worldId)
                    ->where('position_x', (int) $positionX)
                    ->where('position_y', (int) $positionY)
                    ->where('deleted', false)
                    ->update([
                        'state' => $object->state ?? null,
                        'item_name' => $object->itemName ?? null,
                        'plant_time' => \sanitizeNumericValue($object->plantTime ?? 0),
                        'is_jumbo' => (bool) ($object->isJumbo ?? false),
                    ]);

                if ($updated !== 1) {
                    throw new \RuntimeException(sprintf(
                        'Equipment update affected %d rows at (%d,%d)',
                        $updated,
                        $positionX,
                        $positionY,
                    ));
                }
            }

            if ($newObjects !== []) {
                $now = now();
                $records = array_map(static function (object $object) use ($worldId, $now): array {
                    $record = WorldObject::fromFlashObject($object, $worldId);
                    $record['created_at'] = $now;
                    $record['updated_at'] = $now;

                    return $record;
                }, $newObjects);
                WorldObject::insert($records);
            }

            return true;
        }) === true;
    }

    /** Lock, mutate, and save one active world object through the shared boundary. */
    public static function mutateObject($uid, string $worldType, int $objectId, callable $mutation): mixed
    {
        return self::transaction($uid, $worldType, function (int $worldId) use ($objectId, $mutation): mixed {
            $object = WorldObject::query()
                ->where('world_id', $worldId)
                ->where('object_id', $objectId)
                ->where('deleted', false)
                ->lockForUpdate()
                ->first();

            if ($object === null) {
                return false;
            }

            $result = $mutation($object);
            if ($result === false) {
                return false;
            }

            $object->save();

            return $result ?? true;
        });
    }

    /**
     * Shared transaction/cache boundary for specialised object mutations.
     * The callback receives the resolved world ID and must return false to
     * abort without invalidating the request-local world cache.
     */
    public static function transaction($uid, string $worldType, callable $mutation): mixed
    {
        $worldId = \getWorldId($uid, $worldType);
        if ($worldId === null) {
            return false;
        }

        try {
            $result = DB::transaction(static fn () => $mutation($worldId));
            if ($result !== false) {
                \invalidateWorldCache($uid, $worldType);
            }

            return $result;
        } catch (\Throwable $exception) {
            \Logger::error('World', sprintf(
                'World persistence transaction failed: uid=%s type=%s error=%s',
                $uid,
                $worldType,
                $exception->getMessage(),
            ));

            return false;
        }
    }
}
