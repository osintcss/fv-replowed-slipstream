<?php

require_once AMFPHP_ROOTPATH . "Helpers/quest_helper.php";

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

            if (!matchesTaskAction($taskAction, $action, $taskType, $type, $extraData)) {
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
    return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $value));
}

/**
 * Return the quest categories represented by an item. Imported item data does
 * not consistently preserve the client-side crop category names, so retain
 * aliases for names that differ from the internal item key.
 */
function getQuestItemCategories($itemName, $itemData = []) {
    $categories = [];

    // `subtype` is the normal category source in the imported FarmVille item
    // definitions (for example `fruit`, `grain`, `vegetable`, or `flowers`).
    foreach (['categories', 'category', 'subtype'] as $field) {
        if (!isset($itemData[$field])) {
            continue;
        }

        $value = $itemData[$field];
        if (is_string($value)) {
            $categories[] = $value;
        } elseif (is_array($value)) {
            foreach ($value as $category) {
                if (is_string($category)) {
                    $categories[] = $category;
                }
            }
        }
    }

    // `aloe` is the item key, while FarmQuest settings use the client-facing
    // harvest category `AloeVera` (for example `allAloeVera`).
    $itemCategoryAliases = [
        'aloe' => ['AloeVera'],
    ];

    $normalizedItemName = strtolower((string) $itemName);
    foreach ($itemCategoryAliases[$normalizedItemName] ?? [] as $category) {
        $categories[] = $category;
    }

    // FarmQuest uses habitat categories while world objects use their full
    // building key, for example `animal_breeding_petrun_finished`.  Map the
    // stable item-name family once, rather than hard-coding every finished
    // Pet Run variant.
    if (str_contains($normalizedItemName, 'petrun')) {
        $categories[] = 'petRunHabitat';
    }

    // The same finished-building naming convention is used by livestock
    // breeding pens. Quest definitions refer to the family as a habitat.
    if (str_contains($normalizedItemName, 'livestock')) {
        $categories[] = 'livestockHabitat';
    }

    // Horse paddocks use the same finished-building key convention. Quest
    // settings call this family paddockHabitat rather than the internal
    // animal_breeding_horsepaddock_finished item name.
    if (str_contains($normalizedItemName, 'paddock')) {
        $categories[] = 'paddockHabitat';
    }

    return array_values(array_unique($categories));
}

function trackHarvestProgress($uid, $obj, $itemName, $itemData = [], $amount = 1) {
    $extraData = [
        'itemCode' => $itemName,
        'categories' => getQuestItemCategories($itemName, $itemData),
        'objState' => $obj['state'] ?? null,
    ];

    $updates1 = trackQuestProgress($uid, 'harvestByCode', $itemName, $amount, $extraData);

    $updates2 = trackQuestProgress($uid, 'harvestByCategory', $itemName, $amount, $extraData);

    return array_merge($updates1, $updates2);
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
    $extraData = [
        'recipeCode' => $recipeCode,
        'categories' => $recipeData['categories'] ?? [],
    ];

    return trackQuestProgress($uid, 'makeRecipeByCode', $recipeCode, 1, $extraData);
}

function trackBuyItemProgress($uid, $itemCode, $quantity = 1) {
    return trackQuestProgress($uid, 'buyItemByCode', $itemCode, $quantity);
}

function trackUseItemProgress($uid, $itemCode, $quantity = 1) {
    return trackQuestProgress($uid, 'useItemByCode', $itemCode, $quantity);
}

function trackMasteryProgress($uid, $itemCode, $newLevel) {
    $extraData = [
        'masteryLevel' => $newLevel,
    ];

    return trackQuestProgress($uid, 'getMasteryLevelByCode', $itemCode, 1, $extraData);
}

function trackStoreProgress($uid, $itemCode, $quantity = 1) {
    return trackQuestProgress($uid, 'storeItemByCode', $itemCode, $quantity);
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
