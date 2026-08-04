<?php

namespace App\Support;

/**
 * Canonical identities for the old crafting-cottage market entries.
 *
 * The market uses names such as "winery", while the Flash client and
 * crafting.xml use a separate, functional world-object name
 * ("craftingwinery").  Keeping that translation in one place prevents a
 * cottage from being charged as one item but persisted as an unusable one.
 */
final class CraftingCottages
{
    private const COTTAGES = [
        [
            'marketItem' => 'winery',
            'functionalItem' => 'craftingwinery',
            'craftType' => 'winery',
        ],
        [
            'marketItem' => 'bakery',
            'functionalItem' => 'craftingbakery',
            'craftType' => 'bakery',
        ],
        [
            'marketItem' => 'perfumery',
            'functionalItem' => 'craftingspa',
            'craftType' => 'perfumery',
        ],
        ['functionalItem' => 'craftingcreamery', 'craftType' => 'creamery'],
        ['functionalItem' => 'craftingfirework', 'craftType' => 'firework'],
        ['functionalItem' => 'craftingsauna', 'craftType' => 'sauna'],
        ['functionalItem' => 'craftingicecream', 'craftType' => 'icecream'],
        ['functionalItem' => 'craftingtailor', 'craftType' => 'tailor'],
        ['functionalItem' => 'craftingtoy', 'craftType' => 'toy'],
        ['functionalItem' => 'craftingcarousel', 'craftType' => 'carousel'],
        ['functionalItem' => 'craftingcandle', 'craftType' => 'candle'],
        ['functionalItem' => 'craftingperfume', 'craftType' => 'perfume'],
        ['functionalItem' => 'craftingcake', 'craftType' => 'cake'],
        ['functionalItem' => 'craftingjewelry', 'craftType' => 'jewelry'],
        ['functionalItem' => 'craftingdye', 'craftType' => 'dye'],
        ['functionalItem' => 'craftingink', 'craftType' => 'ink'],
        ['functionalItem' => 'craftingflower', 'craftType' => 'flower'],
        // The Craftshop is an older special cottage. Unlike Winery and
        // Bakery, its world-object names do not follow the "crafting" +
        // craft-type convention, so they must be listed explicitly.
        ['functionalItem' => 'craftingworkshop_finished', 'craftType' => 'craftshop'],
        ['functionalItem' => 'xalcraftingworkshop_finished', 'craftType' => 'craftshop'],
    ];

    public static function forMarketItem(?string $itemName): ?array
    {
        return self::find($itemName, 'marketItem');
    }

    public static function forFunctionalItem(?string $itemName): ?array
    {
        return self::find($itemName, 'functionalItem');
    }

    public static function forCraftType(?string $craftType): ?array
    {
        return self::find($craftType, 'craftType');
    }

    public static function craftTypeForItem(?string $itemName): ?string
    {
        $cottage = self::forFunctionalItem($itemName) ?? self::forMarketItem($itemName);

        if ($cottage !== null) {
            return $cottage['craftType'];
        }

        $itemName = strtolower(trim((string) $itemName));

        return str_starts_with($itemName, 'crafting')
            ? substr($itemName, strlen('crafting'))
            : null;
    }

    public static function functionalItemForCraftType(?string $craftType): ?string
    {
        return self::forCraftType($craftType)['functionalItem'] ?? null;
    }

    /**
     * Normalize a crafting-cottage placement to the object identity and state
     * the Flash client can render and open. Returns null for ordinary
     * placements.
     */
    public static function normalizeMarketPlacement(\stdClass $object): ?array
    {
        $cottage = self::forMarketItem($object->itemName ?? null)
            ?? self::forFunctionalItem($object->itemName ?? null);

        if ($cottage === null) {
            return null;
        }

        $object->itemName = $cottage['functionalItem'];
        $object->className = 'CraftingCottageBuilding';
        // CraftingCottageBuilding derives the image suffix (_0 through _4)
        // from its craft level. The stored state itself must stay "built".
        $object->state = 'built';

        return $cottage;
    }

    /** @return array<int, array{marketItem?: string, functionalItem: string, craftType: string}> */
    public static function all(): array
    {
        return self::COTTAGES;
    }

    private static function find(?string $value, string $field): ?array
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        foreach (self::COTTAGES as $cottage) {
            if (($cottage[$field] ?? null) === $value) {
                return $cottage;
            }
        }

        return null;
    }
}
