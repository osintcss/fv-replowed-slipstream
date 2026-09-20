<?php

use App\Models\UserWorld;
use App\Models\Item;
use App\Models\PlayerMeta;
use App\Models\UserMeta;
use App\Models\WorldActionReceipt;
use App\Models\WorldObject;
use App\Support\WorldPersistence;

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
});

function persistenceTestWorld(): UserWorld
{
    return UserWorld::query()->create([
        'uid' => '900001',
        'type' => 'farm',
        'sizeX' => 12,
        'sizeY' => 12,
        'objects' => '[]',
        'messageManager' => serialize(['messages' => [], 'allowSendEmails' => true]),
    ]);
}

function persistenceTestObject(UserWorld $world, int $objectId, array $attributes = []): WorldObject
{
    return WorldObject::query()->create(array_merge([
        'world_id' => $world->id,
        'object_id' => $objectId,
        'class_name' => 'Plot',
        'item_name' => 'romatomatoes',
        'position_x' => $objectId,
        'position_y' => 1,
        'position_z' => 0,
        'state' => 'grown',
        'plant_time' => 123,
        'deleted' => false,
    ], $attributes));
}

function persistenceTestFlashObject(int $objectId, array $attributes = []): stdClass
{
    return (object) array_merge([
        'id' => $objectId,
        'className' => 'Plot',
        'itemName' => 'romatomatoes',
        'position' => (object) ['x' => $objectId, 'y' => 1, 'z' => 0],
        'state' => 'grown',
        'plantTime' => 123,
        'isJumbo' => false,
    ], $attributes);
}

it('enables turbo-ring mode only while a turbo ring is placed in the world', function (): void {
    $world = persistenceTestWorld();

    expect(hasTurboRing($world->uid, $world->type))->toBeFalse();

    $ring = persistenceTestObject($world, 99, [
        'class_name' => 'Decoration',
        'item_name' => 'turboring',
    ]);

    expect(hasTurboRing($world->uid, $world->type))->toBeTrue();

    $ring->update(['deleted' => true]);

    expect(hasTurboRing($world->uid, $world->type))->toBeFalse();
});

it('rejects building parts from garages and filters legacy malformed contents', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    App\Models\Item::query()->create([
        'name' => 'test_brick',
        'code' => 'GB1',
        'data' => serialize(['name' => 'test_brick', 'code' => 'GB1', 'className' => 'BuildingPart']),
    ]);
    App\Models\Item::query()->create([
        'name' => 'test_tractor',
        'code' => 'GT1',
        'data' => serialize(['name' => 'test_tractor', 'code' => 'GT1', 'className' => 'Tractor']),
    ]);
    App\Models\Item::clearCache();

    $world = persistenceTestWorld();
    $garage = persistenceTestObject($world, 601, [
        'class_name' => 'GarageBuilding',
        'item_name' => 'garage_finished',
        'contents' => [
            ['itemCode' => 'GB1', 'numItem' => 10],
            ['itemCode' => 'GT1', 'numItem' => 1],
        ],
    ]);
    $part = persistenceTestObject($world, 602, [
        'class_name' => 'BuildingPart',
        'item_name' => 'test_brick',
    ]);
    unset($GLOBALS['_world_cache']['900001:farm']);

    $flashGarage = $garage->toFlashObject();
    expect($flashGarage->contents)->toBe([
        ['itemCode' => 'GT1', 'numItem' => 1, 'numParts' => 0],
    ]);

    $result = (new Player($world->uid))->storeItem(
        (object) ['id' => 601],
        (object) [
            'resource' => 602,
            'storedItemCode' => 'GB1',
            'storedItemName' => 'test_brick',
            'storedClassName' => 'BuildingPart',
            'numToStore' => 1,
        ],
    );

    expect($result)->toBeFalse()
        ->and($part->fresh()->deleted)->toBeFalse()
        ->and($garage->fresh()->contents)->toBe([
            ['itemCode' => 'GB1', 'numItem' => 10],
            ['itemCode' => 'GT1', 'numItem' => 1],
        ]);
});

it('stores and reloads a direct market combine purchase exactly once', function (): void {
    require_once AMFPHP_ROOTPATH.'Functions/WorldService.php';
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    Item::query()->create([
        'name' => 'test_orange_combine',
        'code' => 'OC1',
        'data' => serialize([
            'name' => 'test_orange_combine',
            'code' => 'OC1',
            'className' => 'Combine',
            'type' => 'vehicle',
            'market' => 'cash',
            'cost' => 1000,
            'cash' => 5,
            'buyXp' => 10,
            'buyable' => 'true',
        ]),
    ]);
    Item::clearCache();

    $world = persistenceTestWorld();
    UserMeta::query()->create([
        'uid' => $world->uid,
        'firstName' => 'Test',
        'lastName' => 'Farmer',
        'gold' => 5000,
        'cash' => 10,
        'xp' => 0,
    ]);
    UserResources::invalidateCache($world->uid);
    $garage = persistenceTestObject($world, 603, [
        'class_name' => 'GarageBuilding',
        'item_name' => 'garage_finished',
        'contents' => [],
    ]);
    unset($GLOBALS['_world_cache']['900001:farm']);

    $player = new Player($world->uid);
    $request = static function (int $sequence): object {
        return (object) [
            'sequence' => $sequence,
            'sequenceID' => 'garage-market-test',
            'params' => [
                ACTION_STORE,
                (object) [
                    'id' => 603,
                    'className' => 'GarageBuilding',
                    'itemName' => 'garage_finished',
                ],
                [(object) [
                    'resource' => 0,
                    'cameFromLocation' => 0,
                    'storedItemCode' => 'OC1',
                    'storedItemName' => 'test_orange_combine',
                    'storedClassName' => 'Combine',
                    'currency' => 'cash',
                    'numToStore' => 1,
                ]],
            ],
        ];
    };
    $first = WorldService::performAction($player, $request(1), null);
    $retry = WorldService::performAction($player, $request(1), null);

    expect($first['data']['success'] ?? false)->toBeTrue()
        ->and($retry['data']['success'] ?? false)->toBeTrue()
        ->and(WorldActionReceipt::query()->where('uid', $world->uid)->count())->toBe(1)
        ->and(UserMeta::query()->where('uid', $world->uid)->value('gold'))->toBe(5000)
        ->and(UserMeta::query()->where('uid', $world->uid)->value('cash'))->toBe(5)
        ->and($garage->fresh()->contents)->toBe([
            ['itemCode' => 'OC1', 'numItem' => 1, 'numParts' => 0],
        ])
        ->and($garage->fresh()->toFlashObject()->contents)->toBe([
            ['itemCode' => 'OC1', 'numItem' => 1, 'numParts' => 0],
        ]);
});

