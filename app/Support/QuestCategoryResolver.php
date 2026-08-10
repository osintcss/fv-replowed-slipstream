<?php

namespace App\Support;

/** Shared runtime and audit mapping from item data to Flash quest categories. */
final class QuestCategoryResolver
{
    /** @return list<string> */
    public static function categories(string $itemName, array $itemData = []): array
    {
        $categories = [];
        foreach (['categories', 'category', 'subtype'] as $field) {
            if (! isset($itemData[$field])) {
                continue;
            }
            $value = $itemData[$field];
            if (is_string($value)) {
                $categories[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $category) {
                    if (is_string($category)) {
                        $categories[] = $category;
                    }
                }
            }
        }

        $aliases = [
            'aloe' => ['AloeVera'],
            'peanuts' => ['Peanut'],
            // Verified against the imported item table. These FarmQuest
            // labels predate (or differ from) the internal crop keys.
            'licoriceplant' => ['Licorice'],
            'daffodils' => ['Daffodil'],
            'bellpepperyellow' => ['BellPeppers'],
            'strawberry' => ['Strawberries'],
            'bluebean' => ['BlueBeans'],
            'maracassflower' => ['MaracasFlowers'],
            'squirtingsunsflower' => ['SquirtingSunflower'],
            'blueberry' => ['Blueberries'],
            'blueberrychandler' => ['ChandlerBlueberry'],
            'blackberriesdarrow' => ['DarrowBlackberry'],
            'bluemorningglory' => ['MorningGlory'],
            'grapes' => ['Grape'],
            'pineapples' => ['Pineapple'],
            'sunflowers' => ['Sunflower'],
            'tulipred' => ['RedTulips'],
            'startree' => ['startrees'],
            'mustard' => ['mustardCategory'],
        ];
        $normalizedItemName = strtolower($itemName);
        foreach ($aliases[$normalizedItemName] ?? [] as $category) {
            $categories[] = $category;
        }
        if (str_contains($normalizedItemName, 'petrun')) {
            $categories[] = 'petRunHabitat';
        }
        if (str_contains($normalizedItemName, 'livestock')) {
            $categories[] = 'livestockHabitat';
        }
        if (str_contains($normalizedItemName, 'paddock')) {
            $categories[] = 'paddockHabitat';
        }
        if (str_contains($normalizedItemName, 'dinolab')) {
            $categories[] = 'dinoLab';
        }
        if (str_contains($normalizedItemName, 'animal_breeding_')) {
            $categories[] = 'animalBreedingAll';
            foreach ([
                'aviary' => 'aviaryHabitat',
                'wildlife' => 'wildlifeHabitat',
                'pasture' => 'pastureHabitat',
                'playpen' => 'playpenHabitat',
                'swimpond' => 'swimpondHabitat',
                'zoo' => 'zooHabitat',
            ] as $needle => $category) {
                if (str_contains($normalizedItemName, $needle)) {
                    $categories[] = $category;
                }
            }
        }

        return array_values(array_unique($categories));
    }

    public static function normalized(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }

    public static function taskCategory(string $type): string
    {
        return str_starts_with($type, 'all') ? substr($type, 3) : $type;
    }
}
