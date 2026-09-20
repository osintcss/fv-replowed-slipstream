<?php

use App\Models\PlayerMeta;
use App\Models\User;
use App\Models\UserMeta;
use App\Models\UserWorld;
use App\Models\WorldCurrency;

function createWorldShopUser(int $xp, int $cash = 0): User
{
    $user = User::factory()->create();

    UserMeta::create([
        'uid' => $user->uid,
        'firstName' => 'World',
        'lastName' => 'Shop',
        'xp' => $xp,
        'cash' => $cash,
        'gold' => 1000,
        'energyMax' => 100,
        'energy' => 100,
        'seenFlags' => serialize([]),
    ]);

    return $user;
}

it('gives one world choice for each reached level without charging cash', function (): void {
    $user = createWorldShopUser(250, 777); // Level 6: levels 5 and 6 are both eligible.

    $this->actingAs($user)
        ->getJson('/api/world-shop/status')
        ->assertOk()
        ->assertJson([
            'playerLevel' => 6,
            'availableClaims' => 2,
            'nextUnlockLevel' => 5,
        ]);

    $first = $this->actingAs($user)
        ->postJson('/api/world-shop/claim', ['worldId' => 'fisherman']);

    $first->assertOk()->assertJson([
        'success' => true,
        'worldId' => 'fisherman',
        'unlockLevel' => 5,
        'remainingClaims' => 1,
    ]);

    $second = $this->actingAs($user)
        ->postJson('/api/world-shop/claim', ['worldId' => 'england']);

    $second->assertOk()->assertJson([
        'success' => true,
        'worldId' => 'england',
        'unlockLevel' => 6,
        'remainingClaims' => 0,
    ]);

    expect(UserMeta::where('uid', $user->uid)->value('cash'))->toBe(777);
    expect(unserialize(PlayerMeta::where('uid', $user->uid)
        ->where('meta_key', 'world_unlock_claims')
        ->value('meta_value')))->toBe([5 => 'fisherman', 6 => 'england']);

    $this->actingAs($user)
        ->postJson('/api/world-shop/claim', ['worldId' => 'australia'])
        ->assertStatus(409)
        ->assertJson(['success' => false]);
});

it('does not allow a world choice before level 5', function (): void {
    $user = createWorldShopUser(70, 777); // Level 4.

    $this->actingAs($user)
        ->getJson('/api/world-shop/status')
        ->assertOk()
        ->assertJson([
            'playerLevel' => 4,
            'availableClaims' => 0,
            'nextUnlockLevel' => 5,
        ]);

    $this->actingAs($user)
        ->postJson('/api/world-shop/claim', ['worldId' => 'fisherman'])
        ->assertForbidden()
        ->assertJson(['success' => false]);
});

it('rejects the old cash purchase path', function (): void {
    $user = createWorldShopUser(140, 777);

    $this->actingAs($user)
        ->postJson('/api/world-shop/purchase', ['worldId' => 'fisherman'])
        ->assertStatus(410)
        ->assertJson(['success' => false]);

    expect(UserMeta::where('uid', $user->uid)->value('cash'))->toBe(777);
});

it('persists travel to an unlocked world', function (): void {
    $user = User::factory()->create();

    UserMeta::create([
        'uid' => $user->uid,
        'firstName' => 'Traveler',
        'lastName' => 'Test',
        'cash' => 0,
        'gold' => 1000,
        'energyMax' => 100,
        'energy' => 100,
        'seenFlags' => serialize([]),
    ]);
    PlayerMeta::create([
        'uid' => $user->uid,
        'meta_key' => 'unlocked_worlds',
        'meta_value' => serialize(['fisherman']),
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/world-shop/travel', ['worldId' => 'fisherman']);

    $response->assertOk()->assertJson([
        'success' => true,
        'worldId' => 'fisherman',
    ]);

    $this->assertDatabaseHas('playermeta', [
        'uid' => $user->uid,
        'meta_key' => 'currentWorldType',
        'meta_value' => 'fisherman',
    ]);
});

it('allows Winter Fable to be claimed and traveled to', function (): void {
    $user = createWorldShopUser(250);

    $this->actingAs($user)
        ->postJson('/api/world-shop/claim', ['worldId' => 'winternord'])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'worldId' => 'winternord',
        ]);

    $this->actingAs($user)
        ->postJson('/api/world-shop/travel', ['worldId' => 'winternord'])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'worldId' => 'winternord',
        ]);

    $this->assertDatabaseHas('playermeta', [
        'uid' => $user->uid,
        'meta_key' => 'currentWorldType',
        'meta_value' => 'winternord',
    ]);
});

