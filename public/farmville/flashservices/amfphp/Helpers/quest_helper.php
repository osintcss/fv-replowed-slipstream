<?php

require_once AMFPHP_ROOTPATH . "Helpers/globals.php";
require_once AMFPHP_ROOTPATH . "Helpers/general_functions.php";
require_once AMFPHP_ROOTPATH . "Helpers/logger.php";

use App\Models\Quest;

define('META_QUEST_ACTIVE', 'quest_active');
define('META_QUEST_COMPLETED', 'quest_completed');
define('META_QUEST_COMPLETED_REPLAYABLE', 'quest_completed_replayable');

function getQuestByName($questName) {
    static $questCache = [];

    if (isset($questCache[$questName])) {
        return $questCache[$questName];
    }

    $questModel = Quest::where('name', $questName)->first();

    if (!$questModel) {
        $questCache[$questName] = null;
        return null;
    }

    $quest = $questModel->toArray();

    $quest['prereqs'] = $quest['prereqs'] ? json_decode($quest['prereqs'], true) : [];
    $quest['children'] = $quest['children'] ? json_decode($quest['children'], true) : [];
    $quest['tasks'] = $quest['tasks'] ? json_decode($quest['tasks'], true) : [];
    $quest['rewards'] = $quest['rewards'] ? json_decode($quest['rewards'], true) : [];
    $quest['frontend'] = $quest['frontend'] ? json_decode($quest['frontend'], true) : [];
    $quest['friend_reward'] = $quest['friend_reward'] ? json_decode($quest['friend_reward'], true) : null;

    $questCache[$questName] = $quest;
    return $quest;
}

function getQuestsByCategory($category) {
    $questNames = Quest::where('category', $category)
        ->orderBy('priority')
        ->pluck('name');

    $quests = [];
    foreach ($questNames as $name) {
        $quest = getQuestByName($name);
        if ($quest) {
            $quests[] = $quest;
        }
    }

    return $quests;
}

function getActiveQuests($uid) {
    $raw = get_meta($uid, META_QUEST_ACTIVE);
    if ($raw) {
        $data = @unserialize($raw);
        if (is_array($data)) {
            return $data;
        }
    }
    return [];
}

function setActiveQuests($uid, $quests) {
    set_meta($uid, META_QUEST_ACTIVE, serialize($quests));
}

function getActiveQuestIds($uid) {
    $activeQuests = getActiveQuests($uid);
    return array_keys($activeQuests);
}

function getCompletedQuests($uid) {
    $raw = get_meta($uid, META_QUEST_COMPLETED);
    if ($raw) {
        $data = @unserialize($raw);
        if (is_array($data)) {
            return $data;
        }
    }
    return [];
}

function addCompletedQuest($uid, $questName) {
    $completed = getCompletedQuests($uid);
    if (!in_array($questName, $completed)) {
        $completed[] = $questName;
        set_meta($uid, META_QUEST_COMPLETED, serialize($completed));
    }
}

function getCompletedReplayableQuests($uid) {
    $raw = get_meta($uid, META_QUEST_COMPLETED_REPLAYABLE);
    if ($raw) {
        $data = @unserialize($raw);
        if (is_array($data)) {
            return $data;
        }
    }
    return [];
}

function addCompletedReplayableQuest($uid, $questName) {
    $completed = getCompletedReplayableQuests($uid);
    if (!in_array($questName, $completed)) {
        $completed[] = $questName;
        set_meta($uid, META_QUEST_COMPLETED_REPLAYABLE, serialize($completed));
    }
}

/**
 * TPostInit converts this object into ReplayableFarmQuestData instances. The
 * old server only persisted quest names, so reconstruct the client record
 * from each quest definition's stable memStoreId.
 */
function buildCompletedReplayableQuestData($uid) {
    $completed = [];

    foreach (getCompletedReplayableQuests($uid) as $questName) {
        $quest = getQuestByName($questName);
        $memStoreId = $quest['mem_store_id'] ?? null;
        if ($memStoreId === null || $memStoreId === '') {
            continue;
        }

        $completed[(string) $memStoreId] = [
            'completion_count' => 1,
            'completion_date' => time(),
            'hideFromQuestManagerComplete' => false,
        ];
    }

    return $completed;
}

function hasCompletedQuest($uid, $questName) {
    return in_array($questName, getCompletedQuests($uid))
        || in_array($questName, getCompletedReplayableQuests($uid));
}

function hasCompletedReplayableQuest($uid, $questName) {
    return in_array($questName, getCompletedReplayableQuests($uid));
}

