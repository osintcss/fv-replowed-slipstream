<?php

namespace App\Support;

use App\Models\WorldObject;

/** Server authority for the pet-only signed world actions. */
final class PetActionHandler
{
    private const TRICK_COOLDOWN_MS = 86400000;
    private const FETCH_REWARD = 'consume_kibble';

    public static function runaway($uid, string $worldType, int $petId): bool
    {
        if ($petId <= 0) {
            return false;
        }

        return (bool) WorldPersistence::mutateObject($uid, $worldType, $petId, static function (WorldObject $pet): bool {
            if ($pet->class_name !== 'Pet') {
                return false;
            }
            $state = PetState::runaway($pet->components, $pet->item_name, (int) $pet->plant_time, (int) getCurrentTimeMs());
            if ($state === null) {
                return false;
            }
            $components = self::components($pet);
            $components->petState = $state;
            $pet->components = $components;
            $pet->state = 'runaway';

            return true;
        });
    }

    public static function rescue($uid, string $worldType, int $petId): ?int
    {
        if ($petId <= 0) {
            return null;
        }

        $rescue = getItemByName('pet_rescue', 'db');
        $cost = is_array($rescue) ? (int) ($rescue['cash'] ?? 0) : 0;
        if ($cost <= 0 || $cost > 100) {
            return null;
        }

        $result = WorldPersistence::mutateObject($uid, $worldType, $petId, static function (WorldObject $pet) use ($uid, $cost): int|false {
            if ($pet->class_name !== 'Pet') {
                return false;
            }
            $now = (int) getCurrentTimeMs();
            $state = PetState::rescue($pet->components, $pet->item_name, (int) $pet->plant_time, $now);
            if ($state === null || !\UserResources::removeCash($uid, $cost)) {
                return false;
            }
            $components = self::components($pet);
            $components->petState = $state;
            $pet->components = $components;
            $pet->state = 'sit';

            return $now;
        });

        return is_int($result) ? $result : null;
    }

    public static function trick($uid, string $worldType, int $petId, $params): array
    {
        $name = is_object($params) ? ($params->trickName ?? null) : null;
        $ids = is_object($params) ? ($params->harvestObjectIds ?? null) : null;
        $failure = $name === 'fetch'
            ? ['fetchStatus' => false]
            : ['harvestLimitedAnimalStatus' => false];
        if ($petId <= 0 || !in_array($name, ['fetch', 'harvest'], true)) {
            return $failure;
        }

        $result = WorldPersistence::transaction($uid, $worldType, static function (int $worldId) use ($uid, $petId, $name, $ids) {
            $pet = WorldObject::query()->where('world_id', $worldId)->where('object_id', $petId)
                ->where('deleted', false)->lockForUpdate()->first();
            if ($pet === null || $pet->class_name !== 'Pet') {
                return false;
            }

            $state = PetState::forFlash($pet->components, $pet->item_name, (int) $pet->plant_time);
            $trick = self::configuredTrick((string) $pet->item_name, (int) $state->petLevel);
            $now = (int) getCurrentTimeMs();
            if ($state->isRunAway || $trick !== $name || $now - (int) ($state->lastTrickAt ?? 0) < self::TRICK_COOLDOWN_MS) {
                return false;
            }

            if ($name === 'fetch') {
                $reward = getItemByName(self::FETCH_REWARD, 'db');
                $code = is_array($reward) ? (string) ($reward['code'] ?? '') : '';
                if ($code === '' || !\addGiftboxItemLocked($uid, $code, 1, $uid)) {
                    return false;
                }
                $response = ['fetchStatus' => true, 'fetchItem' => self::FETCH_REWARD];
            } else {
                // Animal.harvest() sends the actual harvest transactions itself.
                // This callback only acknowledges a pet trick; never mint a second yield.
                if (!self::validHarvestTargets($worldId, $ids, $trick, (string) $pet->item_name)) {
                    return false;
                }
                $response = ['harvestLimitedAnimalStatus' => true];
            }

            $state->lastTrickAt = $now;
            $components = self::components($pet);
            $components->petState = $state;
            $pet->components = $components;
            $pet->save();

            return $response;
        });

        return is_array($result) ? $result : $failure;
    }

    private static function components(WorldObject $pet): \stdClass
    {
        $components = $pet->components;
        return is_object($components) ? $components : new \stdClass();
    }

    /** Resolve the level-five trick from the same pet family table Flash uses. */
    private static function configuredTrick(string $itemName, int $level): ?string
    {
        if ($level < 5) {
            return null;
        }
        static $families = null;
        if ($families === null) {
            $settings = json_decode((string) file_get_contents(public_path('props/gameSettings.json')), true);
            $families = $settings['settings']['petData']['pets']['pet'] ?? [];
        }
        foreach ($families as $family) {
            foreach ($family['item'] ?? [] as $variant) {
                if (($variant['@name'] ?? '') !== $itemName) {
                    continue;
                }
                foreach ($family['trick'] ?? [] as $trick) {
                    if ((int) ($trick['@level'] ?? 0) === $level) {
                        return $trick['@name'] ?? null;
                    }
                }
            }
        }
        return null;
    }

    private static function validHarvestTargets(int $worldId, $ids, string $trick, string $petName): bool
    {
        if ($trick !== 'harvest' || !is_array($ids) || count($ids) < 1 || count($ids) > 500) {
            return false;
        }
        $unique = [];
        foreach ($ids as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                return false;
            }
            $id = (int) $id;
            if ($id <= 0 || isset($unique[$id])) {
                return false;
            }
            $unique[$id] = true;
        }
        $type = str_starts_with($petName, 'terrier_') ? 'duck'
            : (str_starts_with($petName, 'oldenglishsheepdog_') ? 'sheep'
                : (str_starts_with($petName, 'australiancattle_') ? 'cow' : 'first20'));
        if ($type === 'first20' && count($unique) > 20) {
            return false;
        }
        $animals = WorldObject::query()->where('world_id', $worldId)->whereIn('object_id', array_keys($unique))
            ->where('deleted', false)->get();
        if ($animals->count() !== count($unique)) {
            return false;
        }
        foreach ($animals as $animal) {
            if (!in_array($animal->class_name, ['Animal', 'MutableAnimal'], true)
                || ($type !== 'first20' && !str_starts_with((string) $animal->item_name, $type))) {
                return false;
            }
        }
        return true;
    }
}
