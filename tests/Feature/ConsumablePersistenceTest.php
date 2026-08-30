<?php

use App\Models\CraftingInventory;
use App\Models\PlayerMeta;
use App\Models\User;
use App\Models\UserMeta;
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