function startQuest($uid, $questName) {
    $quest = getQuestByName($questName);
    if (!$quest) {
        return null;
    }

    $activeQuests = getActiveQuests($uid);

    if (isset($activeQuests[$questName])) {
        return $activeQuests[$questName];
    }

    $taskCount = count($quest['tasks']);
    $progress = array_fill(0, $taskCount, 0);

    $questState = [
        'name' => $questName,
        'startedAt' => time(),
        'progress' => $progress,
        'taskCount' => $taskCount,
        'removed' => false,
        'expired' => false,
        'completed' => false,
    ];

    $activeQuests[$questName] = $questState;
    setActiveQuests($uid, $activeQuests);

    return $questState;
}

function updateQuestProgress($uid, $questName, $taskIndex, $amount = 1) {
    $activeQuests = getActiveQuests($uid);

    if (!isset($activeQuests[$questName])) {
        return null;
    }

    $quest = getQuestByName($questName);
    if (!$quest) {
        return null;
    }

    if ($taskIndex < 0 || $taskIndex >= count($quest['tasks'])) {
        return null;
    }

    $task = $quest['tasks'][$taskIndex];
    $total = isset($task['total']) ? (int)$task['total'] : 1;

    $currentProgress = $activeQuests[$questName]['progress'][$taskIndex];
    $activeQuests[$questName]['progress'][$taskIndex] = min($currentProgress + $amount, $total);

    setActiveQuests($uid, $activeQuests);

    return $activeQuests[$questName];
}

function checkAndCompleteQuest($uid, $questName) {
    $activeQuests = getActiveQuests($uid);

    if (!isset($activeQuests[$questName])) {
        return false;
    }

    $quest = getQuestByName($questName);
    if (!$quest) {
        return false;
    }

    foreach ($quest['tasks'] as $idx => $task) {
        $total = isset($task['total']) ? (int)$task['total'] : 1;
        $progress = $activeQuests[$questName]['progress'][$idx];

        if ($progress < $total) {
            return false;
        }
    }

    $activeQuests[$questName]['completed'] = true;
    setActiveQuests($uid, $activeQuests);

    return true;
}

function completeQuest($uid, $questName, $worldType = 'main') {
    $activeQuests = getActiveQuests($uid);
    $quest = getQuestByName($questName);

    if (!$quest || !isset($activeQuests[$questName])) {
        return ['success' => false, 'error' => 'Quest not found or not active'];
    }

    $rewardsGranted = grantQuestRewards($uid, $quest['rewards'], $worldType);

    unset($activeQuests[$questName]);
    setActiveQuests($uid, $activeQuests);

    if ($quest['replay']) {
        addCompletedReplayableQuest($uid, $questName);
    } else {
        addCompletedQuest($uid, $questName);
    }

    // Child quests must be evaluated at the player's actual level. Passing
    // startQuestIfEligible() no level here made every chained quest look as
    // though it belonged to a level-1 player, so children with level_min
    // requirements silently failed to start after their intro completed.
    $userMeta = \App\Models\UserMeta::where('uid', $uid)->first(['xp']);
    $playerLevel = $userMeta ? getLevelForXp((int) $userMeta->xp) : 1;

    $childrenStarted = [];
    if (!empty($quest['children'])) {
        foreach ($quest['children'] as $child) {
            if ($child['type'] === 'Quest') {
                $childQuest = startQuestIfEligible($uid, $child['value'], $playerLevel);
                if ($childQuest) {
                    $childrenStarted[] = $child['value'];
                }
            }
        }
    }

    return [
        'success' => true,
        'rewards' => $rewardsGranted,
        'childrenStarted' => $childrenStarted,
    ];
}

/**
 * Repair quests saved by earlier server responses as completed-but-active.
 * Those clients never received their QuestEvent.COMPLETED and therefore never
 * sent the normal reward/share acknowledgement. This is deliberately limited
 * to states already marked complete; in-progress quests are untouched.
 */
function finalizePendingCompletedQuests($uid, $worldType = 'main') {
    $finalized = [];

    foreach (getActiveQuests($uid) as $questName => $state) {
        if (!is_array($state)) {
            continue;
        }

        $quest = getQuestByName($questName);
        if (!$quest) {
            continue;
        }

        $isMarkedComplete = ($state['completed'] ?? false) === true;
        $hasFinishedProgress = true;
        foreach ($quest['tasks'] as $taskIndex => $task) {
            $required = max(1, (int) ($task['total'] ?? 1));
            $progress = (int) ($state['progress'][$taskIndex] ?? 0);
            if ($progress < $required) {
                $hasFinishedProgress = false;
                break;
            }
        }

        // Older saves can contain exact completed counters but a false flag
        // because the final progress response was acknowledged by Flash
        // before the server wrote its completion marker.
        if (!$isMarkedComplete && !$hasFinishedProgress) {
            continue;
        }

        $completion = completeQuest($uid, $questName, $worldType);
        if (($completion['success'] ?? false) === true) {
            $finalized[] = $questName;
        }
    }

    return $finalized;
}

