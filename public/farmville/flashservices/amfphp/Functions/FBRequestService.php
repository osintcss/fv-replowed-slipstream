<?php 

require_once AMFPHP_ROOTPATH . "Helpers/quest_helper.php";
require_once AMFPHP_ROOTPATH . "Helpers/general_functions.php";

use App\Models\WorldObject;
use App\Support\StorageConfig;

class FBRequestService{
    public static function sendInviteRequest($playerObj, $request){
        foreach($request->params[0] as $uid){
            $playerObj->setPendingNeighbors($uid);
        }

        return [];
    }

    /**
     * The construction-material MFS flow uses a distinct AMF method name,
     * even though its request envelope is the same as the generic ask-items
     * flow. Keep both names available so the legacy Flash client can finish
     * the send transaction instead of receiving "Method not found".
     */
    public static function sendAskMatsRequest($playerObj, $request, $market = null){
        return self::sendAskItemsRequest($playerObj, $request, $market);
    }

    /**
     * Records a completed Ask Items request after the client-side social bridge
     * has returned its request IDs. Facebook no longer accepts these requests,
     * but the Flash client still requires this AMF endpoint to complete its
     * send workflow and dismiss the MFS dialog.
     */
    public static function sendAskItemsRequest($playerObj, $request, $market = null){
        $params = is_array($request->params ?? null) ? $request->params : [];
        $itemName = isset($params[0]) && is_string($params[0]) ? $params[0] : '';
        $featureName = isset($params[1]) && is_string($params[1]) ? $params[1] : '';
        $requestIds = isset($params[2]) && is_array($params[2]) ? $params[2] : [];
        $source = isset($params[3]) && is_string($params[3]) ? $params[3] : '';
        $view = isset($params[5]) && is_string($params[5]) ? $params[5] : '';

        // AMF can represent the IDs as numeric values or strings. Keep only
        // scalar IDs and cap the local audit trail so an untrusted request
        // cannot grow player metadata without bound.
        $requestIds = array_values(array_slice(array_filter($requestIds, function ($id) {
            return is_scalar($id) && (string) $id !== '';
        }), 0, 50));

        $uid = $playerObj->getUid();
        $stored = get_meta($uid, 'sent_ask_item_requests');
        $history = is_string($stored) ? (@unserialize($stored) ?: []) : [];
        if (!is_array($history)) {
            $history = [];
        }

        $history[] = [
            'itemName' => $itemName,
            'featureName' => $featureName,
            'requestIds' => $requestIds,
            'source' => $source,
            'view' => $view,
            'sentAt' => time(),
        ];
        set_meta($uid, 'sent_ask_item_requests', serialize(array_slice($history, -100)));

        // Facebook delivery is unavailable in this deployment. Replace the
        // missing friend response with one legitimate feature part in
        // Giftbox. Pig Pen keeps its existing requirement-aware behaviour;
        // other features are validated against their own item definition so
        // an arbitrary item name cannot become a free Giftbox grant.
        $offlineItemGranted = self::grantOfflineRequestedItem(
            $uid,
            $itemName,
            $featureName,
            count($requestIds),
        );

        // The generic MFS "Ask Your Friends" dialog is also used by quest
        // helpers.  Unlike TAskForQuestItem, it does not include a quest or
        // task index in its AMF call: it only sends the requested item name
        // and the feature name (questR4R).  Facebook delivery no longer
        // exists here, so resolve that item to an *active* useItemByCode task
        // before granting its remaining amount.  This deliberately cannot
        // advance unrelated tasks or inactive quests.
        $fulfilled = [];
        if ($featureName === 'questR4R' && $itemName !== '') {
            $item = getItemByName($itemName, 'db');
            $itemCode = is_array($item) ? (string) ($item['code'] ?? '') : '';

            if ($itemCode !== '') {
                foreach (getActiveQuests($uid) as $questName => $questState) {
                    if (!is_array($questState)) {
                        continue;
                    }

                    $quest = getQuestByName($questName);
                    foreach (($quest['tasks'] ?? []) as $taskIndex => $task) {
                        if (!is_array($task)
                            || ($task['action'] ?? null) !== 'useItemByCode'
                            || (string) ($task['type'] ?? '') !== $itemCode) {
                            continue;
                        }

                        $required = max(1, (int) ($task['total'] ?? 1));
                        $current = (int) ($questState['progress'][$taskIndex] ?? 0);
                        $remaining = max(0, $required - $current);
                        if ($remaining === 0) {
                            continue;
                        }

                        addGiftByCode($uid, $itemCode, $remaining, $uid, [
                            'source' => 'offline_mfs_quest_ask',
                            'questName' => $questName,
                            'taskIndex' => (int) $taskIndex,
                        ]);
                        updateQuestProgress($uid, $questName, $taskIndex, $remaining);
                        checkAndCompleteQuest($uid, $questName);
                        $fulfilled[] = "$questName#$taskIndex:$remaining";
                    }
                }
            }
        }

        Logger::debug(
            'FBRequestService',
            sprintf(
                'Offline Ask Items request accepted: uid=%s item=%s feature=%s requests=%d granted=%d',
                $uid,
                $itemName,
                $featureName,
                count($requestIds),
                $offlineItemGranted,
            )
        );

        if ($offlineItemGranted > 0) {
            Logger::debug(
                'FBRequestService',
                sprintf(
                    'Offline item request fulfilment: uid=%s item=%s feature=%s amount=%d',
                    $uid,
                    $itemName,
                    $featureName,
                    $offlineItemGranted,
                )
            );
        }

        if (!empty($fulfilled)) {
            Logger::debug(
                'FBRequestService',
                sprintf(
                    'Offline questR4R fulfilment: uid=%s item=%s tasks=%s',
                    $uid,
                    $itemName,
                    implode(',', $fulfilled)
                )
            );
        }

        return [
            'data' => [
                'success' => true,
                'published' => true,
                'requestIds' => $requestIds,
                'ts' => time(),
            ],
        ];
    }