it('persists a cash vehicle-part upgrade and makes a retry idempotent', function (): void {
    require_once AMFPHP_ROOTPATH.'Functions/EquipmentWorldService.php';
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    Item::query()->create([
        'name' => 'vehiclepart',
        'code' => 'VP1',
        'data' => serialize(['name' => 'vehiclepart', 'code' => 'VP1', 'cash' => 1]),
    ]);
    Item::query()->create([
        'name' => 'test_upgrade_combine',
        'code' => 'EQ1',
        'data' => serialize(['name' => 'test_upgrade_combine', 'code' => 'EQ1', 'className' => 'Combine']),
    ]);
    Item::clearCache();

    $world = persistenceTestWorld();
    UserMeta::query()->create([
        'uid' => $world->uid,
        'firstName' => 'Garage',
        'lastName' => 'Tester',
        'gold' => 5000,
        'cash' => 10,
        'xp' => 0,
    ]);
    UserResources::invalidateCache($world->uid);
    $garage = persistenceTestObject($world, 604, [
        'class_name' => 'GarageBuilding',
        'item_name' => 'garage_finished',
        'contents' => [
            ['itemCode' => 'EQ1', 'numItem' => 1, 'numParts' => 0],
        ],
    ]);
    unset($GLOBALS['_world_cache']['900001:farm']);

    $player = new Player($world->uid);
    $request = (object) [
        'sequence' => 1,
        'sequenceID' => 'garage-upgrade-cash-test',
        'params' => [604, 'EQ1:0', false],
    ];
    $first = EquipmentWorldService::onAddPartToEquipmentInGarage($player, $request, null);
    $retry = EquipmentWorldService::onAddPartToEquipmentInGarage($player, $request, null);

    expect($first['data']['success'] ?? false)->toBeTrue()
        ->and($retry['data']['success'] ?? false)->toBeTrue()
        ->and(WorldActionReceipt::query()->where('uid', $world->uid)->where('action', 'garage_part')->count())->toBe(1)
        ->and(UserMeta::query()->where('uid', $world->uid)->value('cash'))->toBe(9)
        ->and($garage->fresh()->contents)->toBe([
            ['itemCode' => 'EQ1', 'numItem' => 1, 'numParts' => 1],
        ])
        ->and($garage->fresh()->toFlashObject()->contents)->toBe([
            ['itemCode' => 'EQ1', 'numItem' => 1, 'numParts' => 1],
        ]);
});

it('consumes one deferred vehicle-part gift with the Garage upgrade', function (): void {
    require_once AMFPHP_ROOTPATH.'Functions/EquipmentWorldService.php';
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    Item::query()->create([
        'name' => 'vehiclepart',
        'code' => 'VP1',
        'data' => serialize(['name' => 'vehiclepart', 'code' => 'VP1', 'cash' => 1]),
    ]);
    Item::query()->create([
        'name' => 'test_upgrade_tractor',
        'code' => 'EQ2',
        'data' => serialize(['name' => 'test_upgrade_tractor', 'code' => 'EQ2', 'className' => 'Tractor']),
    ]);
    Item::clearCache();

    $world = persistenceTestWorld();
    UserMeta::query()->create([
        'uid' => $world->uid,
        'firstName' => 'Gift',
        'lastName' => 'Tester',
        'gold' => 5000,
        'cash' => 10,
        'xp' => 0,
    ]);
    PlayerMeta::setValue($world->uid, 'giftbox', serialize([
        'VP1' => [1, [], []],
    ]));
    UserResources::invalidateCache($world->uid);
    $garage = persistenceTestObject($world, 605, [
        'class_name' => 'GarageBuilding',
        'item_name' => 'garage_finished',
        'contents' => [
            ['itemCode' => 'EQ2', 'numItem' => 1, 'numParts' => 1],
        ],
    ]);
    unset($GLOBALS['_world_cache']['900001:farm']);

    $player = new Player($world->uid);
    $request = (object) [
        'sequence' => 2,
        'sequenceID' => 'garage-upgrade-gift-test',
        'params' => [605, 'EQ2:1', true],
    ];
    $useResult = \App\Support\ConsumableActionHandler::handle(
        $player,
        (object) [
            'params' => [
                ACTION_USE,
                (object) ['itemName' => 'vehiclepart', 'itemCode' => 'VP1'],
            ],
        ],
        (object) [
            'isGift' => true,
            'isFree' => false,
            'storageId' => GIFTBOX_ID,
            'itemCount' => 1,
        ],
    );
    $giftboxAfterUse = unserialize(
        PlayerMeta::getValue($world->uid, 'giftbox'),
        ['allowed_classes' => false],
    );
    $result = EquipmentWorldService::onAddPartToEquipmentInGarage($player, $request, null);

    expect($useResult['success'] ?? false)->toBeTrue()
        ->and($useResult['deferred'] ?? false)->toBeTrue()
        ->and($giftboxAfterUse)->toHaveKey('VP1')
        ->and($result['data']['success'] ?? false)->toBeTrue()
        ->and(UserMeta::query()->where('uid', $world->uid)->value('cash'))->toBe(10)
        ->and(unserialize(PlayerMeta::getValue($world->uid, 'giftbox'), ['allowed_classes' => false]))->toBe([])
        ->and($garage->fresh()->contents)->toBe([
            ['itemCode' => 'EQ2', 'numItem' => 1, 'numParts' => 2],
        ]);
});

