<?php
require_once AMFPHP_ROOTPATH . "Helpers/general_functions.php";
require_once AMFPHP_ROOTPATH . "Helpers/crafting_helper.php";
require_once AMFPHP_ROOTPATH . "Helpers/constants.php";
require_once AMFPHP_ROOTPATH . "Helpers/quest_helper.php";
require_once AMFPHP_ROOTPATH . "Helpers/collision.php";
require_once AMFPHP_ROOTPATH . "Helpers/capture_feature_helper.php";
require_once AMFPHP_ROOTPATH . "Helpers/mutable_animal_completion.php";

use App\Models\UserMeta;
use App\Models\UserAvatar;
use App\Models\UserWorld;
use App\Models\User;
use App\Models\PlayerMeta;
use App\Models\WorldObject;
use App\Support\StorageConfig;
use App\Support\WorldPersistence;

class Player {

    private const TEMP_OBJECT_ID_MAP_META_KEY = 'flash_temp_object_id_map';
    private const TEMP_OBJECT_ID_MAP_TTL_SECONDS = 600;

    private $uid = null;
    private $pData = array();
    private $worldData = array();
    private $avatarData = array();
    // A Flash placement can be resent after the world object was already
    // persisted.  Keep that fact available to WorldService so the retry is
    // acknowledged without charging the market transaction a second time.
    private $lastPlacementWasIdempotentRetry = false;

    public function __construct($id) {
        $this->uid = $id;
    }

    public function getUid(){
        return $this->uid;
    }

    /**
     * BreedingSkillState is initialized by Flash from this top-level map.
     * Keep a small server-side copy so a reload does not reset a player's
     * sheep/pig breeding progress to level zero.
     */
    public function getBreedingSkillStates(): array
    {
        $raw = get_meta($this->uid, 'breeding_skill_states');
        $states = is_string($raw) ? (@unserialize($raw) ?: []) : [];
        if (!is_array($states)) {
            $states = [];
        }

        foreach (['xuk_sheep_pen_finished', 'pigpenv2_finished'] as $featureName) {
            $state = $states[$featureName] ?? [];
            if (is_object($state)) {
                $state = get_object_vars($state);
            }
            if (!is_array($state)) {
                $state = [];
            }
            $states[$featureName] = [
                'featureName' => $featureName,
                'xp' => max(0, (int) ($state['xp'] ?? 0)),
                'level' => max(1, (int) ($state['level'] ?? 1)),
                'milestones' => is_array($state['milestones'] ?? null) ? $state['milestones'] : [],
            ];
        }

        return $states;
    }

    public function lastPlacementWasIdempotentRetry(): bool {
        return $this->lastPlacementWasIdempotentRetry;
    }

    /**
     * Flash assigns a temporary object ID before a placement reaches the
     * server.  Its next transaction can be a store action for that same
     * object before the placement response has been applied locally.  Keep a
     * short-lived, player-local reconciliation record so that action still
     * addresses the object we actually persisted.
     */
    private function rememberTemporaryObjectId(
        int $temporaryId,
        int $objectId,
        string $worldType,
        ?string $itemName,
        ?int $positionX,
        ?int $positionY,
    ): void {
        if ($temporaryId < TEMP_ID_THRESHOLD || $objectId <= 0) {
            return;
        }

        $now = time();
        $raw = get_meta($this->uid, self::TEMP_OBJECT_ID_MAP_META_KEY);
        $mappings = is_string($raw) ? (@unserialize($raw) ?: []) : [];
        if (!is_array($mappings)) {
            $mappings = [];
        }

        foreach ($mappings as $id => $mapping) {
            if (!is_array($mapping) || (int) ($mapping['createdAt'] ?? 0) < $now - self::TEMP_OBJECT_ID_MAP_TTL_SECONDS) {
                unset($mappings[$id]);
            }
        }

        $mappings[(string) $temporaryId] = [
            'objectId' => $objectId,
            'worldType' => $worldType,
            'itemName' => $itemName,
            'positionX' => $positionX,
            'positionY' => $positionY,
            'createdAt' => $now,
        ];
        set_meta($this->uid, self::TEMP_OBJECT_ID_MAP_META_KEY, serialize($mappings));
    }

    private function resolveTemporaryObjectId(
        int $temporaryId,
        string $worldType,
        ?string $expectedItemName = null,
        ?int $expectedPositionX = null,
        ?int $expectedPositionY = null,
    ): ?int {
        if ($temporaryId < TEMP_ID_THRESHOLD) {
            return null;
        }

        $raw = get_meta($this->uid, self::TEMP_OBJECT_ID_MAP_META_KEY);
        $mappings = is_string($raw) ? (@unserialize($raw) ?: []) : [];
        $mapping = is_array($mappings) ? ($mappings[(string) $temporaryId] ?? null) : null;
        if (!is_array($mapping)
            || (int) ($mapping['createdAt'] ?? 0) < time() - self::TEMP_OBJECT_ID_MAP_TTL_SECONDS
            || ($mapping['worldType'] ?? null) !== $worldType
            || (int) ($mapping['objectId'] ?? 0) <= 0) {
            return null;
        }

        // Flash's temporary IDs wrap during a long session. Bind a mapping
        // to its original object so a delayed action cannot target a newer
        // object that happens to reuse the numeric temporary ID.
        if ($expectedItemName !== null && ($mapping['itemName'] ?? null) !== $expectedItemName) {
            return null;
        }
        if (array_key_exists('positionX', $mapping)
            && $expectedPositionX !== null
            && (int) $mapping['positionX'] !== $expectedPositionX) {
            return null;
        }
        if (array_key_exists('positionY', $mapping)
            && $expectedPositionY !== null
            && (int) $mapping['positionY'] !== $expectedPositionY) {
            return null;
        }

        return (int) $mapping['objectId'];
    }

    /** Resolve a Flash object ID for any action following its placement. */
    public function resolveFlashObjectId($object, ?string $worldType = null): ?int {
        $objectId = isset($object->id) && is_numeric($object->id) ? (int) $object->id : null;
        if ($objectId === null || $objectId < TEMP_ID_THRESHOLD) {
            return $objectId;
        }

        $worldType = $worldType ?: (get_meta($this->uid, 'currentWorldType') ?: 'farm');
        $positionX = isset($object->position) ? ($object->position->x ?? null) : null;
        $positionY = isset($object->position) ? ($object->position->y ?? null) : null;

        return $this->resolveTemporaryObjectId(
            $objectId,
            $worldType,
            isset($object->itemName) ? (string) $object->itemName : null,
            is_numeric($positionX) ? (int) $positionX : null,
            is_numeric($positionY) ? (int) $positionY : null,
        ) ?? $objectId;
    }

    private function forgetTemporaryObjectId(int $temporaryId): void {
        if ($temporaryId < TEMP_ID_THRESHOLD) {
            return;
        }

        $raw = get_meta($this->uid, self::TEMP_OBJECT_ID_MAP_META_KEY);
        $mappings = is_string($raw) ? (@unserialize($raw) ?: []) : [];
        if (!is_array($mappings) || !isset($mappings[(string) $temporaryId])) {
            return;
        }

        unset($mappings[(string) $temporaryId]);
        set_meta($this->uid, self::TEMP_OBJECT_ID_MAP_META_KEY, serialize($mappings));
    }

    /** Keep FeatureBuilding's visual slots in one-to-one correspondence with stored contents. */
    private function synchronizeFeatureStorageSlots(WorldObject $building, array $contents): void {
        if ($building->class_name !== 'FeatureBuilding'
            && !str_starts_with((string) $building->item_name, 'animal_breeding_')) {
            return;
        }

        $components = is_object($building->components) ? $building->components : new \stdClass();
        $existingFeatured = isset($components->featuredItems) && is_object($components->featuredItems)
            ? $components->featuredItems : new \stdClass();

        // A mutable animal's item code is shared by every colour/pattern
        // variant.  Keep the hash sent by Flash for existing slots; replacing
        // it with "code:" makes all sheep resolve to the first DNA record on
        // the next reload.  This is especially easy to trigger when a store
        // action compacts the slot map after the animal has been harvested.
        $featuredItems = new \stdClass();
        $remaining = [];
        foreach ($contents as $content) {
            $itemCode = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
            $count = is_object($content) ? (int) ($content->numItem ?? 0) : (int) ($content['numItem'] ?? 0);
            if (is_string($itemCode) && $itemCode !== '' && $count > 0) {
                $remaining[$itemCode] = ($remaining[$itemCode] ?? 0) + $count;
            }
        }

        $existingSlots = get_object_vars($existingFeatured);
        uksort($existingSlots, static fn ($left, $right) => (int) $left <=> (int) $right);
        foreach ($existingSlots as $slot => $entry) {
            $itemCode = is_object($entry) ? ($entry->itemCode ?? null) : ($entry['itemCode'] ?? null);
            if (!is_string($itemCode) || $itemCode === '' || ($remaining[$itemCode] ?? 0) <= 0) {
                continue;
            }

            $metaHash = is_object($entry)
                ? ($entry->metaHash ?? null)
                : ($entry['metaHash'] ?? null);
            // "code:" is only the non-specific fallback. Treat it as a
            // missing hash so the metadata pass below can assign this slot's
            // actual DNA hash and repair legacy rows during the next write.
            if (!is_string($metaHash) || $metaHash === '' || $metaHash === $itemCode . ':') {
                continue;
            }
            $featuredItems->{(string) $slot} = (object) [
                'itemCode' => $itemCode,
                'metaHash' => $metaHash,
            ];
            --$remaining[$itemCode];
        }

        // Build hashes for metadata records that do not yet have a featured
        // slot (for example, a legacy pen containing animals before the
        // single-slot action was implemented).
        $metadataHashes = [];
        $storageMetadata = isset($components->storageMetadata) && is_object($components->storageMetadata)
            ? $components->storageMetadata : new \stdClass();
        foreach (get_object_vars($storageMetadata) as $metadataKey => $entries) {
            [$baseCode, $keyHash] = array_pad(explode(':', (string) $metadataKey, 2), 2, '');
            if ($baseCode === '') {
                continue;
            }
            $entries = is_array($entries) ? $entries : [$entries];
            foreach ($entries as $metadata) {
                // A hashed storageMetadata key is the stable identity used
                // by Flash. Preserve it verbatim; recomputing from the JSON
                // can produce a different digest for legacy DNA encodings
                // and makes a pig appear as a different colour after reload.
                $hash = $keyHash !== '' ? $keyHash : self::mutableAnimalMetadataHash($metadata);
                if ($hash !== null) {
                    $metadataHashes[$baseCode][] = $baseCode . ':' . $hash;
                }
            }
        }

        $availableHashes = [];
        foreach ($metadataHashes as $hashes) {
            foreach ($hashes as $hash) {
                $availableHashes[$hash] = ($availableHashes[$hash] ?? 0) + 1;
            }
        }
        foreach (get_object_vars($featuredItems) as $entry) {
            if (is_object($entry) && is_string($entry->metaHash ?? null)) {
                if (($availableHashes[$entry->metaHash] ?? 0) > 0) {
                    --$availableHashes[$entry->metaHash];
                }
            }
        }

        $slot = 0;
        foreach ($contents as $content) {
            $itemCode = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
            $count = is_object($content) ? (int) ($content->numItem ?? 0) : (int) ($content['numItem'] ?? 0);
            for ($i = 0; is_string($itemCode) && $itemCode !== '' && $i < max(0, $count); $i++) {
                if (($remaining[$itemCode] ?? 0) <= 0) {
                    continue;
                }
                while (isset($featuredItems->{(string) $slot})) {
                    ++$slot;
                }
                $metaHash = null;
                foreach (($metadataHashes[$itemCode] ?? []) as $candidate) {
                    if (($availableHashes[$candidate] ?? 0) > 0) {
                        $metaHash = $candidate;
                        --$availableHashes[$candidate];
                        break;
                    }
                }
                $featuredItems->{(string) $slot} = (object) [
                    'itemCode' => $itemCode,
                    'metaHash' => $metaHash ?? $itemCode . ':',
                ];
                --$remaining[$itemCode];
                ++$slot;
            }
        }

        $components->featuredItems = $featuredItems;
        $building->components = $components;
    }

