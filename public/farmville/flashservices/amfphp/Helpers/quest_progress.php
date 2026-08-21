<?php

require_once AMFPHP_ROOTPATH . "Helpers/quest_helper.php";

use App\Support\QuestCategoryResolver;

function trackQuestProgress($uid, $action, $type, $amount = 1, $extraData = []) {
    $activeQuests = getActiveQuests($uid);
    $updatedQuests = [];
    $candidateTasks = [];

    foreach ($activeQuests as $questName => $questState) {
        $quest = getQuestByName($questName);
        if (!$quest || empty($quest['tasks'])) {
            continue;
        }

        foreach ($quest['tasks'] as $taskIndex => $task) {
            $taskAction = $task['action'] ?? '';
            $taskType = $task['type'] ?? '';

            $candidateTasks[] = [
                'quest' => $questName,
                'task' => $taskIndex,
                'action' => $taskAction,
                'type' => $taskType,
                'total' => $task['total'] ?? 1,
            ];

            // A few legacy harvestBySubtype tasks use `type="seed"` as the
            // action family and keep the actual category (fruit, vegetables,
            // flowers, etc.) in a separate subtype attribute.
            $matchType = ($taskAction === 'harvestBySubtype' && !empty($task['subtype']))
                ? (string) $task['subtype']
                : $taskType;
            if (!matchesTaskAction($taskAction, $action, $matchType, $type, $extraData)) {
                continue;
            }

            $result = updateQuestProgress($uid, $questName, $taskIndex, $amount);
            if ($result) {
                $updatedQuests[$questName] = $result;

            }
        }
    }

    // Flash can paint the final green checkmark without ever sending its
    // later completion acknowledgement. Treat the saved counter totals as
    // authoritative: when this action finished a quest, atomically archive
    // it, grant its rewards, and make its child quest eligible now. This
    // prevents a completed-looking quest from returning as active on reload.
    foreach (array_keys($updatedQuests) as $questName) {
        if (!checkAndCompleteQuest($uid, $questName)) {
            continue;
        }

        $completion = completeQuest($uid, $questName);
        if (($completion['success'] ?? false) === true) {
            Logger::debug('QuestProgress', sprintf(
                'Finalized uid=%s quest=%s after saved task completion',
                $uid,
                $questName
            ));
        }
    }

    if (!empty($updatedQuests)) {
        Logger::debug('QuestProgress', sprintf(
            'Saved uid=%s event=%s type=%s amount=%d updates=%s',
            $uid,
            $action,
            $type,
            $amount,
            json_encode(array_keys($updatedQuests))
        ));
    } else {
        Logger::debug('QuestProgress', sprintf(
            'No matching task uid=%s event=%s type=%s amount=%d candidates=%s',
            $uid,
            $action,
            $type,
            $amount,
            json_encode($candidateTasks)
        ));
    }

    return $updatedQuests;
}

function matchesTaskAction($taskAction, $playerAction, $taskType, $playerType, $extraData = []) {
    if ($taskAction !== $playerAction) {
        $actionMappings = [
            'harvestByCode' => ['harvest'],
            'harvestByCategory' => ['harvest'],
            'plantCropByCode' => ['plant', 'plantCrop'],
            'plantCropByCategory' => ['plant', 'plantCrop'],
            'plowPlot' => ['plow'],
            'makeRecipeByCode' => ['makeRecipe', 'craft'],
            'buyItemByCode' => ['buyItem', 'purchase'],
            'storeItemByAnySpecificInventoryStorage' => ['storeItemByCode', 'store'],
            'useItemByCode' => ['useItem', 'use'],
            'getMasteryLevelByCode' => ['mastery', 'getMastery'],
            'getMasteryLevelByCategory' => ['mastery', 'getMastery'],
        ];

        $allowedActions = $actionMappings[$taskAction] ?? [];
        if (!in_array($playerAction, $allowedActions)) {
            return false;
        }
    }

    if (!matchesTaskType($taskAction, $taskType, $playerType, $extraData)) {
        return false;
    }

    return true;
}

function matchesTaskType($taskAction, $taskType, $playerType, $extraData = []) {
    if ($taskType === $playerType) {
        return true;
    }

    // Generic storage tasks are displayed as “Store N Items” and use an
    // all-items marker rather than the code of a particular animal or
    // decoration.  They should advance for every successfully stored item.
    if (in_array($taskAction, ['storeItemByCode', 'storeItemByAnySpecificInventoryStorage'], true)) {
        $normalizedTaskType = normalizeQuestIdentifier($taskType);
        if (in_array($normalizedTaskType, ['', 'all', 'allitem', 'allitems', 'item', 'items'], true)) {
            return true;
        }
    }

    if (strpos($taskAction, 'ByCategory') !== false) {
        $itemCategories = $extraData['categories'] ?? [];

        $categoryToMatch = $taskType;
        if (strpos($taskType, 'all') === 0) {
            $categoryToMatch = substr($taskType, 3);
        }

        // Several quest definitions use an `all<ItemName>` category even
        // though the imported item only exposes its internal key.  Treat the
        // key itself as a category too (case and punctuation insensitive), so
        // `wheat` correctly fulfils `allWheat` without an alias per crop.
        if (normalizeQuestIdentifier($playerType) === normalizeQuestIdentifier($categoryToMatch)) {
            return true;
        }

        foreach ($itemCategories as $cat) {
            if (normalizeQuestIdentifier($cat) === normalizeQuestIdentifier($categoryToMatch)) {
                return true;
            }
        }

        return false;
    }

    return false;
}