it('consumes a gift-backed store item atomically and ignores a transport retry', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    Item::query()->create([
        'name' => 'cow_irishmoiled',
        'code' => '4,',
        'data' => serialize([
            'name' => 'cow_irishmoiled',
            'code' => '4,',
            'className' => 'Animal',
        ]),
    ]);
    Item::clearCache();

    $world = persistenceTestWorld();
    persistenceTestObject($world, 700, [
        'class_name' => 'FeatureBuilding',
        'item_name' => 'animal_breeding_dairy_finished',
        'state' => 'bare',
        'contents' => [],
    ]);
    PlayerMeta::setValue($world->uid, 'giftbox', serialize([
        '4,' => [2, [], []],
    ]));
    unset($GLOBALS['_world_cache']["{$world->uid}:farm"]);

    $player = new Player($world->uid);
    $building = (object) ['id' => 700];
    $storeParams = (object) [
        'resource' => 0,
        'storedItemCode' => '4,',
        'storedItemName' => 'cow_irishmoiled',
        'storedClassName' => 'Animal',
        'cameFromLocation' => -1,
        'numToStore' => 1,
    ];

    $first = $player->storeItem($building, $storeParams, true, 'gift-store-retry-1');
    $retry = $player->storeItem($building, $storeParams, true, 'gift-store-retry-1');
    $second = $player->storeItem($building, $storeParams, true, 'gift-store-retry-2');

    expect($first['success'] ?? false)->toBeTrue()
        ->and($retry['success'] ?? false)->toBeTrue()
        ->and($retry['replayed'] ?? false)->toBeTrue()
        ->and($second['success'] ?? false)->toBeTrue()
        ->and(WorldActionReceipt::query()->where('uid', $world->uid)->count())->toBe(2);

    $building = WorldObject::query()
        ->where('world_id', $world->id)
        ->where('object_id', 700)
        ->firstOrFail();
    expect($building->contents)->toBe([
        ['itemCode' => '4,', 'numItem' => 2],
    ]);

    PlayerMeta::clearCache($world->uid, 'giftbox');
    expect(unserialize(PlayerMeta::getValue($world->uid, 'giftbox'), ['allowed_classes' => false]))
        ->toBe([]);
});

it('preserves the empty storage action response envelope through the handler', function (): void {
    require_once AMFPHP_ROOTPATH.'Functions/WorldService.php';
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    $world = persistenceTestWorld();
    $request = (object) [
        'params' => [
            ACTION_STORE,
            (object) ['id' => 0],
            [],
        ],
    ];

    expect(WorldService::performAction(new Player($world->uid), $request, null))
        ->toBe([
            'id' => 0,
            'data' => ['id' => 0],
        ]);
});

it('uses the same atomic Giftbox contract for generic expansion parts', function (): void {
    require_once AMFPHP_ROOTPATH.'Functions/WorldService.php';
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    Item::query()->create([
        'name' => 'test_expansion_building',
        'code' => 'TEB',
        'data' => serialize([
            'name' => 'test_expansion_building',
            'code' => 'TEB',
            'className' => 'FeatureBuilding',
            'features' => (object) [
                'feature' => (object) [
                    'name' => 'expand',
                    'upgrade' => (object) [
                        'level' => 2,
                        'part' => (object) [
                            'name' => 'test_expansion_part',
                            'need' => 2,
                        ],
                    ],
                ],
            ],
        ]),
    ]);
    Item::query()->create([
        'name' => 'test_expansion_part',
        'code' => 'TEP',
        'data' => serialize([
            'name' => 'test_expansion_part',
            'code' => 'TEP',
            'className' => 'BuildingPart',
        ]),
    ]);
    Item::clearCache();

    $world = persistenceTestWorld();
    persistenceTestObject($world, 701, [
        'class_name' => 'FeatureBuilding',
        'item_name' => 'test_expansion_building',
        'state' => 'bare',
        'expansion_level' => 1,
        'expansion_parts' => [],
    ]);
    PlayerMeta::setValue($world->uid, 'currentWorldType', 'farm');
    PlayerMeta::setValue($world->uid, 'giftbox', serialize([
        'TEP' => [2, [], []],
    ]));
    unset($GLOBALS['_world_cache']["{$world->uid}:farm"]);

    $request = static function (int $sequence): object {
        return (object) [
            'sequence' => $sequence,
            'sequenceID' => 'generic-expansion-test',
            'params' => [
                ACTION_STORE,
                (object) [
                    'id' => 701,
                    'className' => 'FeatureBuilding',
                    'itemName' => 'test_expansion_building',
                ],
                [(object) [
                    'storedItemCode' => 'TEP',
                    'storedItemName' => 'test_expansion_part',
                    'numToStore' => 1,
                    'isGift' => true,
                ]],
            ],
        ];
    };

    $player = new Player($world->uid);
    $first = WorldService::performAction($player, $request(1), null);
    $retry = WorldService::performAction($player, $request(1), null);
    $second = WorldService::performAction($player, $request(2), null);

    expect($first['data']['success'] ?? false)->toBeTrue()
        ->and($retry['data']['replayed'] ?? false)->toBeTrue()
        ->and($second['data']['success'] ?? false)->toBeTrue()
        ->and(WorldActionReceipt::query()->where('uid', $world->uid)->count())->toBe(2);

    $building = WorldObject::query()
        ->where('world_id', $world->id)
        ->where('object_id', 701)
        ->firstOrFail();
    expect((int) $building->expansion_level)->toBe(2)
        ->and($building->expansion_parts)->toEqual((object) []);

    PlayerMeta::clearCache($world->uid, 'giftbox');
    expect(unserialize(PlayerMeta::getValue($world->uid, 'giftbox'), ['allowed_classes' => false]))
        ->toBe([]);
});

