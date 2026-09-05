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
    require_once AMFPHP_ROOTPATH.'Helpers/quest_progress.php';
    require_once AMFPHP_ROOTPATH.'Helpers/crafting_helper.php';
    require_once AMFPHP_ROOTPATH.'Functions/EquipmentWorldService.php';
});

it('persists one plot when an equipment plow bundle repeats a coordinate', function (): void {
    $uid = '900002';
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
        'firstName' => 'Equipment',
        'lastName' => 'Tester',
        'gold' => 1000,
        'xp' => 0,
        'cash' => 0,
        'energy' => 10,
        'energyMax' => 10,
    ]);
    PlayerMeta::setValue($uid, 'currentWorldType', 'farm');
    invalidateWorldCache($uid, 'farm');

    $player = new class ($uid) {
        public function __construct(private readonly string $uid) {}

        public function getUid(): string
        {
            return $this->uid;
        }
    };
    $plot = static fn (int $id): object => (object) [
        'id' => $id,
        'position' => (object) ['x' => 4, 'y' => 8, 'z' => 0],
    ];
    $request = (object) ['params' => [
        ACTION_PLOW,
        (object) [],
        [$plot(63001), $plot(63002)],
        PLOT_STATE_PLOWED,
    ]];

    $result = EquipmentWorldService::onUseEquipment($player, $request, null);

    expect($result['data'])->toHaveCount(2)
        ->and($result['data'][0]['id'])->toBe(1)
        ->and($result['data'][1])->toBeNull();
    $this->assertDatabaseCount('world_objects', 1);
    $this->assertDatabaseHas('world_objects', [
        'world_id' => $world->id,
        'object_id' => 1,
        'position_x' => 4,
        'position_y' => 8,
        'state' => PLOT_STATE_PLOWED,
    ]);
    expect(UserMeta::query()->where('uid', $uid)->value('energy'))->toBe(9)
        ->and(UserMeta::query()->where('uid', $uid)->value('gold'))->toBe(985)
        ->and(UserMeta::query()->where('uid', $uid)->value('xp'))->toBe(1);
});

it('grants a fuel reward when equipment harvests a gas pump', function (): void {
    $uid = '900003';
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
        'firstName' => 'Equipment',
        'lastName' => 'Tester',
        'gold' => 0,
        'xp' => 0,
        'cash' => 0,
        'energy' => 10,
        'energyMax' => 10,
    ]);
    DB::table('items')->insert([
        [
            'name' => 'equipment_biofuelpump_test',
            'code' => 'EPMP1',
            'data' => serialize([
                'name' => 'equipment_biofuelpump_test',
                'code' => 'EPMP1',
                'className' => 'FeatureBuilding',
                'features' => [
                    'feature' => [[
                        'name' => 'harvester',
                        'className' => 'HarvestFManager',
                        'harvestReward' => ['name' => 'equipment_fuel_test'],
                    ]],
                ],
            ]),
        ],
        [
            'name' => 'equipment_fuel_test',
            'code' => 'EFUEL1',
            'data' => serialize([
                'name' => 'equipment_fuel_test',
                'code' => 'EFUEL1',
                'type' => 'fuel',
                'count' => '1',
            ]),
        ],
    ]);
    WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 1,
        'class_name' => 'FeatureBuilding',
        'item_name' => 'equipment_biofuelpump_test',
        'position_x' => 4,
        'position_y' => 8,
        'position_z' => 0,
        'state' => HARVESTABLE_STATE_BARE,
        'plant_time' => getCurrentTimeMs() - 1,
        'deleted' => false,
    ]);
    PlayerMeta::setValue($uid, 'currentWorldType', 'farm');
    invalidateWorldCache($uid, 'farm');

    $request = (object) ['params' => [
        ACTION_HARVEST,
        (object) [],
        [(object) ['position' => (object) ['x' => 4, 'y' => 8, 'z' => 0]]],
        null,
    ]];

    $result = EquipmentWorldService::onUseEquipment(
        new class ($uid) {
            public function __construct(private readonly string $uid) {}

            public function getUid(): string
            {
                return $this->uid;
            }
        },
        $request,
        null,
    );

    expect($result['data'][0]['id'])->toBe(1)
        ->and($result['metadata']['HarvestRewards'][0])->toMatchArray([
            'name' => 'equipment_fuel_test',
            'code' => 'EFUEL1',
            'quantity' => 1,
        ])
        ->and($result['storageData'][GIFTBOX_STORAGE_KEY]['EFUEL1'][0])->toBe(1)
        ->and(UserMeta::query()->where('uid', $uid)->value('energy'))->toBe(9);
});
