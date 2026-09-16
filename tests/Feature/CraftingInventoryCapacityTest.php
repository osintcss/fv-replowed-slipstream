<?php

use App\Models\CraftingInventory;
use App\Models\UserWorld;
use App\Models\User;
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

    DB::table('items')->delete();
});

function seedCapacityTestItem(string $name, string $code, array $data): void
{
    DB::table('items')->insert([
        'name' => $name,
        'code' => $code,
        'data' => serialize($data),
    ]);
}

it('enforces market-stall capacity across backend storage rows', function (): void {
    $uid = User::factory()->create()->uid;
    seedCapacityTestItem('capacity_bushel', 'CAP1', [
        'name' => 'capacity_bushel',
        'code' => 'CAP1',
        'type' => 'bushel',
        'subtype' => 'vegetable',
        'className' => 'CBushel',
    ]);

    CraftingInventory::create([
        'uid' => $uid,
        'item_code' => 'CAP1',
        'quantity' => 250,
        'storage_type' => 'silo',
    ]);

    expect(addToInventory($uid, 'CAP1', 150, 'stall'))->toBeTrue()
        ->and(CraftingInventory::where('uid', $uid)->get()->sum('quantity'))->toBe(400)
        ->and(addToInventory($uid, 'CAP1', 1, 'silo'))->toBeFalse()
        ->and(CraftingInventory::where('uid', $uid)->get()->sum('quantity'))->toBe(400);
});

it('does not expose non-bushel crafting rows to the Flash crafting state', function (): void {
    $uid = User::factory()->create()->uid;
    seedCapacityTestItem('capacity_bushel_response', 'CAP2', [
        'name' => 'capacity_bushel_response',
        'code' => 'CAP2',
        'type' => 'bushel',
        'subtype' => 'fruit',
        'className' => 'CBushel',
    ]);
    seedCapacityTestItem('capacity_unrelated_item', 'CAP3', [
        'name' => 'capacity_unrelated_item',
        'code' => 'CAP3',
        'type' => 'consumable',
        'className' => 'CConsumable',
    ]);

    CraftingInventory::query()->insert([
        [
            'uid' => $uid,
            'item_code' => 'CAP2',
            'quantity' => 3,
            'storage_type' => 'silo',
        ],
        [
            'uid' => $uid,
            'item_code' => 'CAP3',
            'quantity' => 99,
            'storage_type' => 'silo',
        ],
    ]);

    expect(getCraftingInventory($uid))->toBe([
        [
            'itemCode' => 'CAP2',
            'quantity' => 3,
            'price' => null,
        ],
    ]);
});

it('persists bushel deductions across all legacy storage rows', function (): void {
    $uid = User::factory()->create()->uid;
    seedCapacityTestItem('shareable_bushel', 'CAP4', [
        'name' => 'shareable_bushel',
        'code' => 'CAP4',
        'type' => 'bushel',
        'subtype' => 'vegetable',
        'className' => 'CBushel',
    ]);

    CraftingInventory::query()->insert([
        [
            'uid' => $uid,
            'item_code' => 'CAP4',
            'quantity' => 3,
            'storage_type' => 'silo',
        ],
        [
            'uid' => $uid,
            'item_code' => 'CAP4',
            'quantity' => 4,
            'storage_type' => 'stall',
        ],
    ]);

    expect(removeBushelsFromInventory($uid, 'CAP4', 5))->toBeTrue()
        ->and((int) CraftingInventory::where('uid', $uid)->sum('quantity'))->toBe(2)
        ->and(removeBushelsFromInventory($uid, 'CAP4', 3))->toBeFalse()
        ->and((int) CraftingInventory::where('uid', $uid)->sum('quantity'))->toBe(2);
});

it('uses the saved market-stall expansion when enforcing capacity', function (): void {
    $uid = User::factory()->create()->uid;
    $world = UserWorld::query()->create([
        'uid' => (string) $uid,
        'type' => 'farm',
        'sizeX' => 12,
        'sizeY' => 12,
        'objects' => '[]',
        'messageManager' => serialize(['messages' => [], 'allowSendEmails' => true]),
    ]);
    WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 900,
        'class_name' => 'MarketStallBuilding',
        'item_name' => 'marketstall',
        'position_x' => 1,
        'position_y' => 1,
        'position_z' => 0,
        'state' => 'built',
        'deleted' => false,
        'expansion_level' => 4,
    ]);
    seedCapacityTestItem('expanded_bushel', 'CAP5', [
        'name' => 'expanded_bushel',
        'code' => 'CAP5',
        'type' => 'bushel',
        'subtype' => 'vegetable',
        'className' => 'CBushel',
    ]);
    CraftingInventory::create([
        'uid' => $uid,
        'item_code' => 'CAP5',
        'quantity' => 450,
        'storage_type' => 'silo',
    ]);

    expect(getMarketStallCapacity($uid, 'farm'))->toBe(475)
        ->and(addToInventory($uid, 'CAP5', 25, 'silo'))->toBeTrue()
        ->and(addToInventory($uid, 'CAP5', 1, 'silo'))->toBeFalse();
});
