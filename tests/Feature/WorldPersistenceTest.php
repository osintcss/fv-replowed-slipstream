<?php

use App\Models\UserWorld;
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