it('canonicalizes the Bloom Garden store placeholder on read and write', function (): void {
    $world = persistenceTestWorld();
    $garden = persistenceTestObject($world, 603, [
        'class_name' => 'FeatureBuilding',
        'item_name' => 'flower_garden',
        'state' => 'ripe',
        'contents' => [['itemCode' => '811', 'numItem' => 6]],
    ]);

    $flash = $garden->toFlashObject();
    expect($flash->itemName)->toBe('flower_garden_finished')
        ->and($flash->state)->toBe('ripe')
        ->and($flash->contents)->toBe([['itemCode' => '811', 'numItem' => 6]]);

    $persisted = WorldObject::fromFlashObject(
        persistenceTestFlashObject(603, [
            'className' => 'FeatureBuilding',
            'itemName' => 'flower_garden',
            'state' => 'ripe',
        ]),
        $world->id,
    );

    expect($persisted['item_name'])->toBe('flower_garden_finished')
        ->and($persisted['class_name'])->toBe('FeatureBuilding')
        ->and($persisted['state'])->toBe('ripe');
});

it('does not let a stale conditional update overwrite a harvested plot', function (): void {
    $world = persistenceTestWorld();
    $plot = persistenceTestObject($world, 101);

    $plot->update([
        'state' => 'fallow',
        'item_name' => null,
        'plant_time' => 0,
    ]);

    $result = WorldPersistence::updateConditionally($world->uid, $world->type, [[
        'object' => persistenceTestFlashObject(101, ['plantTime' => 456]),
        'expected' => [
            'state' => 'grown',
            'item_name' => 'romatomatoes',
            'plant_time' => 123,
        ],
    ]]);

    expect($result['success'])->toBeTrue()
        ->and($result['updated'])->toBe(0)
        ->and($result['skipped'])->toBe(1);

    $this->assertDatabaseHas('world_objects', [
        'world_id' => $world->id,
        'object_id' => 101,
        'state' => 'fallow',
        'item_name' => null,
        'plant_time' => 0,
    ]);
});

it('updates only the requested world object', function (): void {
    $world = persistenceTestWorld();
    persistenceTestObject($world, 201);
    persistenceTestObject($world, 202, ['item_name' => 'pumpkin', 'position_x' => 202]);

    $result = WorldPersistence::mutateObject(
        $world->uid,
        $world->type,
        201,
        static function (WorldObject $object): bool {
            $object->state = 'fallow';
            $object->item_name = null;

            return true;
        },
    );

    expect($result)->toBeTrue();
    $this->assertDatabaseHas('world_objects', ['world_id' => $world->id, 'object_id' => 201, 'state' => 'fallow']);
    $this->assertDatabaseHas('world_objects', ['world_id' => $world->id, 'object_id' => 202, 'state' => 'grown', 'item_name' => 'pumpkin']);
});

it('accepts an unchanged world-object update without attempting a duplicate insert', function (): void {
    $world = persistenceTestWorld();
    persistenceTestObject($world, 203);

    expect(WorldPersistence::updateObject(
        $world->uid,
        $world->type,
        persistenceTestFlashObject(203),
    ))->toBeTrue();

    expect(WorldObject::query()
        ->where('world_id', $world->id)
        ->where('object_id', 203)
        ->count())->toBe(1);
});

it('serializes a completed pigpen with its authoritative storage state', function (): void {
    $world = persistenceTestWorld();
    $pigpen = persistenceTestObject($world, 250, [
        'class_name' => 'PigpenBuilding',
        'item_name' => 'pigpen',
        'state' => 'built',
        'expansion_level' => 2,
        'expansion_parts' => json_encode(['pigpen_part' => 3]),
        'contents' => json_encode([['itemCode' => 'pig', 'numItem' => 2]]),
    ]);

    $flash = $pigpen->toFlashObject();

    expect($flash->isFullyBuilt)->toBeTrue()
        ->and($flash->expansionLevel)->toBe(2)
        ->and($flash->expansionParts->pigpen_part)->toBe(3)
        ->and($flash->contents)->toBe([['itemCode' => 'pig', 'numItem' => 2]]);
});

it('normalizes legacy finished orchards without losing their contents', function (): void {
    $world = persistenceTestWorld();
    $orchard = persistenceTestObject($world, 2501, [
        'class_name' => 'OrchardFeatureBuilding',
        'item_name' => 'orchard_featurebuilding_finished',
        'state' => 'grown',
        'contents' => [
            ['itemCode' => 'AP', 'numItem' => 2],
            ['itemCode' => 'OR', 'numItem' => 1],
        ],
    ]);

    $flash = $orchard->toFlashObject();

    expect($flash->state)->toBe('bare')
        ->and($flash->contents)->toBe([
            ['itemCode' => 'AP', 'numItem' => 2],
            ['itemCode' => 'OR', 'numItem' => 1],
        ]);

    $persisted = WorldObject::fromFlashObject($flash, $world->id);
    expect($persisted['state'])->toBe('bare')
        ->and(json_decode($persisted['contents'], true))->toBe([
            ['itemCode' => 'AP', 'numItem' => 2],
            ['itemCode' => 'OR', 'numItem' => 1],
        ]);
});

it('does not normalize construction or unrelated orchard states', function (): void {
    $world = persistenceTestWorld();
    $construction = persistenceTestObject($world, 2502, [
        'class_name' => 'OrchardConstructionBuilding',
        'item_name' => 'orchard_featurebuilding',
        'state' => 'construction',
    ]);
    $ripe = persistenceTestObject($world, 2503, [
        'class_name' => 'OrchardFeatureBuilding',
        'item_name' => 'orchard_featurebuilding_finished',
        'state' => 'ripe',
    ]);

    expect($construction->toFlashObject()->state)->toBe('construction')
        ->and($ripe->toFlashObject()->state)->toBe('ripe');
});

