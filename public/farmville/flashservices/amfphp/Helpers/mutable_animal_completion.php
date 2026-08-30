<?php

use App\Models\WorldObject;

/**
 * Resolve a fully fed mutable-animal baby into the adult item it represents.
 *
 * Babies use the same TransformBuilding contract for both Complete Now and
 * the final bottle. Keeping the calculation here means the store and
 * transform actions cannot drift on gender, requirements, or DNA handling.
 */
final class MutableAnimalCompletion
{
    public static function forBaby(
        WorldObject $building,
        ?array $contents = null,
        bool $requireFull = false,
    ): ?array {
        if ($building->class_name !== 'MutableAnimalBaby') {
            return null;
        }

        $components = is_object($building->components)
            ? $building->components : new \stdClass();
        $mutableState = is_object($components->mutableAnimalState ?? null)
            ? $components->mutableAnimalState : new \stdClass();
        $dna = is_object($mutableState->dna ?? null)
            ? $mutableState->dna : new \stdClass();

        $adultName = match ((string) $building->item_name) {
            'sheeppen_lamb' => (($dna->G ?? 'F') === 'M' ? 'sheeppen_ram' : 'sheeppen_ewe'),
            'pigpen_baby' => (($dna->G ?? 'F') === 'M' ? 'pigpen_male' : 'pigpen_female'),
            default => null,
        };
        if ($adultName === null) {
            return null;
        }

        $adultItem = getItemByName($adultName, 'db');
        $babyItem = getItemByName((string) $building->item_name, 'db');
        $feedItem = getItemByName('bottle', 'db');
        if (!is_array($adultItem) || !is_array($babyItem) || !is_array($feedItem)) {
            return null;
        }

        $required = max(0, (int) ($babyItem['matsNeeded'] ?? 0));
        $feedCode = $feedItem['code'] ?? null;
        if ($required <= 0 || !is_string($feedCode) || $feedCode === '') {
            return null;
        }

        $contents ??= is_array($building->contents) ? $building->contents : [];
        $collected = 0;
        foreach ($contents as $content) {
            $code = is_object($content)
                ? ($content->itemCode ?? null)
                : ($content['itemCode'] ?? null);
            if ($code !== $feedCode) {
                continue;
            }

            $quantity = is_object($content)
                ? ($content->numItem ?? 0)
                : ($content['numItem'] ?? 0);
            $collected += max(0, (int) $quantity);
        }

        if ($requireFull && $collected < $required) {
            return null;
        }

        return [
            'cashCost' => max(0, $required - $collected)
                * max(0, (int) ($feedItem['cash'] ?? 0)),
            'finishedName' => $adultName,
            'finishedClassName' => $adultItem['className'] ?? 'MutableAnimal',
            'finishedState' => 'bare',
            'finishedReward' => $babyItem['finishedReward'] ?? null,
            // The adult must keep the baby's inherited pattern after reload.
            'mutableAnimalState' => $mutableState,
        ];
    }
}
