<?php

namespace App\Support;

use App\Models\Item;

/**
 * The equipment classes that FarmVille's Garage renderer can reconstruct.
 *
 * Keep this allow-list shared by the storage write and world-load paths. The
 * Flash client supplies a class name in TStoreItem, but the server must use
 * the catalog class associated with the item code instead.
 */
final class GarageEquipmentCatalog
{
    /** @var list<string> */
    private const ALLOWED_CLASSES = [
        'Tractor',
        'RotatableTractor',
        'Seeder',
        'RotatableSeeder',
        'Harvester',
        'RotatableHarvester',
        'Combine',
        'RotatableCombine',
        'OrchardHarvester',
        'RotatableOrchardHarvester',
    ];

    private static ?array $maxPartsByCode = null;

    private static ?int $defaultMaxParts = null;

    /** Return whether a catalog item is safe to place in a Garage. */
    public static function isEquipment(mixed $item): bool
    {
        return is_array($item)
            && in_array($item['className'] ?? null, self::ALLOWED_CLASSES, true);
    }

    /** Match both the catalog identity and the code claimed by Flash. */
    public static function matches(mixed $item, string $itemCode): bool
    {
        return $itemCode !== ''
            && self::isEquipment($item)
            && ($item['code'] ?? null) === $itemCode;
    }

    /**
     * Return the persisted part count for one Garage entry.
     *
     * Early Garage rows predate the explicit `numParts` field. Those rows
     * represent a newly purchased vehicle, whose Flash key is `code:0`.
     */
    public static function entryParts(mixed $entry): int
    {
        $parts = is_object($entry)
            ? ($entry->numParts ?? null)
            : (is_array($entry) ? ($entry['numParts'] ?? null) : null);

        if (!is_numeric($parts) || (int) $parts < 0 || (string) (int) $parts !== (string) $parts) {
            return 0;
        }

        return (int) $parts;
    }

    /**
     * Convert Garage contents to the shape expected by GarageBuilding.loadObject.
     * Flash stores a dictionary keyed by `itemCode:numParts`, while the server
     * stores an array. `numParts` therefore has to cross the persistence boundary
     * explicitly; omitting it makes upgrades target a key that cannot exist.
     *
     * @return list<array{itemCode:string,numItem:int,numParts:int}>
     */
    public static function normalizeContents(mixed $contents): array
    {
        if (!is_array($contents)) {
            return [];
        }

        $normalized = [];
        foreach ($contents as $entry) {
            $code = is_object($entry)
                ? ($entry->itemCode ?? null)
                : (is_array($entry) ? ($entry['itemCode'] ?? null) : null);
            $count = is_object($entry)
                ? (int) ($entry->numItem ?? 0)
                : (is_array($entry) ? (int) ($entry['numItem'] ?? 0) : 0);

            if (!is_string($code) || $code === '' || $count <= 0) {
                continue;
            }

            $item = Item::findByCode($code);
            if (!self::isEquipment($item)) {
                continue;
            }

            $parts = self::entryParts($entry);
            $key = $code . ':' . $parts;
            if (!isset($normalized[$key])) {
                $normalized[$key] = [
                    'itemCode' => $code,
                    'numItem' => 0,
                    'numParts' => $parts,
                ];
            }
            $normalized[$key]['numItem'] += $count;
        }

        return array_values($normalized);
    }

    /** Return the maximum valid part count from the shipped equipment settings. */
    public static function maxParts(string $itemCode): ?int
    {
        self::loadPartLimits();

        return self::$maxPartsByCode[$itemCode] ?? self::$defaultMaxParts;
    }

    /** Load the same per-code level limits used by the Flash client. */
    private static function loadPartLimits(): void
    {
        if (self::$maxPartsByCode !== null) {
            return;
        }

        self::$maxPartsByCode = [];
        $path = function_exists('base_path')
            ? base_path('public/props/gameSettings.json')
            : dirname(__DIR__, 2) . '/public/props/gameSettings.json';
        if (!is_file($path)) {
            return;
        }

        $settings = json_decode((string) file_get_contents($path), true);
        $groups = $settings['equipmentData']['equipment'] ?? null;
        if (!is_array($groups)) {
            return;
        }

        foreach ($groups as $group) {
            $codes = $group['items']['code'] ?? null;
            $levels = $group['levels']['level'] ?? null;
            if ($codes === null || $levels === null) {
                continue;
            }
            $codes = is_array($codes) ? $codes : [$codes];
            $levels = isset($levels['@partsNeeded']) ? [$levels] : $levels;
            if (!is_array($levels)) {
                continue;
            }

            $max = null;
            foreach ($levels as $level) {
                if (is_array($level) && isset($level['@partsNeeded']) && is_numeric($level['@partsNeeded'])) {
                    $max = max((int) $level['@partsNeeded'], $max ?? 0);
                }
            }
            if ($max === null) {
                continue;
            }

            foreach ($codes as $code) {
                if (!is_string($code) || $code === '') {
                    continue;
                }
                if ($code === 'default') {
                    self::$defaultMaxParts = $max;
                } else {
                    self::$maxPartsByCode[$code] = $max;
                }
            }
        }
    }
}