it('preserves mutable animal pattern hashes when rebuilding feature slots', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    $world = persistenceTestWorld();
    $dnaOne = [
        'G' => 'F',
        'B' => ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
        'P' => ['T' => ['c'], 'H' => ['20', '20'], 'S' => ['9', '9'], 'V' => ['9', '9']],
    ];
    $dnaTwo = [
        'G' => 'M',
        'B' => ['H' => ['30', '30'], 'S' => ['7', '7'], 'V' => ['7', '7']],
        'P' => ['T' => ['d'], 'H' => ['40', '40'], 'S' => ['6', '6'], 'V' => ['6', '6']],
    ];
    $building = persistenceTestObject($world, 251, [
        'class_name' => 'FeatureBuilding',
        'item_name' => 'xuk_sheep_pen_finished',
        'state' => 'bare',
        'contents' => [
            ['itemCode' => 'sheeppen_ewe', 'numItem' => 2],
        ],
        'components' => (object) [
            'featuredItems' => (object) [
                '0' => (object) ['itemCode' => 'sheeppen_ewe', 'metaHash' => 'sheeppen_ewe:keepme'],
            ],
            'storageMetadata' => (object) [
                'sheeppen_ewe:' => [json_encode($dnaOne), json_encode($dnaTwo)],
            ],
        ],
    ]);

    $sync = new ReflectionMethod('Player', 'synchronizeFeatureStorageSlots');
    $sync->setAccessible(true);
    $sync->invoke(new Player($world->uid), $building, $building->contents);

    expect($building->components->featuredItems->{'0'}->metaHash)->toBe('sheeppen_ewe:keepme')
        ->and($building->components->featuredItems->{'1'}->metaHash)->toMatch('/^sheeppen_ewe:[a-f0-9]{8}$/')
        ->and($building->components->featuredItems->{'1'}->metaHash)->not->toBe('sheeppen_ewe:');

    // A pen saved by the old synchronizer may still have a generic hash. The
    // reload serializer repairs it from the same DNA metadata.
    $components = $building->getAttribute('components');
    $components->featuredItems->{'0'}->metaHash = 'sheeppen_ewe:';
    $building->setAttribute('components', $components);
    expect($building->toFlashObject()->featuredItems->{'0'}->metaHash)
        ->toMatch('/^sheeppen_ewe:[a-f0-9]{8}$/');

    // A canonical-looking hash from a withdrawn animal is stale, even though
    // older non-canonical hashes (such as `keepme` above) remain untouched.
    $components->featuredItems->{'0'}->metaHash = 'sheeppen_ewe:deadbeef';
    $building->setAttribute('components', $components);
    expect($building->toFlashObject()->featuredItems->{'0'}->metaHash)
        ->toMatch('/^sheeppen_ewe:[a-f0-9]{8}$/')
        ->not->toBe('sheeppen_ewe:deadbeef');
});

it('seeds a newly placed feature habitat from its catalog default only', function (): void {
    require_once AMFPHP_ROOTPATH.'Functions/WorldService.php';

    App\Models\Item::query()->create([
        'name' => 'test_default_habitat',
        'code' => 'TDH',
        'data' => serialize([
            'name' => 'test_default_habitat',
            'code' => 'TDH',
            'className' => 'FeatureBuilding',
            'defaultItem' => ['name' => 'test_default_animal', 'amount' => '1', 'render' => 'true'],
        ]),
    ]);
    App\Models\Item::query()->create([
        'name' => 'test_default_animal',
        'code' => 'TDA',
        'data' => serialize(['name' => 'test_default_animal', 'code' => 'TDA', 'type' => 'animal']),
    ]);
    App\Models\Item::clearCache();

    $world = persistenceTestWorld();
    $habitat = persistenceTestObject($world, 2500, [
        'class_name' => 'FeatureBuilding',
        'item_name' => 'test_default_habitat',
        'state' => 'bare',
        'contents' => [],
        'components' => (object) [],
    ]);
    $seed = new ReflectionMethod('WorldService', 'initialFeatureBuildingDefaultContents');
    $seed->setAccessible(true);

    $featured = (object) ['2' => (object) ['itemCode' => 'TDA', 'metaHash' => 'TDA:']];
    expect($seed->invoke(null, $habitat, (object) [], $featured))->toBe([
        ['itemCode' => 'TDA', 'numItem' => 1],
    ]);

    expect($seed->invoke(null, $habitat, (object) ['serverDefaultItemSeeded' => true], $featured))
        ->toBeNull()
        ->and($seed->invoke(null, $habitat, (object) [], (object) [
            '2' => (object) ['itemCode' => 'not-the-default'],
        ]))->toBeNull();
});

it('treats a hashed storage metadata key as the animal identity', function (): void {
    $world = persistenceTestWorld();
    $dna = [
        'G' => 'F',
        'B' => ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
        'P' => ['T' => ['c'], 'H' => ['20', '20'], 'S' => ['9', '9'], 'V' => ['9', '9']],
    ];
    $building = persistenceTestObject($world, 253, [
        'class_name' => 'FeatureBuilding',
        'item_name' => 'xuk_sheep_pen_finished',
        'contents' => [
            ['itemCode' => 'sheeppen_ewe', 'numItem' => 1],
        ],
        'components' => (object) [
            'featuredItems' => (object) [
                '0' => (object) ['itemCode' => 'sheeppen_ewe', 'metaHash' => 'sheeppen_ewe:12345678'],
            ],
            // The suffix is the persisted identity even when its digest was
            // produced from a legacy representation of the same DNA.
            'storageMetadata' => (object) [
                'sheeppen_ewe:12345678' => [json_encode($dna)],
            ],
        ],
    ]);

    expect($building->toFlashObject()->featuredItems->{'0'}->metaHash)
        ->toBe('sheeppen_ewe:12345678');
});

