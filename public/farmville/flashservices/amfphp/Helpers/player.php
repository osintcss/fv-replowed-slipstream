<?php
require_once AMFPHP_ROOTPATH . "Helpers/general_functions.php";
require_once AMFPHP_ROOTPATH . "Helpers/crafting_helper.php";
require_once AMFPHP_ROOTPATH . "Helpers/constants.php";
require_once AMFPHP_ROOTPATH . "Helpers/quest_helper.php";
require_once AMFPHP_ROOTPATH . "Helpers/collision.php";

use App\Models\UserMeta;
use App\Models\UserAvatar;
use App\Models\UserWorld;
use App\Models\User;
use App\Models\PlayerMeta;

class Player {

    private $uid = null;
    private $pData = array();
    private $worldData = array();
    private $avatarData = array();

    public function __construct($id) {
        $this->uid = $id;
    }

    public function getUid(){
        return $this->uid;
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
                        INVENTORY_STORAGE_KEY => buildInventoryStorageData($this->uid)
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
                    'seenFlags' => @unserialize($row['seenFlags']) ?: [], //tutorial flag
                    'itemFlags' => $itemFlags,
                    'featureFrequency' => $this->getFeatureFrequencies(),
                    'externalLevels' => array(

                    ),
                    'actionCounts' => ["AvatarSurfaceThrottle_backoff_base"],
                    'neighborActionLimits' => array(
                        'm_neighborActionLimits' => getNeighborActionLimits($this->uid)
                    ),
                    'energyManager' => array(
                        "turboChargers" => 0
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

        return [
            "world_seasons" => [
                "farm" => 0,
                "avalon" => 1
            ],
            "irrigation" => [
                "irrigation" => $irrigationData
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

        if (($action == ACTION_PLOW || $action == ACTION_PLANT) && $incomingObjectId !== null && $incomingObjectId >= TEMP_ID_THRESHOLD){
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
                    if ($itemData && isset($itemData["growTime"])){
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

            $className = $newObj->className ?? "";

            switch ($action) {
                case ACTION_HARVEST:
                    $postHarvest = getPostHarvestState($className);
                    $newObj->state = $postHarvest['state'];
                    $newObj->plantTime = $postHarvest['plantTime'];
                    if (isset($postHarvest['itemName'])) {
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
            $saveResult = saveWorld($this->uid, $currentWorldType, $currWorld);
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
                $dbResult = deleteWorldObjectByPosition($worldId, (int)$newPosX, (int)$newPosY);
                break;
            case 'UPDATE':
                $dbResult = updateWorldObjectFull($worldId, $newObj);
                break;
            case 'INSERT':
                $dbResult = insertWorldObject($worldId, $newObj);
                break;
        }

        invalidateWorldCache($this->uid, $currentWorldType);

        if (!$dbResult) {
            throw new \Exception("Failed to perform $operationType on world object for uid={$this->uid}");
        }

        if ($newId > 0){
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

    public function storeItem($buildingObj, $storeParams){
        $currentWorldType = get_meta($this->uid, "currentWorldType") ?: "farm";

        if (empty($this->worldData)){
            $currWorld = getWorldByType($this->uid, $currentWorldType);
        }else{
            $currWorld = $this->worldData;
        }

        $buildingId = $buildingObj->id ?? null;
        $itemCode = $storeParams->storedItemCode ?? null;
        $resourceId = (int) ($storeParams->resource ?? 0);
        $numToStore = (int) ($storeParams->numToStore ?? 1);

        if (!$buildingId || !$itemCode) return false;

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

        $building = $currWorld["objectsArray"][$buildingKey];
        $contents = isset($building->contents) && is_array($building->contents)
            ? $building->contents : [];

        // Flash keeps storage contents on the building itself. A Pet Run sends
        // this action after an animal is harvested from the farm; storing it in
        // the player's generic inventory instead made the client and server
        // disagree after a reload, which is what caused disappearing/duplicate
        // animals.
        $contentIndex = null;
        foreach ($contents as $key => $content) {
            if (is_object($content) && ($content->itemCode ?? null) === $itemCode) {
                $contentIndex = $key;
                break;
            }
            if (is_array($content) && ($content['itemCode'] ?? null) === $itemCode) {
                $contentIndex = $key;
                break;
            }
        }

        if ($contentIndex === null) {
            $contents[] = (object) [
                'itemCode' => $itemCode,
                'numItem' => max(1, $numToStore),
            ];
        } elseif (is_object($contents[$contentIndex])) {
            $contents[$contentIndex]->numItem = (int) ($contents[$contentIndex]->numItem ?? 0) + max(1, $numToStore);
        } else {
            $contents[$contentIndex]['numItem'] = (int) ($contents[$contentIndex]['numItem'] ?? 0) + max(1, $numToStore);
        }

        $building->contents = $contents;
        if ($resourceId > 0) {
            foreach ($currWorld["objectsArray"] as $key => $obj) {
                if ((int) ($obj->id ?? 0) === $resourceId) {
                    unset($currWorld["objectsArray"][$key]);
                    $currWorld["objectsArray"] = array_values($currWorld["objectsArray"]);
                    break;
                }
            }
        }

        // The resource removal above may have reindexed objectsArray.
        foreach ($currWorld["objectsArray"] as $key => $obj) {
            if ((int) ($obj->id ?? 0) === (int) $buildingId) {
                $currWorld["objectsArray"][$key] = $building;
                break;
            }
        }

        $this->worldData = $currWorld;
        $saveResult = saveWorld($this->uid, $currentWorldType, $currWorld);
        if (!$saveResult) {
            throw new \Exception("Failed to save world data (storeItem) for uid={$this->uid}");
        }

        return [
            'success' => true,
            'id' => $resourceId,
            'itemCode' => $itemCode,
            'quantity' => max(1, $numToStore),
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

            if (!saveWorld($this->uid, $currentWorldType, $currWorld)) {
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

    /** Restore a building-stored item if its subsequent world placement fails. */
    public function restoreStoredItem($buildingId, $itemCode){
        return $this->adjustStoredItemCount($buildingId, $itemCode, 1);
    }

    private function adjustStoredItemCount($buildingId, $itemCode, $delta){
        $currentWorldType = get_meta($this->uid, 'currentWorldType') ?: 'farm';
        $currWorld = empty($this->worldData)
            ? getWorldByType($this->uid, $currentWorldType)
            : $this->worldData;

        foreach ($currWorld['objectsArray'] as $buildingKey => $building) {
            if ((int) ($building->id ?? 0) !== (int) $buildingId) {
                continue;
            }

            $contents = isset($building->contents) && is_array($building->contents)
                ? $building->contents : [];
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
            $currWorld['objectsArray'][$buildingKey] = $building;
            $this->worldData = $currWorld;

            if (!saveWorld($this->uid, $currentWorldType, $currWorld)) {
                throw new \Exception("Failed to save building storage for uid={$this->uid} buildingId={$buildingId}");
            }

            return true;
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