function normalizeQuestIdentifier($value) {
    return QuestCategoryResolver::normalized((string) $value);
}

/**
 * Return the quest categories represented by an item. Imported item data does
 * not consistently preserve the client-side crop category names, so retain
 * aliases for names that differ from the internal item key.
 */
function getQuestItemCategories($itemName, $itemData = []) {
    return QuestCategoryResolver::categories(
        (string) $itemName,
        is_array($itemData) ? $itemData : [],
    );
}

function trackHarvestProgress($uid, $obj, $itemName, $itemData = [], $amount = 1) {
    // Quest definitions use the compact item code for `harvestByCode` tasks
    // (for example Cluck Rogers is `6V5`), while category tasks must still
    // receive the stable internal item name. Sending the name to both made
    // code-based harvest progress look correct locally, then reset on reload.
    $itemCode = is_array($itemData) && !empty($itemData['code'])
        ? (string) $itemData['code']
        : (string) $itemName;
    $extraData = [
        'itemCode' => $itemCode,
        'categories' => getQuestItemCategories($itemName, $itemData),
        'objState' => $obj['state'] ?? null,
    ];

    $updates1 = trackQuestProgress($uid, 'harvestByCode', $itemCode, $amount, $extraData);

    $updates2 = trackQuestProgress($uid, 'harvestByCategory', $itemName, $amount, $extraData);

    $updates3 = [];
    $subtype = is_array($itemData) ? (string) ($itemData['subtype'] ?? '') : '';
    if ($subtype !== '') {
        $updates3 = trackQuestProgress($uid, 'harvestBySubtype', $subtype, $amount, $extraData);
    }

    return array_merge($updates1, $updates2, $updates3);
}

function trackPlantProgress($uid, $itemName, $itemData = [], $amount = 1) {
    $extraData = [
        'itemCode' => $itemName,
        'categories' => getQuestItemCategories($itemName, $itemData),
    ];

    $updates1 = trackQuestProgress($uid, 'plantCropByCode', $itemName, $amount, $extraData);

    $updates2 = trackQuestProgress($uid, 'plantCropByCategory', $itemName, $amount, $extraData);

    return array_merge($updates1, $updates2);
}

function trackPlowProgress($uid, $count = 1) {
    return trackQuestProgress($uid, 'plowPlot', 'plot', $count);
}

function trackRecipeProgress($uid, $recipeCode, $recipeData = []) {
    $recipeName = is_array($recipeData) && !empty($recipeData['name'])
        ? (string) $recipeData['name']
        : (string) $recipeCode;
    $recipeCategories = array_merge(
        getQuestItemCategories($recipeName, is_array($recipeData) ? $recipeData : []),
        \App\Support\QuestCategoryResolver::recipeCategories($recipeName),
    );
    $extraData = [
        'recipeCode' => $recipeCode,
        'categories' => array_values(array_unique($recipeCategories)),
    ];

    $updatesByCode = trackQuestProgress($uid, 'makeRecipeByCode', $recipeCode, 1, $extraData);
    $updatesByCategory = trackQuestProgress($uid, 'makeRecipeByCategory', $recipeName, 1, $extraData);
    $updatesAnyRecipe = trackQuestProgress($uid, 'makeRecipeAny', 'any', 1, $extraData);

    return array_merge($updatesByCode, $updatesByCategory, $updatesAnyRecipe);
}

function trackBuyItemProgress($uid, $itemCode, $quantity = 1) {
    return trackQuestProgress($uid, 'buyItemByCode', $itemCode, $quantity);
}

function trackUseItemProgress($uid, $itemCode, $quantity = 1) {
    return trackQuestProgress($uid, 'useItemByCode', $itemCode, $quantity);
}

function trackMasteryProgress($uid, $itemCode, $newLevel, $itemData = []) {
    $itemName = is_array($itemData) && !empty($itemData['name'])
        ? (string) $itemData['name']
        : (string) $itemCode;
    $extraData = [
        'masteryLevel' => $newLevel,
        'itemCode' => $itemCode,
        'itemName' => $itemName,
        'categories' => getQuestItemCategories($itemName, is_array($itemData) ? $itemData : []),
    ];

    // Mastery objectives describe a level, not a number of harvest events.
    // A quest can become active after the player has already earned a star,
    // so reconcile the saved level rather than only incrementing on the
    // next level-up event.
    return syncMasteryQuestProgress($uid, $itemCode, $newLevel, $extraData);
}

