<?php

require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";
require_once AMFPHP_ROOTPATH . "Helpers/general_functions.php";
require_once AMFPHP_ROOTPATH . "Helpers/logger.php";
require_once AMFPHP_ROOTPATH . "Helpers/quest_progress.php";

class PresentService
{
    
    private static function getValue($data, $key, $default = null)
    {
        if (is_object($data) && isset($data->$key)) {
            return $data->$key;
        }
        if (is_array($data) && isset($data[$key])) {
            return $data[$key];
        }
        return $default;
    }

    
    public static function buyAndSend($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        $itemName = $request->params[0] ?? null;
        $recipientUids = $request->params[1] ?? [$uid];
        $itemContext = $request->params[2] ?? "normal";
        $extraItemData = $request->params[3] ?? null;

        Logger::debug('PresentService', "buyAndSend: itemName={$itemName}, extraItemData type=" . gettype($extraItemData));
        if ($extraItemData) {
            if (is_object($extraItemData)) {
                Logger::debug('PresentService', "extraItemData (object): " . json_encode($extraItemData));
            } else if (is_array($extraItemData)) {
                Logger::debug('PresentService', "extraItemData (array): " . json_encode($extraItemData));
            } else {
                Logger::debug('PresentService', "extraItemData (other): " . print_r($extraItemData, true));
            }
        }

        if (!$itemName) {
            return ["data" => ["complete" => false, "error" => "no_item"]];
        }

        if (!is_array($recipientUids)) {
            $recipientUids = [$recipientUids];
        }

        $numRecipients = count($recipientUids);
        if ($numRecipients == 0) {
            return ["data" => ["complete" => false, "error" => "no_recipients"]];
        }

        $item = getItemByName($itemName, "db");
        if (!$item) {
            return ["data" => ["complete" => false, "error" => "item_not_found"]];
        }

        $market_type = $item["market"] ?? "coins";
        $cashCost = (int) ($item["cash"] ?? 0);
        $goldCost = (int) ($item["cost"] ?? 0);

        $totalCashCost = $cashCost * $numRecipients;
        $totalGoldCost = $goldCost * $numRecipients;

        if ($market_type === "cash" && $cashCost > 0) {
            if (!UserResources::removeCash($uid, $totalCashCost)) {
                return ["data" => ["complete" => false, "error" => "insufficient_cash"]];
            }
        } else if ($goldCost > 0) {
            if (!UserResources::removeGold($uid, $totalGoldCost)) {
                return ["data" => ["complete" => false, "error" => "insufficient_gold"]];
            }
        }

        $buyXp = (int) ($item["buyXp"] ?? 0);
        if ($buyXp > 0) {
            UserResources::addXp($uid, $buyXp * $numRecipients);
        }

        $giftExtraData = [
            "sender" => $uid
        ];

        if ($extraItemData) {
            $ringType = self::getValue($extraItemData, 'ringType');
            $message = self::getValue($extraItemData, 'message');
            $world = self::getValue($extraItemData, 'world');
            $opened = self::getValue($extraItemData, 'opened');

            Logger::debug('PresentService', "Parsed extraItemData: ringType={$ringType}, message={$message}, world={$world}");

            if ($ringType !== null) {
                $giftExtraData["ringType"] = $ringType;
            }
            if ($message !== null) {
                $giftExtraData["message"] = $message;
            }
            if ($world !== null) {
                $giftExtraData["world"] = $world;
            }
            if ($opened !== null) {
                $giftExtraData["opened"] = $opened;
            }
        }

        $itemCode = $item["code"] ?? null;
        if (!$itemCode) {
            return ["data" => ["complete" => false, "error" => "no_item_code"]];
        }

        Logger::debug('PresentService', "Final giftExtraData: " . json_encode($giftExtraData));

        $numSent = 0;
        foreach ($recipientUids as $recipientUid) {
            addGiftByCode($recipientUid, $itemCode, 1, $uid, $giftExtraData);

            // Quest "Ask Friends" rewards are represented by a
            // CQuestProgress item. Saving that item to the giftbox alone is
            // not enough: a later QuestComponent snapshot would otherwise
            // restore the task's saved progress to zero. Record the receipt
            // against any active useItemByCode objective now.
            trackUseItemProgress($recipientUid, $itemCode, 1);
            $numSent++;
        }

        return [
            "data" => [
                "complete" => true,
                "numSent" => $numSent,
                "totalCost" => ($market_type === "cash") ? $totalCashCost : $totalGoldCost
            ]
        ];
    }
}
