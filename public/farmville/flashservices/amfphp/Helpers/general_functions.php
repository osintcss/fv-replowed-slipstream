<?php
    require_once AMFPHP_ROOTPATH . "Helpers/constants.php";
    require_once AMFPHP_ROOTPATH . "Helpers/logger.php";
    require_once AMFPHP_ROOTPATH . "Helpers/friend_set_helper.php";

    use App\Helpers\JsonHelper;
    use App\Helpers\ObjectHelper;
    use App\Models\WorldObject;
    use App\Models\Item;
    use App\Models\PlayerMeta;
    use App\Models\UserWorld;
    use App\Models\UserMeta;

    function sanitizeNumericValue($value, $default = 0) {
        if ($value === null || $value === '') {
            return $default;
        }
        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            return $default;
        }
        if (is_string($value) && in_array(strtoupper($value), ['NAN', 'INF', '-INF', 'INFINITY', '-INFINITY'])) {
            return $default;
        }
        if (!is_numeric($value)) {
            return $default;
        }
        return $value;
    }

    
    function getNextAvailableId($worldObjects) {
        $usedIds = [];
        foreach ($worldObjects as $obj) {
            if (isset($obj->id) && $obj->id > 0 && $obj->id < TEMP_ID_THRESHOLD) {
                $usedIds[$obj->id] = true;
            }
        }

        $maxSafeId = TEMP_ID_THRESHOLD - 1;
        for ($i = 1; $i <= $maxSafeId; $i++) {
            if (!isset($usedIds[$i])) {
                return $i;
            }
        }

        return null;
    }

    
    function get_meta($uid, $meta_key){
        if (!is_numeric($uid) || !is_string($meta_key) || $meta_key === "") {
            return false;
        }

        return PlayerMeta::getValue($uid, $meta_key);
    }

    
    function set_meta($uid, $meta_key, $meta_value){
        if (!is_numeric($uid) || !is_string($meta_key) || $meta_key === "") {
            return false;
        }

        return PlayerMeta::setValue($uid, $meta_key, $meta_value);
    }

    
    function getCurrentWorldType($uid) {
        return get_meta($uid, "currentWorldType") ?: "farm";
    }

    
    function getGiftBox($uid) {
        $raw = get_meta($uid, 'giftbox');
        if ($raw) {
            $data = @unserialize($raw);
            if (is_array($data)) return $data;
        }
        return [];
    }

    
    function saveGiftBox($uid, $giftbox) {
        set_meta($uid, 'giftbox', serialize($giftbox));
    }

    
    function addGiftByName($uid, $itemName, $quantity = 1, $senderId = null, $extraData = null) {
        $item = getItemByName($itemName, "db");
        if ($item && isset($item['code'])) {
            Logger::debug('addGiftByName', "Adding gift: uid=$uid, name=$itemName, code={$item['code']}, qty=$quantity");
            addGiftByCode($uid, $item['code'], $quantity, $senderId, $extraData);
        } else {
            Logger::debug('addGiftByName', "Item not found: uid=$uid, name=$itemName");
        }
    }

    
    function addGiftByCode($uid, $itemCode, $quantity = 1, $senderId = null, $extraData = null) {
        $giftbox = getGiftBox($uid);
        
        $extraDataObj = null;
        if ($extraData !== null) {
            $extraDataObj = is_array($extraData) ? (object)$extraData : $extraData;
        }
        
        if (isset($giftbox[$itemCode])) {
            $giftbox[$itemCode][0] += $quantity;
            if ($senderId) {
                $giftbox[$itemCode][1][] = $senderId;
            }
            if ($extraDataObj !== null) {
                if (!isset($giftbox[$itemCode][2]) || !is_array($giftbox[$itemCode][2])) {
                    $giftbox[$itemCode][2] = [];
                }
                for ($i = 0; $i < $quantity; $i++) {
                    $giftbox[$itemCode][2][] = $extraDataObj;
                }
            }
        } else {
            $extraDataArray = [];
            if ($extraDataObj !== null) {
                for ($i = 0; $i < $quantity; $i++) {
                    $extraDataArray[] = $extraDataObj;
                }
            }
            $giftbox[$itemCode] = [
                $quantity,
                $senderId ? [$senderId] : [],
                $extraDataArray
            ];
        }
        saveGiftBox($uid, $giftbox);
    }


    function removeGiftByCode($uid, $itemCode, $quantity = 1) {
        $giftbox = getGiftBox($uid);

        if (!isset($giftbox[$itemCode]) || $giftbox[$itemCode][0] < $quantity) {
            return false;
        }

        $giftbox[$itemCode][0] -= $quantity;

        if ($giftbox[$itemCode][0] <= 0) {
            unset($giftbox[$itemCode]);
        }

        saveGiftBox($uid, $giftbox);
        return true;
    }


    function buildGiftBoxStorageData($uid) {
        $giftbox = getGiftBox($uid);
        $storageData = [];
        foreach ($giftbox as $code => $data) {
            if ($data[0] > 0) {
                $storageData[$code] = $data;
            }
        }
        return $storageData;
    }


    function getInventoryStorage($uid) {
        $raw = get_meta($uid, 'inventory_storage');
        if ($raw) {
            $data = @unserialize($raw);
            if (is_array($data)) return $data;
        }
        return [];
    }

    function saveInventoryStorage($uid, $storage) {
        set_meta($uid, 'inventory_storage', serialize($storage));
    }

    function addToInventoryStorage($uid, $itemCode, $quantity = 1, $extraData = null) {
        $storage = getInventoryStorage($uid);
        
        $extraDataObj = null;
        if ($extraData !== null) {
            $extraDataObj = is_array($extraData) ? (object)$extraData : $extraData;
        }
        
        if (isset($storage[$itemCode])) {
            $storage[$itemCode][0] += $quantity;
            if ($extraDataObj !== null) {
                if (!isset($storage[$itemCode][2]) || !is_array($storage[$itemCode][2])) {
                    $storage[$itemCode][2] = [];
                }
                for ($i = 0; $i < $quantity; $i++) {
                    $storage[$itemCode][2][] = $extraDataObj;
                }
            }
        } else {
            $extraDataArray = [];
            if ($extraDataObj !== null) {
                for ($i = 0; $i < $quantity; $i++) {
                    $extraDataArray[] = $extraDataObj;
                }
            }
            $storage[$itemCode] = [
                $quantity,
                [],
                $extraDataArray
            ];
        }
        saveInventoryStorage($uid, $storage);
    }

    function removeFromInventoryStorage($uid, $itemCode, $quantity = 1) {
        $storage = getInventoryStorage($uid);

        if (!isset($storage[$itemCode]) || $storage[$itemCode][0] < $quantity) {
            return false;
        }

        $storage[$itemCode][0] -= $quantity;

        if (isset($storage[$itemCode][2]) && is_array($storage[$itemCode][2])) {
            for ($i = 0; $i < $quantity && count($storage[$itemCode][2]) > 0; $i++) {
                array_shift($storage[$itemCode][2]);
            }
        }

        if ($storage[$itemCode][0] <= 0) {
            unset($storage[$itemCode]);
        }

        saveInventoryStorage($uid, $storage);
        return true;
    }

    function buildInventoryStorageData($uid) {
        $storage = getInventoryStorage($uid);
        $storageData = [];
        foreach ($storage as $code => $data) {
            if ($data[0] > 0) {
                $storageData[$code] = $data;
            }
        }
        return $storageData;
    }

    function withdrawFromInventoryStorage($uid, $itemCode) {
        $storage = getInventoryStorage($uid);

        if (!isset($storage[$itemCode]) || $storage[$itemCode][0] <= 0) {
            return null;
        }
        
        $extraData = null;
        
        if (isset($storage[$itemCode][2]) && is_array($storage[$itemCode][2]) && count($storage[$itemCode][2]) > 0) {
            $extraData = array_shift($storage[$itemCode][2]);
        }
        
        $storage[$itemCode][0]--;
        
        if ($storage[$itemCode][0] <= 0) {
            unset($storage[$itemCode]);
        }
        
        saveInventoryStorage($uid, $storage);

        return $extraData;
    }


    function peekInventoryStorageExtraData($uid, $itemCode) {
        $storage = getInventoryStorage($uid);
        
        if (!isset($storage[$itemCode]) || $storage[$itemCode][0] <= 0) {
            return null;
        }
        
        if (isset($storage[$itemCode][2]) && is_array($storage[$itemCode][2]) && count($storage[$itemCode][2]) > 0) {
            return $storage[$itemCode][2][0];
        }
        
        return null;
    }

    
    function withdrawGiftboxItem($uid, $itemCode) {
        $giftbox = getGiftBox($uid);
        
        if (!isset($giftbox[$itemCode]) || $giftbox[$itemCode][0] <= 0) {
            return null;
        }
        
        $extraData = null;
        
        if (isset($giftbox[$itemCode][2]) && is_array($giftbox[$itemCode][2]) && count($giftbox[$itemCode][2]) > 0) {
            $extraData = array_shift($giftbox[$itemCode][2]);
        }
        
        if (isset($giftbox[$itemCode][1]) && is_array($giftbox[$itemCode][1]) && count($giftbox[$itemCode][1]) > 0) {
            $sender = array_shift($giftbox[$itemCode][1]);
            if ($extraData === null) {
                $extraData = (object)['sender' => $sender];
            } elseif (is_object($extraData) && !isset($extraData->sender)) {
                $extraData->sender = $sender;
            } elseif (is_array($extraData) && !isset($extraData['sender'])) {
                $extraData['sender'] = $sender;
            }
        }
        
        $giftbox[$itemCode][0]--;
        
        if ($giftbox[$itemCode][0] <= 0) {
            unset($giftbox[$itemCode]);
        }
        
        saveGiftBox($uid, $giftbox);
        
        return $extraData;
    }

    
    function peekGiftboxItemExtraData($uid, $itemCode) {
        $giftbox = getGiftBox($uid);
        
        if (!isset($giftbox[$itemCode]) || $giftbox[$itemCode][0] <= 0) {
            return null;
        }
        
        $extraData = null;
        
        if (isset($giftbox[$itemCode][2]) && is_array($giftbox[$itemCode][2]) && count($giftbox[$itemCode][2]) > 0) {
            $extraData = $giftbox[$itemCode][2][0];
        }
        
        if (isset($giftbox[$itemCode][1]) && is_array($giftbox[$itemCode][1]) && count($giftbox[$itemCode][1]) > 0) {
            $sender = $giftbox[$itemCode][1][0];
            if ($extraData === null) {
                $extraData = (object)['sender' => $sender];
            } elseif (is_object($extraData) && !isset($extraData->sender)) {
                $extraData->sender = $sender;
            } elseif (is_array($extraData) && !isset($extraData['sender'])) {
                $extraData['sender'] = $sender;
            }
        }
        
        return $extraData;
    }

    
    function getFeatureCredits($uid) {
        $raw = get_meta($uid, 'feature_credits');
        if ($raw) {
            $data = JsonHelper::safeDecode($raw, true, []);
            if (is_array($data)) return $data;
        }
        return [];
    }

    function saveFeatureCredits($uid, $credits) {
        set_meta($uid, 'feature_credits', JsonHelper::safeEncode($credits));
    }

    
    function addFeatureCredit($uid, $worldType, $featureName, $count = 1) {
        $credits = getFeatureCredits($uid);

        if (!isset($credits[$worldType])) {
            $credits[$worldType] = [];
        }
        if (!isset($credits[$worldType][$featureName])) {
            $credits[$worldType][$featureName] = ['current' => 0, 'received' => 0];
        }

        $credits[$worldType][$featureName]['current'] += $count;
        $credits[$worldType][$featureName]['received'] += $count;

        saveFeatureCredits($uid, $credits);
        return $credits;
    }

    
    function getFeatureCreditsForClient($uid) {
        $credits = getFeatureCredits($uid);
        if (empty($credits)) {
            return new \stdClass();
        }

        $result = new \stdClass();
        foreach ($credits as $worldId => $features) {
            $result->{$worldId} = new \stdClass();
            foreach ($features as $featureName => $values) {
                $result->{$worldId}->{$featureName} = (object) [
                    'current' => (int) ($values['current'] ?? 0),
                    'received' => (int) ($values['received'] ?? 0)
                ];
            }
        }
        return $result;
    }

    function compressArray($array){

        $jsonData = JsonHelper::safeEncode($array);

        $compressedData = gzcompress($jsonData);

        $base64Encoded = base64_encode($compressedData);

        return $base64Encoded;
    }

    
    function getNeighborActionLimitsRaw($uid) {
        $data = get_meta($uid, 'neighbor_action_limits');
        if ($data) {
            $limits = @unserialize($data);
            if (is_array($limits)) {
                $today = (int) gmdate('ymd');
                foreach (array_keys($limits) as $dateKey) {
                    if ((int) $dateKey !== $today) {
                        unset($limits[$dateKey]);
                    }
                }
                return $limits;
            }
        }
        return [];
    }

    
    function getNeighborActionLimits($uid) {
        $raw = getNeighborActionLimitsRaw($uid);
        $result = [];
        foreach ($raw as $dateKey => $hostData) {
            $result[$dateKey] = array_values($hostData);
        }
        return $result;
    }

    
    function incrementNeighborAction($uid, $hostId, $actionType) {
        $today = (int) gmdate('ymd');
        $limits = getNeighborActionLimitsRaw($uid);

        foreach (array_keys($limits) as $dateKey) {
            if ((int) $dateKey !== $today) {
                unset($limits[$dateKey]);
            }
        }

        if (!isset($limits[$today])) {
            $limits[$today] = [];
        }

        if (!isset($limits[$today][$hostId])) {
            $limits[$today][$hostId] = [
                'targetId' => $hostId
            ];
        }

        $limitKey = null;
        switch ($actionType) {
            case NEIGHBOR_ACTION_FERT:
            case ACTION_PLOW:
            case NEIGHBOR_ACTION_UNWITHER:
            case ACTION_HARVEST:
                $limitKey = LIMIT_KEY_FARM;
                break;
            case NEIGHBOR_ACTION_FEED_CHICKENS:
                $limitKey = LIMIT_KEY_FEED;
                break;
            case NEIGHBOR_ACTION_TRICK:
                $limitKey = NEIGHBOR_ACTION_TRICK;
                break;
            default:
                $limitKey = $actionType;
        }

        if ($limitKey) {
            $current = $limits[$today][$hostId][$limitKey] ?? 0;
            $limits[$today][$hostId][$limitKey] = $current + 1;
        }

        $neighborData = $limits[$today][$hostId];
        $totalActions = ($neighborData[LIMIT_KEY_FARM] ?? 0)
                      + ($neighborData[LIMIT_KEY_FEED] ?? 0)
                      + ($neighborData[NEIGHBOR_ACTION_TRICK] ?? 0);

        $alreadyRewarded = $neighborData['helpCashRewarded'] ?? false;
        if ($totalActions >= 5 && !$alreadyRewarded) {
            UserResources::addCash($uid, 1);
            $limits[$today][$hostId]['helpCashRewarded'] = true;
            Logger::debug('NeighborAction', "Neighbor help cash awarded: uid=$uid, hostId=$hostId, totalActions=$totalActions");

            $helpRecorded = recordFriendHelp($hostId, $uid, "FS06");
            if ($helpRecorded) {
                Logger::debug('NeighborAction', "Friend set help recorded: hostId=$hostId, helperUid=$uid");
            }
        }

        set_meta($uid, 'neighbor_action_limits', serialize($limits));
        return $limits;
    }

    function getItemByName($itemName, $method = "json"){
        if (!is_string($itemName) || $itemName === "") {
            return false;
        }

        if ($method === "db") {
            return Item::findByName($itemName);
        }

        static $jsonIndex = null;

        if ($jsonIndex === null) {
            $items_str = file_get_contents($_SERVER['DOCUMENT_ROOT'] . "/props/items.json");
            $items = JsonHelper::safeDecode($items_str, false);
            $jsonIndex = [];
            if (isset($items->settings->items->item)) {
                foreach ($items->settings->items->item as $item) {
                    $jsonIndex[$item->name] = (array) $item;
                }
            }
        }

        return $jsonIndex[$itemName] ?? false;
    }

    
    function getItemByCode($itemCode) {
        if (!is_string($itemCode) || $itemCode === "") {
            return false;
        }

        return Item::findByCode($itemCode);
    }

    
    function hasExpandFeature($itemData) {
        if (!$itemData || !isset($itemData['features'])) {
            return false;
        }

        $features = $itemData['features'];

        if (isset($features->feature)) {
            $featureList = $features->feature;
            if (!is_array($featureList)) {
                $featureList = [$featureList];
            }
            foreach ($featureList as $feature) {
                if (isset($feature->name) && $feature->name === 'expand') {
                    return true;
                }
            }
        }

        return false;
    }

    function fixLegacyFeatureBuilding($obj) {
        if (!isset($obj->className) || $obj->className !== 'FeatureBuilding') {
            return $obj;
        }

        if (!isset($obj->itemName)) {
            return $obj;
        }

        if (isset($obj->expansionLevel) && isset($obj->expansionParts)) {
            return $obj;
        }

        $itemData = getItemByName($obj->itemName, "db");
        if ($itemData && hasExpandFeature($itemData)) {
            if (!isset($obj->expansionLevel)) {
                $obj->expansionLevel = isset($itemData['initialExpansionLevel'])
                    ? (int)$itemData['initialExpansionLevel']
                    : 1;
            }
            if (!isset($obj->expansionParts)) {
                $obj->expansionParts = new \stdClass();
            }
        }

        return $obj;
    }

    
    function getExpansionUpgradeData($itemData, $currentLevel) {
        if (!$itemData || !isset($itemData['features'])) {
            return null;
        }

        $features = $itemData['features'];
        if (!isset($features->feature)) {
            return null;
        }

        $featureList = is_array($features->feature) ? $features->feature : [$features->feature];

        foreach ($featureList as $feature) {
            if (isset($feature->name) && $feature->name === 'expand' && isset($feature->upgrade)) {
                $upgrades = is_array($feature->upgrade) ? $feature->upgrade : [$feature->upgrade];
                $nextLevel = $currentLevel + 1;

                foreach ($upgrades as $upgrade) {
                    if (isset($upgrade->level) && (int)$upgrade->level === $nextLevel) {
                        return $upgrade;
                    }
                }
            }
        }

        return null;
    }

    
    function isExpansionPart($buildingItemData, $currentLevel, $itemName) {
        $upgradeData = getExpansionUpgradeData($buildingItemData, $currentLevel);

        if (!$upgradeData || !isset($upgradeData->part)) {
            return null;
        }

        $parts = is_array($upgradeData->part) ? $upgradeData->part : [$upgradeData->part];

        foreach ($parts as $part) {
            if (isset($part->name) && $part->name === $itemName) {
                return $part;
            }
        }

        return null;
    }

    
    function checkExpansionComplete($buildingObj, $buildingItemData) {
        $currentLevel = (int)($buildingObj->expansionLevel ?? 1);
        $upgradeData = getExpansionUpgradeData($buildingItemData, $currentLevel);

        if (!$upgradeData || !isset($upgradeData->part)) {
            return false;
        }

        $parts = is_array($upgradeData->part) ? $upgradeData->part : [$upgradeData->part];
        $expansionParts = $buildingObj->expansionParts ?? new \stdClass();

        foreach ($parts as $part) {
            if (!isset($part->name) || !isset($part->need)) {
                continue;
            }

            $partItem = getItemByName($part->name, "db");
            if (!$partItem) {
                return false;
            }

            $partCode = $partItem['code'] ?? $part->name;
            $needed = (int)$part->need;
            $collected = 0;

            if (is_object($expansionParts) && isset($expansionParts->$partCode)) {
                $collected = (int)$expansionParts->$partCode;
            } elseif (is_array($expansionParts) && isset($expansionParts[$partCode])) {
                $collected = (int)$expansionParts[$partCode];
            }

            if ($collected < $needed) {
                return false;
            }
        }

        return true;
    }

    
    
    function getLevelForXp($xp) {
        static $thresholds = [
            1=>0, 2=>15, 3=>30, 4=>70, 5=>140, 6=>250, 7=>400, 8=>600, 9=>850, 10=>1150,
            11=>1500, 12=>1900, 13=>2400, 14=>3000, 15=>3700, 16=>4500, 17=>5400, 18=>6400,
            19=>7500, 20=>8700, 21=>10000, 22=>11500, 23=>13500, 24=>16000, 25=>19000,
            26=>22500, 27=>26500, 28=>31000, 29=>36000, 30=>42000, 31=>49000, 32=>57000,
            33=>65000, 34=>74000, 35=>83000, 36=>93000, 37=>103000, 38=>113000, 39=>123000,
            40=>133000, 41=>143000, 42=>153000, 43=>163000, 44=>173000, 45=>183000,
            46=>193000, 47=>203000, 48=>213000, 49=>223000, 50=>233000, 51=>243000,
            52=>253000, 53=>263000, 54=>273000, 55=>283000, 56=>293000, 57=>303000,
            58=>313000, 59=>323000, 60=>333000, 61=>343000, 62=>353000, 63=>363000,
            64=>373000, 65=>383000, 66=>393000, 67=>403000, 68=>413000, 69=>423000,
            70=>433000, 71=>443500, 72=>454500, 73=>466000, 74=>478000, 75=>490500,
            76=>504000, 77=>518500, 78=>534000, 79=>550500, 80=>568000, 81=>587000,
            82=>607500, 83=>629500, 84=>653000, 85=>678500, 86=>706000, 87=>735500,
            88=>767000, 89=>801000, 90=>837500, 91=>876500, 92=>918500, 93=>963500,
            94=>1012000, 95=>1064000, 96=>1120000, 97=>1180000, 98=>1244500, 99=>1313500,
            100=>1387500
        ];

        $xp = (int) $xp;
        $level = 1;

        for ($i = 100; $i >= 1; $i--) {
            if ($xp >= $thresholds[$i]) {
                $level = $i;
                break;
            }
        }

        if ($xp >= 1500000) {
            $level = 100 + (int) floor(($xp - 1500000) / 100000) + 1;
        }

        return $level;
    }

    function getWorldByType($uid, $type = "farm"){
        if (!isset($GLOBALS['_world_cache'])) {
            $GLOBALS['_world_cache'] = [];
        }

        $cacheKey = "$uid:$type";
        if (isset($GLOBALS['_world_cache'][$cacheKey])) {
            return $GLOBALS['_world_cache'][$cacheKey];
        }

        $worldData = [];

        if (is_numeric($uid) && is_string($type) && $type !== ""){
            $world = UserWorld::getByType($uid, $type);

            if ($world) {
                $worldId = $world->id;
                $worldData["type"] = $world->type;
                $worldData["sizeX"] = $world->sizeX;
                $worldData["sizeY"] = $world->sizeY;
                $worldData["worldId"] = $worldId;

                $worldData["objectsArray"] = getWorldObjectsFromDb($worldId);

                $worldData["creation"] = $world->created_at;
                $msgMgr = @unserialize($world->messageManager);
                $cleanMessages = [];
                if (is_array($msgMgr) && isset($msgMgr["messages"]) && is_array($msgMgr["messages"])) {
                    foreach (array_values($msgMgr["messages"]) as $msg) {
                        $cleanMessages[] = (object) [
                            "id" => (int) ($msg["id"] ?? 0),
                            "message" => (string) ($msg["message"] ?? ""),
                            "authorId" => (string) ($msg["authorId"] ?? ""),
                            "objectId" => (int) ($msg["objectId"] ?? 0),
                            "isNew" => (bool) ($msg["isNew"] ?? true),
                            "timestamp" => (int) ($msg["timestamp"] ?? time())
                        ];
                    }
                }
                $worldData["messageManager"] = (object) [
                    "messages" => $cleanMessages,
                    "allowSendEmails" => (bool) ($msgMgr["allowSendEmails"] ?? true)
                ];
            } else {
                $worldData = createWorldByType($uid, $type);
            }

            if (!empty($worldData)) {
                $worldData["tileSet"] = getTileSetForWorld($worldData["type"]);
            }
        }

        $GLOBALS['_world_cache'][$cacheKey] = $worldData;
        return $worldData;
    }

    
    function sanitizeWorldObject($obj) {
        if (!is_object($obj)) {
            return $obj;
        }

        foreach (get_object_vars($obj) as $prop => $val) {
            if (is_float($val) && (is_nan($val) || is_infinite($val))) {
                $obj->$prop = 0;
            } elseif (is_object($val)) {
                $obj->$prop = sanitizeWorldObject($val);
            } elseif (is_array($val)) {
                foreach ($val as $k => $v) {
                    if (is_object($v)) {
                        $val[$k] = sanitizeWorldObject($v);
                    } elseif (is_float($v) && (is_nan($v) || is_infinite($v))) {
                        $val[$k] = 0;
                    }
                }
                $obj->$prop = $val;
            }
        }
        
        return $obj;
    }

    function invalidateWorldCache($uid, $type) {
        $cacheKey = "$uid:$type";
        unset($GLOBALS['_world_cache'][$cacheKey]);
    }

    function createWorldByType($uid, $type = "farm" ){
        $size = 50;
        $messageManager = serialize(['messages' => [], 'allowSendEmails' => true]);

        if ($type === "farm") {
            $plantTime = getCurrentTimeMs() - calculateGrowTimeMs(3);

            $objects = array(
                0 =>
                (object) array(
                    'plantTime' => 0,
                    'position' =>
                    (object) array(
                    'x' => 27,
                    'z' => 0,
                    'y' => 13,
                    ),
                    'isBigPlot' => false,
                    'direction' => 0,
                    'isJumbo' => true,
                    'deleted' => false,
                    'tempId' => -1,
                    'className' => 'Plot',
                    'state' => 'fallow',
                    'instanceDataStoreKey' => NULL,
                    'components' =>
                    (object) array(
                    ),
                    'isProduceItem' => false,
                    'id' => 1,
                    'itemName' => NULL,
                ),
                1 =>
                (object) array(
                    'plantTime' => 0,
                    'position' =>
                    (object) array(
                    'x' => 27,
                    'z' => 0,
                    'y' => 9,
                    ),
                    'isBigPlot' => false,
                    'direction' => 0,
                    'isJumbo' => true,
                    'deleted' => false,
                    'tempId' => -1,
                    'className' => 'Plot',
                    'state' => 'fallow',
                    'instanceDataStoreKey' => NULL,
                    'components' =>
                    (object) array(
                    ),
                    'isProduceItem' => false,
                    'id' => 2,
                    'itemName' => NULL,
                ),
                2 =>
                (object) array(
                    'plantTime' => $plantTime,
                    'position' =>
                    (object) array(
                    'x' => 19,
                    'z' => 0,
                    'y' => 9,
                    ),
                    'isBigPlot' => false,
                    'direction' => 0,
                    'isJumbo' => false,
                    'deleted' => false,
                    'tempId' => -1,
                    'className' => 'Plot',
                    'state' => 'grown',
                    'instanceDataStoreKey' => NULL,
                    'components' =>
                    (object) array(
                    ),
                    'isProduceItem' => false,
                    'id' => 3,
                    'itemName' => 'eggplant',
                ),
                3 =>
                (object) array(
                    'plantTime' => $plantTime,
                    'position' =>
                    (object) array(
                    'x' => 19,
                    'z' => 0,
                    'y' => 13,
                    ),
                    'isBigPlot' => false,
                    'direction' => 0,
                    'isJumbo' => false,
                    'deleted' => false,
                    'tempId' => -1,
                    'className' => 'Plot',
                    'state' => 'grown',
                    'instanceDataStoreKey' => NULL,
                    'components' =>
                    (object) array(
                    ),
                    'isProduceItem' => false,
                    'id' => 4,
                    'itemName' => 'eggplant',
                ),
                4 =>
                (object) array(
                    'plantTime' => 0,
                    'position' =>
                    (object) array(
                    'x' => 23,
                    'z' => 0,
                    'y' => 9,
                    ),
                    'isBigPlot' => false,
                    'direction' => 0,
                    'isJumbo' => false,
                    'deleted' => false,
                    'tempId' => -1,
                    'className' => 'Plot',
                    'state' => 'plowed',
                    'instanceDataStoreKey' => NULL,
                    'components' =>
                    (object) array(
                    ),
                    'isProduceItem' => false,
                    'id' => 5,
                    'itemName' => NULL,
                ),
                5 =>
                (object) array(
                    'plantTime' => 0,
                    'position' =>
                    (object) array(
                    'x' => 23,
                    'z' => 0,
                    'y' => 13,
                    ),
                    'isBigPlot' => false,
                    'direction' => 0,
                    'isJumbo' => false,
                    'deleted' => false,
                    'tempId' => -1,
                    'className' => 'Plot',
                    'state' => 'plowed',
                    'instanceDataStoreKey' => NULL,
                    'components' =>
                    (object) array(
                    ),
                    'isProduceItem' => false,
                    'id' => 6,
                    'itemName' => NULL,
                ),
            );
        } else {
            $objects = array();
        }

        $worldId = null;
        if (is_numeric($uid) && is_string($type) && $type !== "") {
            $world = UserWorld::create([
                'uid' => $uid,
                'type' => $type,
                'sizeX' => $size,
                'sizeY' => $size,
                'messageManager' => $messageManager,
            ]);
            $worldId = $world->id;

            if ($worldId && !empty($objects)) {
                saveWorldObjectsToDb($worldId, $objects);
            }
        }

        return array(
            "uid" => $uid,
            'type' => $type,
            'sizeX' => $size,
            'sizeY' => $size,
            'objectsArray' => $objects,
            'worldId' => $worldId,
            'tileSet' => getTileSetForWorld($type),
            'messageManager' => array(),
            'creation' => date("Y-m-d h:i:s")
        );
    }

    
    function getTileSetForWorld($worldType) {
        static $completeEntries = array(
            "farm"              => "grass_theme",
            "england"           => "england",
            "fisherman"         => "fisherman",
            "winterwonderland"  => "winterwonderland",
            "australia"         => "australia_theme",
            "space"             => "space_theme",
            "candy"             => "candy_theme",
            "fforest"           => "fforest_theme",
            "hlights"           => "hlights_theme",
            "rainforest"        => "rainforest_theme",
            "oz"                => "oz_theme",
            "mediterranean"     => "mediterranean_theme",
            "oasis"             => "oasis_theme",
            "storybook"         => "storybook_theme",
            "sleepyhollow"      => "sleepyhollow_theme",
            "toyland"           => "toyland_theme",
            "village"           => "village_theme",
            "glen"              => "glen_theme",
            "atlantis"          => "atlantis_theme",
            "hallow"            => "hallow_theme",
        );

        if (isset($completeEntries[$worldType])) {
            return $completeEntries[$worldType];
        }

        return "grass_theme";
    }

    
    function getUnlockedWorlds($uid) {
        $freeWorlds = ['farm'];

        $validPurchasable = VALID_PURCHASABLE_WORLDS;

        $purchasedWorlds = [];
        $meta = get_meta($uid, 'unlocked_worlds');
        if ($meta) {
            $worlds = @unserialize($meta);
            if (is_array($worlds)) {
                $purchasedWorlds = array_intersect($worlds, $validPurchasable);
            }
        }

        return array_values(array_unique(array_merge($freeWorlds, $purchasedWorlds)));
    }

    if (!defined('IRRIGATION_META_KEY')) {
        define('IRRIGATION_META_KEY', 'irrigation_data');
        define('IRRIGATION_DEFAULT_WATER', 20);
        define('IRRIGATION_MAX_WATER', 2000);
    }

    if (!function_exists('getIrrigationData')) {

        function getIrrigationData($uid) {
            $default = [
                'waterPlots' => [
                    'farm' => ['amount' => IRRIGATION_DEFAULT_WATER]
                ]
            ];

            $meta = get_meta($uid, IRRIGATION_META_KEY);

            if ($meta) {
                $data = @unserialize($meta);
                if (is_array($data)) {
                    if (!isset($data['waterPlots'])) {
                        $data['waterPlots'] = $default['waterPlots'];
                    }
                    if (!isset($data['waterPlots']['farm'])) {
                        $data['waterPlots']['farm'] = ['amount' => IRRIGATION_DEFAULT_WATER];
                    }
                    if (!isset($data['waterPlots']['farm']['amount'])) {
                        $data['waterPlots']['farm']['amount'] = IRRIGATION_DEFAULT_WATER;
                    }
                    return $data;
                }
            }

            return $default;
        }
    }

    if (!function_exists('setIrrigationData')) {
        
        function setIrrigationData($uid, $data) {
            set_meta($uid, IRRIGATION_META_KEY, serialize($data));
        }
    }

    if (!function_exists('getWaterAmount')) {
        
        function getWaterAmount($uid, $worldType = 'farm') {
            $data = getIrrigationData($uid);
            if (isset($data['waterPlots'][$worldType]['amount'])) {
                return (int) $data['waterPlots'][$worldType]['amount'];
            }
            return IRRIGATION_DEFAULT_WATER;
        }
    }

    if (!function_exists('addWater')) {
        
        function addWater($uid, $amount, $worldType = 'farm') {
            $data = getIrrigationData($uid);

            if (!isset($data['waterPlots'][$worldType])) {
                $data['waterPlots'][$worldType] = ['amount' => IRRIGATION_DEFAULT_WATER];
            }

            $current = (int) ($data['waterPlots'][$worldType]['amount'] ?? 0);
            $newAmount = min($current + $amount, IRRIGATION_MAX_WATER);
            $data['waterPlots'][$worldType]['amount'] = $newAmount;

            setIrrigationData($uid, $data);
            return $newAmount;
        }
    }

    if (!function_exists('useWater')) {
        
        function useWater($uid, $amount, $worldType = 'farm') {
            $data = getIrrigationData($uid);

            if (!isset($data['waterPlots'][$worldType])) {
                $data['waterPlots'][$worldType] = ['amount' => IRRIGATION_DEFAULT_WATER];
            }

            $current = (int) ($data['waterPlots'][$worldType]['amount'] ?? 0);

            if ($current < $amount) {
                return false;
            }

            $data['waterPlots'][$worldType]['amount'] = $current - $amount;
            setIrrigationData($uid, $data);
            return true;
        }
    }

    if (!function_exists('getIrrigationFeatureOptions')) {
        
        function getIrrigationFeatureOptions($uid) {
            return [
                'irrigation' => getIrrigationData($uid)
            ];
        }
    }

    
    function getMasteryData($uid) {
        $raw = get_meta($uid, 'mastery_data');
        if ($raw) {
            $data = @unserialize($raw);
            if (is_array($data) && isset($data['mastery']) && isset($data['masteryCounters'])) {
                return $data;
            }
        }
        return ['mastery' => [], 'masteryCounters' => []];
    }

    
    function saveMasteryData($uid, $masteryData) {
        set_meta($uid, 'mastery_data', serialize($masteryData));
    }

    
    function processMastery($uid, $itemData, $harvestCount = 1) {
        if (!$itemData) {
            return null;
        }

        $itemCode = $itemData['code'] ?? null;
        $masteryLevelData = $itemData['masteryLevel'] ?? null;

        if (!$itemCode || !$masteryLevelData) {
            return null;
        }

        $thresholds = [];
        if (is_array($masteryLevelData)) {
            foreach ($masteryLevelData as $level) {
                if (is_object($level)) {
                    $thresholds[] = (int) ($level->count ?? 0);
                } elseif (is_array($level)) {
                    $thresholds[] = (int) ($level['count'] ?? 0);
                }
            }
        } elseif (is_string($masteryLevelData)) {
            $thresholds = array_map('intval', explode(',', $masteryLevelData));
        }

        if (count($thresholds) !== 3) {
            return null;
        }

        $masteryData = getMasteryData($uid);
        $mastery = $masteryData['mastery'];
        $counters = $masteryData['masteryCounters'];

        $currentLevel = isset($mastery[$itemCode]) ? (int)$mastery[$itemCode] : -1;
        $currentCount = $counters[$itemCode] ?? 0;

        $newCount = $currentCount + $harvestCount;
        $counters[$itemCode] = $newCount;

        $newLevel = $currentLevel;
        $startCheck = ($currentLevel < 0) ? 0 : $currentLevel + 1;
        for ($i = $startCheck; $i < 3; $i++) {
            if ($newCount >= $thresholds[$i]) {
                $newLevel = $i;
            }
        }

        if ($newLevel >= 0) {
            $mastery[$itemCode] = $newLevel;
        }

        saveMasteryData($uid, ['mastery' => $mastery, 'masteryCounters' => $counters]);

        if ($newLevel > $currentLevel) {
            return [
                'itemCode' => $itemCode,
                'oldLevel' => $currentLevel,
                'newLevel' => $newLevel,
                'harvestCount' => $newCount
            ];
        }

        return null;
    }

    
    function getMasteryForClient($uid) {
        $data = getMasteryData($uid);

        $mastery = empty($data['mastery']) ? new \stdClass() : (object)$data['mastery'];
        $counters = empty($data['masteryCounters']) ? new \stdClass() : (object)$data['masteryCounters'];

        return [
            'mastery' => $mastery,
            'masteryCounters' => $counters
        ];
    }

    function getWorldObjectsFromDb($worldId, $uid = null) {
        if ($uid === null) {
            $world = UserWorld::find($worldId);
            $uid = $world ? (int) $world->uid : null;
        }

        $objects = WorldObject::where('world_id', $worldId)
            ->active()
            ->orderBy('id')
            ->get();

        return $objects->map(function ($obj) use ($uid) {
            return $obj->toFlashObject($uid);
        })->toArray();
    }

    
    function saveWorldObjectsToDb($worldId, $objects) {
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($worldId, $objects) {
                WorldObject::where('world_id', $worldId)->delete();

                if (!empty($objects)) {
                $recordsByObjectId = [];
                $now = now();
                foreach ($objects as $obj) {
                    $data = WorldObject::fromFlashObject($obj, $worldId);
                    $data['created_at'] = $now;
                    $data['updated_at'] = $now;

                    // A stale Flash retry can put the same client identity
                    // into the full-world payload twice. Keep its last state
                    // so the database identity constraint remains valid.
                    $recordsByObjectId[(int) $data['object_id']] = $data;
                }
                $records = array_values($recordsByObjectId);

                    foreach (array_chunk($records, 100) as $chunk) {
                        WorldObject::insert($chunk);
                    }
                }
            });

            Logger::debug('World', "saveWorldObjectsToDb: worldId=$worldId count=" . count($objects));
            return true;
        } catch (\Exception $e) {
            Logger::error('World', "saveWorldObjectsToDb exception for worldId=$worldId: " . $e->getMessage());
            return false;
        }
    }

    
    function updateWorldObjectsByPosition($worldId, $objects) {
        if (empty($objects)) {
            Logger::debug('World', "updateWorldObjectsByPosition called with empty objects array");
            return true;
        }

        Logger::debug('World', "updateWorldObjectsByPosition: worldId=$worldId, objectCount=" . count($objects));

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($worldId, $objects) {
                $updateCount = 0;
                foreach ($objects as $obj) {
                    [$posX, $posY] = ObjectHelper::getPosition($obj);

                    if ($posX === null || $posY === null) {
                        Logger::debug('World', "Skipping object with null position");
                        continue;
                    }

                    $state = $obj->state ?? null;
                    $itemName = $obj->itemName ?? null;
                    $plantTime = sanitizeNumericValue($obj->plantTime ?? 0);
                    $isJumbo = (bool)($obj->isJumbo ?? false);

                    Logger::debug('World', "Updating position ($posX,$posY): state=$state, item=$itemName, plantTime=$plantTime");

                    WorldObject::where('world_id', $worldId)
                        ->atPosition((int)$posX, (int)$posY)
                        ->update([
                            'state' => $state,
                            'item_name' => $itemName,
                            'plant_time' => $plantTime,
                            'is_jumbo' => $isJumbo,
                        ]);

                    $updateCount++;
                }
                Logger::debug('World', "updateWorldObjectsByPosition committed $updateCount updates");
            });

            return true;
        } catch (\Exception $e) {
            Logger::error('World', "updateWorldObjectsByPosition exception for worldId=$worldId: " . $e->getMessage());
            return false;
        }
    }

    
    function insertWorldObjects($worldId, $objects) {
        if (empty($objects)) {
            return true;
        }

        try {
            $records = [];
            $now = now();
            foreach ($objects as $obj) {
                $data = WorldObject::fromFlashObject($obj, $worldId);
                $data['created_at'] = $now;
                $data['updated_at'] = $now;
                $records[] = $data;
            }

            WorldObject::insert($records);

            Logger::debug('World', "insertWorldObjects: worldId=$worldId count=" . count($records));
            return true;
        } catch (\Exception $e) {
            Logger::error('World', "insertWorldObjects exception for worldId=$worldId: " . $e->getMessage());
            return false;
        }
    }

    
    function insertWorldObject($worldId, $obj) {
        try {
            $data = WorldObject::fromFlashObject($obj, $worldId);

            // A Flash retry can resend a placement after the first request
            // has already committed. The database enforces this identity as
            // (world_id, object_id); use an atomic upsert rather than a
            // read-then-insert sequence that races under concurrent retries.
            $now = now();
            $data['created_at'] = $now;
            $data['updated_at'] = $now;
            WorldObject::query()->upsert(
                [$data],
                ['world_id', 'object_id'],
                array_values(array_diff(array_keys($data), ['world_id', 'object_id', 'created_at']))
            );

            Logger::debug('World', "insertWorldObject: worldId=$worldId pos=({$data['position_x']},{$data['position_y']})");
            return true;
        } catch (\Exception $e) {
            Logger::error('World', "insertWorldObject exception for worldId=$worldId: " . $e->getMessage());
            return false;
        }
    }

    
    function deleteWorldObjectByPosition($worldId, $posX, $posY) {
        try {
            $affectedRows = WorldObject::where('world_id', $worldId)
                ->atPosition($posX, $posY)
                ->update(['deleted' => true]);

            Logger::debug('World', "deleteWorldObjectByPosition: worldId=$worldId pos=($posX,$posY) affected=$affectedRows");
            return true;
        } catch (\Exception $e) {
            Logger::error('World', "deleteWorldObjectByPosition exception for worldId=$worldId: " . $e->getMessage());
            return false;
        }
    }

    
    function updateWorldObjectFull($worldId, $obj) {
        $objectId = $obj->id ?? null;

        if ($objectId === null) {
            Logger::error('World', "updateWorldObjectFull: object has null id");
            return false;
        }

        try {
            $data = WorldObject::fromFlashObject($obj, $worldId);

            unset($data['world_id'], $data['object_id']);

            $affectedRows = WorldObject::where('world_id', $worldId)
                ->where('object_id', (int)$objectId)
                ->update($data);

            // A client may still hold a valid object after an interrupted
            // full-world save removed its row. Restore that one object rather
            // than reporting a successful update that wrote nothing.
            if ($affectedRows === 0) {
                WorldObject::create(array_merge($data, [
                    'world_id' => $worldId,
                    'object_id' => (int) $objectId,
                ]));
                $affectedRows = 1;
            }

            $posX = $data['position_x'] ?? '?';
            $posY = $data['position_y'] ?? '?';
            Logger::debug('World', "updateWorldObjectFull: worldId=$worldId objectId=$objectId pos=($posX,$posY) affected=$affectedRows");
            return true;
        } catch (\Exception $e) {
            Logger::error('World', "updateWorldObjectFull exception for worldId=$worldId objectId=$objectId: " . $e->getMessage());
            return false;
        }
    }

    function getWorldId($uid, $type = "farm") {
        return UserWorld::getWorldId($uid, $type);
    }

    function saveWorld($uid, $type, $worldData) {
        $worldId = $worldData['worldId'] ?? getWorldId($uid, $type);

        if ($worldId) {
            $saveResult = saveWorldObjectsToDb($worldId, $worldData["objectsArray"]);
            if (!$saveResult) {
                Logger::error('World', "saveWorldObjectsToDb failed for uid=$uid type=$type worldId=$worldId");
                return false;
            }

            $sizeX = $worldData["sizeX"] ?? 12;
            $sizeY = $worldData["sizeY"] ?? 12;

            UserWorld::where('id', $worldId)->update([
                'sizeX' => $sizeX,
                'sizeY' => $sizeY,
            ]);
        }

        invalidateWorldCache($uid, $type);
        return true;
    }

    function saveWorldWithMessages($uid, $type, $worldData, $messageManager) {
        $worldId = $worldData['worldId'] ?? getWorldId($uid, $type);
        $msgData = serialize($messageManager);

        if ($worldId) {
            saveWorldObjectsToDb($worldId, $worldData["objectsArray"]);

            UserWorld::where('id', $worldId)->update([
                'messageManager' => $msgData,
            ]);
        }

        invalidateWorldCache($uid, $type);
        return true;
    }

    
    function getUnwitherRingPrefix($worldType) {
        static $prefixes = [
            "farm" => "unwitherring",
            "fisherman" => "xcoveunwitherring",
            "winterwonderland" => "xwwunwitherring",
            "hawaii" => "xhiunwitherring",
            "asia" => "xasunwitherring",
            "england" => "xukunwitherring",
            "hallow" => "xhwunwitherring",
            "htown" => "xhdunwitherring",
            "glen" => "xegunwitherring",
            "atlantis" => "xalunwitherring",
            "australia" => "xauunwitherring",
            "space" => "xspunwitherring",
            "candy" => "xcwunwitherring",
            "fforest" => "xffunwitherring",
            "hlights" => "xlgunwitherring",
            "rainforest" => "xrfunwitherring",
            "oz" => "xozunwitherring",
            "mediterranean" => "xmdunwitherring",
            "oasis" => "xoaunwitherring",
            "storybook" => "xsbunwitherring",
            "sleepyhollow" => "xshunwitherring",
            "toyland" => "xtlunwitherring",
            "avalon" => "xmaunwitherring",
            "wildwest" => "xwaunwitherring",
            "treasuretides" => "xsaunwitherring",
            "africa" => "xafunwitherring",
            "transylvania" => "xtr_unwitherring",
            "japan" => "xjp_land_unwitherring",
            "winter" => "xwi_unwitherring",
            "india" => "xin_unwitherring",
            "jungle" => "xjm_unwitherring",
            "mount" => "xmo_unwitherring",
            "limbo" => "xbo_unwitherring",
            "xmas" => "xch_unwitherring",
            "midwest" => "xhh_unwitherring",
            "underwater" => "xuw_unwitherring",
            "turtleisland" => "xti_unwitherring",
            "dreamworld" => "xdw_unwitherring",
            "anglofrench" => "xfe_unwitherring",
            "brazil" => "xbr_unwitherring",
            "halloweenusa" => "xha_unwitherring",
            "whitewinter" => "xfw_unwitherring",
            "tuscany" => "xty_unwitherring",
            "caribbean" => "xcb_unwitherring",
            "dragonvalley" => "xdv_unwitherring",
            "russia" => "xru_unwitherring",
            "newfrontier" => "xnf_unwitherring",
            "israel" => "xis_unwitherring",
            "halloweenmad" => "xhx_unwitherring",
            "winternord" => "xwx_unwitherring",
            "casablanca" => "xca_unwitherring",
            "southindia" => "xbl_unwitherring",
            "twenties" => "xrt_unwitherring",
            "alaska" => "xsu_unwitherring",
            "cocoland" => "xcl_unwitherring",
            "ireland" => "xid_unwitherring",
            "spooky" => "xhf_unwitherring",
            "santavillage" => "xws_unwitherring",
            "farmfest" => "xfs_unwitherring",
            "madagascar" => "xmt_unwitherring",
            "borabora" => "xbb_unwitherring",
            "amsterdam" => "xdm_unwitherring",
            "canada" => "xcd_unwitherring",
            "aloha" => "xah_unwitherring",
            "pumpkin" => "xpu_unwitherring",
            "yuletide" => "xyt_unwitherring",
        ];

        return $prefixes[$worldType] ?? null;
    }

    
    function hasUnwitherRing($uid, $worldType = null) {
        if ($worldType === null) {
            $worldType = getCurrentWorldType($uid);
        }

        $worldId = getWorldId($uid, $worldType);
        if (!$worldId) {
            return false;
        }

        $ringPrefix = getUnwitherRingPrefix($worldType);
        if (!$ringPrefix) {
            return false;
        }

        return WorldObject::where('world_id', $worldId)
            ->where('item_name', 'LIKE', $ringPrefix . '%')
            ->where('item_name', 'NOT LIKE', '%box%')
            ->where('deleted', false)
            ->exists();
    }

    
    function isWitherProtectionActive($uid, $worldType = null) {
        if ($worldType === null) {
            $worldType = getCurrentWorldType($uid);
        }

        $worldId = getWorldId($uid, $worldType);
        if (!$worldId) {
            return false;
        }

        $ringPrefix = getUnwitherRingPrefix($worldType);
        if (!$ringPrefix) {
            return false;
        }

        $ring = WorldObject::where('world_id', $worldId)
            ->where('item_name', 'LIKE', $ringPrefix . '%')
            ->where('item_name', 'NOT LIKE', '%box%')
            ->where('deleted', false)
            ->first();

        if (!$ring) {
            return false;
        }

        $components = $ring->components;

        if (is_string($components)) {
            $components = JsonHelper::safeDecode($components, false);
        }

        if (is_object($components) && property_exists($components, 'active') && $components->active === false) {
            return false;
        }

        return true;
    }

    
    function buildWitherOnObject($uid) {
        $witherOn = new \stdClass();

        $unlockedWorlds = getUnlockedWorlds($uid);

        foreach ($unlockedWorlds as $worldType) {
            $protectionActive = isWitherProtectionActive($uid, $worldType);
            $witherOn->$worldType = !$protectionActive;
        }

        return $witherOn;
    }

    
    function resolveOpenableItem($itemName, $components, $uid) {
        $resultItem = null;
        $extraItemData = null;

        if (strpos($itemName, 'unwitherringbox') !== false) {
            $ringTypeCode = null;
            $ringTypeName = "gold";
            $message = "";
            $sender = $uid;
            $world = null;

            if ($components) {
                if (isset($components->ringType)) {
                    $ringTypeCode = $components->ringType;
                } elseif (isset($components->metal)) {
                    $ringTypeName = $components->metal;
                    if (isset($components->gem) && $components->gem !== 'none') {
                        $ringTypeName .= $components->gem;
                    }
                }
                if (isset($components->message)) {
                    $message = $components->message;
                }
                if (isset($components->sender)) {
                    $sender = $components->sender;
                }
                if (isset($components->world)) {
                    $world = $components->world;
                }
            }

            $resultItemData = null;
            if ($ringTypeCode) {
                $resultItemData = getItemByCode($ringTypeCode);
                if ($resultItemData && isset($resultItemData['name'])) {
                    $resultItem = $resultItemData['name'];
                    if (preg_match('/unwitherring(.+)$/', $resultItem, $matches)) {
                        $ringTypeName = $matches[1];
                    }
                }
            }

            if (!$resultItemData) {
                $prefix = "";
                if (preg_match('/^(.+?)unwitherringbox/', $itemName, $matches)) {
                    $prefix = $matches[1];
                }

                if ($ringTypeCode && strlen($ringTypeCode) > 4) {
                    $ringTypeName = $ringTypeCode;
                }

                $resultItem = $prefix . "unwitherring" . $ringTypeName;

                $resultItemData = getItemByName($resultItem, "db");
                if (!$resultItemData) {
                    $resultItem = $prefix . "unwitherringgold";
                    $ringTypeName = "gold";
                    $resultItemData = getItemByName($resultItem, "db");
                }
            }

            $finalRingCode = $resultItemData ? ($resultItemData['code'] ?? $ringTypeCode) : $ringTypeCode;

            $extraItemData = [
                "ringType" => $finalRingCode,
                "message" => $message,
                "sender" => $sender
            ];
            if ($world) {
                $extraItemData["world"] = $world;
            }

            return ['resultItem' => $resultItem, 'extraItemData' => $extraItemData];
        }

        if ($itemName === 'ringbox') {
            $ringTypeCode = null;
            $ringTypeName = "gold";
            $message = "";
            $sender = $uid;

            if ($components) {
                if (isset($components->ringType)) {
                    $ringTypeCode = $components->ringType;
                } elseif (isset($components->metal)) {
                    $ringTypeName = $components->metal;
                    if (isset($components->gem) && $components->gem !== 'none') {
                        $ringTypeName .= $components->gem;
                    }
                }
                if (isset($components->message)) {
                    $message = $components->message;
                }
                if (isset($components->sender)) {
                    $sender = $components->sender;
                }
            }

            $resultItemData = null;
            if ($ringTypeCode) {
                $resultItemData = getItemByCode($ringTypeCode);
                if ($resultItemData && isset($resultItemData['name'])) {
                    $resultItem = $resultItemData['name'];
                    if (preg_match('/ring(.+)$/', $resultItem, $matches)) {
                        $ringTypeName = $matches[1];
                    }
                }
            }

            if (!$resultItemData) {
                if ($ringTypeCode && strlen($ringTypeCode) > 4) {
                    $ringTypeName = $ringTypeCode;
                }
                $resultItem = "ring" . $ringTypeName;
                $resultItemData = getItemByName($resultItem, "db");
                if (!$resultItemData) {
                    $resultItem = "ring_" . $ringTypeName;
                    $resultItemData = getItemByName($resultItem, "db");
                }
                if (!$resultItemData) {
                    $resultItem = "ringgold";
                    $resultItemData = getItemByName($resultItem, "db");
                }
            }

            $finalRingCode = $resultItemData ? ($resultItemData['code'] ?? $ringTypeCode) : $ringTypeCode;

            $extraItemData = [
                "ringType" => $finalRingCode,
                "message" => $message,
                "sender" => $sender
            ];

            return ['resultItem' => $resultItem, 'extraItemData' => $extraItemData];
        }

        if ($components && isset($components->lootItem)) {
            $resultItem = $components->lootItem;
            $extraItemData = [
                "sender" => $components->sender ?? $uid
            ];
            return ['resultItem' => $resultItem, 'extraItemData' => $extraItemData];
        }

        if (preg_match('/^(.+?)_?box$/', $itemName, $matches)) {
            $potentialResult = $matches[1];
            $resultItemData = getItemByName($potentialResult, "db");
            if ($resultItemData) {
                $resultItem = $potentialResult;
                $extraItemData = [
                    "sender" => ($components && isset($components->sender)) ? $components->sender : $uid
                ];
                return ['resultItem' => $resultItem, 'extraItemData' => $extraItemData];
            }
        }

        return null;
    }
