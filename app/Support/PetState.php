<?php

namespace App\Support;

/** The fields Pet.loadObject reads at the top level of a world object. */
final class PetState
{
    private const DAY_MS = 86400000;

    public static function initial(object $params, array $item, int $nowMs): ?\stdClass
    {
        if (($item['className'] ?? null) !== 'Pet'
            || ($item['subtype'] ?? null) !== 'pets'
            || (isset($params->petType) && (string) $params->petType !== (string) ($item['code'] ?? ''))) {
            return null;
        }

        $name = $params->petName ?? 'Pet';
        if (!is_string($name) || trim($name) === '' || strlen($name) > 64 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            return null;
        }

        $gender = $params->petGender ?? 0;
        if (!is_numeric($gender) || !in_array((int) $gender, [0, 1], true)) {
            return null;
        }

        return (object) [
            'petName' => trim($name),
            'petGender' => (int) $gender,
            'petLevel' => 0,
            'allowFollow' => true,
            'lastFedTime' => $nowMs,
            'kibbleFedCount' => 0,
            'isCash' => ($item['market'] ?? null) === 'cash',
            'hasUserSeenAdult' => false,
            'wasLevelResetOnLoad' => false,
            'levelStartTime' => 0,
            'isRunAway' => false,
        ];
    }

    public static function forFlash($components, ?string $itemName, int $plantTime): \stdClass
    {
        $components = is_array($components) ? (object) $components : $components;
        $saved = is_object($components) ? ($components->petState ?? null) : null;
        $saved = is_array($saved) ? (object) $saved : $saved;
        if (!is_object($saved)) {
            $saved = new \stdClass();
        }

        $defaults = [
            'petName' => 'Pet',
            'petGender' => 0,
            'petLevel' => 0,
            'allowFollow' => true,
            'lastFedTime' => $plantTime > 0 ? $plantTime : (int) (time() * 1000),
            'kibbleFedCount' => 0,
            'isCash' => str_ends_with((string) $itemName, '_cash'),
            'hasUserSeenAdult' => false,
            'wasLevelResetOnLoad' => false,
            'levelStartTime' => 0,
            'isRunAway' => false,
        ];

        return (object) array_replace($defaults, get_object_vars($saved));
    }

    /** Return a new state only when the original client would allow a daily feed. */
    public static function feed($components, ?string $itemName, int $plantTime, int $nowMs, string $foodItem): ?\stdClass
    {
        if ($plantTime <= 0 || $nowMs < $plantTime) {
            return null;
        }

        $state = self::forFlash($components, $itemName, $plantTime);
        if ($state->isRunAway || (int) $state->petLevel >= 5) {
            return null;
        }

        $daysSincePurchase = intdiv($nowMs - $plantTime, self::DAY_MS);
        $adult = (int) $state->petLevel > 0;
        if (!$adult) {
            $matured = self::mature($components, $itemName, $plantTime, $nowMs);
            if ($matured !== null) {
                $state = $matured;
                $adult = true;
            }
        }

        if ($foodItem !== ($adult ? 'consume_treat' : 'consume_kibble')) {
            return null;
        }

        $dayStart = $adult && (int) $state->levelStartTime > 0
            ? (int) $state->levelStartTime : $plantTime;
        $daysSinceStart = intdiv($nowMs - $dayStart, self::DAY_MS);
        $todayBeganAt = $dayStart + $daysSinceStart * self::DAY_MS;
        if ((int) $state->lastFedTime >= $todayBeganAt) {
            return null;
        }

        $state->lastFedTime = $nowMs;
        $state->kibbleFedCount = (int) $state->kibbleFedCount + 1;
        if (!$adult && $daysSincePurchase >= 14 && $state->kibbleFedCount >= 14) {
            $state->petLevel = 1;
            $state->kibbleFedCount = 0;
            $state->levelStartTime = $nowMs;
        } elseif ($adult) {
            $nextLevelTreats = [2 => 1, 3 => 3, 4 => 3, 5 => 7];
            $nextLevel = (int) $state->petLevel + 1;
            if (isset($nextLevelTreats[$nextLevel])
                && $state->kibbleFedCount >= $nextLevelTreats[$nextLevel]) {
                $state->petLevel = $nextLevel;
                $state->kibbleFedCount = 0;
                $state->levelStartTime = $nowMs;
            }
        }

        return $state;
    }

    /** Accept the client's adulthood event only after the original age/feed gate. */
    public static function mature($components, ?string $itemName, int $plantTime, int $nowMs): ?\stdClass
    {
        if ($plantTime <= 0 || $nowMs < $plantTime + 14 * self::DAY_MS) {
            return null;
        }

        $state = self::forFlash($components, $itemName, $plantTime);
        if ((int) $state->petLevel !== 0
            || (!$state->isCash && (int) $state->kibbleFedCount < 14)) {
            return null;
        }

        $state->petLevel = 1;
        $state->kibbleFedCount = 0;
        $state->levelStartTime = $nowMs;

        return $state;
    }

    /** Coin puppies run away only after missing an entire purchase-relative day. */
    public static function runaway($components, ?string $itemName, int $plantTime, int $nowMs): ?\stdClass
    {
        if ($plantTime <= 0 || $nowMs < $plantTime + self::DAY_MS) {
            return null;
        }

        $state = self::forFlash($components, $itemName, $plantTime);
        $today = $plantTime + intdiv($nowMs - $plantTime, self::DAY_MS) * self::DAY_MS;
        if ($state->isCash || (int) $state->petLevel > 0
            || (int) $state->kibbleFedCount >= 14
            || (int) $state->lastFedTime >= $today - self::DAY_MS) {
            return null;
        }

        $state->isRunAway = true;
        $state->allowFollow = false;

        return $state;
    }

    public static function rescue($components, ?string $itemName, int $plantTime, int $nowMs): ?\stdClass
    {
        $state = self::forFlash($components, $itemName, $plantTime);
        if (!$state->isRunAway || $state->isCash || (int) $state->petLevel > 0) {
            return null;
        }

        $state->isRunAway = false;
        $state->lastFedTime = $nowMs;

        return $state;
    }
}