it('normalizes a legacy mutable animal name from its persisted DNA gender', function (): void {
    $world = persistenceTestWorld();
    $animal = persistenceTestObject($world, 254, [
        'class_name' => 'MutableAnimal',
        'item_name' => 'pigpen_male',
        'components' => (object) [
            'mutableAnimalState' => (object) [
                'dna' => (object) [
                    'G' => 'F',
                    'B' => (object) ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
                    'P' => (object) ['T' => ['a'], 'H' => ['20', '20'], 'S' => ['8', '8'], 'V' => ['8', '8']],
                ],
            ],
        ],
    ]);

    expect($animal->toFlashObject()->itemName)->toBe('pigpen_female');
});

it('canonicalizes a mismatched mutable animal before storage', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    $world = persistenceTestWorld();
    $animal = persistenceTestObject($world, 255, [
        'class_name' => 'MutableAnimal',
        'item_name' => 'pigpen_male',
        'components' => (object) [
            'mutableAnimalState' => (object) [
                'dna' => (object) [
                    'G' => 'F',
                    'B' => (object) ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
                    'P' => (object) ['T' => ['a'], 'H' => ['20', '20'], 'S' => ['8', '8'], 'V' => ['8', '8']],
                ],
            ],
        ],
    ]);

    $canonical = new ReflectionMethod('Player', 'canonicalMutableAnimalItemName');
    $canonical->setAccessible(true);

    expect($canonical->invoke(null, $animal))->toBe('pigpen_female');
});

it('rejects stale mutable-animal codes for finished breeding pens', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    // The production item catalog supplies these lookups. Seed the minimal
    // equivalents here so the validator is tested against catalog codes,
    // rather than relying on the client-supplied code.
    App\Models\Item::query()->create([
        'name' => 'pigpen_male',
        'code' => 'H!',
        'data' => serialize(['name' => 'pigpen_male', 'code' => 'H!', 'type' => 'animal']),
    ]);
    App\Models\Item::query()->create([
        'name' => 'pigpen_female',
        'code' => 'I!',
        'data' => serialize(['name' => 'pigpen_female', 'code' => 'I!', 'type' => 'animal']),
    ]);
    App\Models\Item::query()->create([
        'name' => 'sheeppen_ram',
        'code' => 'cx',
        'data' => serialize(['name' => 'sheeppen_ram', 'code' => 'cx', 'type' => 'animal']),
    ]);
    App\Models\Item::query()->create([
        'name' => 'sheeppen_ewe',
        'code' => 'cw',
        'data' => serialize(['name' => 'sheeppen_ewe', 'code' => 'cw', 'type' => 'animal']),
    ]);
    App\Models\Item::clearCache();

    $world = persistenceTestWorld();
    $pen = persistenceTestObject($world, 2514, [
        'class_name' => 'FeatureBuilding',
        'item_name' => 'pigpenv2_finished',
        'state' => 'bare',
    ]);
    $dna = (object) [
        'G' => 'M',
        'B' => (object) ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
        'P' => (object) ['T' => ['c'], 'H' => ['20', '20'], 'S' => ['9', '9'], 'V' => ['9', '9']],
    ];
    $boar = persistenceTestObject($world, 2515, [
        'class_name' => 'MutableAnimal',
        'item_name' => 'pigpen_male',
        'components' => (object) ['mutableAnimalState' => (object) ['dna' => $dna]],
    ]);

    $validation = new ReflectionMethod('Player', 'canStoreInFeatureBuilding');
    $validation->setAccessible(true);

    expect($validation->invoke(null, $pen, $boar, 'H!'))->toBeTrue()
        ->and($validation->invoke(null, $pen, $boar, 'I!'))->toBeFalse();

    $sheepPen = persistenceTestObject($world, 2517, [
        'class_name' => 'FeatureBuilding',
        'item_name' => 'xuk_sheep_pen_finished',
        'state' => 'bare',
    ]);
    $ram = persistenceTestObject($world, 2518, [
        'class_name' => 'MutableAnimal',
        'item_name' => 'sheeppen_ram',
        'components' => (object) ['mutableAnimalState' => (object) ['dna' => $dna]],
    ]);

    expect($validation->invoke(null, $sheepPen, $ram, 'cx'))->toBeTrue()
        ->and($validation->invoke(null, $sheepPen, $ram, 'cw'))->toBeFalse();
});

it('canonicalizes variant mutable-animal names from DNA gender', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    $world = persistenceTestWorld();
    $animal = persistenceTestObject($world, 2516, [
        'class_name' => 'MutableAnimal',
        'item_name' => 'pigpen_male_light_green',
        'components' => (object) [
            'mutableAnimalState' => (object) [
                'dna' => (object) [
                    'G' => 'F',
                    'B' => (object) ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
                    'P' => (object) ['T' => ['a'], 'H' => ['20', '20'], 'S' => ['8', '8'], 'V' => ['8', '8']],
                ],
            ],
        ],
    ]);

    $canonical = new ReflectionMethod('Player', 'canonicalMutableAnimalItemName');
    $canonical->setAccessible(true);

    expect($canonical->invoke(null, $animal))->toBe('pigpen_female');
});

it('allows DNA-backed breeders and the base pig sow in a finished pig pen', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    $world = persistenceTestWorld();
    $pen = persistenceTestObject($world, 2511, [
        'class_name' => 'FeatureBuilding',
        'item_name' => 'pigpenv2_finished',
        'state' => 'bare',
    ]);
    $dna = (object) [
        'G' => 'F',
        'B' => (object) ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
        'P' => (object) ['T' => ['c'], 'H' => ['20', '20'], 'S' => ['9', '9'], 'V' => ['9', '9']],
    ];
    $sow = persistenceTestObject($world, 2512, [
        'class_name' => 'MutableAnimal',
        'item_name' => 'pigpen_female',
        'components' => (object) ['mutableAnimalState' => (object) ['dna' => $dna]],
    ]);
    $ordinaryPig = persistenceTestObject($world, 2513, [
        'class_name' => 'Animal',
        'item_name' => 'pig',
    ]);

    $validation = new ReflectionMethod('Player', 'isValidPigpenBreedingAnimal');
    $validation->setAccessible(true);

    expect($validation->invoke(null, $sow))->toBeTrue()
        ->and($validation->invoke(null, $ordinaryPig))->toBeTrue();
});

