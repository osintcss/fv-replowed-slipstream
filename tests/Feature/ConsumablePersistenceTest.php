<?php

use App\Models\CraftingInventory;
use App\Models\PlayerMeta;
use App\Models\User;
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
    require_once AMFPHP_ROOTPATH.'Helpers/crafting_helper.php';
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';
    require_once AMFPHP_ROOTPATH.'Functions/FarmService.php';
    require_once AMFPHP_ROOTPATH.'Functions/WorldService.php';

    // The testing-only migration creates the legacy items lookup table before
    // RefreshDatabase opens its transaction; clear its rows between tests.
    DB::table('items')->delete();

    PlayerMeta::clearCache();
    UserMeta::invalidateCache('all-test-users');
});

function consumablePersistencePlayer(): array
{
    $user = User::factory()->create();
    UserMeta::create([
        'uid' => $user->uid,
        'firstName' => 'Test',
        'lastName' => 'Player',
        'energy' => 100,
        'energyMax' => 100,
    ]);

    $player = new class ($user->uid) {
        public function __construct(private readonly string $uid) {}

        public function getUid(): string
        {
            return $this->uid;
        }
    };

    return [$user->uid, $player];
}

function seedConsumableItem(string $name, string $code, array $data): void
{
    DB::table('items')->insert([
        'name' => $name,
        'code' => $code,
        'data' => serialize($data),
    ]);
}

it('consumes Giftbox fuel atomically with its energy grant', function (): void {
    [$uid, $player] = consumablePersistencePlayer();
    seedConsumableItem('consume_fuelWhole2', 'Pv1', [
        'name' => 'consume_fuelWhole2',
        'code' => 'Pv1',
        'className' => 'CFuel',
        'count' => '2.0',
    ]);
    PlayerMeta::setValue($uid, 'giftbox', serialize([
        'Pv1' => [1, [], []],
    ]));

    $request = (object) ['params' => ['consume_fuelWhole2', true]];
    $result = FarmService::buyFuel($player, $request, null);

    expect($result['data']['success'])->toBeTrue()
        ->and(UserMeta::where('uid', $uid)->value('energy'))->toBe(300);
    expect(unserialize(PlayerMeta::getValue($uid, 'giftbox'), ['allowed_classes' => false]))
        ->not->toHaveKey('Pv1');

    // A retried AMF request must not mint a second energy grant.
    PlayerMeta::clearCache();
    UserMeta::invalidateCache($uid);
    $retry = FarmService::buyFuel($player, $request, null);
    expect($retry['data']['success'])->toBeFalse()
        ->and(UserMeta::where('uid', $uid)->value('energy'))->toBe(300);
});

it('persists generic Giftbox consumable use', function (): void {
    [$uid, $player] = consumablePersistencePlayer();
    seedConsumableItem('consume_test', 'ZZ', [
        'name' => 'consume_test',
        'code' => 'ZZ',
        'className' => 'CXP',
    ]);
    PlayerMeta::setValue($uid, 'giftbox', serialize([
        'ZZ' => [2, [], []],
    ]));

    $request = (object) ['params' => [
        'use',
        (object) ['itemName' => 'consume_test'],
        [(object) [
            'isGift' => true,
            'isFree' => false,
            'storageId' => GIFTBOX_ID,
            'itemCount' => 1,
            'targetUser' => $uid,
        ]],
    ]];
    $result = WorldService::performAction($player, $request, null);

    expect($result['data']['success'])->toBeTrue()
        ->and($result['data']['consumed'])->toBe(1);
    $giftbox = unserialize(PlayerMeta::getValue($uid, 'giftbox'), ['allowed_classes' => false]);
    expect($giftbox['ZZ'][0])->toBe(1);
});

