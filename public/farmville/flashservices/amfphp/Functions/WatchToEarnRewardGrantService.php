<?php

class WatchToEarnRewardGrantService
{
    public static function getUserZid($playerObj = null, $request = null, $market = null){
        return [
            'data' => [
                'success' => true,
                'zid' => (string) ($playerObj ? $playerObj->getUid() : '0'),
            ],
        ];
    }

    public static function generateDailyTokens($playerObj = null, $request = null, $market = null){
        return [
            'data' => [
                'success' => true,
                'Tokens' => [],
            ],
        ];
    }
}
