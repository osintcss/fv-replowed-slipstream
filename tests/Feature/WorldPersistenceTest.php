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
