<?php

use App\Models\PlayerMeta;
use App\Models\UserMeta;
use App\Models\UserWorld;
use App\Models\WorldObject;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/constants.php';
    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';
    require_once AMFPHP_ROOTPATH.'Helpers/market_transactions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';
    require_once AMFPHP_ROOTPATH.'Functions/WorldService.php';

    PlayerMeta::clearCache();
});

it('acknowledges a replayed single plow without charging coins twice', function (): void {
    $uid = '900004';
    $world = UserWorld::query()->create([
        'uid' => $uid,
        'type' => 'farm',
        'sizeX' => 12,
        'sizeY' => 12,
        'objects' => '[]',
        'messageManager' => serialize(['messages' => [], 'allowSendEmails' => true]),
    ]);
    UserMeta::query()->create([
        'uid' => $uid,
        'firstName' => 'Plow',
        'lastName' => 'Tester',
        'gold' => 100,
        'xp' => 0,
        'cash' => 0,
        'energy' => 10,
        'energyMax' => 10,
    ]);
    WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 1,
        'class_name' => 'Plot',
        'position_x' => 4,
        'position_y' => 8,
        'position_z' => 0,
        'state' => PLOT_STATE_FALLOW,
        'plant_time' => 0,
        'deleted' => false,
    ]);
    PlayerMeta::setValue($uid, 'currentWorldType', 'farm');
    invalidateWorldCache($uid, 'farm');

    $plow = static fn (): object => (object) [
        'id' => 1,
        'className' => 'Plot',
        'state' => PLOT_STATE_PLOWED,
        'position' => (object) ['x' => 4, 'y' => 8, 'z' => 0],
    ];
    $request = static fn (): object => (object) ['params' => [ACTION_PLOW, $plow(), []]];

    $first = WorldService::performAction(new Player($uid), $request(), new MarketTransactions($uid));

    expect($first['data']['id'])->toBe(0)
        ->and(UserMeta::query()->where('uid', $uid)->value('gold'))->toBe(85)
        ->and(UserMeta::query()->where('uid', $uid)->value('xp'))->toBe(1)
        ->and(WorldObject::query()->where('world_id', $world->id)->value('state'))->toBe(PLOT_STATE_PLOWED);

    // A separate Player instance models Flash replaying the request after a reload.
    invalidateWorldCache($uid, 'farm');
    $retry = WorldService::performAction(new Player($uid), $request(), new MarketTransactions($uid));

    expect($retry['data'])->toMatchArray(['id' => 0, 'stale' => true])
        ->and(UserMeta::query()->where('uid', $uid)->value('gold'))->toBe(85)
        ->and(UserMeta::query()->where('uid', $uid)->value('xp'))->toBe(1);
});

it('ignores a stale single plow for a planted plot without charging coins', function (): void {
    $uid = '900005';
    $world = UserWorld::query()->create([
        'uid' => $uid,
        'type' => 'farm',
        'sizeX' => 12,
        'sizeY' => 12,
        'objects' => '[]',
        'messageManager' => serialize(['messages' => [], 'allowSendEmails' => true]),
    ]);
    UserMeta::query()->create([
        'uid' => $uid,
        'firstName' => 'Stale',
        'lastName' => 'Plow',
        'gold' => 100,
        'xp' => 0,
        'cash' => 0,
        'energy' => 10,
        'energyMax' => 10,
    ]);
    WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 1,
        'class_name' => 'Plot',
        'position_x' => 4,
        'position_y' => 8,
        'position_z' => 0,
        'state' => PLOT_STATE_PLANTED,
        'item_name' => 'whiteanthuriums',
        // This is deliberately still inside the crop's growing window. A
        // mature/withered planted row is covered by the test below.
        'plant_time' => getCurrentTimeMs(),
        'deleted' => false,
    ]);
    PlayerMeta::setValue($uid, 'currentWorldType', 'farm');
    invalidateWorldCache($uid, 'farm');

    $request = (object) ['params' => [
        ACTION_PLOW,
        (object) [
            'id' => 1,
            'className' => 'Plot',
            'state' => PLOT_STATE_PLOWED,
            'position' => (object) ['x' => 4, 'y' => 8, 'z' => 0],
        ],
        [],
    ]];

    $result = WorldService::performAction(new Player($uid), $request, new MarketTransactions($uid));

    expect($result['data'])->toMatchArray(['id' => 0, 'stale' => true])
        ->and(UserMeta::query()->where('uid', $uid)->value('gold'))->toBe(100)
        ->and(UserMeta::query()->where('uid', $uid)->value('xp'))->toBe(0)
        ->and(WorldObject::query()->where('world_id', $world->id)->value('state'))->toBe(PLOT_STATE_PLANTED);
});

it('persists a plow for a visually withered planted plot', function (): void {
    $uid = '900007';
    $world = UserWorld::query()->create([
        'uid' => $uid,
        'type' => 'farm',
        'sizeX' => 12,
        'sizeY' => 12,
        'objects' => '[]',
        'messageManager' => serialize(['messages' => [], 'allowSendEmails' => true]),
    ]);
    UserMeta::query()->create([
        'uid' => $uid,
        'firstName' => 'Withered',
        'lastName' => 'Plow',
        'gold' => 100,
        'xp' => 0,
        'cash' => 0,
        'energy' => 10,
        'energyMax' => 10,
    ]);
    DB::table('items')->insert([
        'name' => 'single_withered_plow_test',
        'code' => 'SWP1',
        'data' => serialize([
            'name' => 'single_withered_plow_test',
            'code' => 'SWP1',
            'growTime' => 0.01,
            'expires' => true,
        ]),
    ]);
    WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 1,
        'class_name' => 'Plot',
        'position_x' => 4,
        'position_y' => 8,
        'position_z' => 0,
        'state' => PLOT_STATE_PLANTED,
        'item_name' => 'single_withered_plow_test',
        'plant_time' => getCurrentTimeMs() - (calculateGrowTimeMs(0.01) * 3),
        'deleted' => false,
    ]);
    PlayerMeta::setValue($uid, 'currentWorldType', 'farm');
    invalidateWorldCache($uid, 'farm');

    $request = static fn (): object => (object) ['params' => [
        ACTION_PLOW,
        (object) [
            'id' => 1,
            'className' => 'Plot',
            'state' => PLOT_STATE_PLOWED,
            'position' => (object) ['x' => 4, 'y' => 8, 'z' => 0],
        ],
        [],
    ]];

    $first = WorldService::performAction(new Player($uid), $request(), new MarketTransactions($uid));

    expect($first['data']['id'])->toBe(0)
        ->and(UserMeta::query()->where('uid', $uid)->value('gold'))->toBe(85)
        ->and(UserMeta::query()->where('uid', $uid)->value('xp'))->toBe(1)
        ->and(WorldObject::query()->where('world_id', $world->id)->value('state'))->toBe(PLOT_STATE_PLOWED)
        ->and(WorldObject::query()->where('world_id', $world->id)->value('item_name'))->toBeNull();

    invalidateWorldCache($uid, 'farm');
    $retry = WorldService::performAction(new Player($uid), $request(), new MarketTransactions($uid));

    expect($retry['data'])->toMatchArray(['id' => 0, 'stale' => true])
        ->and(UserMeta::query()->where('uid', $uid)->value('gold'))->toBe(85)
        ->and(UserMeta::query()->where('uid', $uid)->value('xp'))->toBe(1);
});
