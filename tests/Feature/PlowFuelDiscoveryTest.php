<?php

use App\Models\PlayerMeta;
use App\Models\UserMeta;
use App\Models\UserWorld;
use App\Models\WorldObject;
use App\Support\PlowFuelDiscovery;

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
    UserMeta::invalidateCache(fuelDiscoveryWinningUid());
});

function fuelDiscoveryWinningUid(): string
{
    for ($candidate = 910000; $candidate < 920000; $candidate++) {
        // The client constructs TPlow after spending 15 coins: 100 -> 85.
        if (hexdec(substr(md5($candidate.'85'.'4'.'8'), -7)) % 1000 < 20) {
            return (string) $candidate;
        }
    }

    throw new RuntimeException('No winning test UID found.');
}

function fuelDiscoveryPlowFixture(string $uid, int $xp = 1900, int $gold = 100): object
{
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
        'firstName' => 'Fuel',
        'lastName' => 'Tester',
        'gold' => $gold,
        'xp' => $xp,
        'cash' => 0,
        'energy' => 0,
        'energyMax' => 100,
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

    return (object) ['params' => [
        ACTION_PLOW,
        (object) [
            'id' => 1,
            'className' => 'Plot',
            'state' => PLOT_STATE_PLOWED,
            'position' => (object) ['x' => 4, 'y' => 8, 'z' => 0],
        ],
        [],
    ]];
}

it('grants two tanks for a winning paid plow and never for its replay', function (): void {
    $uid = fuelDiscoveryWinningUid();
    $request = fuelDiscoveryPlowFixture($uid);

    $first = WorldService::performAction(new Player($uid), $request, new MarketTransactions($uid));

    expect($first['data'])->toMatchArray([
        'fuelDiscovery' => true,
        'fuelAdded' => 200,
    ])
        ->and(UserMeta::query()->where('uid', $uid)->value('energy'))->toBe(200)
        ->and(UserMeta::query()->where('uid', $uid)->value('gold'))->toBe(85)
        ->and((int) PlayerMeta::getValue($uid, 'fuel_discovery_last_granted_at'))->toBeGreaterThan(0);

    invalidateWorldCache($uid, 'farm');
    $replay = WorldService::performAction(new Player($uid), $request, new MarketTransactions($uid));

    expect($replay['data'])->toMatchArray(['stale' => true])
        ->and($replay['data'])->not->toHaveKey('fuelAdded')
        ->and(UserMeta::query()->where('uid', $uid)->value('energy'))->toBe(200);
});

it('enforces the six-hour discovery cooldown on the server', function (): void {
    $uid = fuelDiscoveryWinningUid();
    $request = fuelDiscoveryPlowFixture($uid);
    WorldService::performAction(new Player($uid), $request, new MarketTransactions($uid));

    expect(PlowFuelDiscovery::grantForPlow($uid, 4, 8))->toBe(0)
        ->and(UserMeta::query()->where('uid', $uid)->value('energy'))->toBe(200);

    $this->travel(6)->hours();

    expect(PlowFuelDiscovery::grantForPlow($uid, 4, 8))->toBe(200)
        ->and(UserMeta::query()->where('uid', $uid)->value('energy'))->toBe(400);
});

it('does not grant fuel when a winning plow is unaffordable', function (): void {
    $uid = fuelDiscoveryWinningUid();
    $request = fuelDiscoveryPlowFixture($uid, 1900, 0);

    $result = WorldService::performAction(new Player($uid), $request, new MarketTransactions($uid));

    expect($result['data']['success'])->toBeFalse()
        ->and($result['data'])->not->toHaveKey('fuelAdded')
        ->and(UserMeta::query()->where('uid', $uid)->value('energy'))->toBe(0);
});

it('keeps fuel discovery locked below level twelve', function (): void {
    $uid = fuelDiscoveryWinningUid();
    $request = fuelDiscoveryPlowFixture($uid, 0);

    $result = WorldService::performAction(new Player($uid), $request, new MarketTransactions($uid));

    expect($result['data'])->not->toHaveKey('fuelAdded')
        ->and(UserMeta::query()->where('uid', $uid)->value('energy'))->toBe(0);
});
