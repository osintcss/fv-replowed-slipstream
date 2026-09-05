<?php
require_once AMFPHP_ROOTPATH . "Helpers/logger.php";
require_once AMFPHP_ROOTPATH . "Helpers/player.php";
require_once AMFPHP_ROOTPATH . "Helpers/market_transactions.php";
require_once AMFPHP_ROOTPATH . "Helpers/quest_helper.php";

require_once AMFPHP_ROOTPATH . "Functions/AvatarService.php";
require_once AMFPHP_ROOTPATH . "Functions/FarmQuestService.php";
require_once AMFPHP_ROOTPATH . "Functions/FBRequestService.php";
require_once AMFPHP_ROOTPATH . "Functions/FriendListService.php";
require_once AMFPHP_ROOTPATH . "Functions/FriendSetService.php";
require_once AMFPHP_ROOTPATH . "Functions/LeaderboardService.php";
require_once AMFPHP_ROOTPATH . "Functions/FarmService.php";
require_once AMFPHP_ROOTPATH . "Functions/CraftingService.php";
require_once AMFPHP_ROOTPATH . "Functions/CaptureFeatureService.php";
require_once AMFPHP_ROOTPATH . "Functions/AnimalBreedingService.php";
require_once AMFPHP_ROOTPATH . "Functions/BreedingService.php";
require_once AMFPHP_ROOTPATH . "Functions/SheepPenService.php";
require_once AMFPHP_ROOTPATH . "Functions/FleaMarketService.php";
require_once AMFPHP_ROOTPATH . "Functions/UserService.php";
require_once AMFPHP_ROOTPATH . "Functions/WorldService.php";
require_once AMFPHP_ROOTPATH . "Functions/LonelyAnimalFriendSetService.php";
require_once AMFPHP_ROOTPATH . "Functions/LonelyCowService.php";
require_once AMFPHP_ROOTPATH . "Functions/OrganicFertilizerService.php";
require_once AMFPHP_ROOTPATH . "Functions/FertilizerService.php";
require_once AMFPHP_ROOTPATH . "Functions/NeighborActionService.php";
require_once AMFPHP_ROOTPATH . "Functions/WatchToEarnRewardGrantService.php";
require_once AMFPHP_ROOTPATH . "Functions/EquipmentWorldService.php";
require_once AMFPHP_ROOTPATH . "Functions/DailyStatsService.php";
require_once AMFPHP_ROOTPATH . "Functions/ZAPIClientService.php";
require_once AMFPHP_ROOTPATH . "Functions/UserFeedService.php";
require_once AMFPHP_ROOTPATH . "Functions/PresentService.php";
require_once AMFPHP_ROOTPATH . "Functions/IrrigationService.php";
require_once AMFPHP_ROOTPATH . "Functions/FarmExpressZMCService.php";
require_once AMFPHP_ROOTPATH . "Functions/PurchaseUnwitherService.php";
require_once AMFPHP_ROOTPATH . "Functions/InGameConsoleService.php";

class FlashService {

    private static function readValue($value, $key, $default = null)
    {
        if (is_array($value) && array_key_exists($key, $value)) {
            return $value[$key];
        }

        if (is_object($value) && isset($value->{$key})) {
            return $value->{$key};
        }

        return $default;
    }

    private static function summarizeObject($object)
    {
        if (!is_array($object) && !is_object($object)) {
            return null;
        }

        $position = self::readValue($object, 'position');
        $summary = [];

        foreach (['id', 'className', 'itemName', 'state', 'plantTime'] as $field) {
            $value = self::readValue($object, $field);
            if ($value !== null) {
                $summary[$field] = is_scalar($value) ? $value : gettype($value);
            }
        }

        if (is_array($position) || is_object($position)) {
            $summary['position'] = [];
            foreach (['x', 'y', 'z'] as $axis) {
                $value = self::readValue($position, $axis);
                if ($value !== null) {
                    $summary['position'][$axis] = is_scalar($value) ? $value : gettype($value);
                }
            }
        }

        return $summary;
    }

    private static function summarizeRequest($request)
    {
        $functionName = (string) self::readValue($request, 'functionName', '');
        $params = self::readValue($request, 'params', []);
        $summary = [
            'function' => $functionName,
            'sequence' => self::readValue($request, 'sequence'),
            'param_count' => is_array($params) ? count($params) : null,
        ];

        if ($functionName === 'WorldService.performAction') {
            $summary['action'] = is_array($params) ? ($params[0] ?? null) : null;
            $summary['object'] = is_array($params) ? self::summarizeObject($params[1] ?? null) : null;
        } elseif ($functionName === 'EquipmentWorldService.onUseEquipment') {
            $plotBundle = is_array($params) ? ($params[2] ?? null) : null;
            $summary['action'] = is_array($params) ? ($params[0] ?? null) : null;
            $summary['item_name'] = is_array($params) ? ($params[3] ?? null) : null;
            $summary['plot_count'] = is_array($plotBundle)
                ? count($plotBundle)
                : (is_object($plotBundle) ? count(get_object_vars($plotBundle)) : null);
        }

        return $summary;
    }