it('atomically transfers a base pig from feature storage and persists female DNA', function (): void {
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    App\Models\Item::query()->create([
        'name' => 'pig',
        'code' => 'PI',
        'data' => serialize(['name' => 'pig', 'code' => 'PI', 'type' => 'animal']),
    ]);
    App\Models\Item::clearCache();

    $world = persistenceTestWorld();
    $femaleDna = [
        'N' => '',
        'G' => 'F',
        'B' => ['H' => ['d5', 'd6'], 'S' => ['2', '2'], 'V' => ['f', 'f']],
        'P' => ['H' => ['9', '9'], 'S' => ['e', 'e'], 'V' => ['f', 'f'], 'T' => ['f']],
    ];
    $maleDna = [
        'G' => 'M',
        'B' => ['H' => ['30', '30'], 'S' => ['8', '8'], 'V' => ['8', '8']],
        'P' => ['T' => ['b'], 'H' => ['40', '40'], 'S' => ['8', '8'], 'V' => ['8', '8']],
    ];

    $source = persistenceTestObject($world, 435, [
        'class_name' => 'FeatureBuilding',
        'item_name' => 'animal_breeding_livestock_finished',
        'contents' => [
            ['itemCode' => 'PI', 'numItem' => 2],
            ['itemCode' => 'SH', 'numItem' => 1],
        ],
        'components' => (object) [
            'featuredItems' => (object) [
                '0' => (object) ['itemCode' => 'PI', 'metaHash' => 'PI:'],
            ],
        ],
    ]);
    $pen = persistenceTestObject($world, 448, [
        'class_name' => 'FeatureBuilding',
        'item_name' => 'pigpenv2_finished',
        'contents' => [
            ['itemCode' => 'H!', 'numItem' => 1],
        ],
        'components' => (object) [
            'featuredItems' => (object) [
                '1' => (object) ['itemCode' => 'H!', 'metaHash' => 'H!:def67890'],
            ],
            'storageMetadata' => (object) [
                'H!:def67890' => [json_encode($maleDna)],
            ],
        ],
    ]);
    unset($source, $pen);
    unset($GLOBALS['_world_cache']['900001:farm']);

    $result = (new Player($world->uid))->storeItem(
        (object) ['id' => 448],
        (object) [
            'resource' => 0,
            'storedItemCode' => 'PI',
            'storedItemName' => 'pig',
            'storedClassName' => 'Animal',
            'cameFromLocation' => 435,
            'numToStore' => 1,
            'metadata' => null,
        ],
    );

    expect($result['success'] ?? false)->toBeTrue();

    $source = WorldObject::query()->where('world_id', $world->id)->where('object_id', 435)->firstOrFail();
    $pen = WorldObject::query()->where('world_id', $world->id)->where('object_id', 448)->firstOrFail();
    expect($source->contents)->toContain(['itemCode' => 'PI', 'numItem' => 1])
        ->and($source->contents)->toContain(['itemCode' => 'SH', 'numItem' => 1])
        ->and($pen->contents)->toContain(['itemCode' => 'H!', 'numItem' => 1])
        ->and($pen->contents)->toContain(['itemCode' => 'PI', 'numItem' => 1]);

    $storageMetadata = $pen->components->storageMetadata;
    $pigEntries = [];
    foreach (get_object_vars($storageMetadata) as $key => $entries) {
        if (str_starts_with((string) $key, 'PI:')) {
            $pigEntries = array_merge($pigEntries, is_array($entries) ? $entries : [$entries]);
        }
    }
    expect($pigEntries)->toHaveCount(1)
        ->and(json_decode($pigEntries[0], true)['G'] ?? null)->toBe('F');
});

it('round-trips adult mutable-animal DNA across world serialization', function (): void {
    $world = persistenceTestWorld();
    $dna = (object) [
        'N' => 'Spots',
        'G' => 'F',
        'B' => (object) ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
        'P' => (object) ['T' => ['c'], 'H' => ['20', '20'], 'S' => ['9', '9'], 'V' => ['9', '9']],
    ];
    $adult = persistenceTestObject($world, 252, [
        'class_name' => 'MutableAnimal',
        'item_name' => 'sheeppen_ewe',
        'state' => 'bare',
        'components' => (object) ['mutableAnimalState' => (object) ['dna' => $dna]],
    ]);

    $flash = $adult->toFlashObject();
    expect($flash->mutableAnimalState->dna->P->T[0])->toBe('c');

    $persisted = WorldObject::fromFlashObject($flash, $world->id);
    $components = json_decode($persisted['components']);
    expect($components->mutableAnimalState->dna->P->T[0])->toBe('c');
});

it('recovers adult breeding DNA saved by the legacy giftbox placement path', function (): void {
    $world = persistenceTestWorld();
    $dna = [
        'G' => 'F',
        'B' => ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
        'P' => ['T' => ['e'], 'H' => ['20', '20'], 'S' => ['9', '9'], 'V' => ['9', '9']],
    ];
    $adult = persistenceTestObject($world, 2521, [
        'class_name' => 'MutableAnimal',
        'item_name' => 'sheeppen_ewe',
        // `(object) $rawJson` stores raw primitive data as `scalar`.
        'components' => (object) ['scalar' => json_encode($dna)],
    ]);

    expect($adult->toFlashObject()->mutableAnimalState->dna->P->T[0])->toBe('e');
});