    /**
     * Fulfil an offline Ask Friends request with a legitimate feature part.
     *
     * Pig Pen has a legacy requirement-aware fallback. Other feature parts
     * are granted one at a time because the request IDs identify selected
     * friends, not confirmed deliveries. The item must be a BuildingPart
     * referenced by the requested feature's storage or expansion data.
     */
    private static function grantOfflineRequestedItem(
        $uid,
        string $itemName,
        string $featureName,
        int $requestCount,
    ): int
    {
        if ($featureName === 'pigpen') {
            return self::grantOfflinePigpenConstructionPart($uid, $itemName);
        }

        // questR4R has its own active-task fulfilment below. Do not also put
        // a generic copy of that quest item in Giftbox.
        if ($featureName === '' || $featureName === 'questR4R' || $requestCount <= 0) {
            return 0;
        }

        $featureItem = getItemByName($featureName, 'db');
        $item = getItemByName($itemName, 'db');
        if (!is_array($featureItem) || !is_array($item)
            || ($item['className'] ?? null) !== 'BuildingPart'
            || !self::featureUsesConstructionPart($featureItem, $itemName)) {
            return 0;
        }

        $itemCode = $item['code'] ?? '';
        if (!is_string($itemCode) || $itemCode === '') {
            return 0;
        }

        // One submission represents one completed offline ask. Do not use
        // the selected-friend count as a multiplier: the server has no
        // confirmed friend responses to justify granting that many items.
        $quantity = 1;
        addGiftByCode($uid, $itemCode, $quantity, $uid, [
            'source' => 'offline_feature_part_ask',
            'featureName' => $featureName,
            'itemName' => $itemName,
        ]);

        return $quantity;
    }