function grantQuestRewards($uid, $rewards, $worldType = 'main') {
    require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";

    $granted = [];

    foreach ($rewards as $reward) {
        $type = $reward['type'];
        $value = $reward['value'];
        $quantity = isset($reward['quantity']) ? (int)$reward['quantity'] : 1;

        switch ($type) {
            case 'xp':
                UserResources::addXp($uid, (int)$value);
                $granted[] = ['type' => 'xp', 'amount' => (int)$value];
                break;

            case 'coins':
                UserResources::addGold($uid, (int)$value);
                $granted[] = ['type' => 'coins', 'amount' => (int)$value];
                break;

            case 'cash':
                UserResources::addCash($uid, (int)$value);
                $granted[] = ['type' => 'cash', 'amount' => (int)$value];
                break;

            case 'item_grant':
                addGiftByCode($uid, $value, $quantity);
                $granted[] = ['type' => 'item_grant', 'itemCode' => $value, 'quantity' => $quantity];
                break;

            case 'world_score':
                addWorldScore($uid, $worldType, (int)$value);
                $granted[] = ['type' => 'world_score', 'amount' => (int)$value];
                break;

            case 'avatar_item':
                unlockAvatarItem($uid, $value);
                $granted[] = ['type' => 'avatar_item', 'item' => $value];
                break;

            case 'seen_flag':
                setUserSeenFlag($uid, $value);
                $granted[] = ['type' => 'seen_flag', 'flag' => $value];
                break;

            default:
                $granted[] = ['type' => $type, 'value' => $value, 'skipped' => true];
                break;
        }
    }

    return $granted;
}

function addWorldScore($uid, $worldType, $amount) {
    $key = "world_score_$worldType";
    $current = (int)get_meta($uid, $key) ?: 0;
    set_meta($uid, $key, (string)($current + $amount));
}

function unlockAvatarItem($uid, $itemCode) {
    $items = get_meta($uid, 'avatar_items');
    $itemArray = $items ? @unserialize($items) : [];

    if (!is_array($itemArray)) {
        $itemArray = [];
    }

    if (!in_array($itemCode, $itemArray)) {
        $itemArray[] = $itemCode;
        set_meta($uid, 'avatar_items', serialize($itemArray));
    }
}

function setUserSeenFlag($uid, $flag) {
    $flags = get_meta($uid, 'seen_flags');
    $flagArray = $flags ? @unserialize($flags) : [];

    if (!is_array($flagArray)) {
        $flagArray = [];
    }

    if (!isset($flagArray[$flag])) {
        $flagArray[$flag] = time();
        set_meta($uid, 'seen_flags', serialize($flagArray));
    }
}

function checkQuestPrereqs($uid, $prereqs, $playerLevel = 1) {
    if (empty($prereqs)) {
        return true;
    }

    $now = time();

    foreach ($prereqs as $prereq) {
        $type = $prereq['type'];
        $value = $prereq['value'];

        switch ($type) {
            case 'level_min':
                if ($playerLevel < (int)$value) {
                    return false;
                }
                break;

            case 'quest_complete':
                if (!hasCompletedQuest($uid, $value)) {
                    return false;
                }
                break;

            case 'start_time':
            case 'end_time':
                break;

        }
    }

    return true;
}

function startQuestIfEligible($uid, $questName, $playerLevel = 1) {
    $quest = getQuestByName($questName);
    if (!$quest) {
        return null;
    }

    $activeQuests = getActiveQuests($uid);
    if (isset($activeQuests[$questName])) {
        return null;
    }

    if (!$quest['replay'] && hasCompletedQuest($uid, $questName)) {
        return null;
    }

    // Replay chains are restarted through userResetQuestChapter, which clears
    // this marker. Do not allow the start button to create duplicate chains.
    if ($quest['replay'] && hasCompletedReplayableQuest($uid, $questName)) {
        return null;
    }

    if (!checkQuestPrereqs($uid, $quest['prereqs'], $playerLevel)) {
        return null;
    }

    return startQuest($uid, $questName);
}

function removeQuest($uid, $questName) {
    $activeQuests = getActiveQuests($uid);

    if (!isset($activeQuests[$questName])) {
        return false;
    }

    unset($activeQuests[$questName]);
    setActiveQuests($uid, $activeQuests);

    return true;
}

