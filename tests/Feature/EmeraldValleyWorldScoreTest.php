<?php

use App\Models\Item;
use App\Models\PlayerMeta;
use App\Models\UserMeta;
use App\Models\UserWorld;
use App\Models\WorldObject;
use App\Support\WorldScoreConfig;

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/constants.php';
    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';
    require_once AMFPHP_ROOTPATH.'Helpers/market_transactions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/quest_progress.php';
    require_once AMFPHP_ROOTPATH.'Helpers/crafting_helper.php';
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';
    require_once AMFPHP_ROOTPATH.'Functions/WorldService.php';
    require_once AMFPHP_ROOTPATH.'Functions/EquipmentWorldService.php';
    require_once AMFPHP_ROOTPATH.'Functions/UserService.php';

    PlayerMeta::clearCache();
});

function emeraldValleyScoreTestPlayer(string $uid): object
{
    return new class($uid) {
        public function __construct(private readonly string $uid) {}

        public function getUid(): string
        {
            return $this->uid;
        }
    };
}

function makeEmeraldValleyScoreTestWorld(string $uid): UserWorld
{
    $world = UserWorld::query()->create([
        'uid' => $uid,
        'type' => 'oz',
        'sizeX' => 12,
        'sizeY' => 12,
        'objects' => '[]',
        'messageManager' => serialize(['messages' => [], 'allowSendEmails' => true]),
    ]);
    UserMeta::query()->create([
        'uid' => $uid,
        'firstName' => 'Emerald',
        'lastName' => 'Tester',
        'gold' => 1_000,
        'xp' => 0,
        'cash' => 0,
        'energy' => 10,
        'energyMax' => 10,
    ]);
    PlayerMeta::setValue($uid, 'currentWorldType', 'oz');
    invalidateWorldCache($uid, 'oz');

    return $world;
}

it('maps Emerald Valley to its rainbowPoints HUD score', function (): void {
    $uid = '940101';
    makeEmeraldValleyScoreTestWorld($uid);
    PlayerMeta::setValue($uid, 'world_score_oz', '17');
    PlayerMeta::setValue($uid, 'world_score_level_oz', '2');

    expect(getWorldScoreUnitForWorldType('oz'))->toBe('rainbowPoints')
        ->and(getWorldTypeForScoreUnit('rainbowPoints'))->toBe('oz')
        ->and(getWorldScoresForClient($uid))->toMatchArray([
            'rainbowPoints' => [
                'score' => 17,
                'level' => WorldScoreConfig::levelForScore('rainbowPoints', 17),
            ],
        ]);
});

it('derives Emerald Valley level from score and repairs legacy rainbow metadata', function (): void {
    $uid = '940105';
    makeEmeraldValleyScoreTestWorld($uid);
    PlayerMeta::setValue($uid, 'world_score_rainbow', '782');
    PlayerMeta::setValue($uid, 'world_score_level_rainbow', '17');

    expect(WorldScoreConfig::levelForScore('rainbowPoints', 782))->toBe(9)
        ->and(getWorldScoresForClient($uid)['rainbowPoints'])->toBe([
            'score' => 782,
            'level' => 9,
        ]);

    reconcileWorldScoreLevel($uid, 'oz');

    expect(PlayerMeta::getValue($uid, 'world_score_oz'))->toBe('782')
        ->and(PlayerMeta::getValue($uid, 'world_score_level_oz'))->toBe('9')
        ->and(PlayerMeta::getValue($uid, 'world_score_level_rainbow'))->toBe('9');
});

it('awards Emerald Valley score once for a valid manual plow', function (): void {
    $uid = '940102';
    $world = makeEmeraldValleyScoreTestWorld($uid);
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
    invalidateWorldCache($uid, 'oz');

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

    WorldService::performAction(new Player($uid), $request, new MarketTransactions($uid));

    expect(PlayerMeta::getValue($uid, 'world_score_oz'))->toBe('1')
        ->and(UserMeta::query()->where('uid', $uid)->value('xp'))->toBe(1);
});

it('awards Emerald Valley score for a valid equipment planting action', function (): void {
    $uid = '940103';
    $world = makeEmeraldValleyScoreTestWorld($uid);
    Item::query()->create([
        'name' => 'emerald_score_seed',
        'code' => 'OZS01',
        'data' => serialize([
            'name' => 'emerald_score_seed',
            'code' => 'OZS01',
            'type' => 'seed',
            'cost' => '20',
            'plantXp' => '3',
            'growTime' => '1',
        ]),
    ]);
    WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 1,
        'class_name' => 'Plot',
        'position_x' => 4,
        'position_y' => 8,
        'position_z' => 0,
        'state' => PLOT_STATE_PLOWED,
        'plant_time' => 0,
        'deleted' => false,
    ]);
    invalidateWorldCache($uid, 'oz');

    $request = (object) ['params' => [
        ACTION_PLANT,
        (object) [],
        [(object) [
            'id' => 1,
            'position' => (object) ['x' => 4, 'y' => 8, 'z' => 0],
        ]],
        'emerald_score_seed',
    ]];

    EquipmentWorldService::onUseEquipment(emeraldValleyScoreTestPlayer($uid), $request, null);

    expect(PlayerMeta::getValue($uid, 'world_score_oz'))->toBe('3')
        ->and(UserMeta::query()->where('uid', $uid)->value('xp'))->toBe(3);
});

it('does not trust a client-reported Emerald Valley score', function (): void {
    $uid = '940104';
    makeEmeraldValleyScoreTestWorld($uid);
    PlayerMeta::setValue($uid, 'world_score_oz', '4');

    UserService::updateWorldScoreLevelUp(
        emeraldValleyScoreTestPlayer($uid),
        (object) ['params' => ['rainbowPoints', 1, 50_000]],
    );

    expect(PlayerMeta::getValue($uid, 'world_score_oz'))->toBe('4');
});