    public function dispatchBatch($userData, $reqData, $params3) {
        $data = array();
        $player = null;
        $market = null;

        if (isset($userData->masterId) && $userData->masterId != ""){
            $player = new Player($userData->masterId);
            $market = new MarketTransactions($userData->masterId);
        }else{
            $player = new Player($userData->zy_user);
            $market = new MarketTransactions($userData->zy_user);
        }

        $uid = (string) $player->getUid();
        $batchStart = microtime(true);
        $requestSummaries = [];
        foreach ($reqData as $request) {
            $requestSummaries[] = self::summarizeRequest($request);
        }

        Logger::trace($uid, 'batch.received', [
            'request_count' => count($reqData),
            'requests' => $requestSummaries,
        ]);

        // A new player needs an active story quest before the client can render
        // QuestComponent. This is idempotent for players who already have one.
        ensureAvailableStoryQuest($player->getUid());
        $worldTime = time();

        Logger::debug('FlashService', "dispatchBatch: " . count($reqData) . " requests");

        foreach ($reqData as $key => $requ){
            $requestStart = microtime(true);
            $outcome = 'success';
            $exceptionDetails = null;
            Logger::debug('FlashService', "Request[$key]: " . $requ->functionName);

            // Debug: Log purchase inputs so failed client transactions can be
            // matched to the item and currency received by the server.
            if (strpos($requ->functionName, 'PresentService') !== false
                || $requ->functionName === 'FarmService.expandFarm'
                || $requ->functionName === 'WorldService.performAction'
                || strpos($requ->functionName, 'AnimalBreedingService.') === 0
                || strpos($requ->functionName, 'FarmQuestService.') === 0) {
                Logger::debug('FlashService', $requ->functionName . ' params: ' . json_encode($requ->params, JSON_PRETTY_PRINT));
            }

            $data[$key] = array(
                "errorType" => 0,
                "errorData" => null,
                "sequenceNumber" => $requ->sequence,
                "worldTime" => $worldTime
            );
            $questComponentOverride = null;
            try{
                $fn_details = explode(".", $requ->functionName);

                if (method_exists($fn_details[0], $fn_details[1])){
                    $result = call_user_func(array($fn_details[0], $fn_details[1]), $player, $requ, $market);
                    if (strpos($requ->functionName, 'FarmQuestService.') === 0) {
                        Logger::debug('FlashService', $requ->functionName . ' result: ' . json_encode($result, JSON_PRETTY_PRINT));
                    }
                    $questComponentOverride = $result['_questComponentOverride'] ?? null;
                    unset($result['_questComponentOverride']);
                    $data[$key] = array_merge($data[$key], $result);
                } elseif (preg_match('/^[A-Za-z0-9]+Service\\.unlock[A-Za-z0-9]+World$/', $requ->functionName)) {
                    // The original client tries to unlock every historical
                    // farm theme during initialization.  We do not implement
                    // those retired theme services, but an error response for
                    // each one floods the Flash transaction queue and can
                    // interrupt the player actions queued immediately after
                    // loading.  A successful empty response is the legacy
                    // contract for an unavailable optional world.
                    $data[$key]['data'] = [];
                    $outcome = 'optional_world_noop';
                } else {
                    Logger::error("FlashService", "Method not found: " . $requ->functionName);
                    $data[$key]["errorType"] = 1;
                    $data[$key]["errorData"] = "Method not found";
                    $outcome = 'method_not_found';
                }
            }catch (\Throwable $e){
                Logger::error("FlashService", $requ->functionName . " error: " . $e->getMessage());
                $data[$key]["errorType"] = 1;
                $data[$key]["errorData"] = "Server error: " . $e->getMessage();
                $outcome = 'exception';
                $exceptionDetails = [
                    'class' => get_class($e),
                    'message' => substr($e->getMessage(), 0, 300),
                ];
            }

            // Quest actions can start, complete, or remove a quest. Build this
            // after the handler runs so the client receives the current state.
            $metadata = $data[$key]['metadata'] ?? [];
            if (is_object($metadata)) {
                $metadata = (array) $metadata;
            }
            if (!is_array($metadata)) {
                $metadata = [];
            }
            $metadata['QuestComponent'] = $questComponentOverride ?? buildQuestComponent($player->getUid());
            $data[$key]['metadata'] = $metadata;

            $responseData = $data[$key]['data'] ?? null;
            $traceEntry = self::summarizeRequest($requ);
            $traceEntry['index'] = $key;
            $traceEntry['outcome'] = $outcome;
            $traceEntry['duration_ms'] = round((microtime(true) - $requestStart) * 1000, 1);
            $traceEntry['response_error_type'] = $data[$key]['errorType'] ?? null;
            $traceEntry['response_data_type'] = gettype($responseData);
            $traceEntry['response_data_count'] = is_array($responseData) ? count($responseData) : null;
            if ($exceptionDetails !== null) {
                $traceEntry['exception'] = $exceptionDetails;
            }
            Logger::trace($uid, 'request.completed', $traceEntry);
            
        } 

        $data = array_values($data);

        $inputSequences = [];
        foreach ($reqData as $request) {
            $inputSequences[] = self::readValue($request, 'sequence');
        }
        $responseSequences = array_map(function ($response) {
            return $response['sequenceNumber'] ?? null;
        }, $data);
        $responseErrorCount = 0;
        foreach ($data as $response) {
            if (($response['errorType'] ?? 0) !== 0) {
                $responseErrorCount++;
            }
        }

        Logger::trace($uid, 'batch.completed', [
            'request_count' => count($reqData),
            'response_count' => count($data),
            'response_error_count' => $responseErrorCount,
            'input_sequences' => $inputSequences,
            'response_sequences' => $responseSequences,
            'response_shape_ok' => count($reqData) === count($data) && $inputSequences === $responseSequences,
            'duration_ms' => round((microtime(true) - $batchStart) * 1000, 1),
        ]);

        return array(
            "errorType" => 0,
            "errorData" => null,
            "serverTime" => time(),
            "zySig" => array(
                "zy_user" => $player->getUid(),
                "zy_ts" => time(),
                "zy_session" => "thetestofthetime"
            ),
            "data" => $data
        );

    }
}

?>