it('persists the server-side effect of the unwither consumable', function (): void {
    [$uid, $player] = consumablePersistencePlayer();
    $world = UserWorld::query()->create([
        'uid' => $uid,
        'type' => 'farm',
        'sizeX' => 12,
        'sizeY' => 12,
        'messageManager' => serialize([]),
    ]);
    seedConsumableItem('consume_unwither', 'A3', [
        'name' => 'consume_unwither',
        'code' => 'A3',
        'className' => 'CUnwither',
    ]);
    PlayerMeta::setValue($uid, 'giftbox', serialize([
        'A3' => [1, [], []],
    ]));

    $growTimeDays = 0.01;
    $oldPlantTime = getCurrentTimeMs() - (calculateGrowTimeMs($growTimeDays) * 2) - 1;
    $oldPlot = WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 1,
        'class_name' => 'Plot',
        'item_name' => 'unwither_test_crop',
        'position_x' => 1,
        'position_y' => 1,
        'position_z' => 0,
        'state' => PLOT_STATE_PLANTED,
        'plant_time' => $oldPlantTime,
        'deleted' => false,
    ]);
    WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 2,
        'class_name' => 'Plot',
        'item_name' => 'unwither_test_crop',
        'position_x' => 2,
        'position_y' => 1,
        'position_z' => 0,
        'state' => PLOT_STATE_PLANTED,
        'plant_time' => getCurrentTimeMs(),
        'deleted' => false,
    ]);
    seedConsumableItem('unwither_test_crop', 'UC', [
        'name' => 'unwither_test_crop',
        'code' => 'UC',
        'growTime' => (string) $growTimeDays,
    ]);

    $request = (object) ['params' => [
        'use',
        (object) ['itemName' => 'consume_unwither'],
        [(object) [
            'isGift' => true,
            'isFree' => false,
            'storageId' => GIFTBOX_ID,
            'itemCount' => 1,
            'targetUser' => $uid,
        ]],
    ]];
    $result = WorldService::performAction($player, $request, null);

    expect($result['data']['success'])->toBeTrue()
        ->and($result['data']['unwitheredCount'])->toBe(1)
        ->and($oldPlot->fresh()->state)->toBe(PLOT_STATE_GROWN)
        ->and(unserialize(PlayerMeta::getValue($uid, 'giftbox'), ['allowed_classes' => false]))
        ->not->toHaveKey('A3');
});

it('persists generic consumable use from the personal crafting silo', function (): void {
    [$uid, $player] = consumablePersistencePlayer();
    seedConsumableItem('consume_test_silo', 'ZY', [
        'name' => 'consume_test_silo',
        'code' => 'ZY',
        'className' => 'CBushel',
    ]);
    CraftingInventory::create([
        'uid' => $uid,
        'item_code' => 'ZY',
        'quantity' => 2,
        'storage_type' => 'silo',
    ]);

    $request = (object) ['params' => [
        'use',
        (object) ['itemName' => 'consume_test_silo'],
        [(object) [
            'isGift' => false,
            'isFree' => false,
            'storageId' => PERSONAL_CRAFTING_INVENTORY_ID,
            'itemCount' => 1,
            'targetUser' => $uid,
        ]],
    ]];
    $result = WorldService::performAction($player, $request, null);

    expect($result['data']['success'])->toBeTrue()
        ->and(CraftingInventory::where('uid', $uid)->where('item_code', 'ZY')->value('quantity'))
        ->toBe(1);
});

it('credits an XP consumable atomically and keeps it after reload', function (): void {
    [$uid, $player] = consumablePersistencePlayer();
    UserMeta::where('uid', $uid)->update(['xp' => 100]);
    seedConsumableItem('consume_xp_test', 'XP1', [
        'name' => 'consume_xp_test',
        'code' => 'XP1',
        'type' => 'consumable',
        'className' => 'CXP',
        'xp' => 50,
    ]);
    PlayerMeta::setValue($uid, 'giftbox', serialize([
        'XP1' => [1, [], []],
    ]));

    $request = (object) ['params' => [
        'use',
        (object) ['itemName' => 'consume_xp_test'],
        [(object) [
            'isGift' => true,
            'isFree' => false,
            'storageId' => GIFTBOX_ID,
            'itemCount' => 1,
            'targetUser' => $uid,
        ]],
    ]];
    $result = WorldService::performAction($player, $request, null);

    expect($result['data']['success'])->toBeTrue()
        ->and($result['data']['consumed'])->toBe(1)
        ->and($result['data']['xpAdded'])->toBe(50)
        ->and(UserMeta::where('uid', $uid)->value('xp'))->toBe(150);

    // A fresh request/reload sees the server-side balance, not only the
    // Flash client's temporary local XP update.
    PlayerMeta::clearCache();
    UserMeta::invalidateCache($uid);
    expect(UserMeta::where('uid', $uid)->value('xp'))->toBe(150);
    expect(unserialize(PlayerMeta::getValue($uid, 'giftbox'), ['allowed_classes' => false]))
        ->not->toHaveKey('XP1');

    // Replaying the same request cannot mint another reward after the item
    // was consumed.
    $retry = WorldService::performAction($player, $request, null);
    expect($retry['data']['success'])->toBeFalse()
        ->and(UserMeta::where('uid', $uid)->value('xp'))->toBe(150);
});

