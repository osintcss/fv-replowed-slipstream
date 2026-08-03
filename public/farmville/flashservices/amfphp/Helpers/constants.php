<?php

use App\Helpers\ObjectHelper;

define('IN_GAME_DAY_SECONDS', 82800); // 23 hours is what game client use to indicate a full day  
define('GROW_MULTIPLIER', 1);

function calculateGrowTimeMs($growTimeDays) {
    return (float) $growTimeDays * IN_GAME_DAY_SECONDS * 1000 * GROW_MULTIPLIER;
}

function getCurrentTimeMs() {
    return (float) (time() * 1000);
}

function calculateFullyGrownPlantTime($growTimeDays) {
    return getCurrentTimeMs() - calculateGrowTimeMs($growTimeDays);
}

define('PLOT_STATE_FALLOW', 'fallow');
define('PLOT_STATE_PLOWED', 'plowed');
define('PLOT_STATE_PLANTED', 'planted');
define('PLOT_STATE_GROWN', 'grown');
define('PLOT_STATE_WITHERED', 'withered');

define('HARVESTABLE_STATE_BARE', 'bare');

define('INSTAGROW_COST_CROP', 4);
define('INSTAGROW_COST_TREE', 3);
define('INSTAGROW_COST_ANIMAL', 3);

define('IRRIGATION_WATER_ITEM_CODE', '3YG');
define('INSTAGROW_COST_BLOOM', 1);

function getInstantGrowCost($typeMatched) {
    switch ($typeMatched) {
        case 'Plot':
            return INSTAGROW_COST_CROP;
        case 'Tree':
            return INSTAGROW_COST_TREE;
        case 'Animal':
            return INSTAGROW_COST_ANIMAL;
        case 'Bloom/Building':
            return INSTAGROW_COST_BLOOM;
        default:
            return 0;
    }
}

function getPlotStateTransitions() {
    return [
        PLOT_STATE_FALLOW   => [PLOT_STATE_PLOWED],
        PLOT_STATE_PLOWED   => [PLOT_STATE_PLANTED, PLOT_STATE_FALLOW],
        PLOT_STATE_PLANTED  => [PLOT_STATE_GROWN, PLOT_STATE_WITHERED, PLOT_STATE_FALLOW],
        PLOT_STATE_GROWN    => [PLOT_STATE_FALLOW],
        PLOT_STATE_WITHERED => [PLOT_STATE_PLOWED, PLOT_STATE_FALLOW],
    ];
}

function getHarvestableStateTransitions() {
    return [
        HARVESTABLE_STATE_BARE  => [PLOT_STATE_GROWN],
        PLOT_STATE_GROWN        => [HARVESTABLE_STATE_BARE],
    ];
}

function getPostHarvestState($className) {
    if (stripos($className, 'Plot') !== false) {
        return [
            'state' => PLOT_STATE_FALLOW,
            'plantTime' => 0,
            'itemName' => null,
            'isJumbo' => false,
        ];
    } else {
        return [
            'state' => HARVESTABLE_STATE_BARE,
            'plantTime' => getCurrentTimeMs(),
        ];
    }
}

function isValidStateTransition($className, $currentState, $newState) {
    if (stripos($className, 'Plot') !== false) {
        $transitions = getPlotStateTransitions();
    } else {
        $transitions = getHarvestableStateTransitions();
    }

    if (!isset($transitions[$currentState])) {
        return false;
    }

    return in_array($newState, $transitions[$currentState]);
}

function getInstantGrowState($className, $currentState) {
    if (stripos($className, 'Plot') !== false) {
        if ($currentState === PLOT_STATE_PLANTED) {
            return PLOT_STATE_GROWN;
        }
    } else {
        if ($currentState === HARVESTABLE_STATE_BARE) {
            return PLOT_STATE_GROWN;
        }
    }
    return null;
}

define('TEMP_ID_THRESHOLD', 63000);
define('TEMP_ID_END', 65500);

define('GIFTBOX_ID', -1);
define('GIFTBOX_STORAGE_KEY', '-6');

define('HOME_INVENTORY_ID', -2);
define('INVENTORY_STORAGE_KEY', '-2');

define('VALID_PURCHASABLE_WORLDS', [
    'england', 'fisherman', 'winterwonderland', 'australia',
    'space', 'candy', 'fforest', 'hlights', 'rainforest', 'oz',
    'mediterranean', 'oasis', 'storybook', 'sleepyhollow', 'toyland',
    'village', 'glen', 'atlantis', 'hallow'
]);

define('ACTION_HARVEST', 'harvest');
define('ACTION_PLOW', 'plow');
define('ACTION_PLANT', 'place');
define('ACTION_COMBINE', 'combine');
define('ACTION_WATER', 'water');
define('ACTION_REMOVE', 'plotRemove');
define('ACTION_MOVE', 'move');
define('ACTION_SELL', 'sell');
define('ACTION_CLEAR', 'clear');
define('ACTION_CLEAR_WITHERED', 'clearWithered');
define('ACTION_INSTANT_GROW', 'instantGrow');
define('ACTION_STORE', 'store');
define('ACTION_SET_MULTIPLE_FEATURED_ITEMS', 'setMultipleFeaturedItems');
define('ACTION_NEIGHBOR_ACT', 'neighborAct');
define('ACTION_REDEEM_NEIGHBOR_FERTILIZE', 'redeemNeighborFertilize');
define('ACTION_PLACE_MESSAGE', 'placeMessage');
define('ACTION_DELETE_MESSAGE_SIGN', 'deleteMessageSign');
define('ACTION_EXPAND_WITH_CURRENCY', 'ExpandWithCurrency');
define('ACTION_COMPLETE_NOW', 'CompleteNow');
define('ACTION_OPEN', 'open');

define('ACTION_UPGRADE_STORAGE', 'upgradeStorage');
define('ACTION_PURCHASE_STORAGE_UPGRADE', 'purchaseStorageUpgrade');
define('ACTION_CANCEL_STORAGE_UPGRADE', 'cancelStorageUpgrade');
define('ACTION_GET_STORAGE_INFO', 'getStorageInfo');

define('NEIGHBOR_ACTION_FERT', 'fert');
define('NEIGHBOR_ACTION_UNWITHER', 'unwither');
define('NEIGHBOR_ACTION_FEED_CHICKENS', 'feedchickens');
define('NEIGHBOR_ACTION_TRICK', 'trickneighbor');

define('LIMIT_KEY_FARM', 'farm');
define('LIMIT_KEY_FEED', 'feed');

function isPositionBasedAction($action) {
    return in_array($action, [ACTION_PLANT, ACTION_HARVEST, ACTION_REMOVE, ACTION_COMBINE]);
}

function buildPositionIndex($objectsArray) {
    $index = [];
    foreach ($objectsArray as $key => $obj) {
        if (isset($obj->position)) {
            [$x, $y] = ObjectHelper::getPosition($obj);
            if ($x !== null && $y !== null) {
                $index["$x,$y"] = $key;
            }
        }
    }
    return $index;
}

function findByPosition($positionIndex, $x, $y) {
    $key = "$x,$y";
    return $positionIndex[$key] ?? null;
}
