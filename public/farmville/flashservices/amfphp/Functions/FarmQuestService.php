<?php

require_once AMFPHP_ROOTPATH . "Helpers/quest_helper.php";
require_once AMFPHP_ROOTPATH . "Helpers/quest_progress.php";
require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";

use App\Models\UserMeta;

class FarmQuestService
{
    
    public static function questManagerStartReplayableQuestChain($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        $questName = $request->params[0] ?? null;

        if (empty($questName)) {
            return ["data" => [], "errorType" => 1, "errorData" => "Missing quest name"];
        }

        $userMeta = UserMeta::where('uid', $uid)->first(['xp']);
        $xp = $userMeta ? (int) $userMeta->xp : 0;
        $playerLevel = getLevelForXp($xp);

        $questDefinition = getQuestByName($questName);
        if ($questDefinition && !empty($questDefinition['replay'])) {
            // The replay-quest manager removes the completed chain from its
            // local view, then calls this method directly when the player
            // presses Start. It does not call userResetQuestChapter first.
            // Reset only an entirely inactive completed chain; never disturb
            // a chain that is currently in progress.
            $chainRoot = findQuestChainRoot($questName);
            $chainNames = getQuestChainNames($chainRoot);
            $activeQuests = getActiveQuests($uid);
            $hasActiveChainQuest = (bool) array_intersect(array_keys($activeQuests), $chainNames);

            if (!$hasActiveChainQuest && hasCompletedReplayableQuest($uid, $questName)) {
                $completedReplayable = array_values(array_diff(
                    getCompletedReplayableQuests($uid),
                    $chainNames
                ));
                set_meta($uid, META_QUEST_COMPLETED_REPLAYABLE, serialize($completedReplayable));
            }
        }

        try {
            $questState = startQuestIfEligible($uid, $questName, $playerLevel);
        } catch (\Throwable $e) {
            return ["data" => ["success" => false, "error" => $e->getMessage()]];
        }

        if ($questState === null) {
            // Repair intros that were started before the atomic transition
            // existed. They are still active in saved state, so a normal
            // start attempt is a no-op unless we complete the intro and put
            // its eligible child into the response now.
            if (isViewDialogOnlyQuest($questDefinition)
                && isset(getActiveQuests($uid)[$questName])) {
                $completion = completeQuest($uid, $questName);
                if (($completion['success'] ?? false) === true) {
                    $questComponent = buildQuestComponent($uid);
                    $questComponentOverride = $questComponent;
                    $questComponentOverride[] = [
                        'name' => $questName,
                        'progress' => [(string) getViewDialogTaskTotal($questDefinition)],
                        'removed' => false,
                        'expired' => false,
                        'complete' => true,
                    ];

                    return ["data" => [
                        "success" => true,
                        "quest" => null,
                        "quests" => $questComponent,
                        "repairedIntro" => true,
                    ], "_questComponentOverride" => $questComponentOverride];
                }
            }

            // The Flash quest UI leaves its "One moment" modal open when this
            // transaction returns an AMF error. A repeat click is normal after
            // an intro has completed (or while a refresh is catching up), so
            // return the current quest state as a successful no-op instead.
            // ensureAvailableStoryQuest also repairs an already-completed intro
            // whose eligible child has not yet been placed in active quests.
            ensureAvailableStoryQuest($uid);

            return ["data" => [
                "success" => true,
                "quest" => null,
                "quests" => buildQuestComponent($uid),
                "alreadyStarted" => true,
            ]];
        }

        $questDefinition = getQuestByName($questName);
        $questComponentOverride = null;

        // A one-task `viewDialog` quest is an intro speech bubble, not a
        // progress panel. Complete it now so its child quest is persisted in
        // the same response that opens the dialogue. The Flash client queues
        // markViewDialogTaskDone only after displaying the dialogue; relying
        // on that later acknowledgement left the UI holding a completed intro
        // with no active child task to render.
        if (isViewDialogOnlyQuest($questDefinition)) {
            $completion = completeQuest($uid, $questName);
            if (($completion['success'] ?? false) === true) {
                // The client still needs the completed intro in this one
                // metadata snapshot in order to show its scripted dialogue.
                // buildQuestComponent() now contains the real child quest.
                $questComponentOverride = buildQuestComponent($uid);
                $questComponentOverride[] = [
                    'name' => $questName,
                    'progress' => [(string) getViewDialogTaskTotal($questDefinition)],
                    'removed' => false,
                    'expired' => false,
                    'complete' => true,
                ];
            }
        }

        $questComponent = buildQuestComponent($uid);
        $startedQuest = null;
        foreach ($questComponent as $quest) {
            if ($quest['name'] === $questName) {
                $startedQuest = $quest;
                break;
            }
        }

        return ["data" => [
            "success" => true,
            "quest" => $startedQuest,
            "quests" => $questComponent,
        ], "_questComponentOverride" => $questComponentOverride];
    }

    
    public static function fullQuestRefresh($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        ensureAvailableStoryQuest($uid);
        $questComponent = buildQuestComponent($uid);

        return [
            "data" => [
                "success" => true,
                "quests" => $questComponent,
            ],
        ];
    }

    
    public static function skipTask($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        $questName = $request->params[0] ?? null;
        $taskIndex = $request->params[1] ?? 0;

        if (empty($questName)) {
            return ["data" => [], "errorType" => 1, "errorData" => "Missing quest name"];
        }

        $result = skipQuestTask($uid, $questName, (int)$taskIndex);

        return ["data" => $result];
    }

    
    public static function userKillQuest($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        $questName = $request->params[0] ?? null;

        if (empty($questName)) {
            return ["data" => [], "errorType" => 1, "errorData" => "Missing quest name"];
        }

        $success = removeQuest($uid, $questName);

        return ["data" => ["success" => $success]];
    }

    
    public static function userPauseQuest($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        $questName = $request->params[0] ?? null;

        if (empty($questName)) {
            return ["data" => [], "errorType" => 1, "errorData" => "Missing quest name"];
        }

        $activeQuests = getActiveQuests($uid);

        if (isset($activeQuests[$questName])) {
            $activeQuests[$questName]['paused'] = true;
            $activeQuests[$questName]['pausedAt'] = time();
            setActiveQuests($uid, $activeQuests);
            return ["data" => ["success" => true]];
        }

        return ["data" => ["success" => false, "error" => "Quest not found"]];
    }

    
    public static function userResetQuestChapter($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        $questName = $request->params[0] ?? null;

        if (empty($questName)) {
            return ["data" => [], "errorType" => 1, "errorData" => "Missing quest name"];
        }

        $quest = getQuestByName($questName);
        if (!$quest) {
            return ["data" => ["success" => false, "error" => "Quest not found"]];
        }

        $chainRoot = findQuestChainRoot($questName);

        $questsInChain = getQuestChainNames($chainRoot);
        $completedReplayable = getCompletedReplayableQuests($uid);

        foreach ($questsInChain as $qName) {
            $key = array_search($qName, $completedReplayable);
            if ($key !== false) {
                unset($completedReplayable[$key]);
            }
        }

        set_meta($uid, META_QUEST_COMPLETED_REPLAYABLE, serialize(array_values($completedReplayable)));

        $questState = startQuest($uid, $chainRoot);

        return [
            "data" => [
                "success" => true,
                "questStarted" => $chainRoot,
                "quest" => $questState,
            ],
        ];
    }

    
    public static function interactedWithQuest($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        $questName = $request->params[0] ?? null;

        if (empty($questName)) {
            return ["data" => ["success" => false]];
        }

        $activeQuests = getActiveQuests($uid);

        if (isset($activeQuests[$questName])) {
            $activeQuests[$questName]['viewed'] = true;
            $activeQuests[$questName]['viewedAt'] = time();
            setActiveQuests($uid, $activeQuests);
        }

        return ["data" => ["success" => true]];
    }

    
    public static function markViewDialogTaskDone($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        $questName = $request->params[0] ?? null;

        if (empty($questName)) {
            return ["data" => ["success" => false]];
        }

        $quest = getQuestByName($questName);
        if (!$quest) {
            return ["data" => ["success" => false]];
        }

        // Intro quests are completed atomically when their replay chain is
        // started. Flash still sends this acknowledgement after the dialogue
        // has been shown, so accept that delayed request without restarting
        // or changing the child quest.
        if (!isset(getActiveQuests($uid)[$questName]) && hasCompletedQuest($uid, $questName)) {
            return ["data" => [
                "success" => true,
                "questCompleted" => true,
                "alreadyCompleted" => true,
                "rewards" => [],
            ]];
        }

        foreach ($quest['tasks'] as $taskIndex => $task) {
            if (($task['action'] ?? '') === 'viewDialog') {
                $total = $task['total'] ?? 1;
                updateQuestProgress($uid, $questName, $taskIndex, $total);

                if (checkAndCompleteQuest($uid, $questName)) {
                    $result = completeQuest($uid, $questName);
                    return [
                        "data" => [
                            "success" => true,
                            "questCompleted" => true,
                            "rewards" => $result['rewards'] ?? [],
                        ],
                    ];
                }

                return ["data" => ["success" => true, "questCompleted" => false]];
            }
        }

        return ["data" => ["success" => false, "error" => "No viewDialog task found"]];
    }

    
    public static function askForQuestItem($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        $questName = $request->params[0] ?? null;
        $taskIndex = $request->params[1] ?? 0;

        $key = "quest_item_request_{$questName}_{$taskIndex}";
        $requestedAt = time();
        set_meta($uid, $key, $requestedAt);

        // Offline social fallback: Ask Friends objectives cannot be fulfilled
        // through Facebook any more. Only fulfil the exact active
        // useItemByCode task requested by Flash; do not turn arbitrary quest
        // requests into item grants.
        $quest = getQuestByName($questName);
        $activeQuests = getActiveQuests($uid);
        $task = $quest['tasks'][$taskIndex] ?? null;
        $questState = $activeQuests[$questName] ?? null;

        if (is_array($task)
            && is_array($questState)
            && ($task['action'] ?? null) === 'useItemByCode'
            && !empty($task['type'])) {
            $required = max(1, (int) ($task['total'] ?? 1));
            $current = (int) ($questState['progress'][$taskIndex] ?? 0);
            $remaining = max(0, $required - $current);

            if ($remaining > 0) {
                $itemCode = (string) $task['type'];
                addGiftByCode($uid, $itemCode, $remaining, $uid, [
                    'source' => 'offline_quest_ask',
                    'questName' => $questName,
                    'taskIndex' => (int) $taskIndex,
                ]);
                updateQuestProgress($uid, $questName, $taskIndex, $remaining);
                checkAndCompleteQuest($uid, $questName);

                Logger::debug('FarmQuestService', sprintf(
                    'Offline Ask Friends fulfilment: uid=%s quest=%s task=%d item=%s amount=%d',
                    $uid,
                    $questName,
                    $taskIndex,
                    $itemCode,
                    $remaining
                ));
            }
        }

        // TAskForQuestItem is not a generic acknowledgement transaction.
        // Transaction.onAmfComplete passes the AMF response's outer `data`
        // object to this callback, so its `data` payload and `ts` must both be
        // nested here. This local deployment has no Facebook feed publisher,
        // so acknowledge the publish immediately. The helper clears its
        // outstanding request and applies the normal ask cooldown, while
        // actual quest-item delivery remains PresentService.buyAndSend's job.
        return [
            "data" => [
                "ts" => $requestedAt,
                "data" => [
                    "published" => true,
                ],
            ],
        ];
    }

    
    public static function recordQuestItemRequest($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        $questName = $request->params[0] ?? null;
        $taskIndex = $request->params[1] ?? 0;
        $timeRequestedAt = $request->params[2] ?? time();

        $key = "quest_item_request_{$questName}_{$taskIndex}";
        set_meta($uid, $key, $timeRequestedAt);

        return ["data" => ["success" => true]];
    }

    
    public static function updateRecentlyCompletedQuests($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        $questName = $request->params[0] ?? null;
        $shouldGenerateFriendReward = $request->params[1] ?? false;

        if (is_string($questName) && $questName !== '') {
            $activeQuests = getActiveQuests($uid);
            $questState = $activeQuests[$questName] ?? null;

            // Flash sends this acknowledgement after it has shown a quest's
            // reward/share flow. Recheck the persisted counters rather than
            // relying only on the transient `completed` flag: older client
            // flows can show the reward UI before that flag is returned to
            // the server. This makes the state transition durable even if a
            // browser refresh immediately follows the completion screen.
            if (is_array($questState) && checkAndCompleteQuest($uid, $questName)) {
                $completion = completeQuest($uid, $questName);
                if (($completion['success'] ?? false) === true) {
                    Logger::debug('FarmQuestService', sprintf(
                        'Finalized completed quest: uid=%s quest=%s',
                        $uid,
                        $questName
                    ));
                }
            }
        }

        if ($shouldGenerateFriendReward) {
            $key = "quest_friend_reward_{$questName}";
            set_meta($uid, $key, time());
        }

        return ["data" => [
            "success" => true,
            "completedQuests" => getCompletedQuests($uid),
        ]];
    }

    
    public static function skipRewardCreditsTask($playerObj, $request, $market = null)
    {
        return self::skipTask($playerObj, $request, $market);
    }

    
    public static function skipGetBushelsTask($playerObj, $request, $market = null)
    {
        return self::skipTask($playerObj, $request, $market);
    }

    
    public static function skipConstructionBuildingTask($playerObj, $request, $market = null)
    {
        return self::skipTask($playerObj, $request, $market);
    }

    
    public static function skipCompleteFriendSetTask($playerObj, $request, $market = null)
    {
        return self::skipTask($playerObj, $request, $market);
    }

    
    public static function skipExpandStorageBuildingTask($playerObj, $request, $market = null)
    {
        return self::skipTask($playerObj, $request, $market);
    }

    
    public static function incrementTask($playerObj, $request, $market = null)
    {
        $uid = $playerObj->getUid();
        $action = $request->params[0] ?? null;

        if (empty($action)) {
            return ["data" => ["success" => false]];
        }

        $updatedQuests = trackQuestProgress($uid, $action, '', 1);

        return [
            "data" => [
                "success" => true,
                "updatedQuests" => array_keys($updatedQuests),
            ],
        ];
    }
}

function findQuestChainRoot($questName)
{
    $visited = [];
    $current = $questName;

    while (true) {
        if (isset($visited[$current])) {
            return $current;
        }
        $visited[$current] = true;

        $quest = getQuestByName($current);
        if (!$quest) {
            return $current;
        }

        $foundParent = false;
        foreach ($quest['prereqs'] as $prereq) {
            if (($prereq['type'] ?? '') === 'quest_complete') {
                $current = $prereq['value'];
                $foundParent = true;
                break;
            }
        }

        if (!$foundParent) {
            return $current;
        }
    }
}

function getQuestChainNames($rootQuestName)
{
    $names = [$rootQuestName];
    $toProcess = [$rootQuestName];

    while (!empty($toProcess)) {
        $current = array_shift($toProcess);
        $quest = getQuestByName($current);

        if (!$quest || empty($quest['children'])) {
            continue;
        }

        foreach ($quest['children'] as $child) {
            if (($child['type'] ?? '') === 'Quest') {
                $childName = $child['value'];
                if (!in_array($childName, $names)) {
                    $names[] = $childName;
                    $toProcess[] = $childName;
                }
            }
        }
    }

    return $names;
}
