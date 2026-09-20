<?php

use App\Models\Item;
use App\Models\PlayerMeta;
use App\Models\UserMeta;
use App\Models\WorldCurrency;
use App\Models\WorldCurrencyAudit;
use App\Support\WorldCurrencyService;

function makeWorldCurrencyPlayer(string $uid = '960001'): void
{
    UserMeta::query()->create([
        'uid' => $uid,
        'firstName' => 'Currency',
        'lastName' => 'Tester',
        'gold' => 1000,
        'xp' => 0,
        'cash' => 0,
        'energy' => 10,
        'energyMax' => 10,
    ]);
}

it('keeps world currency balances strict and auditable', function (): void {
    $uid = '960001';
    makeWorldCurrencyPlayer($uid);

    expect(WorldCurrencyService::grant($uid, 'jade', 100, 'test.grant'))->toBeTrue()
        ->and(WorldCurrencyService::spend($uid, 'jade', 40, 'test.spend'))->toBeTrue()
        ->and(WorldCurrencyService::spend($uid, 'jade', 1000, 'test.shortfall'))->toBeFalse();

    $balance = WorldCurrency::query()
        ->where('uid', $uid)
        ->where('currency_unit', 'jade')
        ->firstOrFail();

    expect((int) $balance->total)->toBe(60)
        ->and((int) $balance->earned)->toBe(100)
        ->and(WorldCurrencyAudit::query()->where('uid', $uid)->count())->toBe(2);
});

it('returns both expansion balances in the Flash payload shapes', function (): void {
    $uid = '960002';
    makeWorldCurrencyPlayer($uid);
    WorldCurrencyService::grant($uid, 'coconuts', 6000, 'test.unlock');

    expect(WorldCurrencyService::balancesForClient($uid))->toMatchArray([
        'jade' => ['total' => 0, 'earned' => 0, 'purchased' => 0],
        'coconuts' => ['total' => 6000, 'earned' => 6000, 'purchased' => 0],
    ])->and(WorldCurrencyService::totalsForPostInit($uid))->toMatchArray([
        'jade' => 0,
        'coconuts' => 6000,
    ]);
});

it('charges legacy coin-priced seeds to coins while in Hawaiian Paradise', function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/constants.php';
    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';
    require_once AMFPHP_ROOTPATH.'Helpers/market_transactions.php';

    $uid = '960003';
    makeWorldCurrencyPlayer($uid);
    PlayerMeta::setValue($uid, 'currentWorldType', 'hawaii');
    WorldCurrencyService::grant($uid, 'coconuts', 4, 'test.unlock');

    // Like White Hibiscus in the imported catalog, this legacy entry has a
    // coin cost and no explicit market field.
    Item::query()->create([
        'name' => 'test_hawaiian_coin_seed',
        'code' => 'THCS',
        'data' => serialize([
            'name' => 'test_hawaiian_coin_seed',
            'code' => 'THCS',
            'type' => 'seed',
            'cost' => '20',
        ]),
    ]);

    $market = new MarketTransactions($uid);
    $seed = (object) ['itemName' => 'test_hawaiian_coin_seed'];

    expect($market->canAfford(ACTION_PLANT, $seed))->toBeTrue()
        ->and(MarketTransactions::calculateBuyDeltas(
            'test_hawaiian_coin_seed',
            1,
            null,
            'hawaii',
        ))->toMatchArray([
            'goldDelta' => -20,
            'worldCurrencyDeltas' => [],
        ])
        ->and($market->newTransaction(ACTION_PLANT, $seed))->toBeTrue()
        ->and(UserMeta::query()->where('uid', $uid)->value('gold'))->toEqual(980)
        ->and(WorldCurrency::query()
            ->where('uid', $uid)
            ->where('currency_unit', 'coconuts')
            ->value('total'))->toEqual(4);
});

it('still charges an item explicitly priced in an expansion currency to that currency', function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/constants.php';
    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';
    require_once AMFPHP_ROOTPATH.'Helpers/market_transactions.php';

    $uid = '960004';
    makeWorldCurrencyPlayer($uid);
    PlayerMeta::setValue($uid, 'currentWorldType', 'hawaii');
    WorldCurrencyService::grant($uid, 'coconuts', 4, 'test.unlock');
    Item::query()->create([
        'name' => 'test_hawaiian_coconut_seed',
        'code' => 'THCC',
        'data' => serialize([
            'name' => 'test_hawaiian_coconut_seed',
            'code' => 'THCC',
            'type' => 'seed',
            'market' => 'coconuts',
            'cost' => '20',
        ]),
    ]);

    expect((new MarketTransactions($uid))->canAfford(
        ACTION_PLANT,
        (object) ['itemName' => 'test_hawaiian_coconut_seed'],
    ))->toBeFalse()
        ->and(MarketTransactions::calculateBuyDeltas(
            'test_hawaiian_coconut_seed',
            1,
            null,
            'hawaii',
        ))->toMatchArray([
            'goldDelta' => 0,
            'worldCurrencyDeltas' => ['coconuts' => -20],
        ]);
});