it('keeps an explicit adult pattern instead of falling back to the default', function (): void {
    $world = persistenceTestWorld();
    $adult = persistenceTestObject($world, 253, [
        'class_name' => 'MutableAnimal',
        'item_name' => 'sheeppen_ewe',
        'components' => (object) [
            'mutableAnimalState' => (object) [
                'dna' => (object) [
                    'G' => 'F',
                    'B' => (object) ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
                    'P' => (object) ['T' => ['g'], 'H' => ['20', '20'], 'S' => ['9', '9'], 'V' => ['9', '9']],
                ],
            ],
        ],
    ]);

    expect($adult->toFlashObject()->mutableAnimalState->dna->P->T[0])->toBe('g');
});

it('reloads an unfinished breeding baby in its interactive state', function (): void {
    $world = persistenceTestWorld();
    $baby = persistenceTestObject($world, 2531, [
        'class_name' => 'MutableAnimalBaby',
        'item_name' => 'sheeppen_lamb',
        'state' => 'built',
        'components' => (object) [
            'mutableAnimalState' => (object) ['dna' => (object) [
                'G' => 'F',
                'B' => (object) ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
                'P' => (object) ['T' => ['a'], 'H' => ['20', '20'], 'S' => ['8', '8'], 'V' => ['8', '8']],
            ]],
        ],
    ]);

    $flash = $baby->toFlashObject();

    expect($flash->state)->toBe('built')
        ->and($flash->isFullyBuilt)->toBeFalse();
});

it('keeps a partially fed breeding baby clickable after reload', function (): void {
    $world = persistenceTestWorld();
    $baby = persistenceTestObject($world, 2532, [
        'class_name' => 'MutableAnimalBaby',
        'item_name' => 'pigpen_baby',
        'state' => 'built',
        'contents' => [
            ['itemCode' => 'B8', 'numItem' => 9],
        ],
        'components' => (object) [
            'mutableAnimalState' => (object) ['dna' => (object) [
                'G' => 'F',
                'B' => (object) ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
                'P' => (object) ['T' => ['a'], 'H' => ['20', '20'], 'S' => ['8', '8'], 'V' => ['8', '8']],
            ]],
        ],
    ]);

    $flash = $baby->toFlashObject();

    expect($flash->state)->toBe('built')
        ->and($flash->isFullyBuilt)->toBeFalse()
        ->and($flash->contents)->toBe([['itemCode' => 'B8', 'numItem' => 9]]);
});

it('normalizes legacy bare breeding babies to construction on reload', function (): void {
    $world = persistenceTestWorld();
    $baby = persistenceTestObject($world, 2533, [
        'class_name' => 'MutableAnimalBaby',
        'item_name' => 'pigpen_baby',
        'state' => 'bare',
    ]);

    $flash = $baby->toFlashObject();

    expect($flash->state)->toBe('construction')
        ->and($flash->isFullyBuilt)->toBeFalse();
});

it('commits equipment changes atomically', function (): void {
    $world = persistenceTestWorld();
    persistenceTestObject($world, 301);
    persistenceTestObject($world, 302);

    $result = WorldPersistence::persistEquipmentChanges(
        $world->uid,
        $world->type,
        [
            persistenceTestFlashObject(301, ['state' => 'fallow', 'itemName' => null, 'plantTime' => 0]),
            persistenceTestFlashObject(302, ['state' => 'fallow', 'itemName' => null, 'plantTime' => 0]),
        ],
        [persistenceTestFlashObject(303, ['state' => 'plowed', 'itemName' => null, 'plantTime' => 0])],
    );

    expect($result)->toBeTrue();
    $this->assertDatabaseHas('world_objects', ['world_id' => $world->id, 'object_id' => 301, 'state' => 'fallow']);
    $this->assertDatabaseHas('world_objects', ['world_id' => $world->id, 'object_id' => 302, 'state' => 'fallow']);
    $this->assertDatabaseHas('world_objects', ['world_id' => $world->id, 'object_id' => 303, 'state' => 'plowed']);
});

it('rolls back an equipment batch when any target is missing', function (): void {
    $world = persistenceTestWorld();
    persistenceTestObject($world, 401);

    $result = WorldPersistence::persistEquipmentChanges(
        $world->uid,
        $world->type,
        [
            persistenceTestFlashObject(401, ['state' => 'fallow', 'itemName' => null, 'plantTime' => 0]),
            persistenceTestFlashObject(499, ['state' => 'fallow', 'itemName' => null, 'plantTime' => 0]),
        ],
        [],
    );

    expect($result)->toBeFalse();
    $this->assertDatabaseHas('world_objects', ['world_id' => $world->id, 'object_id' => 401, 'state' => 'grown']);
});

it('persists message signs and message-manager changes together', function (): void {
    $world = persistenceTestWorld();
    $messages = [
        'messages' => [[
            'id' => 1,
            'message' => 'Hello neighbor',
            'objectId' => 501,
        ]],
        'allowSendEmails' => true,
    ];
    $sign = persistenceTestFlashObject(501, [
        'className' => 'MessageSign',
        'itemName' => 'messagesign',
        'message' => 'Hello neighbor',
        'messageId' => 1,
        'authorId' => '800001',
        'hostId' => $world->uid,
        'timestamp' => 123.45,
    ]);

    expect(WorldPersistence::createMessageSign($world->uid, $world->type, $sign, $messages))->toBeTrue();
    $this->assertDatabaseHas('world_objects', ['world_id' => $world->id, 'object_id' => 501, 'class_name' => 'MessageSign', 'deleted' => false]);
    expect(unserialize($world->fresh()->messageManager))->toBe($messages);

    $emptyMessages = ['messages' => [], 'allowSendEmails' => true];
    expect(WorldPersistence::deleteMessageSign($world->uid, $world->type, 501, $emptyMessages))->toBeTrue();
    $this->assertDatabaseHas('world_objects', ['world_id' => $world->id, 'object_id' => 501, 'deleted' => true]);
    expect(unserialize($world->fresh()->messageManager))->toBe($emptyMessages);
});
