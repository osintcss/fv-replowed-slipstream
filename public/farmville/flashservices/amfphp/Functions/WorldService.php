<?php

require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";
require_once AMFPHP_ROOTPATH . "Helpers/constants.php";
require_once AMFPHP_ROOTPATH . "Helpers/logger.php";
require_once AMFPHP_ROOTPATH . "Helpers/quest_progress.php";
require_once AMFPHP_ROOTPATH . "Helpers/crafting_helper.php";
require_once AMFPHP_ROOTPATH . "Helpers/mutable_animal_completion.php";

use App\Helpers\JsonHelper;
use App\Models\PlayerMeta;
use App\Models\UserMeta;
use App\Models\WorldObject;
use App\Support\CraftingCottages;
use App\Support\WorldPersistence;

class WorldService
{
    const LOG = 'World';
    private const PIGPEN_TRUFFLE_COOLDOWN_SECONDS = 172800;

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

    /**
     * Consume a stored item and persist its player-resource effect together.
     *
     * TUseConsumable removes Giftbox items optimistically in Flash and then
     * calls this action.  Resolve the item's reward from the server catalogue
     * and keep the inventory decrement plus the balance update in one
     * transaction so a retry cannot spend an item without granting its value.
     */
    private static function consumeUseItem($playerObj, $request, $extraParams): array
    {
        $uid = $playerObj->getUid();
        $savedItem = $request->params[1] ?? null;
        $itemName = self::flashValue($savedItem, 'itemName');
        $itemCode = self::flashValue($savedItem, 'itemCode');
        if (!is_string($itemCode) || $itemCode === '') {
            $itemCode = self::flashValue($savedItem, 'code');
        }

        $item = is_string($itemName) && $itemName !== ''
            ? getItemByName($itemName, 'db')
            : false;
        if (!is_array($item) && is_string($itemCode) && $itemCode !== '') {
            $item = getItemByCode($itemCode);
        }
        if (is_object($item)) {
            $item = (array) $item;
        }
        if (is_array($item) && isset($item['code']) && $item['code'] !== '') {
            // The client-provided code is only a lookup hint.  The catalogue
            // owns the code so an item name/code mismatch cannot redirect the
            // reward to a different stored item.
            $itemCode = (string) $item['code'];
        }
        if (!is_string($itemCode) || $itemCode === '') {
            return ['success' => false, 'consumed' => 0, 'error' => 'Consumable has no storage code.'];
        }

        $isGift = self::flashBoolean(self::flashValue($extraParams, 'isGift', true), true);
        $isFree = self::flashBoolean(self::flashValue($extraParams, 'isFree', false), false);
        $storageId = (int) self::flashValue($extraParams, 'storageId', GIFTBOX_ID);
        $itemCount = (int) self::flashValue($extraParams, 'itemCount', 1);
        if ($itemCount <= 0) {
            return ['success' => false, 'consumed' => 0, 'error' => 'Invalid consumable quantity.'];
        }

        $targetUser = self::flashValue($extraParams, 'targetUser', $uid);
        $isOwnWorldUse = $targetUser === null
            || (string) $targetUser === ''
            || (string) $targetUser === (string) $uid;

        $storageIsPersisted = in_array($storageId, [
            GIFTBOX_ID,
            (int) GIFTBOX_STORAGE_KEY,
            HOME_INVENTORY_ID,
            PERSONAL_CRAFTING_INVENTORY_ID,
        ], true);
        // Market/free uses have no persisted source to consume.  Preserve
        // their existing client-side behavior while making storage-backed
        // uses durable.
        if ($isFree || !$storageIsPersisted || (!$isGift && $storageId === GIFTBOX_ID)) {
            return ['success' => true, 'consumed' => 0];
        }

        try {
            $transactionResult = \DB::transaction(function () use (
                $uid,
                $item,
                $itemCode,
                $itemCount,
                $storageId,
                $isOwnWorldUse,
            ) {
                if (in_array($storageId, [GIFTBOX_ID, (int) GIFTBOX_STORAGE_KEY], true)) {
                    PlayerMeta::query()
                        ->where('uid', $uid)
                        ->where('meta_key', 'giftbox')
                        ->lockForUpdate()
                        ->first();
                    PlayerMeta::clearCache($uid, 'giftbox');
                } elseif ($storageId === HOME_INVENTORY_ID) {
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
                if (!$userMeta || !consumeStoredItem($uid, $itemCode, $itemCount, $storageId)) {
                    return false;
                }

                UserResources::invalidateCache($uid);
                $resourceDeltas = self::consumableResourceDeltas($item, $itemCount, $uid);
                if (($resourceDeltas['gold'] ?? 0) !== 0
                    || ($resourceDeltas['xp'] ?? 0) !== 0
                    || ($resourceDeltas['cash'] ?? 0) !== 0) {
                    $updated = UserResources::batchUpdate(
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
            Logger::error(self::LOG, sprintf(
                'Consumable use failed: uid=%s, code=%s, reason=%s',
                $uid,
                $itemCode,
                $e->getMessage(),
            ));
            if (in_array($storageId, [GIFTBOX_ID, (int) GIFTBOX_STORAGE_KEY], true)) {
                PlayerMeta::clearCache($uid, 'giftbox');
            } elseif ($storageId === HOME_INVENTORY_ID) {
                PlayerMeta::clearCache($uid, 'inventory_storage');
            }
            UserResources::invalidateCache($uid);
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

    /**
     * Apply the server-side half of the Flash CUnwither consumable.
     *
     * CUnwither updates plots optimistically in Flash, but its generic
     * WorldService.performAction("use") request is the durable operation.
     * Planted plots remain in that state in storage until their grow and
     * wither windows have elapsed, so restore only those eligible plots.
     */
    private static function restoreWitheredPlots($uid): int
    {
        $worldType = getCurrentWorldType($uid);
        $worldId = getWorldId($uid, $worldType);
        if (!$worldId) {
            return 0;
        }

        $currentTimeMs = getCurrentTimeMs();
        $unwitheredCount = 0;
        $plots = WorldObject::query()
            ->where('world_id', $worldId)
            ->where('class_name', 'Plot')
            ->where('state', PLOT_STATE_PLANTED)
            ->whereNotNull('item_name')
            ->where('plant_time', '>', 0)
            ->where('deleted', false)
            ->get();

        foreach ($plots as $plot) {
            $itemData = getItemByName($plot->item_name, 'db');
            if (!is_array($itemData) || !isset($itemData['growTime'])) {
                continue;
            }

            $growTimeMs = calculateGrowTimeMs((float) $itemData['growTime']);
            if ($currentTimeMs < ($plot->plant_time + ($growTimeMs * 2))) {
                continue;
            }

            $updated = WorldObject::query()
                ->where('id', $plot->id)
                ->where('state', PLOT_STATE_PLANTED)
                ->update([
                    'state' => PLOT_STATE_GROWN,
                    'plant_time' => calculateFullyGrownPlantTime((float) $itemData['growTime']),
                ]);
            $unwitheredCount += $updated;
        }

        invalidateWorldCache($uid, $worldType);
        return $unwitheredCount;
    }

    /**
     * Return authoritative balance deltas for the reward consumables whose
     * Flash implementations normally update only local state.
     */
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

    /**
     * CXPBook fills the XP gap to the next level for each book in sequence.
     * Calculate all books while the player's resource row is locked so a
     * concurrent use cannot calculate against the same pre-book XP value.
     */
    private static function xpBookAmount($uid, int $itemCount): int
    {
        $currentXp = UserResources::getXp($uid);
        $xpToAdd = 0;

        for ($i = 0; $i < $itemCount; $i++) {
            $currentLevel = UserResources::getLevelForXp($currentXp);
            $nextLevelXp = UserResources::getXpForLevel($currentLevel + 1);
            $neededXp = max(0, min(UserResources::XP_MAX, $nextLevelXp) - $currentXp);
            if ($neededXp <= 0) {
                break;
            }

            $xpToAdd += $neededXp;
            $currentXp += $neededXp;
        }

        return $xpToAdd;
    }

    /** Keep all object-ID actions compatible with Flash's same-batch temp IDs. */
    private static function resolveActionObjectId($playerObj, $object, string $worldType): int
    {
        $originalId = isset($object->id) && is_numeric($object->id) ? (int) $object->id : 0;
        $resolvedId = $playerObj->resolveFlashObjectId($object, $worldType);
        if ($resolvedId !== null && $resolvedId !== $originalId) {
            $object->id = $resolvedId;
            return $resolvedId;
        }

        return $originalId;
    }

    /** Return a deliberately modest, non-canonical Pig Pen truffle prize. */
    private static function pigpenTrufflePrize(): ?array
    {
        // One hunt in four finds a truffle. The colour controls the Gift Box
        // consumable; its embedded reward is awarded when the truffle opens.
        if (random_int(1, 100) > 25) {
            return null;
        }

        $roll = random_int(1, 100);
        if ($roll <= 60) {
            return ['truffle' => 'consume_truffle_black', 'reward' => 'bottle'];
        }
        if ($roll <= 87) {
            return ['truffle' => 'consume_truffle_brown', 'reward' => 'xuk_animal_love_potion'];
        }
        if ($roll <= 97) {
            return ['truffle' => 'consume_truffle_white', 'reward' => 'xuk_animal_love_potion'];
        }

        return ['truffle' => 'consume_truffle_gold', 'reward' => 'pig_hotpink'];
    }

    /**
     * A featured slot map is presentation state for a building's authoritative
     * contents. Flash can send a stale compaction map after a rapid
     * place/store sequence; never let it render more copies of an item than
     * are actually stored or hide a stored item completely.
     */
    private static function reconcileFeaturedItemsForContents($contents, $featuredItems, $storageMetadata = null): \stdClass
    {
        $featuredItems = is_object($featuredItems) ? $featuredItems : new \stdClass();
        if (!is_array($contents) || empty($contents)) {
            return new \stdClass();
        }

        // Mutable animals use the metadata key as their stable identity.  A
        // delayed Flash compaction request can contain a hash for an animal
        // that was already withdrawn, while omitting a valid hash that is
        // still in storage.  Keep the slot count from `contents`, but only
        // preserve a hash when the authoritative metadata still contains it.
        // This prevents a stale slot from making a valid animal unbreedable
        // after reload.
        $metadataHashes = [];
        if (is_object($storageMetadata)) {
            foreach (get_object_vars($storageMetadata) as $metadataKey => $entries) {
                [$code, $suffix] = array_pad(explode(':', (string) $metadataKey, 2), 2, '');
                if ($code === '' || $suffix === '') {
                    continue;
                }
                $entries = is_array($entries) ? $entries : [$entries];
                if ($entries !== []) {
                    $metadataHashes[$code][$metadataKey] = count($entries);
                }
            }
        }

        $takeMetadataHash = static function (string $code) use (&$metadataHashes): ?string {
            foreach ($metadataHashes[$code] ?? [] as $hash => $count) {
                if ($count <= 0) {
                    continue;
                }
                --$metadataHashes[$code][$hash];
                return (string) $hash;
            }

            return null;
        };

        $remaining = [];
        $orderedCodes = [];
        foreach ($contents as $content) {
            $code = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
            $count = is_object($content) ? (int) ($content->numItem ?? 0) : (int) ($content['numItem'] ?? 0);
            if (!is_string($code) || $code === '' || $count <= 0) {
                continue;
            }
            $remaining[$code] = ($remaining[$code] ?? 0) + $count;
            for ($i = 0; $i < $count; $i++) {
                $orderedCodes[] = $code;
            }
        }

        $result = new \stdClass();
        $slots = get_object_vars($featuredItems);
        uksort($slots, static fn ($left, $right) => (int) $left <=> (int) $right);
        foreach ($slots as $slot => $entry) {
            $code = is_object($entry) ? ($entry->itemCode ?? null) : ($entry['itemCode'] ?? null);
            if (!is_string($code) || ($remaining[$code] ?? 0) <= 0) {
                continue;
            }
            $metaHash = is_object($entry)
                ? ($entry->metaHash ?? null)
                : ($entry['metaHash'] ?? null);
            $hasMetadata = isset($metadataHashes[$code]);
            $isUsableHash = is_string($metaHash)
                && $metaHash !== ''
                && $metaHash !== $code . ':'
                && isset($metadataHashes[$code][$metaHash])
                && $metadataHashes[$code][$metaHash] > 0;
            if ($isUsableHash) {
                --$metadataHashes[$code][$metaHash];
            } elseif ($hasMetadata) {
                $metaHash = $takeMetadataHash($code);
                if ($metaHash === null) {
                    $metaHash = $code . ':';
                }
            }
            if (!is_string($metaHash) || $metaHash === '') {
                $metaHash = $code . ':';
            }
            $result->{(string) $slot} = (object) [
                'itemCode' => $code,
                'metaHash' => $metaHash,
            ];
            --$remaining[$code];
        }

        $slot = 0;
        foreach ($orderedCodes as $code) {
            if (($remaining[$code] ?? 0) <= 0) {
                continue;
            }
            while (isset($result->{(string) $slot})) {
                ++$slot;
            }
            $metaHash = $takeMetadataHash($code);
            $result->{(string) $slot} = (object) [
                'itemCode' => $code,
                'metaHash' => $metaHash ?? $code . ':',
            ];
            --$remaining[$code];
            ++$slot;
        }

        return $result;
    }

    /**
     * Return the server-authoritative result for a standard construction
     * building's Finish/Complete Now flow.  Flash sends TransformBuilding for
     * this operation (not CompleteNow, which is reserved for expansions).
     */
    private static function constructionCompletionData(WorldObject $building): ?array
    {
        if ($building->class_name === 'MutableAnimalCrate') {
            return self::mutableAnimalCrateCompletionData($building);
        }

        if ($building->class_name === 'MutableAnimalBaby') {
            return self::mutableAnimalBabyCompletionData($building);
        }

        if (!is_string($building->class_name)
            || !str_ends_with($building->class_name, 'ConstructionBuilding')) {
            return null;
        }

        $constructionItem = getItemByName((string) $building->item_name, 'db');
        $finishedName = $constructionItem['finishedName'] ?? null;
        $defaultPart = $constructionItem['defaultItem'] ?? null;
        $materialsNeeded = (int) ($constructionItem['matsNeeded'] ?? 0);

        if (is_object($defaultPart)) {
            $defaultPart = get_object_vars($defaultPart);
        }

        if (!is_string($finishedName) || $finishedName === ''
            || !is_array($defaultPart) || !is_string($defaultPart['name'] ?? null)
            || $materialsNeeded <= 0) {
            return null;
        }

        $finishedItem = getItemByName($finishedName, 'db');
        $partItem = getItemByName($defaultPart['name'], 'db');
        if (!$finishedItem || !$partItem || !isset($partItem['code'])) {
            return null;
        }

        $requiredParts = $materialsNeeded * max(1, (int) ($defaultPart['amount'] ?? 1));
        $collectedParts = 0;
        foreach (is_array($building->contents) ? $building->contents : [] as $content) {
            $itemCode = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
            $quantity = is_object($content) ? ($content->numItem ?? 0) : ($content['numItem'] ?? 0);
            if ($itemCode === $partItem['code']) {
                $collectedParts += max(0, (int) $quantity);
            }
        }

        return [
            'cashCost' => max(0, $requiredParts - $collectedParts) * max(0, (int) ($partItem['cash'] ?? 0)),
            'finishedName' => $finishedName,
            'finishedClassName' => $finishedItem['className'] ?? 'Building',
            'finishedState' => 'built',
            'finishedReward' => $constructionItem['finishedReward'] ?? null,
        ];
    }

    /** Resolve a mutable crate from the adultCode transferred at placement. */
    private static function mutableAnimalCrateCompletionData(WorldObject $building): ?array
    {
        $components = is_object($building->components) ? $building->components : new \stdClass();
        $rawMetadata = $components->mutableAnimalCrateMetadata ?? null;
        $metadata = is_string($rawMetadata)
            ? JsonHelper::safeDecode($rawMetadata, false, new \stdClass())
            : (is_object($rawMetadata) ? $rawMetadata : new \stdClass());
        $adultCode = $metadata->adultCode ?? null;
        $adultItem = is_string($adultCode) ? getItemByCode($adultCode) : false;
        if (is_object($adultItem)) {
            $adultItem = get_object_vars($adultItem);
        }
        if (!is_array($adultItem) || !is_string($adultItem['name'] ?? null) || $adultItem['name'] === '') {
            return null;
        }

        $crateItem = getItemByName((string) $building->item_name, 'db');
        $feedItem = getItemByName('animalfeedtrough_feed', 'db');
        $materialsNeeded = max(0, (int) ($crateItem['matsNeeded'] ?? 0));
        $feedCode = $feedItem['code'] ?? null;
        if (!is_string($feedCode) || $feedCode === '' || $materialsNeeded <= 0) {
            return null;
        }

        $collectedFeed = 0;
        foreach (is_array($building->contents) ? $building->contents : [] as $content) {
            $itemCode = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
            $quantity = is_object($content) ? ($content->numItem ?? 0) : ($content['numItem'] ?? 0);
            if ($itemCode === $feedCode) {
                $collectedFeed += max(0, (int) $quantity);
            }
        }

        $finishedClassName = (string) ($adultItem['className'] ?? 'Animal');
        return [
            'cashCost' => max(0, $materialsNeeded - $collectedFeed) * max(0, (int) ($feedItem['cash'] ?? 0)),
            'finishedName' => $adultItem['name'],
            'finishedClassName' => $finishedClassName,
            'finishedState' => str_ends_with($finishedClassName, 'Building') ? 'built' : 'bare',
            'finishedReward' => $crateItem['finishedReward'] ?? null,
        ];
    }

    /** Resolve a breeding baby into its gender-specific adult animal. */
    private static function mutableAnimalBabyCompletionData(WorldObject $building): ?array
    {
        return MutableAnimalCompletion::forBaby($building);
    }

    /** Extract and validate the crate envelope used by giftbox/inventory storage. */
    private static function mutableAnimalCrateMetadata($extraData): ?string
    {
        if (is_object($extraData)) {
            $extraData = get_object_vars($extraData);
        }
        if (is_array($extraData)) {
            $extraData = $extraData['metadata'] ?? $extraData;
        }
        if (is_object($extraData)) {
            $extraData = $extraData->metadata ?? $extraData;
        }
        if (!is_string($extraData) || $extraData === '') {
            return null;
        }

        $metadata = JsonHelper::safeDecode($extraData, false, new \stdClass());
        if (!is_string($metadata->adultCode ?? null) || $metadata->adultCode === '') {
            return null;
        }

        return $extraData;
    }

    /** Decode the raw per-instance DNA stored with a Giftbox animal reward. */
    private static function mutableAnimalDna($extraData): ?\stdClass
    {
        if (is_string($extraData)) {
            $extraData = JsonHelper::safeDecode($extraData, false, null);
        } elseif (is_array($extraData)) {
            $extraData = (object) $extraData;
        }

        if (is_object($extraData) && isset($extraData->type) && is_string($extraData->type)) {
            $extraData = JsonHelper::safeDecode($extraData->type, false, null);
        }

        if (!is_object($extraData)
            || !isset($extraData->G, $extraData->B, $extraData->P)
            || !is_object($extraData->B) || !is_object($extraData->P)) {
            return null;
        }

        return $extraData;
    }

    /** Decide when a mutable animal may inherit its per-instance Giftbox DNA. */
    private static function shouldHydrateGiftboxAnimal(
        string $className,
        bool $isGiftboxWithdrawal,
        bool $isGiftboxPlacement,
    ): bool {
        if ($isGiftboxWithdrawal || $className === 'MutableAnimalBaby') {
            return true;
        }

        // Adult breeding rewards use the legacy isGift marker but may omit
        // isStorageWithdrawal entirely, just like Sal's boar did.
        return $className === 'MutableAnimal' && $isGiftboxPlacement;
    }

    /**
     * Some construction rewards are individual mutable animals, rather than
     * ordinary stackable Gift Box items.  Their traits must travel with the
     * reward so placing the animal creates a real breeding parent.
     */
    private static function constructionRewardExtraData(string $itemName): ?\stdClass
    {
        if ($itemName !== 'pigpen_male_light_green') {
            return null;
        }

        // This is the Pig Pen's documented starter Green Boar variant from
        // AnimalBreeding.xml.  Its plain pattern is available at level one.
        return (object) [
            'N' => 'pigpen_male_light_green',
            'G' => 'M',
            'B' => (object) ['H' => ['66', '66'], 'S' => ['c', 'c'], 'V' => ['c', 'c']],
            'P' => (object) ['H' => ['66', '66'], 'S' => ['c', 'c'], 'V' => ['c', 'c'], 'T' => ['a']],
        ];
    }

    /**
     * The original service rolled the Pet Run's server-side loot table before
     * handing a market-bought crate to Flash. That table is absent from the
     * recovered data set, so use an explicit equal-weight pool of valid rare
     * level-two cat and dog adults (the Pet Run animal family).
     */
    private static function marketMutableAnimalCrateMetadata(string $itemName): ?string
    {
        if ($itemName !== 'petrun_baby_rare2') {
            return null;
        }

        static $adultCodes = null;
        if ($adultCodes === null) {
            $adultCodes = [];
            foreach (\App\Models\Item::query()
                ->where(function ($query) {
                    $query->where('name', 'like', 'cat%')
                        ->orWhere('name', 'like', 'dog%');
                })
                ->get() as $candidate) {
                $data = (array) $candidate->itemData;
                if (($data['type'] ?? null) !== 'animal'
                    || ($data['breedingShare'] ?? null) !== 'exclusiveRare'
                    || !in_array($data['rareItem'] ?? null, [true, 'true'], true)
                    || (int) ($data['animalLevel'] ?? 0) < 2
                    || !is_string($candidate->code) || $candidate->code === '') {
                    continue;
                }
                $adultCodes[] = $candidate->code;
            }
        }

        if ($adultCodes === []) {
            return null;
        }

        return JsonHelper::safeEncode([
            'storageContent' => [],
            'fullyBuilt' => false,
            'adultCode' => $adultCodes[random_int(0, count($adultCodes) - 1)],
        ]);
    }

    public static function performAction($playerObj, $request, $market)
    {
        $data = array("id" => 0, "data" => array("id" => 0));
        $action = $request->params[0];

        $extraParams = (isset($request->params[2]) && is_array($request->params[2]) && isset($request->params[2][0]))
            ? $request->params[2][0] : null;

        $energyCost = 0;
        if ($extraParams !== null && isset($extraParams->energyCost)) {
            $energyCost = (int) $extraParams->energyCost;
        }

        if ($energyCost > 0) {
            $uid = $playerObj->getUid();
            UserResources::removeEnergy($uid, $energyCost);
        }

        switch ($action) {
            case ACTION_PLANT:
                $marketPurchaseObj = $request->params[1];
                $plantObj = clone $marketPurchaseObj;
                $cottage = CraftingCottages::normalizeMarketPlacement($plantObj);
                $className = $plantObj->className ?? '';
                $isStorageWithdrawal = $extraParams !== null
                    ? (int) ($extraParams->isStorageWithdrawal ?? 0) : 0;
                // Flash identifies the giftbox itself as -6 in storageData.
                // Older requests used the legacy -1 source ID, so accept
                // both formats when removing an item placed from the box.
                $isGiftboxWithdrawal = in_array($isStorageWithdrawal, [
                    GIFTBOX_ID,
                    (int) GIFTBOX_STORAGE_KEY,
                ], true);
                $isGiftboxPlacement = $extraParams !== null
                    && (bool) ($extraParams->isGift ?? false);
                $isBuildingWithdrawal = $isStorageWithdrawal > 0;
                $isInventoryWithdrawal = $isStorageWithdrawal === HOME_INVENTORY_ID;
                $withdrawnInventoryItemCode = null;
                $withdrawnInventoryExtraData = null;
                $withdrawnBuildingItemCode = null;
                $withdrawnMutableCrateMetadata = null;
                $withdrawnMutableAnimalMetadata = null;
                $inferredMutableGiftboxWithdrawal = false;

                // A breeding reward's DNA must be part of the *initial*
                // world-object insert.  Previously we withdrew the Giftbox
                // metadata only after setWorld(), then patched it onto the
                // row in a second write.  Flash can reload immediately after
                // placing a lamb; that window let the reload snapshot save a
                // blank baby, whose later TransformBuilding call produced a
                // default-pattern adult.  Peek without consuming first, so a
                // failed placement still leaves the Giftbox untouched.
                if (in_array($className, ['MutableAnimal', 'MutableAnimalBaby'], true)) {
                    $giftItem = getItemByName($plantObj->itemName ?? '', 'db');
                    $giftCode = is_array($giftItem) ? ($giftItem['code'] ?? null) : null;
                    $giftboxDna = null;
                    if (is_string($giftCode) && $giftCode !== '') {
                        $giftboxDna = self::mutableAnimalDna(
                            peekGiftboxItemExtraData($playerObj->getUid(), $giftCode)
                        );
                        // Some Flash Giftbox placement requests omit their
                        // isStorageWithdrawal envelope entirely. Per-instance
                        // breeding rewards are not market items, so a matching
                        // Giftbox DNA record is authoritative even when that
                        // legacy source marker is missing. The same request
                        // shape is used for adult breeding animals (not just
                        // lambs), with isGift set instead of a source ID.
                        $canInferGiftboxAnimal = self::shouldHydrateGiftboxAnimal(
                            $className,
                            $isGiftboxWithdrawal,
                            $isGiftboxPlacement,
                        );
                        if ($giftboxDna !== null && $canInferGiftboxAnimal) {
                            $plantObj->mutableAnimalState = (object) ['dna' => $giftboxDna];
                            $plantComponents = isset($plantObj->components) && is_object($plantObj->components)
                                ? $plantObj->components : new \stdClass();
                            $plantComponents->mutableAnimalState = $plantObj->mutableAnimalState;
                            $plantObj->components = $plantComponents;
                            $inferredMutableGiftboxWithdrawal = !$isGiftboxWithdrawal && $canInferGiftboxAnimal;
                        }
                    }
                    if ($className === 'MutableAnimalBaby'
                        || ($className === 'MutableAnimal' && $isGiftboxPlacement)) {
                        Logger::debug(self::LOG, sprintf(
                            'Mutable Giftbox placement: uid=%s item=%s source=%d extra=%s giftCode=%s dna=%s inferred=%s',
                            $playerObj->getUid(),
                            $plantObj->itemName ?? '',
                            $isStorageWithdrawal,
                            $extraParams === null ? 'none' : 'present',
                            $giftCode ?? 'none',
                            $giftboxDna === null ? 'missing' : 'present',
                            $inferredMutableGiftboxWithdrawal ? 'yes' : 'no',
                        ));
                    }
                }

                // Remove home/inventory items before creating their world
                // object. The previous post-placement withdrawal silently
                // did nothing when item lookup or storage data was missing,
                // which allowed the same animal to be placed repeatedly.
                if ($isInventoryWithdrawal) {
                    $itemData = getItemByName($plantObj->itemName ?? '', 'db');
                    $itemCode = $itemData['code'] ?? null;
                    $inventory = $itemCode ? getInventoryStorage($playerObj->getUid()) : [];
                    $available = $itemCode ? (int) ($inventory[$itemCode][0] ?? 0) : 0;

                    if (!$itemCode || $available < 1) {
                        Logger::error(self::LOG, sprintf(
                            'Rejected storage placement: uid=%s source=%d item=%s code=%s available=%d',
                            $playerObj->getUid(),
                            $isStorageWithdrawal,
                            $plantObj->itemName ?? '',
                            $itemCode ?? 'unknown',
                            $available
                        ));

                        return [
                            'id' => 0,
                            'data' => ['id' => 0, 'success' => false, 'error' => 'Item is not available in storage'],
                        ];
                    }

                    $withdrawnInventoryItemCode = $itemCode;
                    $withdrawnInventoryExtraData = withdrawFromInventoryStorage(
                        $playerObj->getUid(),
                        $itemCode
                    );
                    if (($plantObj->className ?? null) === 'MutableAnimalCrate') {
                        $withdrawnMutableCrateMetadata = self::mutableAnimalCrateMetadata(
                            $withdrawnInventoryExtraData
                        );
                        if ($withdrawnMutableCrateMetadata === null) {
                            addToInventoryStorage(
                                $playerObj->getUid(),
                                $itemCode,
                                1,
                                $withdrawnInventoryExtraData
                            );

                            return [
                                'id' => 0,
                                'data' => ['id' => 0, 'success' => false, 'error' => 'Crate metadata is not available in storage'],
                            ];
                        }
                    }
                } elseif ($isBuildingWithdrawal) {
                    $itemData = getItemByName($plantObj->itemName ?? '', 'db');
                    $itemCode = $itemData['code'] ?? null;

                    if (($plantObj->className ?? null) === 'MutableAnimalCrate') {
                        $withdrawal = $itemCode
                            ? $playerObj->withdrawMutableAnimalCrate($isStorageWithdrawal, $itemCode)
                            : false;
                        if ($withdrawal === false) {
                            Logger::error(self::LOG, sprintf(
                                'Rejected mutable-crate placement: uid=%s buildingId=%d item=%s code=%s',
                                $playerObj->getUid(),
                                $isStorageWithdrawal,
                                $plantObj->itemName ?? '',
                                $itemCode ?? 'unknown'
                            ));

                            return [
                                'id' => 0,
                                'data' => ['id' => 0, 'success' => false, 'error' => 'Crate metadata is not available in this building'],
                            ];
                        }
                        $withdrawnBuildingItemCode = $itemCode;
                        $withdrawnMutableCrateMetadata = $withdrawal['metadata'] ?? null;
                    } elseif (in_array(($plantObj->className ?? null), ['MutableAnimal', 'MutableAnimalBaby'], true)) {
                        $withdrawal = $itemCode
                            ? $playerObj->withdrawMutableAnimal($isStorageWithdrawal, $itemCode)
                            : false;
                        if ($withdrawal !== false) {
                            $withdrawnMutableAnimalMetadata = $withdrawal['metadata'] ?? null;
                        } elseif (!$itemCode || !$playerObj->withdrawStoredItem($isStorageWithdrawal, $itemCode)) {
                            Logger::error(self::LOG, sprintf(
                                'Rejected mutable-animal placement: uid=%s buildingId=%d item=%s code=%s',
                                $playerObj->getUid(), $isStorageWithdrawal, $plantObj->itemName ?? '', $itemCode ?? 'unknown'
                            ));
                            return [
                                'id' => 0,
                                'data' => ['id' => 0, 'success' => false, 'error' => 'Item is not available in this building'],
                            ];
                        }
                    } else {
                        // PigpenDialog places an ordinary stored Pig as an
                        // Animal, even though its feature slot carries a
                        // per-pig DNA record.  Consume that record together
                        // with the contents count when present.  Otherwise a
                        // removed Pig remains rendered in the pen after a
                        // reload and can be counted again by breeding.
                        $withdrawal = $itemCode
                            ? $playerObj->withdrawMutableAnimal($isStorageWithdrawal, $itemCode)
                            : false;
                        if ($withdrawal !== false) {
                            $withdrawnMutableAnimalMetadata = $withdrawal['metadata'] ?? null;
                        } elseif (!$itemCode || !$playerObj->withdrawStoredItem($isStorageWithdrawal, $itemCode)) {
                        Logger::error(self::LOG, sprintf(
                            'Rejected building-storage placement: uid=%s buildingId=%d item=%s code=%s',
                            $playerObj->getUid(),
                            $isStorageWithdrawal,
                            $plantObj->itemName ?? '',
                            $itemCode ?? 'unknown'
                        ));

                        return [
                            'id' => 0,
                                'data' => ['id' => 0, 'success' => false, 'error' => 'Item is not available in this building'],
                            ];
                        }
                    }

                    $withdrawnBuildingItemCode = $itemCode;
                }
                $retId = $playerObj->setWorld($plantObj, $action);

                // An unsuccessful placement must not consume the item.
                if ($isInventoryWithdrawal && $retId <= 0) {
                    addToInventoryStorage(
                        $playerObj->getUid(),
                        $withdrawnInventoryItemCode,
                        1,
                        $withdrawnInventoryExtraData
                    );

                    return [
                        'id' => 0,
                        'data' => ['id' => 0, 'success' => false, 'error' => 'Could not place item'],
                    ];
                }

                if ($isBuildingWithdrawal && $retId <= 0) {
                    if ($withdrawnMutableCrateMetadata !== null) {
                        $playerObj->restoreMutableAnimalCrate(
                            $isStorageWithdrawal,
                            $withdrawnBuildingItemCode,
                            $withdrawnMutableCrateMetadata
                        );
                    } elseif ($withdrawnMutableAnimalMetadata !== null) {
                        $playerObj->restoreMutableAnimal(
                            $isStorageWithdrawal,
                            $withdrawnBuildingItemCode,
                            $withdrawnMutableAnimalMetadata
                        );
                    } else {
                        $playerObj->restoreStoredItem($isStorageWithdrawal, $withdrawnBuildingItemCode);
                    }

                    return [
                        'id' => 0,
                        'data' => ['id' => 0, 'success' => false, 'error' => 'Could not place item'],
                    ];
                }

                // MutableAnimalCrate's adult target lives in the source
                // FeatureBuilding's per-instance storage metadata. The Flash
                // placement object deliberately omits it, so preserve the
                // withdrawn record on the new world object before any later
                // completion request can transform it.
                if ($withdrawnMutableCrateMetadata !== null && $retId > 0) {
                    $uid = $playerObj->getUid();
                    $worldType = getCurrentWorldType($uid);
                    WorldPersistence::mutateObject(
                        $uid,
                        $worldType,
                        $retId,
                        static function (WorldObject $placedObject) use ($withdrawnMutableCrateMetadata): bool {
                            $components = is_object($placedObject->components)
                                ? $placedObject->components : new \stdClass();
                            $components->mutableAnimalCrateMetadata = $withdrawnMutableCrateMetadata;
                            $metadata = JsonHelper::safeDecode(
                                $withdrawnMutableCrateMetadata,
                                false,
                                new \stdClass()
                            );
                            if (is_array($metadata->storageContent ?? null)) {
                                $placedObject->contents = $metadata->storageContent;
                            }
                            $placedObject->components = $components;

                            return true;
                        },
                    );
                }

                if ($withdrawnMutableAnimalMetadata !== null && $retId > 0) {
                    $uid = $playerObj->getUid();
                    $worldType = getCurrentWorldType($uid);
                    WorldPersistence::mutateObject(
                        $uid,
                        $worldType,
                        $retId,
                        static function (WorldObject $placedObject) use ($withdrawnMutableAnimalMetadata): bool {
                            $metadata = is_string($withdrawnMutableAnimalMetadata)
                                ? JsonHelper::safeDecode($withdrawnMutableAnimalMetadata, false, null)
                                : null;
                            if (is_object($metadata) && isset($metadata->type) && is_string($metadata->type)) {
                                $metadata = JsonHelper::safeDecode($metadata->type, false, null);
                            }
                            if (!is_object($metadata)) {
                                return false;
                            }
                            $components = is_object($placedObject->components)
                                ? $placedObject->components : new \stdClass();
                            $components->mutableAnimalState = (object) ['dna' => $metadata];
                            $placedObject->components = $components;
                            return true;
                        },
                    );
                }

                // Direct market placement bypasses giftbox/inventory metadata.
                // Mint the crate's adult result here, before it can be reloaded
                // or completed, using the equal-weight Pet Run rare pool.
                if (($plantObj->className ?? null) === 'MutableAnimalCrate'
                    && $isStorageWithdrawal === 0
                    && $retId > 0) {
                    $marketCrateMetadata = self::marketMutableAnimalCrateMetadata(
                        (string) ($plantObj->itemName ?? '')
                    );
                    if ($marketCrateMetadata !== null) {
                        $uid = $playerObj->getUid();
                        $worldType = getCurrentWorldType($uid);
                        WorldPersistence::mutateObject(
                            $uid,
                            $worldType,
                            $retId,
                            static function (WorldObject $placedObject) use ($marketCrateMetadata): bool {
                                $components = is_object($placedObject->components)
                                    ? $placedObject->components : new \stdClass();
                                $components->mutableAnimalCrateMetadata = $marketCrateMetadata;
                                $placedObject->components = $components;

                                return true;
                            },
                        );
                    }
                }

                if ($cottage !== null) {
                    Logger::log(self::LOG, sprintf(
                        'Converted crafting market item %s to functional cottage %s (objectId=%d)',
                        $marketPurchaseObj->itemName ?? '',
                        $cottage['functionalItem'],
                        $retId
                    ));
                }

                // When Flash omitted the Giftbox source envelope for a lamb,
                // consume the same DNA record only after its world row has
                // been inserted successfully. This preserves the normal
                // failed-placement guarantee while preventing a duplicate
                // lamb from remaining in Giftbox.
                if ($inferredMutableGiftboxWithdrawal && $retId > 0) {
                    $giftItem = getItemByName($plantObj->itemName ?? '', 'db');
                    $giftCode = is_array($giftItem) ? ($giftItem['code'] ?? null) : null;
                    if (is_string($giftCode) && $giftCode !== '') {
                        withdrawGiftboxItem($playerObj->getUid(), $giftCode);
                    }
                }

                // Placing an item already owned in a storage box must not be
                // processed as a new market purchase.
                if ($isStorageWithdrawal === 0 && !$playerObj->lastPlacementWasIdempotentRetry()) {
                    try {
                        $currency = ($extraParams !== null && isset($extraParams->currency))
                            ? (string) $extraParams->currency : null;
                        $market->newTransaction($action, $marketPurchaseObj, $currency);
                    } catch (\Throwable $e) {
                        Logger::error('WorldService', "Plant transaction error: " . $e->getMessage());
                    }
                } elseif ($isStorageWithdrawal === 0) {
                    Logger::debug('WorldService', sprintf(
                        'Skipped duplicate market charge: uid=%s item=%s objectId=%s',
                        $playerObj->getUid(),
                        $marketPurchaseObj->itemName ?? '',
                        $retId
                    ));
                }

                if ($extraParams) {
                    if ($isGiftboxWithdrawal) {
                        $placedItemName = $plantObj->itemName ?? null;
                        if ($placedItemName && $retId > 0) {
                            $uid = $playerObj->getUid();
                            $itemData = getItemByName($placedItemName, "db");
                            if ($itemData && isset($itemData["code"])) {
                                $giftboxExtraData = withdrawGiftboxItem($uid, $itemData["code"]);

                                if ($giftboxExtraData) {
                                    $worldType = getCurrentWorldType($uid);
                                    $worldId = getWorldId($uid, $worldType);
                                    
                                    if ($worldId) {
                                        $placedObj = \App\Models\WorldObject::where('world_id', $worldId)
                                            ->where('object_id', $retId)
                                            ->where('deleted', false)
                                            ->first();
                                        
                                        if ($placedObj) {
                                            $components = $placedObj->components;
                                            if (is_string($components)) {
                                                $components = JsonHelper::safeDecode($components, false, new \stdClass());
                                            } elseif (!is_object($components) || $components === null) {
                                                $components = new \stdClass();
                                            }
                                            
                                            $extraDataObj = is_object($giftboxExtraData) ? $giftboxExtraData : (object)$giftboxExtraData;
                                            foreach ($extraDataObj as $key => $value) {
                                                $components->$key = $value;
                                            }

                                            // Breeding currently awards a gender-specific adult
                                            // MutableAnimal directly. Both that adult and a lamb
                                            // use the same raw giftbox DNA envelope; restoring it
                                            // only for babies made a new patterned sheep render
                                            // correctly until the next reload, then fall back to
                                            // its default coat.
                                            if (in_array(($plantObj->className ?? null), ['MutableAnimal', 'MutableAnimalBaby'], true)) {
                                                $dna = self::mutableAnimalDna($giftboxExtraData);
                                                if ($dna !== null) {
                                                    $components->mutableAnimalState = (object) ['dna' => $dna];
                                                }
                                            }

                                            if (($plantObj->className ?? null) === 'MutableAnimalCrate') {
                                                $crateMetadata = self::mutableAnimalCrateMetadata($giftboxExtraData);
                                                if ($crateMetadata !== null) {
                                                    $components->mutableAnimalCrateMetadata = $crateMetadata;
                                                    $decodedCrateMetadata = JsonHelper::safeDecode(
                                                        $crateMetadata,
                                                        false,
                                                        new \stdClass()
                                                    );
                                                    if (is_array($decodedCrateMetadata->storageContent ?? null)) {
                                                        $placedObj->contents = $decodedCrateMetadata->storageContent;
                                                    }
                                                }
                                            }
                                            $placedContents = $placedObj->contents;
                                            
                                            if (!isset($components->active) && stripos($placedItemName, 'unwitherring') !== false) {
                                                $components->active = true;
                                            }
                                            
                                            WorldPersistence::mutateObject(
                                                $uid,
                                                $worldType,
                                                $retId,
                                                static function (WorldObject $lockedObject) use ($components, $placedContents): bool {
                                                    $lockedObject->components = $components;
                                                    $lockedObject->contents = $placedContents;

                                                    return true;
                                                },
                                            );
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                // Craftshop has its own FeatureBuilding visual state, while
                // the other cottages use CraftingCottageBuilding. Both need
                // the common crafting metadata when first placed.
                if ($cottage !== null && $retId > 0) {
                    $uid = $playerObj->getUid();
                    $worldType = getCurrentWorldType($uid);
                    $worldId = getWorldId($uid, $worldType);

                    if ($worldId) {
                        $placedCottage = \App\Models\WorldObject::where('world_id', $worldId)
                            ->where('object_id', $retId)
                            ->where('deleted', false)
                            ->first();

                        if ($placedCottage) {
                            $components = $placedCottage->components;
                            if (is_string($components)) {
                                $components = JsonHelper::safeDecode($components, false, new \stdClass());
                            } elseif (!is_object($components) || $components === null) {
                                $components = new \stdClass();
                            }

                            if (!isset($components->foundingTS) || $components->foundingTS == 0) {
                                $components->foundingTS = (int) ($plantObj->plantTime ?? 0);
                                if ($components->foundingTS <= 0) {
                                    $components->foundingTS = (int) (microtime(true) * 1000);
                                }
                            }

                            if (!isset($components->cottageName)) {
                                $components->cottageName = '';
                            }
                            if (!isset($components->finishedRecipes)) {
                                $components->finishedRecipes = new \stdClass();
                            }
                            if (!isset($components->transactionHistory)) {
                                $components->transactionHistory = [];
                            }
                            if (!isset($components->historyLastViewedTS)) {
                                $components->historyLastViewedTS = 0;
                            }
                            if (!isset($components->historyXPGain)) {
                                $components->historyXPGain = 0;
                            }
                            if (!isset($components->pendingLevelUpFeed)) {
                                $components->pendingLevelUpFeed = null;
                            }

                            WorldPersistence::mutateObject(
                                $uid,
                                $worldType,
                                $retId,
                                static function (WorldObject $lockedObject) use ($components): bool {
                                    $lockedObject->components = $components;

                                    return true;
                                },
                            );
                        }
                    }
                }

                $plantedItemName = $plantObj->itemName ?? null;
                if ($plantedItemName) {
                    $uid = $playerObj->getUid();
                    $itemData = getItemByName($plantedItemName, "db");
                    trackPlantProgress($uid, $plantedItemName, $itemData ?: []);
                }

                $data["id"] = $retId;
                $data["data"] = array("id" => $retId);
                break;

            case ACTION_PLOW:
                $plowObject = $request->params[1];
                $position = is_object($plowObject) ? ($plowObject->position ?? null) : null;
                $posX = is_object($position) ? ($position->x ?? null) : (is_array($position) ? ($position['x'] ?? null) : null);
                $posY = is_object($position) ? ($position->y ?? null) : (is_array($position) ? ($position['y'] ?? null) : null);
                $uid = $playerObj->getUid();

                Logger::debug('PlowAudit', 'Single plow received', [
                    'uid' => (string) $uid,
                    'world_type' => getCurrentWorldType($uid),
                    'x' => $posX,
                    'y' => $posY,
                    'object_id' => is_object($plowObject) ? ($plowObject->id ?? null) : null,
                ]);

                try {
                    $retId = $playerObj->setWorld($plowObject, $action);
                } catch (\Throwable $exception) {
                    Logger::error('PlowAudit', 'Single plow persistence threw an exception', [
                        'uid' => (string) $uid,
                        'x' => $posX,
                        'y' => $posY,
                        'error' => $exception->getMessage(),
                    ]);

                    throw $exception;
                }

                Logger::debug('PlowAudit', $retId === false ? 'Single plow rejected' : 'Single plow committed', [
                    'uid' => (string) $uid,
                    'world_type' => getCurrentWorldType($uid),
                    'x' => $posX,
                    'y' => $posY,
                    'object_id' => $retId,
                ]);

                try {
                    $currency = ($extraParams !== null && isset($extraParams->currency))
                        ? (string) $extraParams->currency : null;
                    $market->newTransaction($action, $request->params[1], $currency);
                } catch (\Throwable $e) {
                    Logger::error('WorldService', "Plow transaction error: " . $e->getMessage());
                }

                $uid = $playerObj->getUid();
                trackPlowProgress($uid, 1);

                $data["id"] = $retId;
                $data["data"] = array("id" => $retId);
                break;

            case ACTION_MOVE:
            case ACTION_CLEAR:
            case ACTION_CLEAR_WITHERED:
                $retId = $playerObj->setWorld($request->params[1], $action);
                $data["id"] = $retId;
                $data["data"] = array("id" => $retId);
                break;

            case ACTION_SELL:
                $uid = $playerObj->getUid();
                $clientObj = $request->params[1];

                $currentWorldType = getCurrentWorldType($uid);
                $world = getWorldByType($uid, $currentWorldType);
                $positionIndex = buildPositionIndex($world["objectsArray"] ?? []);

                $posX = isset($clientObj->position) ? ($clientObj->position->x ?? ($clientObj->position['x'] ?? null)) : null;
                $posY = isset($clientObj->position) ? ($clientObj->position->y ?? ($clientObj->position['y'] ?? null)) : null;

                $foundKey = findByPosition($positionIndex, $posX, $posY);
                // Most harvests identify the persisted object by position,
                // but FeatureBuilding transactions also carry the stable
                // object ID.  Some habitat snapshots omit or normalize their
                // position before this request is processed; falling back to
                // the ID keeps the authoritative harvest and its quest credit
                // tied to the same stored building.
                if ($foundKey === null && isset($clientObj->id) && is_numeric($clientObj->id)) {
                    $clientObjectId = (int) $clientObj->id;
                    foreach ($world['objectsArray'] ?? [] as $key => $worldObject) {
                        if ((int) ($worldObject->id ?? 0) === $clientObjectId) {
                            $foundKey = $key;
                            Logger::debug('WorldService', sprintf(
                                'Harvest resolved by object ID: uid=%s id=%d',
                                $uid,
                                $clientObjectId
                            ));
                            break;
                        }
                    }
                }
                $serverItemName = null;

                if ($foundKey !== null && isset($world["objectsArray"][$foundKey])) {
                    $serverObj = $world["objectsArray"][$foundKey];
                    $serverItemName = $serverObj->itemName ?? null;
                    $clientItemName = $clientObj->itemName ?? null;

                    if ($clientItemName !== null && $serverItemName !== null && $clientItemName !== $serverItemName) {
                        Logger::warning('WorldService', "Sell mismatch: uid=$uid, pos=($posX,$posY), client=$clientItemName, server=$serverItemName");
                    }
                }

                $retId = $playerObj->setWorld($clientObj, $action);

                if ($retId !== false && $serverItemName) {
                    $secureSellObj = clone $clientObj;
                    $secureSellObj->itemName = $serverItemName;

                    try {
                        $currency = ($extraParams !== null && isset($extraParams->currency))
                            ? (string) $extraParams->currency : null;
                        $market->newTransaction($action, $secureSellObj, $currency);
                    } catch (\Throwable $e) {
                        Logger::error('WorldService', "Sell transaction error: " . $e->getMessage());
                    }
                }

                $data["id"] = $retId;
                $data["data"] = array("id" => $retId);
                break;

            case ACTION_HARVEST:
                $uid = $playerObj->getUid();
                $clientObj = $request->params[1];
                $transactionResult = null;

                $currentWorldType = getCurrentWorldType($uid);
                $world = getWorldByType($uid, $currentWorldType);
                $positionIndex = buildPositionIndex($world["objectsArray"] ?? []);

                $posX = isset($clientObj->position) ? ($clientObj->position->x ?? ($clientObj->position['x'] ?? null)) : null;
                $posY = isset($clientObj->position) ? ($clientObj->position->y ?? ($clientObj->position['y'] ?? null)) : null;

                $foundKey = findByPosition($positionIndex, $posX, $posY);
                $serverItemName = null;
                $isStalePlotHarvest = false;

                if ($foundKey !== null && isset($world["objectsArray"][$foundKey])) {
                    $serverObj = $world["objectsArray"][$foundKey];
                    $serverItemName = $serverObj->itemName ?? null;

                    // Flash can replay a queued harvest after a reload.  The
                    // request still names the crop, but the authoritative
                    // plot has already become fallow.  Previously we applied
                    // rewards and quest credit again because setWorld accepts
                    // an update by ID.  A plot may only yield while its saved
                    // state is harvest-ready; accept both names used by the
                    // legacy data/client (grown and ripe).
                    $serverClassName = (string) ($serverObj->className ?? '');
                    $serverState = (string) ($serverObj->state ?? '');
                    if (stripos($serverClassName, 'Plot') !== false
                        && !in_array($serverState, [PLOT_STATE_GROWN, 'ripe'], true)) {
                        $isStalePlotHarvest = true;
                        Logger::debug('WorldService', sprintf(
                            'Ignored stale plot harvest: uid=%s id=%s state=%s',
                            $uid,
                            $serverObj->id ?? 'unknown',
                            $serverState
                        ));
                    }

                    $clientItemName = $clientObj->itemName ?? null;
                    if ($clientItemName !== null && $serverItemName !== null && $clientItemName !== $serverItemName) {
                        Logger::warning('WorldService', "Harvest mismatch: uid=$uid, pos=($posX,$posY), client=$clientItemName, server=$serverItemName");
                    }
                }

                if ($isStalePlotHarvest) {
                    // Treat a replay as a successful no-op.  Returning an
                    // AMF error causes Flash to keep it in its transaction
                    // queue and retry it on the next reload.
                    $data["id"] = 0;
                    $data["data"] = ["id" => 0, "stale" => true];
                    break;
                }

                $retId = $playerObj->setWorld($clientObj, $action);

                if ($retId !== false && $serverItemName) {
                    $secureHarvestObj = clone $clientObj;
                    $secureHarvestObj->itemName = $serverItemName;

                    try {
                        $currency = ($extraParams !== null && isset($extraParams->currency))
                            ? (string) $extraParams->currency : null;
                        $transactionResult = $market->newTransaction($action, $secureHarvestObj, $currency);
                    } catch (\Throwable $e) {
                        Logger::error('WorldService', "Harvest transaction error: " . $e->getMessage());
                    }

                    $itemData = getItemByName($serverItemName, "db");
                    trackHarvestProgress($uid, (array) $secureHarvestObj, $serverItemName, $itemData ?: []);
                }

                $data["id"] = $retId;
                $data["data"] = array("id" => $retId);

                // PigPenHarvestFManager dereferences postHarvestData before
                // checking whether a truffle was found. The generic harvest
                // reply has only an id, which made a normal pig-pen harvest
                // crash client-side with Error #1009. Supply the no-reward
                // envelope for this ordinary building harvest.
                if ($serverItemName === 'pigpenv2_finished') {
                    $data['data']['postHarvestData'] = [
                        'pigCode' => '',
                        'truffleFound' => '',
                    ];
                }

                if (is_array($transactionResult) && !empty($transactionResult['masteryLevelUp'])) {
                    $levelUp = $transactionResult['masteryLevelUp'];
                    $data["data"]["goals"] = [[
                        "type" => "Mastery",
                        "code" => $levelUp['itemCode'],
                        "difficulty" => $levelUp['newLevel'],
                        "link" => ""
                    ]];
                }

                if ($retId !== false && $serverItemName) {
                    $actionDrops = recordHarvestBushelDrops($uid, [$serverItemName => 1]);
                    if (!empty($actionDrops)) {
                        $data['metadata'] = ['ActionDrops' => $actionDrops];
                    }
                }
                break;

            case 'huntTruffle':
                $uid = $playerObj->getUid();
                $worldType = getCurrentWorldType($uid);
                $buildingObj = $request->params[1] ?? null;
                $buildingId = $buildingObj === null
                    ? 0 : self::resolveActionObjectId($playerObj, $buildingObj, $worldType);
                $pigCode = is_string($extraParams) ? $extraParams : '';
                $failure = null;
                $hunt = WorldPersistence::transaction(
                    $uid,
                    $worldType,
                    static function (int $worldId) use ($buildingId, $pigCode, &$failure): array {
                        $building = WorldObject::query()
                            ->where('world_id', $worldId)
                            ->where('object_id', $buildingId)
                            ->where('item_name', 'pigpenv2_finished')
                            ->where('deleted', false)
                            ->lockForUpdate()
                            ->first();
                        if ($building === null || $pigCode === '') {
                            $failure = 'invalid_pigpen_hunt';
                            throw new \RuntimeException($failure);
                        }

                        $pigCount = 0;
                        foreach (is_array($building->contents) ? $building->contents : [] as $content) {
                            $code = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
                            $count = is_object($content) ? ($content->numItem ?? 0) : ($content['numItem'] ?? 0);
                            if ($code === $pigCode) {
                                $pigCount += max(0, (int) $count);
                            }
                        }
                        $pig = getItemByCode($pigCode);
                        $pigName = is_array($pig) ? (string) ($pig['name'] ?? '') : '';
                        if ($pigCount < 1 || !is_array($pig)
                            || ($pigName !== 'pig'
                                && !preg_match('/^pigpen_(male|female)(?:_|$)/', $pigName))) {
                            $failure = 'invalid_hunt_pig';
                            throw new \RuntimeException($failure);
                        }

                        $components = is_object($building->components) ? $building->components : new \stdClass();
                        $cooldowns = is_object($components->truffleHuntCooldowns ?? null)
                            ? $components->truffleHuntCooldowns : new \stdClass();
                        $now = time();
                        $timestamps = $cooldowns->{$pigCode} ?? [];
                        $timestamps = is_array($timestamps) ? $timestamps : [];
                        $timestamps = array_values(array_filter($timestamps, static fn ($timestamp): bool =>
                            is_numeric($timestamp) && (int) $timestamp + self::PIGPEN_TRUFFLE_COOLDOWN_SECONDS > $now
                        ));
                        if (count($timestamps) >= $pigCount) {
                            $failure = 'pig_resting';
                            throw new \RuntimeException($failure);
                        }

                        $timestamps[] = $now;
                        $cooldowns->{$pigCode} = $timestamps;
                        $components->truffleHuntCooldowns = $cooldowns;
                        $building->components = $components;
                        $building->save();

                        return self::pigpenTrufflePrize() ?? [];
                    },
                );

                if ($hunt === false) {
                    $data['data'] = ['success' => false, 'error' => $failure ?? 'hunt_failed'];
                    break;
                }

                $truffle = (string) ($hunt['truffle'] ?? '');
                if ($truffle !== '') {
                    addGiftByName($uid, $truffle, 1, $uid, (object) ['rewardName' => $hunt['reward']]);
                }
                $data['data'] = [
                    'id' => $buildingId,
                    'success' => true,
                    'truffleFound' => $truffle,
                    // The recovered game data contains no original neighbor
                    // selection service. Keep the success dialog usable.
                    'friendName' => 'a neighboring farmer',
                    'friendUid' => 0,
                    'friendPic' => '',
                    'rewardUrl' => '',
                ];
                break;

            case ACTION_INSTANT_GROW:
                $uid = $playerObj->getUid();
                $currentWorldType = getCurrentWorldType($uid);
                $world = getWorldByType($uid, $currentWorldType);
                $modified = false;
                $modifiedCount = 0;
                $instantGrowChanges = [];
                $instantGrowQuestEvents = [];

                $typeCounts = [
                    'Plot' => 0,
                    'Tree' => 0,
                    'Animal' => 0,
                    'Bloom/Building' => 0,
                ];

                $typeMask = ($extraParams !== null && isset($extraParams->type))
                    ? (int) $extraParams->type : 15;

                if (!empty($world) && isset($world["objectsArray"])) {
                    foreach ($world["objectsArray"] as $key => $obj) {
                        $plantTime = $obj->plantTime ?? null;
                        $itemName = $obj->itemName ?? null;
                        $className = $obj->className ?? "";
                        $state = $obj->state ?? null;

                        if ($plantTime === null || $itemName === null) {
                            continue;
                        }

                        $isTargetType = false;
                        $typeMatched = "";

                        if (($typeMask & 1) && stripos($className, 'Plot') !== false && $state === "planted") {
                            $isTargetType = true;
                            $typeMatched = "Plot";
                        } elseif (($typeMask & 2) && stripos($className, 'Tree') !== false) {
                            $isTargetType = true;
                            $typeMatched = "Tree";
                        } elseif (($typeMask & 4) && stripos($className, 'Animal') !== false && stripos($className, 'LonelyAnimal') === false) {
                            $isTargetType = true;
                            $typeMatched = "Animal";
                        } elseif (($typeMask & 8) && (stripos($className, 'Building') !== false || stripos($className, 'Bloom') !== false)) {
                            $isTargetType = true;
                            $typeMatched = "Bloom/Building";
                        }

                        if (!$isTargetType) {
                            continue;
                        }

                        $itemData = getItemByName($itemName, "db");
                        if (!$itemData || !isset($itemData["growTime"])) {
                            continue;
                        }

                        // Retain the precise snapshot used to decide this
                        // object was eligible. The conditional persistence
                        // below must not overwrite a concurrent harvest.
                        $expectedState = $state;
                        $expectedItemName = $itemName;
                        $expectedPlantTime = $plantTime;

                        $growTimeDays = (float) $itemData["growTime"];

                        $newPlantTime = calculateFullyGrownPlantTime($growTimeDays);
                        $world["objectsArray"][$key]->plantTime = $newPlantTime;

                        $newState = getInstantGrowState($className, $state);
                        if ($newState !== null) {
                            $world["objectsArray"][$key]->state = $newState;
                        }

                        $modified = true;
                        $modifiedCount++;
                        $typeCounts[$typeMatched]++;
                        $instantGrowChanges[] = [
                            'object' => $world["objectsArray"][$key],
                            'type' => $typeMatched,
                            'expected' => [
                                'state' => $expectedState,
                                'item_name' => $expectedItemName,
                                'plant_time' => $expectedPlantTime,
                            ],
                        ];
                        // Quest settings explicitly register instantGrow as a
                        // harvest transaction for harvestByCategory tasks.
                        // Defer progress until after the authoritative world
                        // write succeeds, then mirror that client behaviour.
                        $instantGrowQuestEvents[] = [
                            'object' => (array) $world["objectsArray"][$key],
                            'itemName' => $itemName,
                            'itemData' => $itemData,
                        ];
                    }

                    if ($modified) {
                        $instantGrowResult = WorldPersistence::updateConditionally(
                            $uid,
                            $currentWorldType,
                            $instantGrowChanges,
                        );
                        if (empty($instantGrowResult['success'])) {
                            throw new \Exception("Failed to update world objects (instant grow) for uid=$uid");
                        }

                        // A simultaneous harvest wins for the same tile. Do
                        // not grant instant-grow quest progress or charge for
                        // mutations that were deliberately skipped.
                        $updatedObjectIds = array_flip($instantGrowResult['updatedObjectIds'] ?? []);
                        $appliedTypeCounts = array_fill_keys(array_keys($typeCounts), 0);
                        foreach ($instantGrowChanges as $change) {
                            $objectId = (int) ($change['object']->id ?? 0);
                            if (isset($updatedObjectIds[$objectId])) {
                                $appliedTypeCounts[$change['type']]++;
                            }
                        }
                        $totalCost = 0;
                        if ($appliedTypeCounts['Plot'] > 0) {
                            $totalCost += INSTAGROW_COST_CROP;
                        }
                        if ($appliedTypeCounts['Tree'] > 0) {
                            $totalCost += INSTAGROW_COST_TREE;
                        }
                        if ($appliedTypeCounts['Animal'] > 0) {
                            $totalCost += INSTAGROW_COST_ANIMAL;
                        }
                        if ($appliedTypeCounts['Bloom/Building'] > 0) {
                            $totalCost += INSTAGROW_COST_BLOOM;
                        }

                        foreach ($instantGrowQuestEvents as $event) {
                            $objectId = (int) ($event['object']['id'] ?? 0);
                            if (!isset($updatedObjectIds[$objectId])) {
                                continue;
                            }
                            trackHarvestProgress(
                                $uid,
                                $event['object'],
                                $event['itemName'],
                                $event['itemData'] ?: [],
                            );
                        }
                        if ($totalCost > 0) {
                            UserResources::removeCash($uid, $totalCost);
                        }
                    }
                }

                $data["data"] = array("id" => 0);
                break;

            case ACTION_USE:
                $useResult = self::consumeUseItem($playerObj, $request, $extraParams);
                $data["data"] = array_merge(
                    ["id" => 0],
                    $useResult,
                );
                if (empty($useResult['success'])) {
                    Logger::log(self::LOG, sprintf(
                        'Consumable use rejected: uid=%s, reason=%s',
                        $playerObj->getUid(),
                        $useResult['error'] ?? 'unavailable',
                    ));
                }
                break;

            case ACTION_STORE:
                $buildingObj = $request->params[1];
                $storeWorldType = getCurrentWorldType($playerObj->getUid());
                self::resolveActionObjectId($playerObj, $buildingObj, $storeWorldType);
                if ($extraParams) {
                    $storedItemName = $extraParams->storedItemName ?? null;
                    $storedItemCode = $extraParams->storedItemCode ?? null;
                    $numToStore = (int) ($extraParams->numToStore ?? 1);
                    $storageTarget = isset($extraParams->target) ? (int) $extraParams->target : null;
                    $buildingId = $buildingObj->id ?? null;
                    $buildingItemName = $buildingObj->itemName ?? null;

                    $isExpansionPartItem = false;
                    $buildingItemData = null;
                    $partData = null;

                    if ($buildingId && $buildingItemName && $storedItemName) {
                        $buildingItemData = getItemByName($buildingItemName, "db");
                        if ($buildingItemData && hasExpandFeature($buildingItemData)) {
                            $uid = $playerObj->getUid();
                            $currentWorldType = getCurrentWorldType($uid);
                            $currWorld = getWorldByType($uid, $currentWorldType);

                            foreach ($currWorld["objectsArray"] as $obj) {
                                if (isset($obj->id) && $obj->id == $buildingId) {
                                    $currentLevel = (int)($obj->expansionLevel ?? 1);
                                    $partData = isExpansionPart($buildingItemData, $currentLevel, $storedItemName);
                                    if ($partData) {
                                        $isExpansionPartItem = true;
                                    }
                                    break;
                                }
                            }
                        }
                    }

                    $storeResult = null;
                    if (!$isExpansionPartItem) {
                        // TInventoryStore identifies its destination with
                        // target=-2 and sends the resource itself as the
                        // action object. TStoreItem has no target and sends a
                        // real StorageBuilding. They must not share a path.
                        $storeResult = $storageTarget === HOME_INVENTORY_ID
                            ? $playerObj->storeInHomeInventory($extraParams)
                            : $playerObj->storeItem($buildingObj, $extraParams);

                        if (!$storeResult) {
                            return [
                                'id' => 0,
                                'data' => [
                                    'id' => 0,
                                    'success' => false,
                                    'error' => 'Could not store item',
                                ],
                            ];
                        }

                        // Quest progress is only recorded after the storage
                        // write succeeds, so a rejected store neither removes
                        // an item nor advances a quest.
                        trackStoreProgress(
                            $playerObj->getUid(),
                            $storedItemCode ?? ($storeResult['itemCode'] ?? ''),
                            max(1, $numToStore)
                        );

                        $data['data'] = [
                            'id' => $storeResult['id'] ?? 0,
                            'success' => true,
                        ];

                        // A construction store becomes complete as soon as
                        // the final configured part is committed. Player::storeItem
                        // performs that transition inside the same persistence
                        // transaction; return the normal Flash completion
                        // envelope so the client replaces its local frame with
                        // the finished building instead of disabling its menu.
                        $completion = is_array($storeResult['completion'] ?? null)
                            ? $storeResult['completion'] : null;
                        if ($completion !== null) {
                            $reward = $completion['gift']
                                ?? ($completion['finishedReward'] ?? null);
                            if (is_string($reward) && $reward !== '') {
                                addGiftByName(
                                    $playerObj->getUid(),
                                    $reward,
                                    1,
                                    $playerObj->getUid(),
                                    self::constructionRewardExtraData($reward),
                                );
                            }

                            $data['data']['finishedName'] = $completion['finishedName'];
                            $data['data']['finishedClassName'] = $completion['finishedClassName'];
                            $data['data']['finishedState'] = $completion['finishedState'];
                            $data['data']['gift'] = $reward;
                        }
                    }

                    $creditItems = [
                        "shovel_item_01"            => "InventoryCellar",
                        "shovel_item_20"            => "InventoryCellar",
                        "shovel_itempack"           => "InventoryCellar",
                        "beehive_bee"               => "beehive",
                        "beehive_queen"             => "beehive",
                        "beehive_bee_5"             => "beehive",
                        "halloween_candy_5pack"     => "halloweenBasket",
                        "haitibackpack_itempack_5"  => "haitiBackpack",
                    ];

                    if ($storedItemName && isset($creditItems[$storedItemName])) {
                        $uid = $playerObj->getUid();
                        $currentWorldType = getCurrentWorldType($uid);
                        $featureName = $creditItems[$storedItemName];

                        $itemData = getItemByName($storedItemName, "db");
                        $creditCount = ($itemData && isset($itemData['count'])) ? (int) $itemData['count'] : 1;

                        addFeatureCredit($uid, $currentWorldType, $featureName, $creditCount * $numToStore);

                        if ($itemData) {
                            $cashCost = (int) ($itemData['cash'] ?? 0);
                            if ($cashCost > 0) {
                                UserResources::removeCash($uid, $cashCost * $numToStore);
                            }
                        }
                    }

                    if ($isExpansionPartItem && $partData) {
                        $uid = $playerObj->getUid();
                        $currentWorldType = getCurrentWorldType($uid);
                        $currWorld = getWorldByType($uid, $currentWorldType);

                        $buildingKey = null;
                        foreach ($currWorld["objectsArray"] as $key => $obj) {
                            if (isset($obj->id) && $obj->id == $buildingId) {
                                $buildingKey = $key;
                                break;
                            }
                        }

                        if ($buildingKey !== null) {
                            $building = $currWorld["objectsArray"][$buildingKey];
                            $currentLevel = (int)($building->expansionLevel ?? 1);

                            $partItemData = getItemByName($storedItemName, "db");
                            $partCode = ($partItemData && isset($partItemData['code']))
                                ? $partItemData['code']
                                : $storedItemCode;

                            if ($partCode) {
                                $isGift = $extraParams->isGift ?? false;
                                if (!$isGift && $partItemData) {
                                    $cashCost = (int)($partItemData['cash'] ?? 0);
                                    if ($cashCost > 0) {
                                        $totalCost = $cashCost * $numToStore;
                                        UserResources::removeCash($uid, $totalCost);
                                    }
                                }

                                if (!isset($building->expansionParts)) {
                                    $building->expansionParts = new \stdClass();
                                }

                                $currentCount = 0;
                                if (is_object($building->expansionParts) && isset($building->expansionParts->$partCode)) {
                                    $currentCount = (int)$building->expansionParts->$partCode;
                                }

                                $needed = (int)($partData->need ?? 10);
                                $newCount = min($currentCount + $numToStore, $needed);
                                $building->expansionParts->$partCode = $newCount;

                                if (checkExpansionComplete($building, $buildingItemData)) {
                                    $building->expansionLevel = $currentLevel + 1;
                                    $building->expansionParts = new \stdClass();
                                }

                                if (!WorldPersistence::updateObject($uid, $currentWorldType, $building)) {
                                    throw new \Exception("Failed to save world (store expansion) for uid=$uid");
                                }
                            }
                        }
                    }
                }
                break;

            case ACTION_SET_MULTIPLE_FEATURED_ITEMS:
                $buildingObj = $request->params[1] ?? null;
                $featuredItems = $extraParams->featuredItems ?? null;
                $uid = $playerObj->getUid();
                $buildingId = $buildingObj === null
                    ? 0
                    : self::resolveActionObjectId($playerObj, $buildingObj, getCurrentWorldType($uid));

                if ($buildingId > 0 && is_object($featuredItems)) {
                    // This transaction is emitted after storing/compacting a
                    // pen.  It describes one building only.  Re-saving the
                    // entire cached farm here can overwrite a different pen
                    // that was just changed by its own atomic store action.
                    // Persist only this building, matching setFeaturedItem.
                    $worldType = getCurrentWorldType($uid);
                    $persistedFeaturedItems = WorldPersistence::transaction(
                        $uid,
                        $worldType,
                        function (int $worldId) use ($buildingId, $featuredItems) {
                            $building = WorldObject::query()
                                ->where('world_id', $worldId)
                                ->where('object_id', $buildingId)
                                ->where('deleted', false)
                                ->lockForUpdate()
                                ->first();

                            if ($building === null) {
                                throw new \RuntimeException("Featured-item building {$buildingId} no longer exists");
                            }

                            $components = is_object($building->components)
                                ? $building->components
                                : new \stdClass();
                            $components->featuredItems = self::reconcileFeaturedItemsForContents(
                                $building->contents,
                                $featuredItems,
                                $components->storageMetadata ?? null,
                            );
                            $building->components = $components;
                            $building->save();

                            return $components->featuredItems;
                        },
                    );
                    if ($persistedFeaturedItems !== false) {
                        $featuredItems = $persistedFeaturedItems;
                    }
                }

                // TSetMultipleFeaturedItems only uses this field when a caller
                // supplied a callback, but returning the canonical data keeps
                // the AMF response aligned with the Flash transaction.
                $data['data'] = ['featuredItems' => $featuredItems ?? new \stdClass()];
                break;

            case ACTION_SET_FEATURED_ITEM:
                // FeaturedRenderFManager uses this single-slot transaction
                // when an animal is first put into a habitat.  The later
                // setMultipleFeaturedItems call is only used for compaction
                // or removal, so ignoring this action left a stored animal
                // in `contents` but invisible after a reload.
                $buildingObj = $request->params[1] ?? null;
                $uid = $playerObj->getUid();
                $buildingId = $buildingObj === null
                    ? 0
                    : self::resolveActionObjectId($playerObj, $buildingObj, getCurrentWorldType($uid));
                $slot = $extraParams->itemSlot ?? null;
                $itemCode = $extraParams->itemCode ?? null;
                $metaHash = $extraParams->metaHash ?? null;
                $removeOrAdd = !empty($extraParams->removeOrAdd);
                $featuredItems = new \stdClass();

                if ($buildingId > 0 && $slot !== null && $itemCode !== null) {
                    $worldType = getCurrentWorldType($uid);
                    $persistedFeaturedItems = WorldPersistence::transaction(
                        $uid,
                        $worldType,
                        function (int $worldId) use (
                            $buildingId,
                            $slot,
                            $itemCode,
                            $metaHash,
                            $removeOrAdd
                        ) {
                            $building = WorldObject::query()
                                ->where('world_id', $worldId)
                                ->where('object_id', $buildingId)
                                ->where('deleted', false)
                                ->lockForUpdate()
                                ->first();

                            if ($building === null) {
                                throw new \RuntimeException("Featured-item building {$buildingId} no longer exists");
                            }

                            $components = is_object($building->components)
                                ? $building->components
                                : new \stdClass();
                            $featured = isset($components->featuredItems) && is_object($components->featuredItems)
                                ? $components->featuredItems
                                : new \stdClass();
                            $slotKey = (string) $slot;

                            if ($removeOrAdd) {
                                $featured->{$slotKey} = (object) [
                                    'itemCode' => (string) $itemCode,
                                    'metaHash' => (string) ($metaHash ?? ((string) $itemCode . ':')),
                                ];
                            } else {
                                unset($featured->{$slotKey});
                            }

                            // Resolve the slot against the building's
                            // authoritative contents and metadata. A blank
                            // or stale hash from Flash must not become a
                            // generic slot that rebinds to another pig on the
                            // next reload.
                            $hasStoredItem = false;
                            foreach ((array) ($building->contents ?? []) as $content) {
                                $contentCode = is_object($content)
                                    ? ($content->itemCode ?? null)
                                    : ($content['itemCode'] ?? null);
                                $contentCount = is_object($content)
                                    ? (int) ($content->numItem ?? 0)
                                    : (int) ($content['numItem'] ?? 0);
                                if ($contentCode === (string) $itemCode && $contentCount > 0) {
                                    $hasStoredItem = true;
                                    break;
                                }
                            }
                            $components->featuredItems = $hasStoredItem
                                ? self::reconcileFeaturedItemsForContents(
                                    $building->contents,
                                    $featured,
                                    $components->storageMetadata ?? null,
                                )
                                : $featured;
                            $building->components = $components;
                            $building->save();

                            return $featured;
                        },
                    );
                    if ($persistedFeaturedItems !== false) {
                        $featuredItems = $persistedFeaturedItems;
                    }
                }

                $data['data'] = ['featuredItems' => $featuredItems];
                break;

            case ACTION_NEIGHBOR_ACT:
                $plotObj = $request->params[1];
                $actParams = (isset($request->params[2]) && is_array($request->params[2]) && isset($request->params[2][0]))
                    ? $request->params[2][0] : null;

                $hostId = $actParams->hostId ?? null;
                $actionType = $actParams->actionType ?? null;
                
                Logger::debug('NeighborAction', "ACTION_NEIGHBOR_ACT: hostId=$hostId, actionType=$actionType");

                $data["data"] = array(
                    "staleFarm" => "false",
                    "goodieBagRewardItemCode" => null,
                    "fertilizeRewardLink" => null,
                    "fuelDiscovery" => 0,
                    "fuelRewardLink" => null,
                    "itemFoundName" => null,
                    "itemShareName" => null,
                    "itemFoundDialogText" => null,
                    "itemFoundRewardUrl" => null,
                    "itemFoundFeedBundle" => null
                );

                if ($hostId && $actionType && $plotObj) {
                    $uid = $playerObj->getUid();
                    $plotId = $plotObj->id ?? null;

                        switch ($actionType) {
                        case NEIGHBOR_ACTION_FERT:
                        case ACTION_PLOW:
                        case NEIGHBOR_ACTION_UNWITHER:
                        case ACTION_HARVEST:
                            UserResources::addXp($uid, 1);
                            UserResources::addGold($uid, 10);
                            break;
                        case NEIGHBOR_ACTION_FEED_CHICKENS:
                        case NEIGHBOR_ACTION_TRICK:
                            UserResources::addXp($uid, 1);
                            break;
                    }

                    incrementNeighborAction($uid, $hostId, $actionType);

                    if ($plotId !== null) {
                        $hostWorldType = get_meta($hostId, "currentWorldType") ?: "farm";
                        $hostWorld = getWorldByType($hostId, $hostWorldType);

                        if (!empty($hostWorld) && isset($hostWorld["objectsArray"])) {
                            $modified = false;

                            foreach ($hostWorld["objectsArray"] as $key => $obj) {
                                if (isset($obj->id) && $obj->id == $plotId) {
                                    switch ($actionType) {
                                        case NEIGHBOR_ACTION_FERT:
                                            $hostWorld["objectsArray"][$key]->isJumbo = true;
                                            $modified = true;
                                            break;
                                        case NEIGHBOR_ACTION_UNWITHER:
                                            $currentState = $obj->state ?? '';
                                            $itemName = $obj->itemName ?? null;
                                            $plantTime = $obj->plantTime ?? 0;

                                            if ($currentState === PLOT_STATE_PLANTED && $itemName && $plantTime > 0) {
                                                $itemData = getItemByName($itemName, "db");
                                                if ($itemData && isset($itemData["growTime"])) {
                                                    $growTimeDays = (float) $itemData["growTime"];
                                                    $growTimeMs = calculateGrowTimeMs($growTimeDays);
                                                    $witherTimeMs = $growTimeMs;
                                                    $currentTimeMs = getCurrentTimeMs();

                                                    $hasRingProtection = isWitherProtectionActive($hostId, $hostWorldType);

                                                    if ($currentTimeMs >= ($plantTime + $growTimeMs + $witherTimeMs) && !$hasRingProtection) {
                                                        $currentState = PLOT_STATE_WITHERED;
                                                    } elseif ($currentTimeMs >= ($plantTime + $growTimeMs)) {
                                                        $currentState = PLOT_STATE_GROWN;
                                                    }
                                                }
                                            }

                                            if ($currentState === PLOT_STATE_WITHERED) {
                                                $hostWorld["objectsArray"][$key]->state = PLOT_STATE_GROWN;
                                                if ($itemName) {
                                                    $itemData = $itemData ?? getItemByName($itemName, "db");
                                                    if ($itemData && isset($itemData["growTime"])) {
                                                        $growTimeDays = (float) $itemData["growTime"];
                                                        $hostWorld["objectsArray"][$key]->plantTime = calculateFullyGrownPlantTime($growTimeDays);
                                                    }
                                                }
                                                $modified = true;
                                            }
                                            break;
                                        case ACTION_PLOW:
                                            if (isset($obj->state) && $obj->state === PLOT_STATE_FALLOW) {
                                                $hostWorld["objectsArray"][$key]->state = PLOT_STATE_PLOWED;
                                                $modified = true;
                                            }
                                            break;
                                    }
                                    break;
                                }
                            }

                            if ($modified) {
                                if (!WorldPersistence::updateObject($hostId, $hostWorldType, $hostWorld['objectsArray'][$key])) {
                                    throw new \Exception("Failed to save host world (neighbor action) for hostId=$hostId");
                                }
                            }
                        }
                    }
                }
                break;

            case ACTION_REDEEM_NEIGHBOR_FERTILIZE:
                $data["data"] = array("id" => 0);
                break;

            case ACTION_PLACE_MESSAGE:
                $signObj = $request->params[1] ?? null;
                $hostId = $signObj->hostId ?? null;
                $authorId = $signObj->authorId ?? null;

                if (!$hostId || !$signObj) {
                    $data["data"] = array("id" => 0, "messageId" => 0, "messageText" => "");
                    break;
                }

                $messageText = $signObj->message ?? "";
                $hostWorldType = get_meta($hostId, "currentWorldType") ?: "farm";
                $hostWorld = getWorldByType($hostId, $hostWorldType);

                if (empty($hostWorld) || !isset($hostWorld["objectsArray"])) {
                    $data["data"] = array("id" => 0, "messageId" => 0, "messageText" => "");
                    break;
                }

                $usedIds = [];
                foreach ($hostWorld["objectsArray"] as $obj) {
                    if (isset($obj->id) && $obj->id > 0 && $obj->id < TEMP_ID_THRESHOLD) {
                        $usedIds[$obj->id] = true;
                    }
                }
                $newSignId = null;
                $maxSafeId = TEMP_ID_THRESHOLD - 1;
                for ($i = 1; $i <= $maxSafeId; $i++) {
                    if (!isset($usedIds[$i])) {
                        $newSignId = $i;
                        break;
                    }
                }
                if ($newSignId === null) {
                    $data["data"] = array("id" => 0, "messageId" => 0, "messageText" => "");
                    break;
                }
                $newSign = clone $signObj;
                $newSign->id = (int) $newSignId;
                $newSign->deleted = false;
                $newSign->tempId = (int) -1;

                $msgMgrObj = $hostWorld["messageManager"] ?? null;
                $messageManager = ["messages" => [], "allowSendEmails" => true];
                if (is_object($msgMgrObj)) {
                    $messages = [];
                    if (isset($msgMgrObj->messages) && is_array($msgMgrObj->messages)) {
                        foreach ($msgMgrObj->messages as $m) {
                            $messages[] = is_object($m) ? (array) $m : $m;
                        }
                    }
                    $messageManager["messages"] = $messages;
                    $messageManager["allowSendEmails"] = $msgMgrObj->allowSendEmails ?? true;
                } elseif (is_array($msgMgrObj)) {
                    $messageManager = $msgMgrObj;
                    if (!isset($messageManager["messages"])) {
                        $messageManager["messages"] = [];
                    }
                }

                $maxMsgId = 0;
                foreach ($messageManager["messages"] as $msg) {
                    $msgId = $msg["id"] ?? 0;
                    if ($msgId > $maxMsgId) {
                        $maxMsgId = $msgId;
                    }
                }
                $newMessageId = $maxMsgId + 1;
                $messageManager["messages"][] = [
                    "id" => (int) $newMessageId,
                    "message" => (string) $messageText,
                    "authorId" => (string) $authorId,
                    "objectId" => (int) $newSignId,
                    "isNew" => true,
                    "timestamp" => (int) time()
                ];

                $newSign->messageId = (int) $newMessageId;
                if (!WorldPersistence::createMessageSign($hostId, $hostWorldType, $newSign, $messageManager)) {
                    throw new \Exception("Failed to create message sign for hostId=$hostId");
                }

                $data["id"] = $newSignId;
                $data["data"] = array(
                    "id" => $newSignId,
                    "messageId" => $newMessageId,
                    "messageText" => $messageText
                );
                break;

            case ACTION_DELETE_MESSAGE_SIGN:
                $signObj = $request->params[1] ?? null;
                $hostId = $signObj->hostId ?? null;
                $signId = $signObj->id ?? null;
                $messageId = $signObj->messageId ?? null;

                if (!$hostId || !$signId) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $hostWorldType = get_meta($hostId, "currentWorldType") ?: "farm";
                $hostWorld = getWorldByType($hostId, $hostWorldType);

                if (empty($hostWorld) || !isset($hostWorld["objectsArray"])) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $found = false;
                foreach ($hostWorld["objectsArray"] as $key => $obj) {
                    if (isset($obj->id) && $obj->id == $signId && isset($obj->className) && $obj->className === 'MessageSign') {
                        $found = true;
                        break;
                    }
                }
                $msgMgrObj = $hostWorld["messageManager"] ?? null;
                $messageManager = ["messages" => [], "allowSendEmails" => true];
                if (is_object($msgMgrObj)) {
                    $messages = [];
                    if (isset($msgMgrObj->messages) && is_array($msgMgrObj->messages)) {
                        foreach ($msgMgrObj->messages as $m) {
                            $messages[] = is_object($m) ? (array) $m : $m;
                        }
                    }
                    $messageManager["messages"] = $messages;
                    $messageManager["allowSendEmails"] = $msgMgrObj->allowSendEmails ?? true;
                } elseif (is_array($msgMgrObj)) {
                    $messageManager = $msgMgrObj;
                    if (!isset($messageManager["messages"])) {
                        $messageManager["messages"] = [];
                    }
                }

                if ($messageId) {
                    foreach ($messageManager["messages"] as $msgKey => $msg) {
                        if (($msg["id"] ?? 0) == $messageId) {
                            unset($messageManager["messages"][$msgKey]);
                            break;
                        }
                    }
                    $messageManager["messages"] = array_values($messageManager["messages"]);
                }

                $deleted = WorldPersistence::deleteMessageSign(
                    $hostId,
                    $hostWorldType,
                    (int) $signId,
                    $messageManager,
                );
                $data["data"] = array("success" => $found && $deleted);
                break;

            case ACTION_EXPAND_WITH_CURRENCY:
                $expandObj = $request->params[1];
                $itemName = $expandObj->itemName ?? "NULL";

                $uid = $playerObj->getUid();
                $worldType = getCurrentWorldType($uid);
                $objId = self::resolveActionObjectId($playerObj, $expandObj, $worldType);

                if ($objId <= 0) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $world = getWorldByType($uid, $worldType);

                if (empty($world) || !isset($world["objectsArray"])) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $found = false;
                foreach ($world["objectsArray"] as $key => &$obj) {
                    if (isset($obj->id) && $obj->id == $objId) {
                        $currentLevel = isset($obj->expansionLevel) ? (int)$obj->expansionLevel : 1;
                        $obj->expansionLevel = $currentLevel + 1;
                        $obj->expansionParts = new \stdClass();
                        $found = true;
                        break;
                    }
                }
                unset($obj);

                if (!$found) {
                    $data["data"] = array("success" => false);
                    break;
                }

                if (!WorldPersistence::updateObject($uid, $worldType, $world['objectsArray'][$key])) {
                    throw new \Exception("Failed to save world (expand with currency) for uid=$uid");
                }
                $expandedItemData = getItemByName($itemName, "db");
                if ($expandedItemData) {
                    trackStorageBuildingExpansionProgress($uid, $expandedItemData);
                }
                $data["data"] = array("success" => true);
                break;

            case ACTION_COMPLETE_NOW:
                $expandObj = $request->params[1];
                $itemName = $expandObj->itemName ?? "NULL";
                $currency = $extraParams->currency ?? null;

                $uid = $playerObj->getUid();
                $worldType = getCurrentWorldType($uid);
                $objId = self::resolveActionObjectId($playerObj, $expandObj, $worldType);

                if ($objId <= 0) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $world = getWorldByType($uid, $worldType);

                if (empty($world) || !isset($world["objectsArray"])) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $buildingKey = null;
                $building = null;
                foreach ($world["objectsArray"] as $key => $obj) {
                    if (isset($obj->id) && $obj->id == $objId) {
                        $buildingKey = $key;
                        $building = $obj;
                        break;
                    }
                }

                if ($buildingKey === null || !$building) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $buildingItemData = getItemByName($itemName, "db");
                if (!$buildingItemData || !hasExpandFeature($buildingItemData)) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $currentLevel = (int)($building->expansionLevel ?? 1);
                $upgradeData = getExpansionUpgradeData($buildingItemData, $currentLevel);

                if (!$upgradeData || !isset($upgradeData->part)) {
                    $data["data"] = array("success" => false);
                    break;
                }

                $totalCashCost = 0;
                $parts = is_array($upgradeData->part) ? $upgradeData->part : [$upgradeData->part];
                $expansionParts = $building->expansionParts ?? new \stdClass();

                foreach ($parts as $part) {
                    if (!isset($part->name) || !isset($part->need)) continue;

                    $partItem = getItemByName($part->name, "db");
                    if (!$partItem) continue;

                    $partCode = $partItem['code'] ?? $part->name;
                    $partCash = (int)($partItem['cash'] ?? 1);
                    $needed = (int)$part->need;
                    $collected = 0;

                    if (is_object($expansionParts) && isset($expansionParts->$partCode)) {
                        $collected = (int)$expansionParts->$partCode;
                    } elseif (is_array($expansionParts) && isset($expansionParts[$partCode])) {
                        $collected = (int)$expansionParts[$partCode];
                    }

                    $remaining = max(0, $needed - $collected);
                    $totalCashCost += $remaining * $partCash;
                }

                if ($currency === 'cash' && $totalCashCost > 0) {
                    UserResources::removeCash($uid, $totalCashCost);
                }

                $world["objectsArray"][$buildingKey]->expansionLevel = $currentLevel + 1;
                $world["objectsArray"][$buildingKey]->expansionParts = new \stdClass();

                if (!WorldPersistence::updateObject($uid, $worldType, $world['objectsArray'][$buildingKey])) {
                    throw new \Exception("Failed to save world (complete now) for uid=$uid");
                }
                trackStorageBuildingExpansionProgress($uid, $buildingItemData);
                $data["data"] = array("success" => true);
                break;

            case ACTION_TRANSFORM_BUILDING:
                $buildingObj = $request->params[1] ?? null;
                $uid = $playerObj->getUid();
                $worldType = getCurrentWorldType($uid);
                $buildingId = $buildingObj === null
                    ? 0
                    : self::resolveActionObjectId($playerObj, $buildingObj, $worldType);

                if ($buildingId <= 0) {
                    $data['data'] = ['success' => false, 'error' => 'invalid_building'];
                    break;
                }

                $failure = null;
                $completion = WorldPersistence::transaction(
                    $uid,
                    $worldType,
                    static function (int $worldId) use ($uid, $buildingId, &$failure): array {
                        $building = WorldObject::query()
                            ->where('world_id', $worldId)
                            ->where('object_id', $buildingId)
                            ->where('deleted', false)
                            ->lockForUpdate()
                            ->first();

                        if ($building === null) {
                            $failure = 'building_not_found';
                            throw new \RuntimeException($failure);
                        }

                        $completion = self::constructionCompletionData($building);
                        if ($completion === null) {
                            $failure = 'unsupported_construction';
                            throw new \RuntimeException($failure);
                        }

                        if ($building->class_name === 'MutableAnimalBaby') {
                            $dna = $building->components->mutableAnimalState->dna ?? null;
                            Logger::debug(self::LOG, sprintf(
                                'Mutable lamb transform: uid=%s objectId=%d dna=%s',
                                $uid,
                                $buildingId,
                                is_object($dna) ? 'present' : 'missing',
                            ));
                        }

                        if (!UserResources::removeCash($uid, (int) $completion['cashCost'])) {
                            $failure = 'insufficient_cash';
                            throw new \RuntimeException($failure);
                        }

                        $building->item_name = $completion['finishedName'];
                        $building->class_name = $completion['finishedClassName'];
                        $building->state = $completion['finishedState'];
                        $building->contents = [];
                        // Eloquent's object cast returns a fresh decoded
                        // object on each property read. Build this envelope
                        // locally and assign it once; mutating
                        // `$building->components->...` after assigning `{}`
                        // silently loses the nested DNA at save time.
                        $finishedComponents = new \stdClass();
                        if (isset($completion['mutableAnimalState'])
                            && is_object($completion['mutableAnimalState'])) {
                            $finishedComponents->mutableAnimalState = $completion['mutableAnimalState'];
                        }
                        $building->components = $finishedComponents;

                        if (!$building->save()) {
                            $failure = 'persistence_failed';
                            throw new \RuntimeException($failure);
                        }

                        $reward = $completion['finishedReward'];
                        if (is_string($reward) && $reward !== '') {
                            addGiftByName(
                                $uid,
                                $reward,
                                1,
                                $uid,
                                self::constructionRewardExtraData($reward),
                            );
                        }

                        return $completion;
                    },
                );

                if ($completion === false) {
                    $data['data'] = ['success' => false, 'error' => $failure ?? 'transform_failed'];
                    break;
                }

                $data['data'] = [
                    'success' => true,
                    'finishedName' => $completion['finishedName'],
                    'gift' => $completion['finishedReward'],
                ];
                break;

            case ACTION_OPEN:
                $presentObj = $request->params[1];
                $objItemName = $presentObj->itemName ?? null;

                $uid = $playerObj->getUid();
                $worldType = getCurrentWorldType($uid);
                $objId = self::resolveActionObjectId($playerObj, $presentObj, $worldType);

                if ($objId <= 0 || !$objItemName) {
                    $data["data"] = array("error" => "invalid_object");
                    break;
                }

                $worldId = getWorldId($uid, $worldType);

                if (!$worldId) {
                    $data["data"] = array("error" => "no_world");
                    break;
                }

                $dbPresent = \App\Models\WorldObject::where('world_id', $worldId)
                    ->where('object_id', $objId)
                    ->where('deleted', false)
                    ->first();
                
                $components = null;
                if ($dbPresent && $dbPresent->components) {
                    $components = $dbPresent->components;
                    if (is_string($components)) {
                        $components = JsonHelper::safeDecode($components, false);
                    }
                }

                if (!$components && isset($presentObj->components)) {
                    $components = $presentObj->components;
                }

                $openResult = resolveOpenableItem($objItemName, $components, $uid);

                if (!$openResult || !$openResult['resultItem']) {
                    $data["data"] = array("error" => "unsupported_present");
                    break;
                }

                $resultItem = $openResult['resultItem'];
                $extraItemData = $openResult['extraItemData'] ?? null;

                $posX = isset($presentObj->position) ? ($presentObj->position->x ?? null) : null;
                $posY = isset($presentObj->position) ? ($presentObj->position->y ?? null) : null;

                if ($posX !== null && $posY !== null) {
                    WorldPersistence::deleteAtPosition($uid, $worldType, (int) $posX, (int) $posY);
                }

                $senderId = $extraItemData['sender'] ?? $uid;
                addGiftByName($uid, $resultItem, 1, $senderId, $extraItemData);

                $data["data"] = [
                    "item" => $resultItem,
                    "giftSenderId" => $senderId,
                    "extraItemData" => $extraItemData
                ];
                break;

            case ACTION_UPGRADE_STORAGE:
                $buildingObj = $request->params[1] ?? null;
                $uid = $playerObj->getUid();
                $worldType = getCurrentWorldType($uid);
                $buildingId = $buildingObj === null
                    ? 0
                    : self::resolveActionObjectId($playerObj, $buildingObj, $worldType);

                if ($buildingId <= 0) {
                    $data["data"] = ["error" => "invalid_building"];
                    break;
                }

                $worldId = getWorldId($uid, $worldType);

                if (!$worldId) {
                    $data["data"] = ["error" => "no_world"];
                    break;
                }

                $existingUpgradeJson = get_meta($uid, 'upgradeStatus');
                if ($existingUpgradeJson) {
                    $existingUpgrade = JsonHelper::safeDecode($existingUpgradeJson, true, []);
                    if ($existingUpgrade && ($existingUpgrade['isActive'] ?? false)) {
                        $data["data"] = ["error" => "upgrade_already_active"];
                        break;
                    }
                }

                $building = \App\Models\WorldObject::where('world_id', $worldId)
                    ->where('object_id', $buildingId)
                    ->where('deleted', false)
                    ->first();

                if (!$building) {
                    $data["data"] = ["error" => "building_not_found"];
                    break;
                }

                if (!in_array($building->class_name, ['StorageBuilding', 'InventoryCellar'])) {
                    $data["data"] = ["error" => "invalid_building_type"];
                    break;
                }

                $helperURL = "upgrade_{$buildingId}_{$uid}_{$worldType}";

                $nowMs = getCurrentTimeMs();
                $upgradeStatus = [
                    'isActive' => true,
                    'buildingId' => $buildingId,
                    'worldType' => $worldType,
                    'numHelped' => 0,
                    'helpers' => [],
                    'lastPosted' => $nowMs,
                    'expires' => $nowMs + (86400 * 1000),
                ];
                set_meta($uid, 'upgradeStatus', JsonHelper::safeEncode($upgradeStatus));

                $data["data"] = [
                    "helperURL" => $helperURL,
                    "buildingId" => $buildingId,
                    "worldType" => $worldType,
                    "numHelped" => 0,
                    "helperList" => [],
                    "lastPosted" => $upgradeStatus['lastPosted'],
                    "expires" => $upgradeStatus['expires'],
                    "clientUpdated" => $nowMs,
                ];
                break;

            case ACTION_PURCHASE_STORAGE_UPGRADE:
                $buildingObj = $request->params[1] ?? null;
                $uid = $playerObj->getUid();
                $worldType = getCurrentWorldType($uid);
                $buildingId = $buildingObj === null
                    ? 0
                    : self::resolveActionObjectId($playerObj, $buildingObj, $worldType);

                if ($buildingId <= 0) {
                    $data["data"] = ["error" => "invalid_building"];
                    break;
                }

                $worldId = getWorldId($uid, $worldType);

                if (!$worldId) {
                    $data["data"] = ["error" => "no_world"];
                    break;
                }

                $building = \App\Models\WorldObject::where('world_id', $worldId)
                    ->where('object_id', $buildingId)
                    ->where('deleted', false)
                    ->first();

                if (!$building) {
                    $data["data"] = ["error" => "building_not_found"];
                    break;
                }

                if (!in_array($building->class_name, ['StorageBuilding', 'InventoryCellar'])) {
                    $data["data"] = ["error" => "invalid_building_type"];
                    break;
                }

                $itemName = $building->item_name;
                $itemData = getItemByName($itemName, "db");
                $currentLevel = $building->expansion_level ?? 1;

                $upgradeCost = 5;

                $currentCash = UserResources::getCash($uid);
                if ($currentCash < $upgradeCost) {
                    $data["data"] = ["error" => "insufficient_cash"];
                    break;
                }

                UserResources::removeCash($uid, $upgradeCost);

                $newLevel = $currentLevel + 1;
                $persisted = WorldPersistence::mutateObject(
                    $uid,
                    $worldType,
                    $buildingId,
                    static function (WorldObject $lockedBuilding) use ($newLevel): bool {
                        $lockedBuilding->expansion_level = $newLevel;
                        $lockedBuilding->expansion_parts = null;

                        return true;
                    },
                );
                if ($persisted === false) {
                    throw new \Exception("Failed to purchase storage upgrade for uid=$uid");
                }

                if ($itemData) {
                    trackStorageBuildingExpansionProgress($uid, $itemData);
                }

                set_meta($uid, 'upgradeStatus', '');

                $data["data"] = [
                    "success" => true,
                    "newLevel" => $newLevel,
                    "helperURL" => null,
                    "worldType" => $worldType,
                ];
                break;

            case ACTION_CANCEL_STORAGE_UPGRADE:
                $uid = $playerObj->getUid();

                set_meta($uid, 'upgradeStatus', '');

                $data["data"] = ["success" => true];
                break;

            case ACTION_GET_STORAGE_INFO:
                $buildingObj = $request->params[1] ?? null;
                $uid = $playerObj->getUid();
                $buildingId = $buildingObj === null
                    ? 0
                    : self::resolveActionObjectId($playerObj, $buildingObj, getCurrentWorldType($uid));

                $upgradeStatusJson = get_meta($uid, 'upgradeStatus');
                $upgradeStatus = ($upgradeStatusJson && $upgradeStatusJson !== '')
                    ? JsonHelper::safeDecode($upgradeStatusJson, true)
                    : null;

                if ($upgradeStatus && isset($upgradeStatus['buildingId']) && $upgradeStatus['buildingId'] == $buildingId) {
                    $data["data"] = [
                        "helperURL" => "upgrade_{$buildingId}_{$uid}_{$upgradeStatus['worldType']}",
                        "buildingId" => $upgradeStatus['buildingId'],
                        "worldType" => $upgradeStatus['worldType'],
                        "numHelped" => $upgradeStatus['numHelped'] ?? 0,
                        "helperList" => $upgradeStatus['helpers'] ?? [],
                        "lastPosted" => $upgradeStatus['lastPosted'] ?? 0,
                        "expires" => $upgradeStatus['expires'] ?? 0,
                        "clientUpdated" => getCurrentTimeMs(),
                    ];
                } else {
                    $data["data"] = [
                        "buildingId" => $buildingId,
                        "isActive" => false,
                    ];
                }
                break;
        }
        return $data;
    }

    public static function loadOwnWorld($playerObj, $request, $market = null)
    {
        $loadType = $request->params[0] == "" ? 'farm' : $request->params[0];
        $travelWorld = getWorldByType($playerObj->getUid(), $loadType);
        $data["data"] = array(
            "user" => array(
                "currentWorldType" => $travelWorld["type"],
                "worldSummaryData" => array(
                    $travelWorld["type"] => array(
                        "firstLoaded" => strtotime($travelWorld['creation']),
                        "lastLoaded" => strtotime(date("Y-m-d h:i:s"))
                    )

                ),
                "player" => array(
                    "featureCredits" => getFeatureCreditsForClient($playerObj->getUid())
                )
            ),
            "world" => $travelWorld
        );

        set_meta($playerObj->getUid(), 'currentWorldType', $travelWorld["type"]);

        return $data;
    }

    public static function loadNeighborWorld($playerObj, $request){
        $neighborUid = $request->params[0];
        $travelWorld = getWorldByType($neighborUid);
        $neighborWorldType = get_meta($neighborUid, "currentWorldType") ?: "farm";

        $data["data"] = array(
            "user" => array(
                "ugcItemData" => [],
                "instanceDataStore" => [],
                "currentWorldType" => $neighborWorldType
            ),
            "world" => $travelWorld
        );

        return $data;
    }
}
