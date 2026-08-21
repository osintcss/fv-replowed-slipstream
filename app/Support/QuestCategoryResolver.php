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
            'blackberriesdarrow' => ['DarrowBlackberry', 'DarrowBlackberries'],
            'bluemorningglory' => ['MorningGlory'],
            'grapes' => ['Grape'],
            'pineapples' => ['Pineapple'],
            'greenstrawberries' => ['GreenStrawberry'],
            'sunflowers' => ['Sunflower'],
            'tulipred' => ['RedTulips'],
            'startree' => ['startrees'],
            // Chicken coops are harvestable animal-storage buildings. Quest
            // definitions use `allCoop`, while their item keys include the
            // coop family and (for seasonal variants) an optional prefix.
            'chickencoop' => ['Coop'],
            'mustard' => ['mustardCategory'],
            'redflamingo' => ['FlamingoFlower'],
            'pinkrosetree' => ['PinkRoses'],
            'rosepink' => ['PinkRoses'],
            'squashpetitpan' => ['PetiPanSquash'],
            'cornergasstation' => ['cornerGasStations'],
            'cranberryamerican' => ['CoveCranberry'],
            'buttersugarcorn' => ['ButterAndSugarCorn'],
        ];
        $normalizedItemName = strtolower($itemName);
        foreach ($aliases[$normalizedItemName] ?? [] as $category) {
            $categories[] = $category;
        }
        if (str_contains($normalizedItemName, 'petrun')) {
            $categories[] = 'petRunHabitat';
        }
        if (str_contains($normalizedItemName, 'chickencoop')) {
            $categories[] = 'Coop';
        }
        // FarmQuest uses one `allstartrees` objective for the entire Star
        // Tree family.  The item table includes variants such as
        // `shootingstartree`, which do not exactly equal the base key.
        if (str_contains($normalizedItemName, 'startree')) {
            $categories[] = 'startrees';
        }
        if (str_contains($normalizedItemName, 'livestock')) {
            $categories[] = 'livestockHabitat';
        }
        if (str_contains($normalizedItemName, 'paddock')) {
            $categories[] = 'paddockHabitat';
        }
        if (str_contains($normalizedItemName, 'tulip')) {
            $categories[] = 'Tulips';
        }
        if (str_contains($normalizedItemName, 'orchard')) {
            $categories[] = 'orchardCategory';
        }
        if (str_contains($normalizedItemName, 'horse')) {
            $categories[] = 'horses';
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
                'swimhole' => 'swimpondHabitat',
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

    /** @return list<string> */
    public static function recipeCategories(string $recipeName): array
    {
        $baseName = preg_replace('/(?:_CS)?recipe$/i', '', $recipeName) ?: '';
        $categories = [$recipeName, $baseName];

        if (str_starts_with($baseName, 'consume_')) {
            $categories[] = substr($baseName, strlen('consume_'));
        }

        // Legacy Craft Shop names sometimes put a decoration family before
        // its product name (fence_dainty), while quests use the product
        // category (Daintyfence).
        if (preg_match('/^(fence|deco)_(.+)$/i', $baseName, $matches)) {
            $categories[] = $matches[2].'_'.$matches[1];
        }
        if (str_starts_with($baseName, 'fence') && strlen($baseName) > strlen('fence')) {
            $categories[] = substr($baseName, strlen('fence')).'fence';
        }

        // Quest labels are not consistent about singular/plural product
        // names. Supplying the harmless plural counterpart lets an Arborist
        // recipe satisfy an `allArborists` requirement.
        foreach ($categories as $category) {
            if ($category !== '' && !str_ends_with($category, 's')) {
                $categories[] = $category.'s';
            }
        }

        return array_values(array_unique(array_filter($categories)));
    }
}