    /** Return the same short DNA hash used by AnimalBreedingService. */
    private static function mutableAnimalMetadataHash($metadata): ?string {
        if (is_object($metadata) && isset($metadata->type) && is_string($metadata->type)) {
            $metadata = $metadata->type;
        }
        if (is_array($metadata) && isset($metadata['type']) && is_string($metadata['type'])) {
            $metadata = $metadata['type'];
        }
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        } elseif (is_object($metadata)) {
            $metadata = get_object_vars($metadata);
        }
        if (!is_array($metadata) || !isset($metadata['G'], $metadata['B'], $metadata['P'])) {
            return null;
        }

        $state = (string) $metadata['G'];
        foreach (['B', 'P'] as $trait) {
            foreach (['H', 'S', 'V'] as $channel) {
                $values = $metadata[$trait][$channel] ?? ['', ''];
                $state .= ($values[0] ?? '') . ',' . ($values[1] ?? '');
            }
            if ($trait === 'P') {
                $state .= $metadata['P']['T'][0] ?? '';
            }
        }

        return substr(md5($state), 0, 8);
    }

    /** A finished pig pen may contain only adult, DNA-backed breeding pigs. */
    private static function isValidPigpenBreedingAnimal(WorldObject $animal): bool {
        // The original Pig Pen treats the ordinary market Pig as a sow. It
        // has no mutable envelope on the farm, so storeItem supplies its
        // canonical female breeding DNA when it enters the pen.
        if ($animal->class_name === 'Animal' && $animal->item_name === 'pig') {
            return true;
        }

        if ($animal->class_name !== 'MutableAnimal'
            || !preg_match('/^pigpen_(male|female)(?:_|$)/', (string) $animal->item_name, $match)) {
            return false;
        }

        $components = is_object($animal->components) ? $animal->components : new \stdClass();
        $mutableState = $components->mutableAnimalState ?? null;
        $dna = is_object($mutableState) ? ($mutableState->dna ?? null) : null;
        if (!is_object($dna) && !is_array($dna)) {
            return false;
        }

        $metadata = json_decode(json_encode($dna), true);
        if (self::mutableAnimalMetadataHash($metadata) === null) {
            return false;
        }

        return ($metadata['G'] ?? null) === ($match[1] === 'male' ? 'M' : 'F');
    }

    private static function isBasePigpenSow(WorldObject $animal): bool {
        return $animal->class_name === 'Animal' && $animal->item_name === 'pig';
    }

    /** Return the generic breeding item name implied by a mutable DNA gender. */
    private static function canonicalMutableAnimalItemName(WorldObject $animal): ?string {
        if ($animal->class_name !== 'MutableAnimal') {
            return null;
        }

        $components = is_object($animal->components) ? $animal->components : new \stdClass();
        $mutableState = $components->mutableAnimalState ?? null;
        $dna = is_object($mutableState) ? ($mutableState->dna ?? null) : null;
        $gender = is_object($dna) ? strtoupper((string) ($dna->G ?? '')) : '';
        if (!in_array($gender, ['M', 'F'], true)) {
            return null;
        }

        return match ((string) $animal->item_name) {
            'pigpen_male', 'pigpen_female' => $gender === 'M' ? 'pigpen_male' : 'pigpen_female',
            'sheeppen_ram', 'sheeppen_ewe' => $gender === 'M' ? 'sheeppen_ram' : 'sheeppen_ewe',
            default => null,
        };
    }

    /** Default female traits for an ordinary Pig stored in the Pig Pen. */
    private static function basePigpenSowDna(): array {
        return [
            'N' => '',
            'G' => 'F',
            'B' => ['H' => ['d5', 'd6'], 'S' => ['2', '2'], 'V' => ['f', 'f']],
            'P' => ['H' => ['9', '9'], 'S' => ['e', 'e'], 'V' => ['f', 'f'], 'T' => ['f']],
        ];
    }

    /** Reject animals that can render in a pen but cannot participate in its breeding protocol. */
    private static function canStoreInFeatureBuilding(WorldObject $building, ?WorldObject $animal, string $itemCode): bool {
        if ($building->item_name !== 'pigpenv2_finished') {
            return true;
        }
        if ($animal === null || !self::isValidPigpenBreedingAnimal($animal)) {
            return false;
        }

        $item = getItemByName((string) $animal->item_name, 'db');
        return is_array($item) && ($item['code'] ?? null) === $itemCode;
    }

    public function getData($requ) {
        $userMeta = UserMeta::where('uid', $this->uid)->first();

        if ($userMeta === null) {
            return null;
        }

        $row = $userMeta->toArray();

        $currentWorldType = get_meta($this->uid, "currentWorldType") ?: "farm";
        $currentWorld = getWorldByType($this->uid, $currentWorldType);
        $masteryClientData = getMasteryForClient($this->uid);
        $savedOptionsRaw = get_meta($this->uid, 'player_options');
        $savedOptions = is_string($savedOptionsRaw) ? (@unserialize($savedOptionsRaw) ?: []) : [];
        $playerOptions = array_merge([
            'sfxDisabled' => false,
            'musicDisabled' => false,
            'animationDisabled' => false,
        ], is_array($savedOptions) ? $savedOptions : []);
        $savedItemFlagsRaw = get_meta($this->uid, 'item_flags');
        $savedItemFlags = is_string($savedItemFlagsRaw) ? (@unserialize($savedItemFlagsRaw) ?: []) : [];
        $itemFlags = array_merge(['giftcard' => ''], is_array($savedItemFlags) ? $savedItemFlags : []);
        $seenFlags = @unserialize($row['seenFlags']) ?: [];

        // CraftingManager opens its first-stall purchase tutorial whenever
        // its local world scan momentarily finds zero stalls.  A saved stall
        // is authoritative, so suppress that one-time tutorial up front for
        // existing owners rather than inviting them to buy a duplicate.
        $hasPlacedMarketStall = false;
        foreach (($currentWorld['objectsArray'] ?? []) as $worldObject) {
            $className = is_object($worldObject) ? ($worldObject->className ?? '') : ($worldObject['className'] ?? '');
            if ($className === 'MarketStallBuilding') {
                $hasPlacedMarketStall = true;
                break;
            }
        }
        if ($hasPlacedMarketStall) {
            $firstMarketStallFlag = 'FirstMarketStall' . ($currentWorldType === 'farm' ? '_farm' : '_' . $currentWorldType);
            if (empty($seenFlags[$firstMarketStallFlag])) {
                $seenFlags[$firstMarketStallFlag] = true;
                $userMeta->seenFlags = serialize($seenFlags);
                $userMeta->save();
                $row['seenFlags'] = $userMeta->seenFlags;
            }
        }

        $this->pData = array(
            "sequenceNumber" => $requ->sequence,
            "sequenceId" => 1483867184,

            "crossPromos" => null,
            // TFarmTransaction copies this top-level field into
            // CraftingInventoryStateV2. Omitting it leaves Flash with a
            // zero-capacity Crafting Silo, so Buy Ingredients incorrectly
            // reports that no silo has been placed.
            "craftingSiloMaxCapacity" => getCraftingSiloCapacity($this->uid, $currentWorldType),
            "flashHotParams" => array(
                "STAT_SAMPLE_ZLOC_FAIL" => 10,
                "ZYNGA_USER_ID" => $this->uid,
                "ZRUNTIME_KEY_HIDE_STATS_HUD" => false,
                "SKIP_NEW_CMS_MODULES" => false,
                "BINGO" => '{"CADENCENAME": "bingo","START_DATE": "05/13/2013","END_DATE": "05/30/2013","PREVIOUS_END_DATE": "05/30/2013","TITLE": "FARM BINGO","WINDOW_BACKGROUND": "assets/dialogs/FV_Support/FV_Bingo/Bingo_bg_default.png","MOTD": "assets/dialogs/FV_motd_Bingo.swf","BUY_RANDOM_PRICE": 2,"BUY_SPECIFIC_PRICE": 5,"COOLDOWN_HOURS": 6,"AUTOPOP_HOURS": 10,"PRIZES": "saddleshoetree,atthehop,sheep_thickglasses,cow_designersuit,pegacorn_poodleskirt","CARD_NUMBERS": "14,8,2,5,11,25,17,30,26,19,44,42,37,39,57,53,48,60,58,63,74,72,66,61","CARD_NUMBERS_NOT_SELECTED": "1,3,4,6,7,9,10,12,13,15,16,18,20,21,22,23,24,27,28,29,31,32,33,34,35,36,38,40,41,43,45,46,47,49,50,51,52,54,55,56,59,62,64,65,67,68,69,70,71,73,75"}',
                // Feature-message prerequisites construct MiniDartsManager even
                // when Mini Darts is not being offered. Its constructor
                // unconditionally JSON-decodes this runtime value. Keep a valid,
                // expired runtime here so the unsupported feature remains hidden
                // without crashing post-init for affected accounts.
                "MINIDARTS" => '{"CURRENCY_ITEM":"","THROTTLE":0,"CONSUMABLE":"consume_mystery_game_revamp_dart","END_DATE":"01/01/2010","VERSION":1,"CONSUMABLE_COST":15}',
                "REALITEMNAME_ENABLED" => true,
                "MARKET_REPOP_BLACKLIST" => "",
                // ExchangeSelectorSlot replaces the page flash parameters with this
                // InitUser payload. Both values are required for bushel-trader
                // inputs; a missing value is coerced to zero and disables Add.
                "DEFAULT_BUSHEL_ADD_TEMPRT" => 25,
                "BUSHEL_TRADE_NEEDED_TEMPRT" => 25,
                // The original cash-purchase flow opened Zynga's external
                // payment dialog.  In the offline build it instead falls
                // back to this message.  The Flash client dereferences this
                // value, so leaving it absent causes an Error #1009 whenever
                // a player tries to buy an item they cannot afford.
                "FC_POPUP_MESSAGE" => "You do not have enough Farm Cash for that item.",
                "LONELY_ANIMAL_CREW_ITEM" => "horse_xhf_octoberfestival"
            ),
            "wishData" => array(
                "wishSenders" => null,
                "wishRewardLink" => null,
                "wishName" => null,
                "wishImage" => null
            ),
            "energy" => $row['energy'],
            "seedHarvestCountsSinceLastBushelDrop" => getBushelHarvestCounts($this->uid),
            "locale" => "en_US",
            "witherOn" => buildWitherOnObject($this->uid),
            "isFarmvilleFan" => false,
            "fanPageStatuses" => array(),
            "subscriptionStatus" => "",
            "promos" => array(),
            "socialActions" => null,
            "snExtendedPermissions" => [
                "publish_actions",
                "user_games_activity",
                "friends_games_activity",
                "publish_actions",
                "user_birthday",
                "read_stream",
                "user_friends",
                "extended_permissions_gift_given"
            ],
            "systemNotifications" => true,
            "dynamicSystemNotifications" => true,
            "hasValidUnwitherClock" => isWitherProtectionActive($this->uid, $currentWorldType) ? 1 : 0,
            "errorPopupEnabled" => 1,
            "suppressDialogs" => false,
            "qaPopupBlock" => false,
            "neighbors" => compressArray($this->getCurrentNeighbors()),
            "npcs" => array(),
            "pendingPresents" => array(),
            "bumperCropPaid" => 0,
            "firstDay" => false,
            "friendUnwithered" => 0,
            "geoip" => null,
            "purchaseHistory" => array(),
            "experiments" => config('experiments'),
            "userLocale" => "en_US",
            "req_initUserStartTimestamp" => time(),
            "world" => $currentWorld,
            // TInitUser reads breeding skill states from the top-level
            // InitUser payload, not from postInit's legacy breedingState.
            "breedingSkillStates" => $this->getBreedingSkillStates(),
            "craftingState" => array(
                "craftingItems" => getCraftingInventory($this->uid),
                "nextCalendarDate" => 12,
                "calendarDate" => 11,
                "maxCapacity" => 400,
                "currentMarketStallCount" => 1,
                "firstCraft" => "stall",
                "shoppingState" => null,
                "pendingRewards" => null,
                "craftingSkillState" => getCraftingSkillState($this->uid),
                "recipeQueue" => getRecipeQueue($this->uid)
            ),
            "userInfo" => array(
                "currentWorldType" => $currentWorldType,
                "attr" => array(
                    "name" => $row["firstName"]
                ),
                "unlockedWorldTypes" => getUnlockedWorlds($this->uid),
                "player" => array(
                    "gold" => $row['gold'],
                    "cash" => $row['cash'],
                    "xp" => $row['xp'],
                    "energyMax" => $row['energyMax'],
                    "energy" => $row['energy'],
                    "options" => $playerOptions,
                    "storageData" => [
                        GIFTBOX_STORAGE_KEY => buildGiftBoxStorageData($this->uid),
                        INVENTORY_STORAGE_KEY => buildInventoryStorageData($this->uid),
                        CRAFTED_GOODS_STORAGE_KEY => getCraftedGoodsStorageData($this->uid),
                        ],
                    "hasVisitFriend" => false,
                    "achievements" => array(),
                    "achCounters" => null,
                    "mastery" => $masteryClientData['mastery'],
                    "masteryCounters" => $masteryClientData['masteryCounters'],
                    'organicCounters' => null,
                    'organicCertificationTotal' => null,
                    'collectionCounters' => null,
                    'storedCollections' => null,
                    'collectionLevels' => null,
                    'hasUnlimitedLights' => null,
                    'farmServiceCredits' => [],
                    'altGraphicCredits' => null,
                    'numLightsLeft' => 0,
                    'numOpenedPresents' => 0,
                    'dateOfLastPublishPermissionRequest' => 0,
                    'hasPublishPermission' => true,
                    'lastHorseStableSendTime' => 0,
                    'lastFrenchChateauSendTime' => 0,
                    'lastNurserySendTime' => 0,
                    // Older item catalogs still contain incremental-gate
                    // identifiers (for example I001 on coin farm
                    // expansions). The Flash market unconditionally reads
                    // this as an object; returning scalar 0 causes Error
                    // #1069 before it can render a slot. Keep the legacy
                    // gate available and unlocked for offline play. The XML
                    // patch removes these gates from new catalogs, but this
                    // also safely supports a player with a cached catalog.
                    'incrementalGateArray' => [
                        // FarmItem.checkIncrementalGate() reads the nested
                        // `acquired` member, not a numeric flag.
                        'I001' => [
                            'acquired' => true,
                        ],
                    ],
                    'progressBarData' => null,
                    'neighborPlumbingAddExcludeList' => null,
                    'pendingNeighbors' => $this->getPendingNeighbors(),
                    'neighbors' => $this->getCurrentNeighborUids(),
                    'lastSocialPlumbingActionTime' => 0,
                    'adoptedAnimals' => 0,
                    'superCropsStatus' => null,
                    'lotteryTickets' => 0,
                    'lonelyAnimalCode' => "2dvd",
                    'motdSeenFlags' => 0,
                    'limitedSaleExpirations' => 0,
                    'cashPurchasedTotal' => "100000",
                    'initialCashPurchaseTransactions' => 0,
                    'initialCPATransactions' => 0,
                    'avatarSurfacingEnabled' => false,
                    'avatarSurfacingFrequency' => 0,
                    'avatarSurfacingItem' => null,
                    'transactionLog' => null,
                    'farmTalkPermission' => true,
                    'chatLastMessageReadTime' => 0,
                    'userId' => $row['uid'],
                    'featureCredits' => getFeatureCreditsForClient($this->uid),
                    'incrementalFriendChecks' => array(),
                    'friendRewards' => null,
                    'seenFlags' => $seenFlags, // tutorial flags
                    'itemFlags' => $itemFlags,
                    'featureFrequency' => $this->getFeatureFrequencies(),
                    'externalLevels' => array(

                    ),
                    'actionCounts' => ["AvatarSurfaceThrottle_backoff_base"],
                    'neighborActionLimits' => array(
                        'm_neighborActionLimits' => getNeighborActionLimits($this->uid)
                    ),
                    'energyManager' => array(
                        // FarmService::buyTurboChargers persists this value in player
                        // metadata. Return the same value on InitUser so a reload
                        // does not reset the client's Turbo Fuel balance to zero.
                        "turboChargers" => (int) (get_meta($this->uid, "turboChargers") ?: 0)
                    ),
                    "isAKeynoteUser" => "1"
                ),
                "worldSummaryData" => array(
                    $currentWorldType => array(
                        "firstLoaded" => strtotime($currentWorld['creation']),
                        "lastLoaded" => strtotime(date("Y-m-d h:i:s"))
                    )
                ),
                "is_new" => $row["isNew"],
                "firstDay" => $row["firstDay"],
                "firstDayTimestamp" => 0,
                "featureOptions"=> $this->buildFeatureOptions(),
                "iconCodes" => [
                    "scratchCard"
                ],
                "avatar" => $this->getAvatar(),
                "questComponent" => buildQuestComponent($this->uid)

            )
        );

        return $this->pData;
    }

    private function buildFeatureOptions() {
        $irrigationData = getIrrigationData($this->uid);
        $currentWorldType = getCurrentWorldType($this->uid);
        $turboRingActiveWorlds = [];

        if (hasTurboRing($this->uid, $currentWorldType)) {
            $turboRingActiveWorlds[$currentWorldType] = true;
        }

        return [
            "world_seasons" => [
                "farm" => 0,
                "avalon" => 1
            ],
            "irrigation" => [
                "irrigation" => $irrigationData
            ],
            "gophergarden" => [
                "gophergarden" => getCaptureFeatureData($this->uid, "gophergarden")
            ],
            // InfiniteTurboManager reads this setting on every Turbo action.
            // Keeping it world-scoped preserves the original Turbo Ring rule:
            // a ring affects its placed world, not a player's fuel inventory.
            "turbo_rings" => [
                "turbo_rings_active_worlds" => $turboRingActiveWorlds
            ]
        ];
    }

    public function getAvatar(){
        $this->avatarData = null;

        if (is_numeric($this->uid)){
            $this->avatarData = UserAvatar::getForUser($this->uid);
        }

        return $this->avatarData;
    }

    public function setWorld($newObj, $action, $newSizeX = null, $newSizeY = null){
        $this->lastPlacementWasIdempotentRetry = false;
        $currentWorldType = get_meta($this->uid, "currentWorldType") ?: "farm";

        if (empty($this->worldData)){
            $currWorld = getWorldByType($this->uid, $currentWorldType);
        }else{
            $currWorld = $this->worldData;
        }

        $worldId = getWorldId($this->uid, $currentWorldType);
        if ($worldId === null) {
            Logger::error('World', "setWorld: no world found for uid={$this->uid} type=$currentWorldType");
            return false;
        }

        $delActions = [ACTION_SELL, ACTION_CLEAR];
        $exists = "";
        $usedIds = [];
        $operationType = null;
        $newId = 0;

        $newPosX = isset($newObj->position) ? ($newObj->position->x ?? null) : null;
        $newPosY = isset($newObj->position) ? ($newObj->position->y ?? null) : null;
        $incomingObjectId = isset($newObj->id) && is_numeric($newObj->id)
            ? (int) $newObj->id
            : null;

        // Flash may place an object and immediately act on it in the same
        // AMF batch.  The second request still carries Flash's temporary ID,
        // while the placement has already been persisted with a server ID.
        // Resolve that per-player mapping before actions such as sell search
        // for the object; otherwise the client sees the sale succeed locally
        // but the server silently rejects it as not found.
        //
        // Keep placement requests on their original temporary ID so the
        // placement branch below can allocate and record the mapping.
        if (
            $action !== ACTION_PLANT
            && $incomingObjectId !== null
            && $incomingObjectId >= TEMP_ID_THRESHOLD
        ) {
            $resolvedObjectId = $this->resolveFlashObjectId($newObj, $currentWorldType);
            if ($resolvedObjectId !== null && $resolvedObjectId !== $incomingObjectId) {
                $newObj->id = $resolvedObjectId;
                $incomingObjectId = $resolvedObjectId;
            }
        }

        foreach ($currWorld["objectsArray"] as $key => $tile){
            $tileObjectId = isset($tile->id) && is_numeric($tile->id)
                ? (int) $tile->id
                : null;

            // Flash may serialize IDs as strings or numbers. Normalize first,
            // then use strict identity; loose equality makes empty and numeric
            // values alias one another and can update the wrong world object.
            if ($incomingObjectId !== null && $tileObjectId !== null && $incomingObjectId === $tileObjectId){
                $exists = $key;
            }

            if ($tileObjectId !== null && $tileObjectId > 0 && $tileObjectId < TEMP_ID_THRESHOLD) {
                $usedIds[$tileObjectId] = true;
            }
        }

        // Flash's local object counter can also produce low IDs after a
        // reload. Treat every placement as a server-assigned identity
        // operation: an existing plot keeps its ID, while a new object gets
        // the next unused ID. Restricting this to large temporary IDs let a
        // new breeding lamb with a low ID overwrite an unrelated penguin.
        if ($action == ACTION_PLOW || $action == ACTION_PLANT){
            $placement = CollisionDetector::validatePlacement($newObj, $currWorld["objectsArray"], $action);
            
            if ($placement['existingKey'] !== null) {
                $existingObject = $currWorld["objectsArray"][$placement['existingKey']];
                $newClassName = (string) ($newObj->className ?? '');
                $existingClassName = (string) ($existingObject->className ?? '');
                $newItemName = (string) ($newObj->itemName ?? '');
                $existingItemName = (string) ($existingObject->itemName ?? '');
                $isPlotUpdate = stripos($newClassName, 'Plot') !== false
                    && stripos($existingClassName, 'Plot') !== false;
                $isIdempotentPlacement = $newClassName === $existingClassName
                    && $newItemName === $existingItemName;

                // A retried placement of the same tree/garden may reuse the
                // existing object. A different item at the same anchor is a
                // real collision and must never overwrite or delete it.
                if (!$isPlotUpdate && !$isIdempotentPlacement) {
                    return false;
                }

                if ($isIdempotentPlacement) {
                    $this->lastPlacementWasIdempotentRetry = true;
                }

                $newObj->id = $existingObject->id;
                $exists = $placement['existingKey'];
            } elseif ($placement['reason'] === 'collision_detected') {
                return false;
            } else {
                $newId = null;
                $maxSafeId = TEMP_ID_THRESHOLD - 1;
                for ($i = 1; $i <= $maxSafeId; $i++) {
                    if (!isset($usedIds[$i])) {
                        $newId = $i;
                        break;
                    }
                }
                if ($newId !== null) {
                    $newObj->id = $newId;
                }
                $exists = "";
            }
        }

        if (in_array($action, $delActions) && $exists === ""){
            return false;
        }

        if ($action == ACTION_HARVEST && $exists !== ""){
            $existingObj = $currWorld["objectsArray"][$exists];
            $className = $existingObj->className ?? null;
            $isAnimal = $className && (
                stripos($className, 'Animal') !== false ||
                stripos($className, 'Chicken') !== false ||
                stripos($className, 'Cow') !== false ||
                stripos($className, 'Pig') !== false ||
                stripos($className, 'Sheep') !== false ||
                stripos($className, 'Horse') !== false ||
                stripos($className, 'Goat') !== false
            );

            if (!$isAnimal) {
                $plantTime = $existingObj->plantTime ?? null;
                $itemName = $existingObj->itemName ?? null;
                if ($plantTime !== null && $itemName !== null){
                    $itemData = getItemByName($itemName, "db");
                    $isAnimalBreedingHarvester = false;
                    if ($itemData && isset($itemData['features']) && is_object($itemData['features'])) {
                        $features = $itemData['features']->feature ?? [];
                        if (!is_array($features)) {
                            $features = [$features];
                        }
                        foreach ($features as $feature) {
                            if (is_object($feature)
                                && ($feature->className ?? null) === 'AnimalBreedingHarvestFManager') {
                                $isAnimalBreedingHarvester = true;
                                break;
                            }
                        }
                    }

                    // Paddocks are FeatureBuildings, but they are not normal
                    // crops. Their AnimalBreedingHarvestFManager owns the
                    // ready state and Instant Grow updates that state on the
                    // client. Applying the item's generic growTime here
                    // incorrectly imposed a one-day crop timer afterwards,
                    // so a genuinely ripe paddock looked harvested locally
                    // yet the server rejected it and quest progress vanished
                    // on reload.
                    if (!$isAnimalBreedingHarvester && $itemData && isset($itemData["growTime"])){
                        $growTimeDays = (float) $itemData["growTime"];
                        $growTimeMs = calculateGrowTimeMs($growTimeDays);
                        $nowMs = getCurrentTimeMs();
                        if ($nowMs < ($plantTime + $growTimeMs)){
                            return false;
                        }
                    }
                }
            }
        }

        if ($exists !== "" && !in_array($action, $delActions)){
            $operationType = 'UPDATE';
            $existingObj = $currWorld["objectsArray"][$exists];
            if (isset($existingObj->contents) && is_array($existingObj->contents) && !empty($existingObj->contents)){
                $newObj->contents = $existingObj->contents;
            }

            // Generic actions such as harvesting a Pet Run carry an empty
            // `components` object. Those actions do not own storage or
            // featured-slot state, so merging the existing component fields
            // prevents a harvest from making stored animals invisible after a
            // reload.
            $existingComponents = isset($existingObj->components) && is_object($existingObj->components)
                ? $existingObj->components : null;
            if ($existingComponents !== null) {
                $incomingComponents = isset($newObj->components) && is_object($newObj->components)
                    ? $newObj->components : new \stdClass();
                $preserveComponents = ['featuredItems', 'storageMetadata', 'paintColor'];
                if (in_array(($newObj->className ?? ''), ['MutableAnimal', 'MutableAnimalBaby'], true)
                    || in_array(($existingObj->className ?? ''), ['MutableAnimal', 'MutableAnimalBaby'], true)) {
                    $preserveComponents[] = 'mutableAnimalState';
                }
                foreach ($preserveComponents as $componentKey) {
                    if (property_exists($newObj, $componentKey) && !property_exists($incomingComponents, $componentKey)) {
                        $incomingComponents->{$componentKey} = $newObj->{$componentKey};
                    }
                    if (!property_exists($incomingComponents, $componentKey)
                        && property_exists($existingComponents, $componentKey)) {
                        $incomingComponents->{$componentKey} = $existingComponents->{$componentKey};
                    }
                }
                $newObj->components = $incomingComponents;
            }

            $className = $newObj->className ?? "";

            switch ($action) {
                case ACTION_HARVEST:
                    $postHarvest = getPostHarvestState($className);
                    $newObj->state = $postHarvest['state'];
                    $newObj->plantTime = $postHarvest['plantTime'];
                    // A null itemName is intentional for ordinary plots: it
                    // removes the crop sprite after harvest.
                    if (array_key_exists('itemName', $postHarvest)) {
                        $newObj->itemName = $postHarvest['itemName'];
                    }
                    if (isset($postHarvest['isJumbo'])) {
                        $newObj->isJumbo = $postHarvest['isJumbo'];
                    }
                    break;
                case ACTION_CLEAR_WITHERED:
                    $newObj->state = PLOT_STATE_PLOWED;
                    $newObj->plantTime = null;
                    $newObj->itemName = null;
                    break;
            }

            // Flash finishes a normal orchard by sending its construction
            // object in terminal `built` state. Persisting that object as-is
            // leaves it as a placement shadow and without the orchard
            // storage feature after reload. Store the completed resource
            // contract instead, matching the market's finished item.
            if (
                ($newObj->itemName ?? null) === 'orchard_featurebuilding'
                && ($newObj->className ?? null) === 'OrchardConstructionBuilding'
                && ($newObj->state ?? null) === 'built'
            ) {
                $newObj->itemName = 'orchard_featurebuilding_finished';
                $newObj->className = 'OrchardFeatureBuilding';
                $newObj->state = 'bare';
            }

            $currWorld["objectsArray"][$exists] = $newObj;

        }else if (in_array($action, $delActions)){
            $operationType = 'DELETE';
            unset($currWorld["objectsArray"][$exists]);
            $currWorld["objectsArray"] = array_values($currWorld["objectsArray"]);

        }else {
            $operationType = 'INSERT';
            
            $collision = CollisionDetector::checkCollision($newObj, $currWorld["objectsArray"]);
            if ($collision['collides']) {
                return false;
            }
            
            if (isset($newObj->className) && $newObj->className === 'FeatureBuilding' && isset($newObj->itemName)) {
                $itemData = getItemByName($newObj->itemName, "db");
                if ($itemData && hasExpandFeature($itemData)) {
                    if (!isset($newObj->expansionLevel)) {
                        $newObj->expansionLevel = isset($itemData['initialExpansionLevel'])
                            ? (int)$itemData['initialExpansionLevel']
                            : 1;
                    }
                    if (!isset($newObj->expansionParts)) {
                        $newObj->expansionParts = new \stdClass();
                    }
                }
            }

            // Market placement packets use the final artwork state (`built`)
            // even for an uncompleted construction building.  That is only a
            // client-side placement preview: persisting it makes Flash render
            // an unfinished Pig Pen as complete after a reload, while its
            // server contract is still PigpenConstructionBuilding.  The
            // completion flow is the sole authority allowed to create the
            // finished building, so newly inserted construction sites always
            // start in Flash's explicit `construction` state. `bare` is not
            // equivalent here: StorageBuilding.loadObject treats an empty
            // bare building as built before choosing its artwork.
            if (is_string($newObj->className ?? null)
                && str_ends_with($newObj->className, 'ConstructionBuilding')) {
                $newObj->state = 'construction';
            }

            $currWorld["objectsArray"][] = $newObj;
        }

        $newObj = $this->sanitizeObjectValues($newObj);

        if ($newSizeX !== null || $newSizeY !== null) {
            if ($newSizeX != null){
                $currWorld["sizeX"] = $newSizeX;
            }
            if ($newSizeY != null){
                $currWorld["sizeY"] = $newSizeY;
            }
            foreach ($currWorld["objectsArray"] as $key => $obj) {
                $currWorld["objectsArray"][$key] = $this->sanitizeObjectValues($obj);
            }
            $this->worldData = $currWorld;
            $saveResult = WorldPersistence::replaceSnapshot(
                $this->uid,
                $currentWorldType,
                $currWorld,
                'world-size-change',
            );
            if (!$saveResult) {
                throw new \Exception("Failed to save world data for uid={$this->uid}");
            }
            if ($newId > 0){
                return $newId;
            }
            return 0;
        }

        $this->worldData = $currWorld;

        $dbResult = true;
        switch ($operationType) {
            case 'DELETE':
                $dbResult = WorldPersistence::deleteAtPosition(
                    $this->uid,
                    $currentWorldType,
                    (int) $newPosX,
                    (int) $newPosY,
                );
                break;
            case 'UPDATE':
                $dbResult = WorldPersistence::updateObject($this->uid, $currentWorldType, $newObj);
                break;
            case 'INSERT':
                $dbResult = WorldPersistence::insertObject($this->uid, $currentWorldType, $newObj);
                break;
        }

        if (!$dbResult) {
            throw new \Exception("Failed to perform $operationType on world object for uid={$this->uid}");
        }

        if ($newId > 0){
            $this->rememberTemporaryObjectId(
                (int) $incomingObjectId,
                $newId,
                $currentWorldType,
                $newObj->itemName ?? null,
                $newPosX !== null ? (int) $newPosX : null,
                $newPosY !== null ? (int) $newPosY : null,
            );
            return $newId;
        }

        return 0;
    }

    
    private function sanitizeObjectValues($obj) {
        if (!is_object($obj)) {
            return $obj;
        }

        foreach (get_object_vars($obj) as $prop => $val) {
            if (is_float($val) && (is_nan($val) || is_infinite($val))) {
                $obj->$prop = 0;
            } elseif (is_string($val) && (strtoupper($val) === 'NAN' || strtoupper($val) === 'INF' || strtoupper($val) === '-INF')) {
                $obj->$prop = 0;
            } elseif (is_object($val)) {
                $obj->$prop = $this->sanitizeObjectValues($val);
            } elseif (is_array($val)) {
                foreach ($val as $k => $v) {
                    if (is_object($v)) {
                        $val[$k] = $this->sanitizeObjectValues($v);
                    } elseif (is_float($v) && (is_nan($v) || is_infinite($v))) {
                        $val[$k] = 0;
                    } elseif (is_string($v) && (strtoupper($v) === 'NAN' || strtoupper($v) === 'INF' || strtoupper($v) === '-INF')) {
                        $val[$k] = 0;
                    }
                }
                $obj->$prop = $val;
            }
        }

        return $obj;
    }

    /**
     * Return the finished Pig Pen contract once every configured construction
     * part is present. This is intentionally checked against the locked
     * database row, rather than Flash's isFull flag.
     */
    private function pigpenConstructionCompletion(WorldObject $building, array $contents): ?array
    {
        if ($building->item_name !== 'pigpen'
            || $building->class_name !== 'PigpenConstructionBuilding'
            || $building->state !== 'construction') {
            return null;
        }

        $constructionItem = getItemByName('pigpen', 'db');
        if (!is_array($constructionItem)) {
            return null;
        }

        $storageType = $constructionItem['storageType'] ?? null;
        $storageClass = is_array($storageType)
            ? ($storageType['itemClass'] ?? null)
            : (is_object($storageType) ? ($storageType->itemClass ?? null) : null);
        $requirements = StorageConfig::constructionRequirements($storageClass);
        if ($requirements === []) {
            return null;
        }

        foreach ($requirements as $partName => $required) {
            $partItem = getItemByName((string) $partName, 'db');
            $partCode = is_array($partItem) ? ($partItem['code'] ?? null) : null;
            if (!is_string($partCode) || $partCode === '') {
                return null;
            }

            $collected = 0;
            foreach ($contents as $content) {
                $itemCode = is_object($content)
                    ? ($content->itemCode ?? null)
                    : ($content['itemCode'] ?? null);
                if ($itemCode !== $partCode) {
                    continue;
                }

                $collected += max(0, (int) (is_object($content)
                    ? ($content->numItem ?? 0)
                    : ($content['numItem'] ?? 0)));
            }

            if ($collected < (int) $required) {
                return null;
            }
        }

        $finishedName = $constructionItem['finishedName'] ?? null;
        if (!is_string($finishedName) || $finishedName === '') {
            return null;
        }

        $finishedItem = getItemByName($finishedName, 'db');
        if (!is_array($finishedItem)) {
            return null;
        }

        $finishedClassName = is_string($finishedItem['className'] ?? null)
            ? $finishedItem['className'] : 'Building';
        $finishedState = $finishedClassName === 'FeatureBuilding' ? 'bare' : 'built';

        return [
            'finishedName' => $finishedName,
            'finishedClassName' => $finishedClassName,
            'finishedState' => $finishedState,
            'gift' => $constructionItem['finishedReward'] ?? null,
        ];
    }

    public function storeItem($buildingObj, $storeParams){
        $currentWorldType = get_meta($this->uid, "currentWorldType") ?: "farm";

        if (empty($this->worldData)){
            $currWorld = getWorldByType($this->uid, $currentWorldType);
        }else{
            $currWorld = $this->worldData;
        }

        $buildingId = $buildingObj->id ?? null;
        $itemCode = $storeParams->storedItemCode ?? null;
        $storedItemName = $storeParams->storedItemName ?? null;
        $requestedResourceId = (int) ($storeParams->resource ?? 0);
        $resourceId = $requestedResourceId;
        $numToStore = (int) ($storeParams->numToStore ?? 1);
        $storedClassName = (string) ($storeParams->storedClassName ?? '');
        $storedMetadata = $storeParams->metadata ?? null;

        if (!$buildingId || !$itemCode) return false;

        $resolvedBuildingId = $this->resolveFlashObjectId($buildingObj, $currentWorldType);
        if ($resolvedBuildingId !== null) {
            $buildingId = $resolvedBuildingId;
            $buildingObj->id = $buildingId;
        }

        $buildingKey = null;
        foreach ($currWorld["objectsArray"] as $key => $obj) {
            if ((int) ($obj->id ?? 0) === (int) $buildingId) {
                $buildingKey = $key;
                break;
            }
        }

        if ($buildingKey === null) {
            Logger::error('World', "storeItem: building not found uid={$this->uid} buildingId={$buildingId}");
            return false;
        }

        // TStoreItem can follow a TPlace before Flash has replaced its
        // temporary object ID with the server ID. Resolve only the placing
        // player's recent mapping; the resource is still locked and its item
        // name verified below before anything is moved into storage.
        if ($resourceId >= TEMP_ID_THRESHOLD) {
            $resolvedResourceId = $this->resolveTemporaryObjectId(
                $resourceId,
                $currentWorldType,
                $storedItemName,
            );
            if ($resolvedResourceId !== null) {
                Logger::debug('World', "storeItem: resolved Flash temp resource uid={$this->uid} tempId={$resourceId} objectId={$resolvedResourceId}");
                $resourceId = $resolvedResourceId;
            }
        }

        // Do not replace the entire world when an animal is put in a pen.
        // A full snapshot can be stale by the time Flash sends this action;
        // its later delete/reinsert used to erase the pen contents that had
        // just appeared client-side. Lock and update only the pen and the
        // animal being moved.
        $contents = WorldPersistence::transaction($this->uid, $currentWorldType, function (int $worldId) use ($buildingId, $resourceId, $itemCode, $storedItemName, $numToStore, $storedClassName, $storedMetadata) {
            $storedBuilding = WorldObject::query()
                ->where('world_id', $worldId)
                ->where('object_id', (int) $buildingId)
                ->where('deleted', false)
                ->lockForUpdate()
                ->first();

            if ($storedBuilding === null) {
                throw new \RuntimeException("Storage building {$buildingId} no longer exists");
            }

            $resource = null;
            if ($resourceId > 0) {
                $resource = WorldObject::query()
                    ->where('world_id', $worldId)
                    ->where('object_id', $resourceId)
                    ->where('deleted', false)
                    ->lockForUpdate()
                    ->first();

                if ($resource === null) {
                    throw new \RuntimeException("Stored resource {$resourceId} no longer exists");
                }

                $canonicalItemName = self::canonicalMutableAnimalItemName($resource);
                $correctedMutableName = false;
                if ($canonicalItemName !== null && $canonicalItemName !== $resource->item_name) {
                    Logger::warning('World', sprintf(
                        'Corrected mutable animal gender before storage: uid=%s resourceId=%d old=%s new=%s',
                        $this->uid,
                        $resourceId,
                        (string) $resource->item_name,
                        $canonicalItemName,
                    ));
                    $resource->item_name = $canonicalItemName;
                    $correctedMutableName = true;
                    $canonicalItem = getItemByName($canonicalItemName, 'db');
                    if (is_array($canonicalItem) && is_string($canonicalItem['code'] ?? null)) {
                        $itemCode = $canonicalItem['code'];
                    }
                }

                if ($storedItemName !== null
                    && $resource->item_name !== $storedItemName
                    && !$correctedMutableName) {
                    throw new \RuntimeException("Stored resource {$resourceId} does not match requested item");
                }
            }

            // Generic pigs used to be accepted into the breeding pen because
            // they share its visual animal category. They have no mutable DNA
            // or gender, however, so they could never form a valid pair.
            // Validate the locked world object rather than Flash's claimed
            // class/name metadata, before changing either object.
            if (!$this->canStoreInFeatureBuilding($storedBuilding, $resource, (string) $itemCode)) {
                throw new \RuntimeException('invalid_feature_storage_animal');
            }
            $isBasePigpenSow = $storedBuilding->item_name === 'pigpenv2_finished'
                && $resource !== null && self::isBasePigpenSow($resource);
            // Keep the same code Flash supplied.  PigpenDialog later sends
            // that code back when it removes the pig from the pen; rewriting
            // PI (Pig) to I! (pigpen_female) leaves the visual slot and its
            // stored count out of sync with the removal request.
            $storageItemCode = (string) $itemCode;

            $contents = is_array($storedBuilding->contents) ? $storedBuilding->contents : [];
            $contentIndex = null;
            foreach ($contents as $key => $content) {
                $code = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
                if ($code === $storageItemCode) {
                    $contentIndex = $key;
                    break;
                }
            }

            if ($contentIndex === null) {
                $contents[] = ['itemCode' => $storageItemCode, 'numItem' => max(1, $numToStore)];
            } else {
                $currentCount = is_object($contents[$contentIndex])
                    ? (int) ($contents[$contentIndex]->numItem ?? 0)
                    : (int) ($contents[$contentIndex]['numItem'] ?? 0);
                $contents[$contentIndex] = [
                    'itemCode' => $storageItemCode,
                    'numItem' => $currentCount + max(1, $numToStore),
                ];
            }

            if ($resource !== null) {
                $resource->update(['deleted' => true]);
            }

            // Mutable animals carry their DNA in the store transaction rather
            // than in the compact contents count. Keep that per-instance
            // metadata beside the count so a later withdrawal or breeding
            // request can recover the same traits after a reload.
            $effectiveStoredMetadata = $storedMetadata;
            if ($effectiveStoredMetadata === null && $resource !== null
                && in_array($resource->class_name, ['MutableAnimal', 'MutableAnimalBaby'], true)) {
                $resourceComponents = is_object($resource->components) ? $resource->components : new \stdClass();
                $resourceState = $resourceComponents->mutableAnimalState ?? null;
                $resourceDna = is_object($resourceState) ? ($resourceState->dna ?? null) : null;
                if (is_object($resourceDna)) {
                    $effectiveStoredMetadata = json_encode($resourceDna);
                }
            }

            if (($effectiveStoredMetadata !== null
                    && ($resource === null || in_array($resource->class_name, ['MutableAnimal', 'MutableAnimalBaby'], true)))
                || $isBasePigpenSow) {
                $components = is_object($storedBuilding->components)
                    ? $storedBuilding->components : new \stdClass();
                $storageMetadata = is_object($components->storageMetadata ?? null)
                    ? $components->storageMetadata : new \stdClass();
                // FeaturedRenderFMutableObject expects the raw JSON state,
                // not TStoreItem's {type: ...} wrapper object. Persisting the
                // wrapper makes Flash coerce it to "[object Object]" and
                // fail JSON parsing during the next world load.
                $metadataValue = $isBasePigpenSow
                    ? json_encode(self::basePigpenSowDna()) : $effectiveStoredMetadata;
                if (!$isBasePigpenSow) {
                    if (is_object($metadataValue) && isset($metadataValue->type) && is_string($metadataValue->type)) {
                        $metadataValue = $metadataValue->type;
                    } elseif (is_array($metadataValue) && isset($metadataValue['type']) && is_string($metadataValue['type'])) {
                        $metadataValue = $metadataValue['type'];
                    } elseif (is_object($metadataValue) || is_array($metadataValue)) {
                        $metadataValue = json_encode($metadataValue);
                    }
                }
                $metadataHash = self::mutableAnimalMetadataHash($metadataValue);
                $metadataKey = $storageItemCode . ':' . ($metadataHash ?? '');
                $entries = $storageMetadata->{$metadataKey} ?? [];
                $entries = is_array($entries) ? $entries : [];
                for ($i = 0; $i < max(1, $numToStore); $i++) {
                    if (is_string($metadataValue) && $metadataValue !== '') {
                        $entries[] = $metadataValue;
                    }
                }
                $storageMetadata->{$metadataKey} = $entries;
                $components->storageMetadata = $storageMetadata;
                $storedBuilding->components = $components;
            }

            $completion = $this->pigpenConstructionCompletion($storedBuilding, $contents);
            if ($completion === null && $storedBuilding->class_name === 'MutableAnimalBaby') {
                // The final bottle is the normal, free completion path for a
                // breeding baby. Persist the same adult transition that the
                // explicit TransformBuilding action uses so a reload cannot
                // leave the client displaying a permanent 10/10 baby.
                $completion = MutableAnimalCompletion::forBaby($storedBuilding, $contents, true);
                if ($completion !== null) {
                    Logger::debug('World', sprintf(
                        'Mutable baby final bottle transform: uid=%s objectId=%d item=%s finished=%s',
                        $this->uid,
                        (int) $storedBuilding->object_id,
                        (string) $storedBuilding->item_name,
                        (string) $completion['finishedName'],
                    ));
                }
            }
            if ($completion !== null) {
                $storedBuilding->item_name = $completion['finishedName'];
                $storedBuilding->class_name = $completion['finishedClassName'];
                $storedBuilding->state = $completion['finishedState'];
                $storedBuilding->contents = [];
                $finishedComponents = new \stdClass();
                if (isset($completion['mutableAnimalState'])
                    && is_object($completion['mutableAnimalState'])) {
                    $finishedComponents->mutableAnimalState = $completion['mutableAnimalState'];
                }
                $storedBuilding->components = $finishedComponents;
                $contents = [];
            } else {
                $storedBuilding->contents = $contents;
            }

            $this->synchronizeFeatureStorageSlots($storedBuilding, $contents);
            $storedBuilding->save();

            return [
                'contents' => $contents,
                'completion' => $completion,
            ];
        });

        if ($contents === false) {
            return false;
        }

        $completion = is_array($contents['completion'] ?? null)
            ? $contents['completion'] : null;
        $contents = is_array($contents['contents'] ?? null)
            ? $contents['contents'] : [];

        $building = $currWorld["objectsArray"][$buildingKey];
        $building->contents = $contents;
        if ($completion !== null) {
            $building->itemName = $completion['finishedName'];
            $building->className = $completion['finishedClassName'];
            $building->state = $completion['finishedState'];
            $finishedComponents = new \stdClass();
            if (isset($completion['mutableAnimalState'])
                && is_object($completion['mutableAnimalState'])) {
                $finishedComponents->mutableAnimalState = $completion['mutableAnimalState'];
            }
            $building->components = $finishedComponents;
        }
        foreach ($currWorld["objectsArray"] as $key => $obj) {
            if ((int) ($obj->id ?? 0) === $resourceId) {
                unset($currWorld["objectsArray"][$key]);
            }
        }
        $currWorld["objectsArray"] = array_values($currWorld["objectsArray"]);
        foreach ($currWorld["objectsArray"] as $key => $obj) {
            if ((int) ($obj->id ?? 0) === (int) $buildingId) {
                $currWorld["objectsArray"][$key] = $building;
                break;
            }
        }

        $this->worldData = $currWorld;

        if ($requestedResourceId !== $resourceId) {
            $this->forgetTemporaryObjectId($requestedResourceId);
        }

        return [
            'success' => true,
            'id' => $resourceId,
            'itemCode' => $itemCode,
            'quantity' => max(1, $numToStore),
            'completion' => $completion,
        ];
    }

    /**
     * Store an item in the player's Home Inventory (-2).
     *
     * Flash uses the same `store` world action for both a physical storage
     * building and Home Inventory.  In the latter form the action object is
     * the resource being stored, not a building.  Treating it as a building
     * wrote its contents and then removed it from the world, losing the item.
     */
    public function storeInHomeInventory($storeParams){
        $itemCode = $storeParams->storedItemCode ?? $storeParams->code ?? null;
        $resourceId = (int) ($storeParams->resource ?? 0);
        $quantity = max(1, (int) ($storeParams->numToStore ?? 1));

        if (!$itemCode) {
            Logger::error('World', "storeInHomeInventory: missing item code uid={$this->uid}");
            return false;
        }

        $currentWorldType = get_meta($this->uid, 'currentWorldType') ?: 'farm';
        $currWorld = empty($this->worldData)
            ? getWorldByType($this->uid, $currentWorldType)
            : $this->worldData;
        $resourceKey = null;

        if ($resourceId > 0) {
            foreach ($currWorld['objectsArray'] as $key => $obj) {
                if ((int) ($obj->id ?? 0) === $resourceId) {
                    $resourceKey = $key;
                    break;
                }
            }

            // Never grant/remove an on-farm item unless the server can see
            // that exact object. A rejected request leaves the farm intact.
            if ($resourceKey === null) {
                Logger::error('World', "storeInHomeInventory: resource not found uid={$this->uid} resourceId={$resourceId}");
                return false;
            }
        }

        // `packStoreItemMetaData` carries per-animal state such as names and
        // breeding information. Persist it beside the inventory count so a
        // later placement recreates the original object rather than a blank
        // copy.
        $metadata = $storeParams->metadata ?? null;
        addToInventoryStorage($this->uid, $itemCode, $quantity, $metadata);

        if ($resourceKey !== null) {
            unset($currWorld['objectsArray'][$resourceKey]);
            $currWorld['objectsArray'] = array_values($currWorld['objectsArray']);
            $this->worldData = $currWorld;

            if (!WorldPersistence::deleteObject($this->uid, $currentWorldType, $resourceId)) {
                // The inventory write is deliberately first: a save failure
                // may duplicate an item, but can never destroy one.
                throw new \Exception("Failed to save world data (storeInHomeInventory) for uid={$this->uid}");
            }
        }

        return [
            'success' => true,
            'id' => $resourceId,
            'itemCode' => $itemCode,
            'quantity' => $quantity,
        ];
    }

    public function withdrawItem($buildingId, $itemCode, $count = 1){
        $extraData = withdrawFromInventoryStorage($this->uid, $itemCode);
        if ($count > 1) {
            removeFromInventoryStorage($this->uid, $itemCode, $count - 1);
        }
        return $extraData;
    }

    /**
     * Removes one item from a particular world storage building. Unlike home
     * inventory, FeatureBuilding storage (such as a Pet Run) belongs to the
     * building's `contents` array and has to survive a world reload.
     */
    public function withdrawStoredItem($buildingId, $itemCode){
        return $this->adjustStoredItemCount($buildingId, $itemCode, -1);
    }

    /**
     * Withdraw one mutable animal and its per-instance DNA metadata from a
     * FeatureBuilding. Returns false when the older save has no metadata; the
     * caller can then fall back to the ordinary count-only withdrawal.
     *
     * @return array{metadata: string|null}|false
     */
    public function withdrawMutableAnimal($buildingId, $itemCode){
        $currentWorldType = get_meta($this->uid, 'currentWorldType') ?: 'farm';
        return WorldPersistence::transaction($this->uid, $currentWorldType, function (int $worldId) use ($buildingId, $itemCode) {
            $building = WorldObject::query()->where('world_id', $worldId)
                ->where('object_id', (int) $buildingId)->where('deleted', false)
                ->lockForUpdate()->first();
            if ($building === null) return false;

            $contents = is_array($building->contents) ? $building->contents : [];
            $hasItem = false;
            foreach ($contents as $content) {
                $code = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
                $count = is_object($content) ? (int) ($content->numItem ?? 0) : (int) ($content['numItem'] ?? 0);
                if ($code === $itemCode && $count > 0) { $hasItem = true; break; }
            }
            if (!$hasItem) return false;

            $components = is_object($building->components) ? $building->components : new \stdClass();
            $storageMetadata = is_object($components->storageMetadata ?? null)
                ? $components->storageMetadata : new \stdClass();
            $keys = array_keys(get_object_vars($storageMetadata));
            $metadataKey = null;
            foreach ($keys as $key) {
                if (explode(':', (string) $key, 2)[0] !== (string) $itemCode) continue;
                $entries = $storageMetadata->{$key};
                if (is_array($entries) && $entries !== []) {
                    $metadataKey = $key;
                }
            }
            if ($metadataKey === null) return false;

            $entries = $storageMetadata->{$metadataKey};
            $metadata = array_pop($entries);
            if ($entries === []) unset($storageMetadata->{$metadataKey});
            else $storageMetadata->{$metadataKey} = array_values($entries);
            $components->storageMetadata = $storageMetadata;

            foreach ($contents as $index => $content) {
                $code = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
                if ($code !== $itemCode) continue;
                $count = is_object($content) ? (int) ($content->numItem ?? 0) : (int) ($content['numItem'] ?? 0);
                if ($count <= 1) unset($contents[$index]);
                elseif (is_object($content)) $content->numItem = $count - 1;
                else $contents[$index]['numItem'] = $count - 1;
                break;
            }
            $building->contents = array_values($contents);
            $building->components = $components;
            $this->synchronizeFeatureStorageSlots($building, $building->contents);
            $building->save();

            return ['metadata' => is_string($metadata) ? $metadata : json_encode($metadata)];
        });
    }

    /** Restore a mutable-animal withdrawal, retaining its exact DNA hash key. */
    public function restoreMutableAnimal($buildingId, $itemCode, $metadata): bool {
        if (!is_string($metadata) || $metadata === '') return false;
        $currentWorldType = get_meta($this->uid, 'currentWorldType') ?: 'farm';
        $restored = WorldPersistence::transaction($this->uid, $currentWorldType, function (int $worldId) use ($buildingId, $itemCode, $metadata) {
            $building = WorldObject::query()->where('world_id', $worldId)
                ->where('object_id', (int) $buildingId)->where('deleted', false)
                ->lockForUpdate()->first();
            if ($building === null) return false;
            $contents = is_array($building->contents) ? $building->contents : [];
            $found = false;
            foreach ($contents as $index => $content) {
                $code = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
                if ($code !== $itemCode) continue;
                if (is_object($content)) ++$content->numItem;
                else ++$contents[$index]['numItem'];
                $found = true; break;
            }
            if (!$found) $contents[] = (object) ['itemCode' => $itemCode, 'numItem' => 1];
            $components = is_object($building->components) ? $building->components : new \stdClass();
            $storageMetadata = is_object($components->storageMetadata ?? null) ? $components->storageMetadata : new \stdClass();
            $hash = self::mutableAnimalMetadataHash($metadata);
            $key = $itemCode . ':' . ($hash ?? '');
            $entries = $storageMetadata->{$key} ?? [];
            $entries = is_array($entries) ? $entries : [];
            $entries[] = $metadata;
            $storageMetadata->{$key} = $entries;
            $components->storageMetadata = $storageMetadata;
            $building->contents = $contents;
            $building->components = $components;
            $this->synchronizeFeatureStorageSlots($building, $contents);
            $building->save();
            return true;
        });
        return $restored === true;
    }

    /**
     * Withdraw one mutable animal crate and its per-instance metadata from a
     * FeatureBuilding.  Crates share one item code but can grow into
     * different adults, so the metadata entry is as authoritative as the
     * count in `contents`.
     *
     * @return array{metadata: string|null}|false
     */
    public function withdrawMutableAnimalCrate($buildingId, $itemCode){
        $currentWorldType = get_meta($this->uid, 'currentWorldType') ?: 'farm';
        $result = WorldPersistence::transaction($this->uid, $currentWorldType, function (int $worldId) use ($buildingId, $itemCode) {
            $building = WorldObject::query()
                ->where('world_id', $worldId)
                ->where('object_id', (int) $buildingId)
                ->where('deleted', false)
                ->lockForUpdate()
                ->first();
            if ($building === null) {
                return false;
            }

            $contents = is_array($building->contents) ? $building->contents : [];
            $contentIndex = null;
            foreach ($contents as $key => $content) {
                $code = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
                $count = is_object($content) ? (int) ($content->numItem ?? 0) : (int) ($content['numItem'] ?? 0);
                if ($code === $itemCode && $count > 0) {
                    $contentIndex = $key;
                    break;
                }
            }
            if ($contentIndex === null) {
                return false;
            }

            $components = is_object($building->components) ? $building->components : new \stdClass();
            $storageMetadata = is_object($components->storageMetadata ?? null)
                ? $components->storageMetadata : new \stdClass();
            $metadataKey = $itemCode . ':';
            $metadataEntries = $storageMetadata->{$metadataKey} ?? [];
            if (!is_array($metadataEntries) || empty($metadataEntries)) {
                // A mutable crate without its adultCode cannot be completed
                // safely. Leave the source building unchanged rather than
                // creating an irrecoverable generic crate.
                return false;
            }

            // FeatureBuilding.removeContents() removes the last matching
            // entry when a metadata record is not explicitly identified in a
            // world-action request. Mirror that legacy client behavior.
            $metadata = array_pop($metadataEntries);
            if ($metadataEntries === []) {
                unset($storageMetadata->{$metadataKey});
            } else {
                $storageMetadata->{$metadataKey} = array_values($metadataEntries);
            }
            $components->storageMetadata = $storageMetadata;

            $count = is_object($contents[$contentIndex])
                ? (int) ($contents[$contentIndex]->numItem ?? 0)
                : (int) ($contents[$contentIndex]['numItem'] ?? 0);
            if ($count <= 1) {
                unset($contents[$contentIndex]);
                $contents = array_values($contents);
            } elseif (is_object($contents[$contentIndex])) {
                --$contents[$contentIndex]->numItem;
            } else {
                --$contents[$contentIndex]['numItem'];
            }

            $building->contents = $contents;
            $building->components = $components;
            $this->synchronizeFeatureStorageSlots($building, $contents);
            $building->save();

            return ['metadata' => is_string($metadata) ? $metadata : null];
        });

        return $result;
    }

    /** Restore a metadata-aware crate withdrawal when its placement fails. */
    public function restoreMutableAnimalCrate($buildingId, $itemCode, $metadata): bool {
        if (!is_string($metadata) || $metadata === '') {
            return false;
        }

        $currentWorldType = get_meta($this->uid, 'currentWorldType') ?: 'farm';
        $restored = WorldPersistence::transaction($this->uid, $currentWorldType, function (int $worldId) use ($buildingId, $itemCode, $metadata) {
            $building = WorldObject::query()
                ->where('world_id', $worldId)
                ->where('object_id', (int) $buildingId)
                ->where('deleted', false)
                ->lockForUpdate()
                ->first();
            if ($building === null) {
                return false;
            }

            $contents = is_array($building->contents) ? $building->contents : [];
            $contentIndex = null;
            foreach ($contents as $key => $content) {
                $code = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
                if ($code === $itemCode) {
                    $contentIndex = $key;
                    break;
                }
            }
            if ($contentIndex === null) {
                $contents[] = (object) ['itemCode' => $itemCode, 'numItem' => 1];
            } elseif (is_object($contents[$contentIndex])) {
                ++$contents[$contentIndex]->numItem;
            } else {
                ++$contents[$contentIndex]['numItem'];
            }

            $components = is_object($building->components) ? $building->components : new \stdClass();
            $storageMetadata = is_object($components->storageMetadata ?? null)
                ? $components->storageMetadata : new \stdClass();
            $metadataKey = $itemCode . ':';
            $entries = $storageMetadata->{$metadataKey} ?? [];
            $entries = is_array($entries) ? $entries : [];
            $entries[] = $metadata;
            $storageMetadata->{$metadataKey} = $entries;
            $components->storageMetadata = $storageMetadata;

            $building->contents = $contents;
            $building->components = $components;
            $this->synchronizeFeatureStorageSlots($building, $contents);
            $building->save();

            return true;
        });

        return $restored === true;
    }

    /** Restore a building-stored item if its subsequent world placement fails. */
    public function restoreStoredItem($buildingId, $itemCode){
        return $this->adjustStoredItemCount($buildingId, $itemCode, 1);
    }

    private function adjustStoredItemCount($buildingId, $itemCode, $delta){
        $currentWorldType = get_meta($this->uid, 'currentWorldType') ?: 'farm';
        $currWorld = empty($this->worldData)
            ? getWorldByType($this->uid, $currentWorldType)
            : $this->worldData;
        $contents = WorldPersistence::transaction($this->uid, $currentWorldType, function (int $worldId) use ($buildingId, $itemCode, $delta) {
            $building = WorldObject::query()
                ->where('world_id', $worldId)
                ->where('object_id', (int) $buildingId)
                ->where('deleted', false)
                ->lockForUpdate()
                ->first();
            if ($building === null) {
                return false;
            }

            $contents = is_array($building->contents) ? $building->contents : [];
            $contentIndex = null;
            $currentCount = 0;

            foreach ($contents as $key => $content) {
                $code = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
                if ($code === $itemCode) {
                    $contentIndex = $key;
                    $currentCount = is_object($content)
                        ? (int) ($content->numItem ?? 0)
                        : (int) ($content['numItem'] ?? 0);
                    break;
                }
            }

            if ($delta < 0 && ($contentIndex === null || $currentCount < abs($delta))) {
                return false;
            }

            if ($contentIndex === null) {
                $contents[] = (object) [
                    'itemCode' => $itemCode,
                    'numItem' => $delta,
                ];
            } else {
                $newCount = $currentCount + $delta;
                if ($newCount <= 0) {
                    unset($contents[$contentIndex]);
                    $contents = array_values($contents);
                } elseif (is_object($contents[$contentIndex])) {
                    $contents[$contentIndex]->numItem = $newCount;
                } else {
                    $contents[$contentIndex]['numItem'] = $newCount;
                }
            }

            $building->contents = $contents;
            $this->synchronizeFeatureStorageSlots($building, $contents);
            $building->save();

            return $contents;
        });

        if ($contents === false) {
            return false;
        }

        foreach ($currWorld['objectsArray'] as $buildingKey => $building) {
            if ((int) ($building->id ?? 0) === (int) $buildingId) {
                $building->contents = $contents;
                $currWorld['objectsArray'][$buildingKey] = $building;
                $this->worldData = $currWorld;
                return true;
            }
        }

        return false;
    }

    public function setAvatar($attribs){
        if (is_numeric($this->uid) && is_array($attribs)){
            $attribs = serialize($attribs);
            UserAvatar::updateAttributes($this->uid, $attribs);
        }
    }

    public function expandWorld($newSizeX, $newSizeY){
        $currentWorldType = get_meta($this->uid, "currentWorldType") ?: "farm";

        if (empty($this->worldData)){
            $currWorld = getWorldByType($this->uid, $currentWorldType);
        }else{
            $currWorld = $this->worldData;
        }

        $currWorld["sizeX"] = $newSizeX;
        $currWorld["sizeY"] = $newSizeY;

        $this->worldData = $currWorld;

        UserWorld::updateSize($this->uid, $currentWorldType, $newSizeX, $newSizeY);

        return $currWorld;
    }

    public function getPlayerDataForNeighbor(){
        $rows = [];

        if (is_numeric($this->uid)){
            $rows = User::join('usermeta', 'users.uid', '=', 'usermeta.uid')
                ->where('users.uid', '<>', $this->uid)
                ->select([
                    'users.uid as uid',
                    'users.name as name',
                    'usermeta.firstName as firstname',
                    'usermeta.lastName as lastname'
                ])
                ->get()
                ->toArray();
        }

        return $rows;
    }

    public function getCurrentNeighbors(){
        $currNeighbors = get_meta($this->uid, 'current_neighbors');

        if (!$currNeighbors){
            return [];
        }

        $currNeighborUids = @unserialize($currNeighbors) ?: [];
        if (empty($currNeighborUids)) {
            return [];
        }

        return $this->getPlayersDataBatch($currNeighborUids);
    }

    
    private function getPlayersDataBatch(array $uids){
        if (empty($uids)) {
            return [];
        }

        $uids = array_values(array_unique(array_filter($uids, 'is_numeric')));
        if (empty($uids)) {
            return [];
        }

        $usersData = User::join('usermeta', 'users.uid', '=', 'usermeta.uid')
            ->whereIn('users.uid', $uids)
            ->select([
                'users.uid as uid',
                'users.name as name',
                'usermeta.firstName as firstname',
                'usermeta.lastName as lastname',
                'usermeta.xp as xp',
                'usermeta.gold as gold',
                'usermeta.profile_picture as profile_picture'
            ])
            ->get()
            ->keyBy('uid')
            ->toArray();

        $avatarRows = UserAvatar::whereIn('uid', $uids)->get();
        $avatars = [];
        foreach ($avatarRows as $avatarRow) {
            $avatars[$avatarRow->uid] = ($avatarRow->value !== null) ? @unserialize($avatarRow->value) : null;
        }

        $worldsRows = PlayerMeta::whereIn('uid', $uids)
            ->where('meta_key', 'unlocked_worlds')
            ->get();
        $unlockedWorldsData = [];
        foreach ($worldsRows as $worldsRow) {
            $unlockedWorldsData[$worldsRow->uid] = $worldsRow->meta_value;
        }

        $validPurchasable = VALID_PURCHASABLE_WORLDS;

        $neighborData = [];
        foreach ($uids as $uid) {
            if (!isset($usersData[$uid])) {
                continue;
            }

            $row = $usersData[$uid];
            $xp = (int) ($row['xp'] ?? 0);
            $gold = (int) ($row['gold'] ?? 0);
            $level = getLevelForXp($xp);
            $avatar = $avatars[$uid] ?? null;

            $picSquare = $row['profile_picture'] ?: "https://fv-assets.s3.us-east-005.backblazeb2.com/profile-pictures/default_avatar.png";

            $unlockedWorlds = ['farm'];
            if (isset($unlockedWorldsData[$uid])) {
                $worlds = @unserialize($unlockedWorldsData[$uid]);
                if (is_array($worlds)) {
                    $purchasedWorlds = array_intersect($worlds, $validPurchasable);
                    $unlockedWorlds = array_values(array_unique(array_merge($unlockedWorlds, $purchasedWorlds)));
                }
            }

            $neighborData[] = (object) [
                "uid" => (string) $row['uid'],
                "name" => $row['name'],
                "first_name" => $row['firstname'],
                "last_name" => $row['lastname'],
                "level" => $level,
                "xp" => $xp,
                "gold" => $gold,
                "avatar" => $avatar,
                "profilePic" => "",
                "isNeighbor" => true,
                "community" => 0,
                "stats" => null,
                "achievementDetails" => null,
                "mastery" => 0,
                "featureCredits" => null,
                "unlockedWorldTypes" => $unlockedWorlds,
                "worldScores" => null,
                "hasEmailPermission" => false,
                "breedingStats" => null,
                "questIds" => null,
                "is_app_user" => true,
                "valid" => true,
                "allowed_restrictions" => false,
                "pic_square" => $picSquare,
                "pic_big" => $picSquare
            ];
        }

        return $neighborData;
    }

    private function getCurrentNeighborUids(){
        $currNeighbors = get_meta($this->uid, 'current_neighbors');

        if (!$currNeighbors){
            return [];
        }
        return @unserialize($currNeighbors) ?: [];
    }

    public function setPendingNeighbors($pid){

        $pid = (string) $pid;
        if ($pid === '' || $pid === (string) $this->uid) {
            return;
        }

        // Neighbor requests are accepted by default. Players can opt out
        // through the Account Settings modal; the same player metadata key is
        // used by the Laravel neighbor endpoints.
        if ($this->autoAcceptNeighborRequests($pid)) {
            $this->addCurrentNeighbor($pid, (string) $this->uid);
            $this->addCurrentNeighbor((string) $this->uid, $pid);
            $this->removePendingNeighbor($pid, (string) $this->uid);
            return;
        }

        $res_uns = [];

        $currNeighbors = get_meta($pid, 'pending_neighbors');
        if ($currNeighbors){
            $res_uns = @unserialize($currNeighbors) ?: [];
            if (!in_array($this->uid, $res_uns)){
                $res_uns[] = $this->uid;
            }
        }else{
            $res_uns[] = $this->uid;
        }

        set_meta($pid, 'pending_neighbors', serialize($res_uns));

    }

    private function autoAcceptNeighborRequests(string $uid): bool
    {
        $value = get_meta($uid, 'auto_accept_neighbor_requests');
        if (!is_string($value) || trim($value) === '') {
            return true;
        }

        return !in_array(strtolower(trim($value)), ['0', 'false', 'off', 'no'], true);
    }

    private function addCurrentNeighbor(string $uid, string $neighborId): void
    {
        $raw = get_meta($uid, 'current_neighbors');
        $neighbors = is_string($raw) ? (@unserialize($raw) ?: []) : [];
        $neighbors = is_array($neighbors) ? array_map('strval', $neighbors) : [];

        if (!in_array($neighborId, $neighbors, true)) {
            $neighbors[] = $neighborId;
            set_meta($uid, 'current_neighbors', serialize(array_values($neighbors)));
        }
    }

    private function removePendingNeighbor(string $uid, string $neighborId): void
    {
        $raw = get_meta($uid, 'pending_neighbors');
        $pending = is_string($raw) ? (@unserialize($raw) ?: []) : [];
        $pending = is_array($pending)
            ? array_values(array_filter($pending, static fn ($id) => (string) $id !== $neighborId))
            : [];

        set_meta($uid, 'pending_neighbors', serialize($pending));
    }

    public function getPendingNeighbors(){
        $pendingNeighbors = get_meta($this->uid, 'pending_neighbors');

        if (!$pendingNeighbors){
            return [];
        }
        return @unserialize($pendingNeighbors) ?: [];
    }

    
    private function getFeatureFrequencies() {
        $raw = get_meta($this->uid, 'feature_frequency_timestamps');
        $stored = $raw ? (@unserialize($raw) ?: []) : [];

        $defaults = [
            "AvatarIndicatorLastInteraction" => 10,
            "r2AddNeighborInFlashPop" => 0
        ];

        return array_merge($defaults, $stored);
    }
}
