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
    use App\Support\Database;
    use App\Support\WorldScoreConfig;

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

    /**
     * Return the Flash world-score identifier for a world type.
     *
     * Most worlds use <worldType>Points, but some expansions have an
     * independent themed score unit. Keeping this translation server-side
     * prevents rewards from being saved under a key the Flash client does
     * not read.
     */
    function getWorldScoreUnitForWorldType($worldType) {
        $worldType = is_string($worldType) ? trim($worldType) : '';
        if ($worldType === '' || $worldType === 'farm') {
            return null;
        }

        return WorldScoreConfig::scoreUnitForWorld($worldType);
    }

    /**
     * Normalize either a world type or a Flash score unit to its world type.
     */
    function getWorldTypeForScoreUnit($scoreUnit) {
        $scoreUnit = is_string($scoreUnit) ? trim($scoreUnit) : '';
        if ($scoreUnit === '') {
            return null;
        }

        return WorldScoreConfig::worldForScoreUnit($scoreUnit);
    }

    /**
     * Resolve the world used for a quest reward.  The old default of
     * "main" caused every omitted world argument to save world-score rewards
     * under world_score_main, even when the player was in Haunted Hollow or
     * Sleepy Hollow.
     */
    function getWorldScoreWorldType($uid, $worldType = null) {
        $worldType = is_string($worldType) ? trim($worldType) : '';
        if ($worldType === '' || $worldType === 'main') {
            $worldType = getCurrentWorldType($uid);
        }

        return getWorldTypeForScoreUnit($worldType) ?: 'farm';
    }

    /**
     * Build the Player.worldScores object consumed by the Flash client.
     * Scores and levels are stored separately so old score-only saves remain
     * readable while new level-up transactions can persist the exact level.
     */
    function getWorldScoresForClient($uid) {
        $scoreValues = [];
        $levelValues = [];
        $worldTypes = [];

        $currentWorldType = getWorldScoreWorldType($uid);
        if ($currentWorldType !== 'farm') {
            $worldTypes[$currentWorldType] = true;
        }

        foreach (getUnlockedWorlds($uid) as $unlockedWorldType) {
            $normalizedWorldType = getWorldScoreWorldType($uid, $unlockedWorldType);
            if ($normalizedWorldType !== 'farm') {
                $worldTypes[$normalizedWorldType] = true;
            }
        }

        $metadata = PlayerMeta::where('uid', $uid)
            ->where('meta_key', 'like', 'world_score_%')
            ->get(['meta_key', 'meta_value']);

        foreach ($metadata as $entry) {
            $metaKey = (string) $entry->meta_key;
            if (strpos($metaKey, 'world_score_level_') === 0) {
                $rawWorldType = substr($metaKey, strlen('world_score_level_'));
                $normalizedWorldType = getWorldScoreWorldType($uid, $rawWorldType);
                if ($normalizedWorldType === 'farm') {
                    continue;
                }

                $levelValues[$normalizedWorldType] = max(
                    $levelValues[$normalizedWorldType] ?? 1,
                    max(1, (int) $entry->meta_value),
                );
                $worldTypes[$normalizedWorldType] = true;
                continue;
            }

            if (strpos($metaKey, 'world_score_') !== 0) {
                continue;
            }

            $rawWorldType = substr($metaKey, strlen('world_score_'));
            $normalizedWorldType = getWorldScoreWorldType($uid, $rawWorldType);
            if ($normalizedWorldType === 'farm') {
                continue;
            }

            // Historical saves may have used either the world type or its
            // score unit as a suffix. A score must never go backwards just
            // because one of those legacy rows is stale, so merge aliases by
            // their greatest value until the repair job synchronizes them.
            $scoreValues[$normalizedWorldType] = max(
                $scoreValues[$normalizedWorldType] ?? 0,
                max(0, (int) $entry->meta_value),
            );
            $worldTypes[$normalizedWorldType] = true;
        }

        $result = [];
        foreach (array_keys($worldTypes) as $worldType) {
            $scoreUnit = getWorldScoreUnitForWorldType($worldType);
            if ($scoreUnit === null) {
                continue;
            }

            $score = max(0, (int) ($scoreValues[$worldType] ?? 0));
            // The original world-score asset is authoritative. Persisted
            // levels from old client calls are a compatibility fallback only;
            // they must not disagree with the visible score meter.
            $derivedLevel = WorldScoreConfig::levelForScore($scoreUnit, $score);
            $result[$scoreUnit] = [
                'score' => $score,
                'level' => $derivedLevel ?? max(1, (int) ($levelValues[$worldType] ?? 1)),
            ];
        }

        return $result;
    }

    /**
     * Reconcile a world score and level from a single authoritative source.
     *
     * A score can be reported by an older Flash client, but it is merged
     * monotonically and never decremented. The associated level is always
     * calculated from the recovered original world-score table; client levels
     * are deliberately ignored because requests may be stale or malformed.
     */
    function synchronizeWorldScoreState($uid, $worldType, $reportedScore = null, $increment = 0, $force = false) {
        if (!is_numeric($uid)) {
            return [];
        }

        $worldType = getWorldTypeForScoreUnit($worldType);
        if (!$worldType || $worldType === 'farm') {
            return [];
        }

        $hasReportedScore = is_numeric($reportedScore);
        $increment = is_numeric($increment) ? max(0, (int) $increment) : 0;
        if (!$force && !$hasReportedScore && $increment === 0) {
            return [];
        }

        $uid = (string) $uid;
        $scoreUnit = getWorldScoreUnitForWorldType($worldType);
        if ($scoreUnit === null) {
            return [];
        }
        $suffixes = WorldScoreConfig::metadataSuffixesForWorld($worldType);
        if ($suffixes === []) {
            $suffixes = [$worldType];
        }
        $scoreKeys = array_values(array_unique(array_map(
            static fn (string $suffix): string => "world_score_$suffix",
            $suffixes,
        )));
        $levelKeys = array_values(array_unique(array_map(
            static fn (string $suffix): string => "world_score_level_$suffix",
            $suffixes,
        )));
        sort($scoreKeys, SORT_STRING);
        sort($levelKeys, SORT_STRING);
        $canonicalScoreKey = "world_score_$worldType";
        $canonicalLevelKey = "world_score_level_$worldType";

        return Database::transaction(
            'synchronize world score state',
            static function () use ($uid, $scoreUnit, $scoreKeys, $levelKeys, $canonicalScoreKey, $canonicalLevelKey, $reportedScore, $hasReportedScore, $increment): array {
                // Query every historical alias together, in a fixed order,
                // so concurrently arriving client calls cannot race the
                // server-side plot awards or leave a stale alias behind.
                $scoreRows = PlayerMeta::query()
                    ->where('uid', $uid)
                    ->whereIn('meta_key', $scoreKeys)
                    ->orderBy('meta_key')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $currentScore = 0;
                foreach ($scoreRows as $row) {
                    if (is_numeric($row->meta_value)) {
                        $currentScore = max($currentScore, max(0, (int) $row->meta_value));
                    }
                }
                $nextScore = $currentScore + $increment;
                if ($hasReportedScore) {
                    $nextScore = max($nextScore, max(0, (int) $reportedScore));
                }

                $scoreKeysPresent = [];
                foreach ($scoreRows as $row) {
                    $scoreKeysPresent[$row->meta_key] = true;
                    $row->update(['meta_value' => (string) $nextScore]);
                }
                if (!isset($scoreKeysPresent[$canonicalScoreKey])) {
                    PlayerMeta::query()->create([
                        'uid' => $uid,
                        'meta_key' => $canonicalScoreKey,
                        'meta_value' => (string) $nextScore,
                    ]);
                }

                $configuredLevel = WorldScoreConfig::levelForScore($scoreUnit, $nextScore);
                $levelRows = PlayerMeta::query()
                    ->where('uid', $uid)
                    ->whereIn('meta_key', $levelKeys)
                    ->orderBy('meta_key')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $currentLevel = 1;
                foreach ($levelRows as $row) {
                    if (is_numeric($row->meta_value)) {
                        $currentLevel = max($currentLevel, max(1, (int) $row->meta_value));
                    }
                }
                $nextLevel = $configuredLevel ?? $currentLevel;
                $levelKeysPresent = [];
                foreach ($levelRows as $row) {
                    $levelKeysPresent[$row->meta_key] = true;
                    // A configured level is intentionally allowed to decrease
                    // here: it repairs a historic stale client level while the
                    // score itself remains strictly monotonic.
                    if ($configuredLevel !== null) {
                        $row->update(['meta_value' => (string) $nextLevel]);
                    }
                }
                if ($configuredLevel !== null && !isset($levelKeysPresent[$canonicalLevelKey])) {
                    PlayerMeta::query()->create([
                        'uid' => $uid,
                        'meta_key' => $canonicalLevelKey,
                        'meta_value' => (string) $nextLevel,
                    ]);
                }

                foreach (array_merge($scoreKeys, $levelKeys) as $metaKey) {
                    PlayerMeta::clearCache($uid, $metaKey);
                }

                return ['score' => $nextScore, 'level' => $nextLevel];
            },
        );
    }

    /**
     * Persist a client-reported score without trusting its level. Retain the
     * one-argument legacy call as a no-op, since it carries no state.
     */
    function persistMonotonicWorldScore($uid, $worldType, $worldScore = null, $worldLevel = null) {
        if (!is_numeric($worldScore) && !is_numeric($worldLevel)) {
            return [];
        }

        return synchronizeWorldScoreState($uid, $worldType, $worldScore);
    }

    /** Recalculate and synchronize a saved score's level without changing its score. */
    function reconcileWorldScoreLevel($uid, $worldType) {
        return synchronizeWorldScoreState($uid, $worldType, null, 0, true);
    }

    /**
     * Add a server-authoritative amount to an individual world's score.
     *
     * World actions can arrive concurrently (especially vehicle sweeps), so
     * this must increment under a row lock rather than read, add, and write
     * through the ordinary metadata cache.  Duplicate historical metadata
     * rows are kept in sync for the same reason as persistMonotonicWorldScore.
     */
    function incrementWorldScore($uid, $worldType, $amount) {
        if (!is_numeric($uid) || !is_numeric($amount) || (int) $amount <= 0) {
            return null;
        }

        $worldType = getWorldScoreWorldType($uid, $worldType);
        if ($worldType === 'farm') {
            return null;
        }

        $state = synchronizeWorldScoreState($uid, $worldType, null, (int) $amount, true);

        return isset($state['score']) ? (int) $state['score'] : null;
    }

    /**
     * Plot actions contribute to the configured expansion score for worlds
     * that use an action score. The caller supplies the already-authoritative
     * normal-XP delta, so the score cannot be inflated by client input.
     */
    function awardPlotActionWorldScore($uid, $worldType, $xpDelta) {
        $worldType = getWorldScoreWorldType($uid, $worldType);
        if (!in_array($worldType, ['oz', 'asia'], true)
            || !is_numeric($xpDelta)
            || (int) $xpDelta <= 0) {
            return null;
        }

        return incrementWorldScore($uid, $worldType, (int) $xpDelta);
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

        // Keep per-item metadata aligned with the remaining quantity.  The
        // Flash StorageItem trims metadata from the front of the stack when
        // quantity decreases; mirror that behavior on the persisted Giftbox
        // so patterned/instance-specific gifts cannot inherit sold metadata.
        if (isset($giftbox[$itemCode][2]) && is_array($giftbox[$itemCode][2])) {
            while (count($giftbox[$itemCode][2]) > $giftbox[$itemCode][0]) {
                array_shift($giftbox[$itemCode][2]);
            }
        }

        if ($giftbox[$itemCode][0] <= 0) {
            unset($giftbox[$itemCode]);
        } elseif (isset($giftbox[$itemCode][1])
            && is_array($giftbox[$itemCode][1])
            && count($giftbox[$itemCode][1]) > 0) {
            array_shift($giftbox[$itemCode][1]);
        }

        saveGiftBox($uid, $giftbox);
        return true;
    }


    /**
     * Consume Giftbox contents while the caller's database transaction is
     * holding the row lock.  The ordinary removeGiftByCode() helper performs
     * a read/modify/write through the request cache, which is not safe when a
     * gift-backed storage action updates a world object in the same request.
     */
    function consumeGiftboxItemLocked($uid, string $itemCode, int $quantity = 1): bool {
        if (!is_numeric($uid) || $itemCode === '' || $quantity <= 0) {
            return false;
        }

        $meta = PlayerMeta::query()
            ->where('uid', (string) $uid)
            ->where('meta_key', 'giftbox')
            ->lockForUpdate()
            ->first();

        if ($meta === null) {
            return false;
        }

        $giftbox = @unserialize((string) $meta->meta_value, ['allowed_classes' => false]);
        if (!is_array($giftbox)
            || !isset($giftbox[$itemCode])
            || !is_array($giftbox[$itemCode])
            || (int) ($giftbox[$itemCode][0] ?? 0) < $quantity) {
            return false;
        }

        $remaining = (int) $giftbox[$itemCode][0] - $quantity;
        $giftbox[$itemCode][0] = $remaining;

        // Keep per-instance metadata and sender queues aligned with the
        // remaining quantity.  This mirrors the normal Giftbox withdrawal
        // contract without doing another unlocked read.
        foreach ([1, 2] as $queueIndex) {
            if (!isset($giftbox[$itemCode][$queueIndex])
                || !is_array($giftbox[$itemCode][$queueIndex])) {
                continue;
            }

            while (count($giftbox[$itemCode][$queueIndex]) > $remaining) {
                array_shift($giftbox[$itemCode][$queueIndex]);
            }
        }

        if ($remaining <= 0) {
            unset($giftbox[$itemCode]);
        }

        $meta->meta_value = serialize($giftbox);
        $meta->save();
        PlayerMeta::clearCache($uid, 'giftbox');

        return true;
    }


    /**
     * Add Giftbox contents while the caller's transaction owns the row lock.
     * This is the write-side counterpart to consumeGiftboxItemLocked(); using
     * addGiftByCode() here would read and overwrite a stale request cache.
     */
    function addGiftboxItemLocked($uid, string $itemCode, int $quantity = 1, $senderId = null, $extraData = null): bool {
        if (!is_numeric($uid) || $itemCode === '' || $quantity <= 0) {
            return false;
        }

        $meta = PlayerMeta::query()
            ->where('uid', (string) $uid)
            ->where('meta_key', 'giftbox')
            ->lockForUpdate()
            ->first();
        if ($meta === null) {
            // Most players already have this row, but the legacy metadata
            // table does not require one. Match set_meta()/addGiftByCode() by
            // creating an empty bucket for accounts that never received a gift.
            PlayerMeta::query()->create([
                'uid' => (string) $uid,
                'meta_key' => 'giftbox',
                'meta_value' => serialize([]),
            ]);
            $meta = PlayerMeta::query()
                ->where('uid', (string) $uid)
                ->where('meta_key', 'giftbox')
                ->lockForUpdate()
                ->first();
            if ($meta === null) {
                return false;
            }
        }

        $giftbox = @unserialize((string) $meta->meta_value, ['allowed_classes' => false]);
        $giftbox = is_array($giftbox) ? $giftbox : [];
        $extraDataObj = $extraData === null
            ? null
            : (is_array($extraData) ? (object) $extraData : $extraData);

        if (!isset($giftbox[$itemCode]) || !is_array($giftbox[$itemCode])) {
            $giftbox[$itemCode] = [0, [], []];
        }
        $giftbox[$itemCode][0] = (int) ($giftbox[$itemCode][0] ?? 0) + $quantity;
        if ($senderId !== null && $senderId !== '') {
            $giftbox[$itemCode][1] = is_array($giftbox[$itemCode][1] ?? null)
                ? $giftbox[$itemCode][1] : [];
            for ($i = 0; $i < $quantity; $i++) {
                $giftbox[$itemCode][1][] = $senderId;
            }
        }
        if ($extraDataObj !== null) {
            $giftbox[$itemCode][2] = is_array($giftbox[$itemCode][2] ?? null)
                ? $giftbox[$itemCode][2] : [];
            for ($i = 0; $i < $quantity; $i++) {
                $giftbox[$itemCode][2][] = $extraDataObj;
            }
        }

        $meta->meta_value = serialize($giftbox);
        $meta->save();
        PlayerMeta::clearCache($uid, 'giftbox');

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

    /**
     * Persist one or more items consumed by the Flash client.
     *
     * TUseConsumable removes Giftbox items optimistically before sending its
     * WorldService.performAction request.  Some callers instead consume from
     * Home Inventory or the personal crafting silo.  Keep the storage-ID
     * mapping in one place so those paths cannot silently diverge.
     */
    function consumeStoredItem($uid, string $itemCode, int $quantity, int $storageId): bool {
        if (!is_numeric($uid) || $itemCode === '' || $quantity <= 0) {
            return false;
        }

        if (in_array($storageId, [GIFTBOX_ID, (int) GIFTBOX_STORAGE_KEY], true)) {
            // Storage reads are cached for the duration of an AMF request.
            // Clear the Giftbox entry before a transactional consume so a
            // preceding read cannot re-save stale serialized contents.
            PlayerMeta::clearCache($uid, 'giftbox');
            return removeGiftByCode($uid, $itemCode, $quantity);
        }

        if ($storageId === HOME_INVENTORY_ID) {
            PlayerMeta::clearCache($uid, 'inventory_storage');
            return removeFromInventoryStorage($uid, $itemCode, $quantity);
        }

        if ($storageId === PERSONAL_CRAFTING_INVENTORY_ID) {
            // The client calls this collection the personal crafting
            // inventory; the server exposes it as the `silo` bucket.
            return removeFromInventory($uid, $itemCode, $quantity, 'silo');
        }

        return false;
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


    /**
     * Check whether a Giftbox stack contains a specific per-instance value.
     * UGC buildings use their UUID as raw extraData, while some older
     * instance records wrap the same value in a {type: ...} object.
     */
    function giftboxHasItemMetadata($uid, $itemCode, string $expectedMetadata): bool {
        $giftbox = getGiftBox($uid);

        if (!isset($giftbox[$itemCode]) || (int) ($giftbox[$itemCode][0] ?? 0) <= 0) {
            return false;
        }

        foreach (($giftbox[$itemCode][2] ?? []) as $metadata) {
            if (is_string($metadata) && $metadata === $expectedMetadata) {
                return true;
            }
            if (is_object($metadata) && ($metadata->type ?? null) === $expectedMetadata) {
                return true;
            }
            if (is_array($metadata) && ($metadata['type'] ?? null) === $expectedMetadata) {
                return true;
            }
        }

        return false;
    }


    /**
     * Consume one Giftbox entry by its per-instance metadata instead of
     * blindly shifting the first item in a same-code stack.
     */
    function withdrawGiftboxItemByMetadata($uid, $itemCode, string $expectedMetadata) {
        $giftbox = getGiftBox($uid);

        if (!isset($giftbox[$itemCode]) || (int) ($giftbox[$itemCode][0] ?? 0) <= 0) {
            return null;
        }

        $metadataList = isset($giftbox[$itemCode][2]) && is_array($giftbox[$itemCode][2])
            ? $giftbox[$itemCode][2]
            : [];
        $metadataIndex = null;
        foreach ($metadataList as $index => $metadata) {
            if ((is_string($metadata) && $metadata === $expectedMetadata)
                || (is_object($metadata) && ($metadata->type ?? null) === $expectedMetadata)
                || (is_array($metadata) && ($metadata['type'] ?? null) === $expectedMetadata)) {
                $metadataIndex = $index;
                break;
            }
        }

        if ($metadataIndex === null) {
            return null;
        }

        $extraData = $metadataList[$metadataIndex];
        array_splice($metadataList, (int) $metadataIndex, 1);
        $giftbox[$itemCode][2] = $metadataList;
        $quantity = max(0, (int) $giftbox[$itemCode][0] - 1);
        $giftbox[$itemCode][0] = $quantity;

        if (isset($giftbox[$itemCode][1])
            && is_array($giftbox[$itemCode][1])
            && count($giftbox[$itemCode][1]) > 0) {
            if (count($giftbox[$itemCode][1]) === ((int) $giftbox[$itemCode][0] + 1)
                && $metadataIndex < count($giftbox[$itemCode][1])) {
                array_splice($giftbox[$itemCode][1], (int) $metadataIndex, 1);
            } else {
                array_shift($giftbox[$itemCode][1]);
            }
        }

        if ($quantity <= 0) {
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

        // Flash's Player.getFeatureCredits dereferences the current world's
        // bucket before it can create a missing feature entry.  A player who
        // has not yet earned credits in that world would otherwise crash when
        // a credits building (for example, a Beehive) updates on a visit.
        // Supply empty buckets for every world this server supports; this is
        // response normalization only and never grants or persists credits.
        $worldTypes = array_merge(['farm'], VALID_PURCHASABLE_WORLDS);
        foreach ($worldTypes as $worldType) {
            if (!isset($credits[$worldType]) || !is_array($credits[$worldType])) {
                $credits[$worldType] = [];
            }
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
        } elseif ($type === 'winternord') {
            $objects = createWinternordStarterObjects();
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

    /**
     * First-load layout for Winter Fable (the client world is internally
     * named `winternord`). The original NPC farm file is not part of this
     * deployment, so create the durable starter objects at the same server
     * boundary used by ordinary world snapshots.
     */
    function createWinternordStarterObjects(): array {
        $emptyComponents = (object) [];
        $emptyContents = [];
        $foundingTs = (int) round(microtime(true) * 1000);

        $plot = static function (int $id, int $x, int $y): object {
            return (object) [
                'plantTime' => 0,
                'position' => (object) ['x' => $x, 'y' => $y, 'z' => 0],
                'isBigPlot' => false,
                'direction' => 0,
                'isJumbo' => false,
                'deleted' => false,
                'tempId' => -1,
                'className' => 'Plot',
                'state' => 'fallow',
                'instanceDataStoreKey' => null,
                'components' => (object) [],
                'isProduceItem' => false,
                'id' => $id,
                'itemName' => null,
            ];
        };

        return [
            $plot(1, 6, 8),
            $plot(2, 10, 8),
            $plot(3, 6, 12),
            $plot(4, 10, 12),
            (object) [
                'plantTime' => 0,
                'position' => (object) ['x' => 3, 'y' => 3, 'z' => 0],
                'isBigPlot' => false,
                'direction' => 0,
                'isJumbo' => false,
                'deleted' => false,
                'tempId' => -1,
                'className' => 'MarketStallBuilding',
                'state' => 'built',
                'instanceDataStoreKey' => null,
                'components' => $emptyComponents,
                'isProduceItem' => false,
                'id' => 5,
                'itemName' => 'xwx_marketstall',
                'contents' => $emptyContents,
            ],
            (object) [
                'plantTime' => 0,
                'position' => (object) ['x' => 8, 'y' => 3, 'z' => 0],
                'isBigPlot' => false,
                'direction' => 0,
                'isJumbo' => false,
                'deleted' => false,
                'tempId' => -1,
                'className' => 'InventoryCellar',
                'state' => 'built',
                'instanceDataStoreKey' => null,
                'components' => (object) [],
                'isProduceItem' => false,
                'id' => 6,
                'itemName' => 'xwx_storage',
                'contents' => $emptyContents,
            ],
            (object) [
                'plantTime' => 0,
                'position' => (object) ['x' => 3, 'y' => 15, 'z' => 0],
                'isBigPlot' => false,
                'direction' => 0,
                'isJumbo' => false,
                'deleted' => false,
                'tempId' => -1,
                'className' => 'OrchardConstructionBuilding',
                'state' => 'construction',
                'instanceDataStoreKey' => null,
                'components' => (object) [],
                'isProduceItem' => false,
                'id' => 7,
                'itemName' => 'xwx_orchard',
                'contents' => $emptyContents,
            ],
            // The Patisserie is the functional Winter Fable crafting
            // cottage. Its xwxcrafttype recipes are already present in the
            // bundled crafting catalog; persisting the cottage here makes
            // those recipes available after the first world load.
            (object) [
                'plantTime' => 0,
                'position' => (object) ['x' => 16, 'y' => 3, 'z' => 0],
                'isBigPlot' => false,
                'direction' => 0,
                'isJumbo' => false,
                'deleted' => false,
                'tempId' => -1,
                'className' => 'CraftingCottageBuilding',
                'state' => 'built',
                'instanceDataStoreKey' => null,
                'components' => (object) ['foundingTS' => $foundingTs],
                'isProduceItem' => false,
                'id' => 8,
                'itemName' => 'xwx_craftingcottage',
                'contents' => $emptyContents,
            ],
            // The Animal Workshop starts as a normal construction building;
            // WorldObject's construction normalizer supplies its initial
            // sugar part from the catalog on both save and reload.
            (object) [
                'plantTime' => 0,
                'position' => (object) ['x' => 27, 'y' => 3, 'z' => 0],
                'isBigPlot' => false,
                'direction' => 0,
                'isJumbo' => false,
                'deleted' => false,
                'tempId' => -1,
                'className' => 'AnimalBreedingPenConstructionBuilding',
                'state' => 'construction',
                'instanceDataStoreKey' => null,
                'components' => $emptyComponents,
                'isProduceItem' => false,
                'id' => 9,
                'itemName' => 'animal_breeding_animalworkshop',
                'contents' => $emptyContents,
            ],
        ];
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
            // The client patch completes winternord_theme with the snow
            // terrain fields while retaining its authentic xwx background.
            "winternord"        => "winternord_theme",
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

    /**
     * Return the extra mastery multipliers supplied by permanent mastery
     * decorations currently placed on any of the player's farms.
     *
     * Flash applies a PermanentBuffDecoration on first placement and keeps
     * one player buff per backing buff item.  The AMF server does not receive
     * that client-only application step, so derive the same durable state
     * from the placed decoration and its imported `buff` / `masteryTypes`
     * metadata.  A distinct backing buff contributes one extra multiplier.
     */
    function getPermanentMasteryBuffMultiplier($uid, $itemData): int {
        if (is_object($itemData)) {
            $itemData = (array) $itemData;
        }
        $masteryType = is_array($itemData) ? (string) ($itemData['type'] ?? '') : '';
        if ($masteryType === '') {
            return 0;
        }

        $worldIds = UserWorld::query()->where('uid', $uid)->pluck('id');
        if ($worldIds->isEmpty()) {
            return 0;
        }

        // Do not scan the whole item catalogue with an unindexed
        // `data LIKE '%PermanentBuffDecoration%'` query here. This method
        // runs for every manual harvest; on production that scan holds the
        // AMF action response for many seconds and causes Flash to abandon
        // later optimistic harvests. Query only the item names actually
        // placed by this player, then identify permanent buffs in that small,
        // indexed set.
        $placedItemNames = WorldObject::query()
            ->whereIn('world_id', $worldIds)
            ->where('deleted', false)
            ->pluck('item_name')
            ->filter()
            ->unique()
            ->values();

        if ($placedItemNames->isEmpty()) {
            return 0;
        }

        $permanentDecorations = [];
        foreach (Item::query()->whereIn('name', $placedItemNames)->get() as $decoration) {
            if (stripos((string) $decoration->data, 'PermanentBuffDecoration') === false) {
                continue;
            }
            $data = $decoration->itemData;
            if (is_object($data)) {
                $data = (array) $data;
            }
            $buffName = is_array($data) ? (string) ($data['buff'] ?? '') : '';
            if ($buffName !== '') {
                $permanentDecorations[(string) $decoration->name] = $buffName;
            }
        }

        if ($permanentDecorations === []) {
            return 0;
        }

        $matchingBuffs = [];
        foreach (array_keys($permanentDecorations) as $decorationName) {
            $buffName = $permanentDecorations[$decorationName];
            $buffData = $buffName ? Item::findByName($buffName) : null;
            if (is_object($buffData)) {
                $buffData = (array) $buffData;
            }
            $masteryTypeData = is_array($buffData) ? ($buffData['masteryTypes'] ?? []) : [];
            if (is_object($masteryTypeData)) {
                $masteryTypeData = (array) $masteryTypeData;
            }
            $masteryTypes = is_array($masteryTypeData) ? ($masteryTypeData['masteryType'] ?? []) : [];
            if (is_object($masteryTypes)) {
                $masteryTypes = (array) $masteryTypes;
            }
            if (isset($masteryTypes['type'])) {
                $masteryTypes = [$masteryTypes];
            }

            foreach ((array) $masteryTypes as $typeData) {
                if (is_object($typeData)) {
                    $typeData = (array) $typeData;
                }
                $buffType = is_array($typeData) ? (string) ($typeData['type'] ?? '') : '';
                if ($buffType === $masteryType) {
                    $matchingBuffs[$buffName] = true;
                }
            }
        }

        return count($matchingBuffs);
    }

    
    function processMastery($uid, $itemData, $harvestCount = 1) {
        if (!$itemData) {
            return null;
        }
        if (is_object($itemData)) {
            $itemData = (array) $itemData;
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

        // Mastery calculates its normal yield first, then adds one multiplier
        // for each distinct applicable permanent buff.  The Flash client caps
        // the final yield at five; apply the same ceiling server-side.
        $permanentBuffMultiplier = getPermanentMasteryBuffMultiplier($uid, $itemData);
        $masteryYield = max(0, min(5, (int) $harvestCount * (1 + $permanentBuffMultiplier)));
        $newCount = $currentCount + $masteryYield;
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
            // All harvest and crafting paths converge here. Notify the quest
            // tracker only after the new mastery state is persisted, so a
            // level-up objective cannot be displayed and then disappear on
            // reload.
            if (function_exists('trackMasteryProgress')) {
                trackMasteryProgress($uid, $itemCode, $newLevel, $itemData);
            }
            return [
                'itemCode' => $itemCode,
                'oldLevel' => $currentLevel,
                'newLevel' => $newLevel,
                'harvestCount' => $newCount,
                'masteryYield' => $masteryYield,
                'permanentBuffMultiplier' => $permanentBuffMultiplier,
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
                    // IDs in Flash's temporary range exist only until its
                    // corresponding place response supplies a persistent ID.
                    // A whole-world snapshot can contain one during that
                    // short window; saving it creates a second loose animal
                    // that is no longer tied to its placement transaction.
                    $objectId = isset($obj->id) && is_numeric($obj->id) ? (int) $obj->id : 0;
                    if ($objectId >= TEMP_ID_THRESHOLD) {
                        Logger::debug('World', "saveWorldObjectsToDb: skipped temporary objectId={$objectId}");
                        continue;
                    }
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

    /**
     * Apply a state-only world mutation if the persisted object still matches
     * the snapshot on which the caller made its decision.  This is for actions
     * such as instant-grow that can touch many objects at once: replacing the
     * whole world from a request-local snapshot can otherwise resurrect crops
     * harvested by another concurrent AMF request.
     *
     * Each change is an array with `object` (the new Flash object) and
     * `expected` (the original state, item_name, and plant_time).
     */
    function updateWorldObjectsConditionally($worldId, $changes) {
        if (empty($changes)) {
            return ['success' => true, 'updated' => 0, 'skipped' => 0, 'updatedObjectIds' => []];
        }

        try {
            $updated = 0;
            $skipped = 0;
            $updatedObjectIds = [];

            \Illuminate\Support\Facades\DB::transaction(function () use ($worldId, $changes, &$updated, &$skipped, &$updatedObjectIds) {
                foreach ($changes as $change) {
                    $obj = $change['object'] ?? null;
                    $expected = $change['expected'] ?? [];
                    $objectId = $obj->id ?? null;
                    if ($obj === null || $objectId === null) {
                        $skipped++;
                        continue;
                    }

                    $query = WorldObject::where('world_id', $worldId)
                        ->where('object_id', (int) $objectId);

                    foreach (['state', 'item_name', 'plant_time'] as $column) {
                        $value = $expected[$column] ?? null;
                        if ($value === null) {
                            $query->whereNull($column);
                        } else {
                            $query->where($column, $value);
                        }
                    }

                    $affected = $query->update([
                        'state' => $obj->state ?? null,
                        'item_name' => $obj->itemName ?? null,
                        'plant_time' => sanitizeNumericValue($obj->plantTime ?? 0),
                        'is_jumbo' => (bool) ($obj->isJumbo ?? false),
                    ]);

                    if ($affected === 1) {
                        $updated++;
                        $updatedObjectIds[(int) $objectId] = true;
                    } else {
                        $skipped++;
                    }
                }
            });

            Logger::debug('World', "updateWorldObjectsConditionally: worldId=$worldId updated=$updated skipped=$skipped");
            return [
                'success' => true,
                'updated' => $updated,
                'skipped' => $skipped,
                'updatedObjectIds' => array_keys($updatedObjectIds),
            ];
        } catch (\Exception $e) {
            Logger::error('World', "updateWorldObjectsConditionally exception for worldId=$worldId: " . $e->getMessage());
            return ['success' => false, 'updated' => 0, 'skipped' => count($changes), 'updatedObjectIds' => []];
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

            // MySQL reports zero affected rows both when the object is absent
            // and when it already holds the exact values we submitted. Only
            // restore a missing row. Treating an unchanged update as absent
            // attempted a duplicate insert on the unique (world_id,
            // object_id) key, which rejected the whole Flash action.
            $objectExists = WorldObject::where('world_id', $worldId)
                ->where('object_id', (int)$objectId)
                ->exists();

            if ($affectedRows === 0 && !$objectExists) {
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

    /**
     * @deprecated Use WorldPersistence::replaceSnapshot() for intentional
     * full-world replacement, or a targeted WorldPersistence operation for
     * ordinary gameplay mutations.
     */
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

    /**
     * Turbo Rings grant infinite Turbo only in the world where they are
     * placed.  The Flash client receives that fact through FeatureOptions;
     * it does not infer it from the decoration while loading the world.
     */
    function hasTurboRing($uid, $worldType = null) {
        if ($worldType === null) {
            $worldType = getCurrentWorldType($uid);
        }

        $worldId = getWorldId($uid, $worldType);
        if (!$worldId) {
            return false;
        }

        return WorldObject::where('world_id', $worldId)
            ->where('item_name', 'LIKE', '%turboring%')
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
