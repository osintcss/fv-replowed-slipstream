<?php

class LeaderboardService{
    /**
     * Return one offline-safe friend list for every leaderboard requested by
     * TGetLeaderboardFriendLists. The Flash transaction indexes the response
     * directly by leaderboard name before invoking each feature callback.
     */
    public static function getBatchFriendLists($playerObj, $request, $market = null){
        $params = is_array($request->params ?? null) ? $request->params : [];
        $requestedNames = isset($params[0]) && is_array($params[0]) ? $params[0] : [];
        $friendLists = [];

        foreach (array_slice($requestedNames, 0, 10) as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            // Social leaderboards can no longer be populated from Facebook.
            // Supplying the requested key with an empty list still lets the
            // original callback complete without inventing friend records.
            $friendLists[$name] = [];
        }

        return $friendLists;
    }

    public static function getFriendList(){
        $data["data"] = array(
            "leaderboardName" => "test",
            "friendList" => array(
                array(
                    "firstName" => "testBoi",
                    "value" => 100
                )
            )
        );

        return $data;
    }
}
