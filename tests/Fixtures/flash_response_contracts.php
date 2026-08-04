<?php

return [
    'UserService.postInit' => [
        'arguments' => [null],
        'required' => [
            'data.pricingTests' => 'array',
            'data.pricingTests.fv_unwither_optimization' => 'array',
            'data.pricingTests.fv_unwither_optimization.type' => 'string',
            'data.pricingTests.fv_unwither_optimization.scheme' => 'array',
            'data.pricingTests.fv_unwither_optimization.scheme.multiple' => 'integer',
            'data.pricingTests.fv_unwither_optimization.scheme.cap' => 'integer',
            'data.hudIcons' => 'array',
            'data.fcSlotMachineRewards' => 'array',
            'data.completedQuests' => 'array',
            'data.completedReplayableQuests' => 'array',
        ],
        'values' => [
            'data.pricingTests.fv_unwither_optimization.type' => 'cash',
            'data.pricingTests.fv_unwither_optimization.scheme.multiple' => 0,
            'data.pricingTests.fv_unwither_optimization.scheme.cap' => 0,
        ],
    ],
    'WatchToEarnRewardGrantService.getUserZid' => [
        'arguments' => [null, null, null],
        'required' => [
            'data.success' => 'boolean',
            'data.zid' => 'string',
        ],
    ],
    'WatchToEarnRewardGrantService.generateDailyTokens' => [
        'arguments' => [null, null, null],
        'required' => [
            'data.success' => 'boolean',
            'data.Tokens' => 'array',
        ],
    ],
    'LeaderboardService.getBatchFriendLists' => [
        'arguments' => [
            null,
            (object) ['params' => [['dailyCoins', 'weeklyXp']]],
            null,
        ],
        'required' => [
            'dailyCoins' => 'array',
            'weeklyXp' => 'array',
        ],
        'values' => [
            'dailyCoins' => [],
            'weeklyXp' => [],
        ],
    ],
    'PurchaseUnwitherService.purchaseUnwitherItem' => [
        // Its state transition is covered by a database-backed world test;
        // this fixture still ensures the AMF dispatcher can load the method.
        'registered_only' => true,
    ],
];