function skipQuestTask($uid, $questName, $taskIndex) {
    require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";

    $quest = getQuestByName($questName);
    $activeQuests = getActiveQuests($uid);

    if (!$quest || !isset($activeQuests[$questName])) {
        return ['success' => false, 'error' => 'Quest not found or not active'];
    }

    if ($taskIndex < 0 || $taskIndex >= count($quest['tasks'])) {
        return ['success' => false, 'error' => 'Invalid task index'];
    }

    $task = $quest['tasks'][$taskIndex];
    $cashValue = isset($task['cashValue']) ? (int)$task['cashValue'] : 5;

    $userCash = UserResources::getCash($uid);
    if ($userCash < $cashValue) {
        return ['success' => false, 'error' => 'Not enough cash', 'required' => $cashValue];
    }

    UserResources::addCash($uid, -$cashValue);

    $total = isset($task['total']) ? (int)$task['total'] : 1;
    $activeQuests[$questName]['progress'][$taskIndex] = $total;
    setActiveQuests($uid, $activeQuests);

    return [
        'success' => true,
        'cashSpent' => $cashValue,
        'progress' => $activeQuests[$questName]['progress'],
    ];
}

function buildQuestComponent($uid) {
    $activeQuests = getActiveQuests($uid);
    $component = [];

    foreach ($activeQuests as $questName => $state) {
        // AMF must carry task progress as numbers.  The Flash quest manager
        // performs arithmetic with this array while processing a world-action
        // response.  Sending strings makes ActionScript concatenate values
        // instead (for example a predicted 10000 plus saved "2" becomes
        // 10002), which can falsely complete an objective.
        $progressValues = array_map('intval', $state['progress']);

        $component[] = [
            'name' => $questName,
            'progress' => $progressValues,
            'removed' => $state['removed'] ?? false,
            'expired' => $state['expired'] ?? false,
            // `complete: true` means something very specific to the Flash
            // quest manager: it skips reconciling the returned progress.
            // If we set it as soon as the server notices every task is done,
            // Flash never dispatches its QuestEvent.COMPLETED, so it shows
            // green checks but never runs the reward/share flow or moves the
            // quest to Completed. Keep this false until that original client
            // flow acknowledges the completion and removes the quest.
            'complete' => false,
        ];
    }

    return $component;
}

function isViewDialogOnlyQuest(?array $quest): bool {
    return $quest !== null
        && count($quest['tasks'] ?? []) === 1
        && (($quest['tasks'][0]['action'] ?? null) === 'viewDialog');
}

function getViewDialogTaskTotal(array $quest): int {
    return max(1, (int) ($quest['tasks'][0]['total'] ?? 1));
}

function getAvailableQuests($uid, $playerLevel = 1, $limit = 20) {
    $activeQuests = getActiveQuests($uid);
    $completedQuests = getCompletedQuests($uid);

    $questNames = Quest::where('category', 'story')
        ->orderBy('priority')
        ->limit(100)
        ->pluck('name');

    $available = [];

    foreach ($questNames as $questName) {
        if (isset($activeQuests[$questName])) {
            continue;
        }

        $quest = getQuestByName($questName);

        if (!$quest['replay'] && in_array($questName, $completedQuests)) {
            continue;
        }

        if (checkQuestPrereqs($uid, $quest['prereqs'], $playerLevel)) {
            $available[] = $quest;

            if (count($available) >= $limit) {
                break;
            }
        }
    }

    return $available;
}

/**
 * Ensure a player with no active quests receives the next eligible story quest.
 *
 * The Flash client receives active quest state through QuestComponent metadata;
 * it does not create a starter quest on its own.
 */
function ensureAvailableStoryQuest($uid) {
    if (!empty(getActiveQuests($uid))) {
        return null;
    }

    $meta = \App\Models\UserMeta::where('uid', $uid)->first(['xp']);
    $playerLevel = $meta ? getLevelForXp((int) $meta->xp) : 1;

    // CompleteQuest normally starts child quests immediately. This also
    // repairs chains that were completed before replayable completions were
    // considered valid `quest_complete` prerequisites.
    $completedNames = array_merge(getCompletedQuests($uid), getCompletedReplayableQuests($uid));
    foreach ($completedNames as $completedName) {
        $completedQuest = getQuestByName($completedName);
        if (!$completedQuest) {
            continue;
        }

        foreach ($completedQuest['children'] as $child) {
            if (($child['type'] ?? null) !== 'Quest') {
                continue;
            }

            $childName = $child['value'] ?? null;
            if (!$childName || hasCompletedQuest($uid, $childName)) {
                continue;
            }

            $started = startQuestIfEligible($uid, $childName, $playerLevel);
            if ($started !== null) {
                return $started;
            }
        }
    }

    $available = getAvailableQuests($uid, $playerLevel, 1);

    if (empty($available)) {
        return null;
    }

    return startQuestIfEligible($uid, $available[0]['name'], $playerLevel);
}