    /**
     * Check the same feature metadata the Flash client uses to identify a
     * construction/expansion part. Supports storage-config buildings and
     * FeatureBuilding expansion parts such as Black Rose and Biofuel Pump.
     */
    private static function featureUsesConstructionPart(array $featureItem, string $itemName): bool
    {
        $storageType = $featureItem['storageType'] ?? null;
        $storageClass = is_array($storageType)
            ? ($storageType['itemClass'] ?? null)
            : (is_object($storageType) ? ($storageType->itemClass ?? null) : null);
        if (StorageConfig::constructionRequirements($storageClass)[$itemName] ?? 0) {
            return true;
        }

        $features = $featureItem['features'] ?? null;
        $featureList = is_array($features)
            ? ($features['feature'] ?? [])
            : (is_object($features) ? ($features->feature ?? []) : []);
        $featureList = is_array($featureList) ? $featureList : [$featureList];

        foreach ($featureList as $feature) {
            $featureName = is_array($feature)
                ? ($feature['name'] ?? null)
                : (is_object($feature) ? ($feature->name ?? null) : null);
            if ($featureName !== 'expand') {
                continue;
            }

            $upgrades = is_array($feature)
                ? ($feature['upgrade'] ?? [])
                : (is_object($feature) ? ($feature->upgrade ?? []) : []);
            $upgrades = is_array($upgrades) ? $upgrades : [$upgrades];
            foreach ($upgrades as $upgrade) {
                $parts = is_array($upgrade)
                    ? ($upgrade['part'] ?? [])
                    : (is_object($upgrade) ? ($upgrade->part ?? []) : []);
                $parts = is_array($parts) ? $parts : [$parts];
                foreach ($parts as $part) {
                    $partName = is_array($part)
                        ? ($part['name'] ?? null)
                        : (is_object($part) ? ($part->name ?? null) : null);
                    if ($partName === $itemName) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** Award the remaining configured construction-part quantity for Pig Pen. */
    private static function grantOfflinePigpenConstructionPart($uid, string $itemName): int
    {
        $featureName = 'pigpen';
        $buildingItem = getItemByName($featureName, 'db');
        $storageType = is_array($buildingItem)
            ? ($buildingItem['storageType'] ?? null)
            : null;
        $storageClass = is_array($storageType)
            ? ($storageType['itemClass'] ?? null)
            : (is_object($storageType) ? ($storageType->itemClass ?? null) : null);
        $required = StorageConfig::constructionRequirements($storageClass)[$itemName] ?? 0;
        if ($required <= 0) {
            return 0;
        }

        $item = getItemByName($itemName, 'db');
        $itemCode = is_array($item) ? ($item['code'] ?? '') : '';
        if (!is_string($itemCode) || $itemCode === '') {
            return 0;
        }

        $onHand = self::pigpenConstructionPartCount($uid, $itemCode, $itemName);
        $inGiftbox = self::giftboxPartCount($uid, $itemCode);
        $remaining = max(0, $required - $onHand - $inGiftbox);
        if ($remaining === 0) {
            return 0;
        }

        addGiftByCode($uid, $itemCode, $remaining, $uid, [
            'source' => 'offline_construction_ask',
            'featureName' => $featureName,
            'itemName' => $itemName,
        ]);

        return $remaining;
    }

    /** Count a requested part already applied to the player's active Pig Pen. */
    private static function pigpenConstructionPartCount($uid, string $itemCode, string $itemName): int
    {
        $worldType = getCurrentWorldType($uid);
        $worldId = getWorldId($uid, $worldType);
        if ($worldId === null) {
            return 0;
        }

        $building = WorldObject::query()
            ->where('world_id', $worldId)
            ->where('item_name', 'pigpen')
            ->where('class_name', 'PigpenConstructionBuilding')
            ->where('state', 'construction')
            ->where('deleted', false)
            ->first();
        if ($building === null) {
            return 0;
        }

        $count = 0;
        foreach (is_array($building->contents) ? $building->contents : [] as $content) {
            $code = is_object($content) ? ($content->itemCode ?? null) : ($content['itemCode'] ?? null);
            if ($code !== $itemCode) {
                continue;
            }
            $count += max(0, (int) (is_object($content)
                ? ($content->numItem ?? 0)
                : ($content['numItem'] ?? 0)));
        }

        // The Flash client seeds a newly placed Pig Pen with one brick, but
        // legacy rows may not have that starter persisted yet.
        if ($itemName === 'brick' && $count < 1) {
            $count = 1;
        }

        return $count;
    }

    /** Count existing copies of a part already waiting in Giftbox. */
    private static function giftboxPartCount($uid, string $itemCode): int
    {
        $giftbox = getGiftBox($uid);
        $entry = $giftbox[$itemCode] ?? null;
        if (!is_array($entry)) {
            return 0;
        }

        return max(0, (int) ($entry[0] ?? 0));
    }
}