it('allows Jade Falls and Hawaiian Paradise to be claimed and traveled to', function (string $worldId): void {
    $user = createWorldShopUser(250);

    $this->actingAs($user)
        ->postJson('/api/world-shop/claim', ['worldId' => $worldId])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'worldId' => $worldId,
        ]);

    $this->actingAs($user)
        ->postJson('/api/world-shop/travel', ['worldId' => $worldId])
        ->assertOk()
        ->assertJson([
            'success' => true,
            'worldId' => $worldId,
        ]);

    $expectedUnit = $worldId === 'asia' ? 'jade' : 'coconuts';
    $this->assertDatabaseHas('world_currencies', [
        'uid' => $user->uid,
        'currency_unit' => $expectedUnit,
        'total' => 6000,
        'earned' => 6000,
    ]);
})->with(['asia', 'hawaii']);

it('rejects travel to a locked world', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/world-shop/travel', ['worldId' => 'fisherman']);

    $response->assertForbidden()->assertJson([
        'success' => false,
        'message' => 'World is not unlocked',
    ]);
});

it('filters locked worlds from the AMF world loader', function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';
    require_once AMFPHP_ROOTPATH.'Functions/WorldService.php';
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    $uid = '900000001';
    $request = (object) ['params' => ['fisherman']];

    expect(fn () => WorldService::loadOwnWorld(new Player($uid), $request))
        ->toThrow(RuntimeException::class, 'World is not unlocked: fisherman');
});

it('loads a neighbor current world rather than defaulting to the farm world', function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';
    require_once AMFPHP_ROOTPATH.'Functions/WorldService.php';
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';

    $neighborUid = '900000002';
    PlayerMeta::setValue($neighborUid, 'currentWorldType', 'winternord');
    UserWorld::query()->create([
        'uid' => $neighborUid,
        'type' => 'farm',
        'sizeX' => 12,
        'sizeY' => 12,
        'objects' => '[]',
        'messageManager' => serialize([
            'messages' => [[
                'id' => 1,
                'message' => 'Farm message',
                'authorId' => '1',
                'objectId' => 0,
                'isNew' => false,
                'timestamp' => 100,
            ]],
            'allowSendEmails' => true,
        ]),
    ]);
    UserWorld::query()->create([
        'uid' => $neighborUid,
        'type' => 'winternord',
        'sizeX' => 12,
        'sizeY' => 12,
        'objects' => '[]',
        'messageManager' => serialize([
            'messages' => [[
                'id' => 2,
                'message' => 'Winter message',
                'authorId' => '2',
                'objectId' => 0,
                'isNew' => true,
                'timestamp' => 200,
            ]],
            'allowSendEmails' => true,
        ]),
    ]);

    $GLOBALS['_world_cache'] = [];
    PlayerMeta::clearCache();

    $response = WorldService::loadNeighborWorld(
        new Player('900000003'),
        (object) ['params' => [$neighborUid]],
    );

    expect($response['data']['user']['currentWorldType'])->toBe('winternord')
        ->and($response['data']['world']['type'])->toBe('winternord')
        ->and($response['data']['world']['messageManager']->messages[0]->message)->toBe('Winter message');
});