/**
 * Bring active mastery tasks up to the player's saved star level.
 * Flash and the stored mastery component use zero-based levels, while quest
 * task totals count stars, hence the `+ 1`.
 */
function syncMasteryQuestProgress($uid, $itemCode, $masteryLevel, $extraData = []) {
    if (empty($extraData['itemName']) && function_exists('getItemByCode')) {
        $itemData = getItemByCode((string) $itemCode);
        if (is_array($itemData)) {
            $extraData['itemName'] = (string) ($itemData['name'] ?? $itemCode);
            $extraData['categories'] = getQuestItemCategories($extraData['itemName'], $itemData);
        }
    }
    $activeQuests = getActiveQuests($uid);
    $updatedQuests = [];
    $achievedStars = max(0, (int) $masteryLevel + 1);

    foreach ($activeQuests as $questName => &$questState) {
        $quest = getQuestByName($questName);
        if (!$quest || empty($quest['tasks'])) {
            continue;
        }

        foreach ($quest['tasks'] as $taskIndex => $task) {
            $taskAction = $task['action'] ?? '';
            if (!in_array($taskAction, ['getMasteryLevelByCode', 'getMasteryLevelByCategory'], true)) {
                continue;
            }

            $playerType = $taskAction === 'getMasteryLevelByCode'
                ? (string) $itemCode
                : (string) ($extraData['itemName'] ?? $itemCode);
            if (!matchesTaskAction($taskAction, $taskAction, (string) ($task['type'] ?? ''), $playerType, $extraData)) {
                continue;
            }

            $total = max(1, (int) ($task['total'] ?? 1));
            $current = (int) ($questState['progress'][$taskIndex] ?? 0);
            $reconciled = min($achievedStars, $total);
            if ($reconciled > $current) {
                $questState['progress'][$taskIndex] = $reconciled;
                $updatedQuests[$questName] = true;
            }
        }
    }
    unset($questState);

    if ($updatedQuests === []) {
        return [];
    }

    setActiveQuests($uid, $activeQuests);
    foreach (array_keys($updatedQuests) as $questName) {
        if (checkAndCompleteQuest($uid, $questName)) {
            completeQuest($uid, $questName);
        }
    }

    Logger::debug('QuestProgress', sprintf(
        'Reconciled mastery uid=%s code=%s level=%d updates=%s',
        $uid,
        $itemCode,
        $masteryLevel,
        json_encode(array_keys($updatedQuests))
    ));

    return $updatedQuests;
}

function trackStoreProgress($uid, $itemCode, $quantity = 1) {
    return trackQuestProgress($uid, 'storeItemByCode', $itemCode, $quantity);
}

function trackStorageBuildingExpansionProgress($uid, $itemData): array {
    $itemCode = is_array($itemData) ? (string) ($itemData['code'] ?? '') : '';
    if ($itemCode === '') {
        return [];
    }

    return trackQuestProgress($uid, 'expandStorageBuildingToLevelByCode', $itemCode, 1);
}

function trackDialogView($uid, $dialogId) {
    return trackQuestProgress($uid, 'viewDialog', $dialogId, 1);
}

function trackWorldScoreLevel($uid, $worldType, $currentLevel) {
    $extraData = [
        'worldType' => $worldType,
        'level' => $currentLevel,
    ];

    return trackQuestProgress($uid, 'reachWorldScoreLevel', (string)$currentLevel, 1, $extraData);
}

function trackFeatureCraftingNPC($uid, $npcCode, $featureCode = '') {
    $extraData = [
        'featureCode' => $featureCode,
    ];

    return trackQuestProgress($uid, 'completeFeatureCraftingNPC', $npcCode, 1, $extraData);
}

function trackPlaceItemProgress($uid, $itemCode, $quantity = 1) {
    return trackQuestProgress($uid, 'placeItemByCode', $itemCode, $quantity);
}

function trackCollectFromBuilding($uid, $buildingCode) {
    return trackQuestProgress($uid, 'collectFromBuildingByCode', $buildingCode, 1);
}

function trackBatchProgress($uid, $progressUpdates) {
    $allUpdates = [];

    foreach ($progressUpdates as $update) {
        $action = $update[0] ?? '';
        $type = $update[1] ?? '';
        $amount = $update[2] ?? 1;
        $extraData = $update[3] ?? [];

        $updates = trackQuestProgress($uid, $action, $type, $amount, $extraData);
        $allUpdates = array_merge($allUpdates, $updates);
    }

    return $allUpdates;
}