it('credits coin and cash consumables from authoritative item metadata', function (): void {
    [$uid, $player] = consumablePersistencePlayer();
    seedConsumableItem('consume_coins_test', 'CO1', [
        'name' => 'consume_coins_test',
        'code' => 'CO1',
        'type' => 'consumable',
        'className' => 'CCoins',
        'coins' => 5000,
    ]);
    seedConsumableItem('consume_cash_test', 'CA1', [
        'name' => 'consume_cash_test',
        'code' => 'CA1',
        'type' => 'consumable',
        'className' => 'CCash',
        'cash' => 3,
    ]);
    PlayerMeta::setValue($uid, 'giftbox', serialize([
        'CO1' => [2, [], []],
        'CA1' => [1, [], []],
    ]));

    $coinRequest = (object) ['params' => [
        'use',
        (object) [
            'itemName' => 'consume_coins_test',
            // Deliberately send a mismatched code; the server catalogue must
            // still consume and credit the named item.
            'itemCode' => 'CA1',
        ],
        [(object) [
            'isGift' => true,
            'isFree' => false,
            'storageId' => GIFTBOX_ID,
            'itemCount' => 2,
            'targetUser' => $uid,
        ]],
    ]];
    $coinResult = WorldService::performAction($player, $coinRequest, null);

    expect($coinResult['data']['success'])->toBeTrue()
        ->and($coinResult['data']['goldAdded'])->toBe(10000)
        ->and(UserMeta::where('uid', $uid)->value('gold'))->toBe(11000);

    $cashRequest = (object) ['params' => [
        'use',
        (object) ['itemName' => 'consume_cash_test'],
        [(object) [
            'isGift' => true,
            'isFree' => false,
            'storageId' => GIFTBOX_ID,
            'itemCount' => 1,
            'targetUser' => $uid,
        ]],
    ]];
    $cashResult = WorldService::performAction($player, $cashRequest, null);

    expect($cashResult['data']['success'])->toBeTrue()
        ->and($cashResult['data']['cashAdded'])->toBe(3)
        ->and(UserMeta::where('uid', $uid)->value('cash'))->toBe(13);

    PlayerMeta::clearCache();
    UserMeta::invalidateCache($uid);
    expect(UserMeta::where('uid', $uid)->value('gold'))->toBe(11000)
        ->and(UserMeta::where('uid', $uid)->value('cash'))->toBe(13);
});

it('fills an XP book to the next level and persists its level-up reward', function (): void {
    [$uid, $player] = consumablePersistencePlayer();
    UserMeta::where('uid', $uid)->update(['xp' => 100]);
    seedConsumableItem('consume_xp_book_test', 'XB1', [
        'name' => 'consume_xp_book_test',
        'code' => 'XB1',
        'type' => 'consumable',
        'className' => 'CXPBook',
    ]);
    PlayerMeta::setValue($uid, 'giftbox', serialize([
        'XB1' => [1, [], []],
    ]));

    $request = (object) ['params' => [
        'use',
        (object) ['itemName' => 'consume_xp_book_test'],
        [(object) [
            'isGift' => true,
            'isFree' => false,
            'storageId' => GIFTBOX_ID,
            'itemCount' => 1,
            'targetUser' => $uid,
        ]],
    ]];
    $result = WorldService::performAction($player, $request, null);

    // Level 4 begins at 70 XP and level 5 begins at 140 XP, so the book adds
    // the 40 XP gap and the normal one-cash level-up reward.
    expect($result['data']['success'])->toBeTrue()
        ->and($result['data']['xpAdded'])->toBe(40)
        ->and(UserMeta::where('uid', $uid)->value('xp'))->toBe(140)
        ->and(UserMeta::where('uid', $uid)->value('cash'))->toBe(11);

    PlayerMeta::clearCache();
    UserMeta::invalidateCache($uid);
    expect(UserMeta::where('uid', $uid)->value('xp'))->toBe(140)
        ->and(UserMeta::where('uid', $uid)->value('cash'))->toBe(11);
});

it('does not consume a reward when the player resource row is unavailable', function (): void {
    $user = User::factory()->create();
    $uid = $user->uid;
    $player = new class ($uid) {
        public function __construct(private readonly string $uid) {}

        public function getUid(): string
        {
            return $this->uid;
        }
    };
    seedConsumableItem('consume_atomic_missing_user', 'XM1', [
        'name' => 'consume_atomic_missing_user',
        'code' => 'XM1',
        'type' => 'consumable',
        'className' => 'CXP',
        'xp' => 20,
    ]);
    PlayerMeta::setValue($uid, 'giftbox', serialize([
        'XM1' => [1, [], []],
    ]));

    $request = (object) ['params' => [
        'use',
        (object) ['itemName' => 'consume_atomic_missing_user'],
        [(object) [
            'isGift' => true,
            'isFree' => false,
            'storageId' => GIFTBOX_ID,
            'itemCount' => 1,
            'targetUser' => $uid,
        ]],
    ]];
    $result = WorldService::performAction($player, $request, null);

    expect($result['data']['success'])->toBeFalse();
    expect(unserialize(PlayerMeta::getValue($uid, 'giftbox'), ['allowed_classes' => false]))
        ->toHaveKey('XM1');
});
