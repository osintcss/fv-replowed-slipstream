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

class FlashService {

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

        // A new player needs an active story quest before the client can render
        // QuestComponent. This is idempotent for players who already have one.
        ensureAvailableStoryQuest($player->getUid());
        $worldTime = time();

        Logger::debug('FlashService', "dispatchBatch: " . count($reqData) . " requests");

        foreach ($reqData as $key => $requ){
            Logger::debug('FlashService', "Request[$key]: " . $requ->functionName);

            // Debug: Log purchase inputs so failed client transactions can be
            // matched to the item and currency received by the server.
            if (strpos($requ->functionName, 'PresentService') !== false
                || $requ->functionName === 'FarmService.expandFarm'
                || $requ->functionName === 'WorldService.performAction'
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
                } else {
                    Logger::error("FlashService", "Method not found: " . $requ->functionName);
                    $data[$key]["errorType"] = 1;
                    $data[$key]["errorData"] = "Method not found";
                }
            }catch (\Throwable $e){
                Logger::error("FlashService", $requ->functionName . " error: " . $e->getMessage());
                $data[$key]["errorType"] = 1;
                $data[$key]["errorData"] = "Server error: " . $e->getMessage();
            }

            // Quest actions can start, complete, or remove a quest. Build this
            // after the handler runs so the client receives the current state.
            $data[$key]["metadata"] = array(
                "QuestComponent" => $questComponentOverride ?? buildQuestComponent($player->getUid())
            );
            
        } 

        $data = array_values($data);

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
