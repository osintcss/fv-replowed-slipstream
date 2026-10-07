<?php

use App\Models\Item;
use App\Models\PlayerMeta;
use App\Models\UserMeta;
use App\Models\UserWorld;
use App\Models\WorldObject;
use App\Support\PetState;

beforeEach(function (): void {
    if (!defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Functions/WorldService.php';
    require_once AMFPHP_ROOTPATH.'Helpers/player.php';
});

it('places both dog variants and preserves their names, genders and follow choice through moves and reloads', function (): void {
    $world = UserWorld::query()->create([
        'uid' => '901280',
        'type' => 'farm',
        'sizeX' => 24,
        'sizeY' => 24,
        'objects' => '[]',
        'messageManager' => serialize(['messages' => [], 'allowSendEmails' => true]),
    ]);
    UserMeta::query()->create([
        'uid' => $world->uid,
        'firstName' => 'Pet',
        'lastName' => 'Tester',
        'gold' => 1000000,
        'cash' => 100,
        'xp' => 0,
    ]);

    foreach ([
        ['bordercollie_puppy_red', '71', 'Rusty', 1, 4],
        ['terrier_puppy_honey', '7D', 'Honey', 0, 9],
    ] as [$itemName, $code, $name, $gender, $x]) {
        Item::query()->create([
            'name' => $itemName,
            'code' => $code,
            'data' => serialize([
                'name' => $itemName,
                'code' => $code,
                'className' => 'Pet',
                'subtype' => 'pets',
                'cost' => 5000,
                'buyable' => 'true',
            ]),
        ]);
    }
    Item::clearCache();

    $market = new class {
        public function canAfford($action, $item, $currency): bool { return true; }
        public function newTransaction($action, $item, $currency): bool { return true; }
    };
    $player = new Player($world->uid);

    foreach ([
        ['bordercollie_puppy_red', '71', 'Rusty', 1, 4],
        ['terrier_puppy_honey', '7D', 'Honey', 0, 9],
    ] as [$itemName, $code, $name, $gender, $x]) {
        $placement = (object) [
            'params' => [
                ACTION_PLANT,
                (object) [
                    'id' => 63000 + $x,
                    'className' => 'Pet',
                    'itemName' => $itemName,
                    'position' => (object) ['x' => $x, 'y' => 4, 'z' => 0],
                    'state' => 'sit',
                ],
                [(object) ['petName' => $name, 'petGender' => $gender, 'petType' => $code]],
            ],
        ];
        $result = WorldService::performAction($player, $placement, $market);
        $id = (int) ($result['id'] ?? 0);
        expect($id)->toBeGreaterThan(0);

        $row = WorldObject::query()->where('world_id', $world->id)->where('object_id', $id)->firstOrFail();
        $saved = $row->toFlashObject();
        expect($saved->petName)->toBe($name)
            ->and($saved->petGender)->toBe($gender)
            ->and($saved->allowFollow)->toBeTrue()
            ->and($saved->isCash)->toBeFalse()
            ->and($saved->plantTime)->toBeGreaterThan(0);

        // A TMove snapshot does not include the pet-specific save fields.
        $move = (object) [
            'params' => [
                ACTION_MOVE,
                (object) [
                    'id' => $id,
                    'className' => 'Pet',
                    'itemName' => $itemName,
                    'position' => (object) ['x' => $x + 1, 'y' => 4, 'z' => 0],
                    'state' => 'sit',
                    'components' => (object) ['petState' => (object) ['petName' => 'forged']],
                ],
                [(object) ['allowFollow' => false]],
            ],
        ];
        WorldService::performAction($player, $move, $market);
        $reloaded = $row->fresh()->toFlashObject();
        expect($reloaded->petName)->toBe($name)
            ->and($reloaded->petGender)->toBe($gender)
            ->and($reloaded->allowFollow)->toBeFalse()
            ->and($reloaded->position->x)->toBe($x + 1)
            ->and($reloaded->plantTime)->toBe($saved->plantTime);
    }
});

it('rejects pet types that do not match the catalog item', function (): void {
    expect(PetState::initial(
        (object) ['petName' => 'Rusty', 'petGender' => 1, 'petType' => 'wrong'],
        ['className' => 'Pet', 'subtype' => 'pets', 'code' => '71'],
        123,
    ))->toBeNull();

    $cashPet = PetState::initial(
        (object) ['petName' => 'Rusty', 'petGender' => 1, 'petType' => '72'],
        ['className' => 'Pet', 'subtype' => 'pets', 'code' => '72', 'market' => 'cash'],
        123,
    );
    expect($cashPet->isCash)->toBeTrue()
        ->and($cashPet->lastFedTime)->toBe(123);
});

it('sells a following pet by its saved ID, not its newer client position', function (): void {
    $world = UserWorld::query()->create([
        'uid' => '901284',
        'type' => 'farm',
        'sizeX' => 50,
        'sizeY' => 50,
        'objects' => '[]',
        'messageManager' => serialize(['messages' => [], 'allowSendEmails' => true]),
    ]);
    UserMeta::query()->create([
        'uid' => $world->uid,
        'firstName' => 'Pet',
        'lastName' => 'Seller',
    ]);
    $pet = WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 73,
        'class_name' => 'Pet',
        'item_name' => 'husky_puppy_black_cash',
        'position_x' => 30,
        'position_y' => 31,
        'position_z' => 0,
        'deleted' => false,
    ]);
    $other = WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 74,
        'class_name' => 'Decoration',
        'item_name' => 'unrelated_decoration',
        'position_x' => 35,
        'position_y' => 21,
        'position_z' => 0,
        'deleted' => false,
    ]);
    $market = new class {
        public array $soldItems = [];

        public function newTransaction($action, $item, $currency): bool
        {
            $this->soldItems[] = $item->itemName;
            return true;
        }
    };
    $request = (object) ['params' => [
        ACTION_SELL,
        (object) [
            'id' => 73,
            'className' => 'Pet',
            'itemName' => 'husky_puppy_black_cash',
            'position' => (object) ['x' => 35, 'y' => 21, 'z' => 0],
        ],
        [],
    ]];
    $player = new Player($world->uid);

    $first = WorldService::performAction($player, $request, $market);
    $second = WorldService::performAction($player, $request, $market);

    expect($first['id'])->toBe(0)
        ->and($second['id'])->toBeFalse()
        ->and($pet->fresh()->deleted)->toBeTrue()
        ->and($other->fresh()->deleted)->toBeFalse()
        ->and($market->soldItems)->toBe(['husky_puppy_black_cash']);
});

it('feeds the specified owned puppy once per day and consumes exactly one kibble', function (): void {
    $world = UserWorld::query()->create([
        'uid' => '901281',
        'type' => 'farm',
        'sizeX' => 24,
        'sizeY' => 24,
        'objects' => '[]',
        'messageManager' => serialize(['messages' => [], 'allowSendEmails' => true]),
    ]);
    UserMeta::query()->create([
        'uid' => $world->uid,
        'firstName' => 'Pet',
        'lastName' => 'Tester',
    ]);
    Item::query()->create([
        'name' => 'consume_kibble',
        'code' => '0O',
        'data' => serialize(['name' => 'consume_kibble', 'code' => '0O', 'className' => 'CPetsKibble']),
    ]);
    Item::clearCache();

    $placedAt = (int) getCurrentTimeMs() - 90000000;
    $petState = PetState::initial(
        (object) ['petName' => 'Rusty', 'petGender' => 1, 'petType' => '71'],
        ['className' => 'Pet', 'subtype' => 'pets', 'code' => '71'],
        $placedAt,
    );
    $pet = WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 17,
        'class_name' => 'Pet',
        'item_name' => 'bordercollie_puppy_red',
        'position_x' => 4,
        'position_y' => 4,
        'position_z' => 0,
        'plant_time' => $placedAt,
        'components' => (object) ['petState' => $petState],
        'deleted' => false,
    ]);
    PlayerMeta::setValue($world->uid, 'giftbox', serialize(['0O' => [2, [], []]]));

    $request = static fn (int $targetId): object => (object) ['params' => [
        ACTION_USE,
        (object) ['itemName' => 'consume_kibble'],
        [(object) [
            'isGift' => true,
            'isFree' => false,
            'storageId' => GIFTBOX_ID,
            'itemCount' => 1,
            'targetUser' => $world->uid,
            'targetPetId' => $targetId,
        ]],
    ]];

    $player = new Player($world->uid);
    $first = WorldService::performAction($player, $request(17), null);
    $duplicate = WorldService::performAction($player, $request(17), null);
    $missing = WorldService::performAction($player, $request(18), null);

    expect($first['data']['success'])->toBeTrue()
        ->and($first['data']['petFed'])->toBeTrue()
        ->and($duplicate['data']['success'])->toBeFalse()
        ->and($missing['data']['success'])->toBeFalse()
        ->and($pet->fresh()->toFlashObject()->kibbleFedCount)->toBe(1)
        ->and($pet->fresh()->toFlashObject()->petName)->toBe('Rusty');
    $giftbox = unserialize(PlayerMeta::getValue($world->uid, 'giftbox'), ['allowed_classes' => false]);
    expect($giftbox['0O'][0])->toBe(1);
});

it('uses treats for adult pets and advances their original trick levels', function (): void {
    $placedAt = 100000000;
    $adult = PetState::initial(
        (object) ['petName' => 'Honey', 'petGender' => 0, 'petType' => '0a'],
        ['className' => 'Pet', 'subtype' => 'pets', 'code' => '0a', 'market' => 'cash'],
        $placedAt,
    );
    $now = $placedAt + 15 * 86400000;

    expect(PetState::feed(
        (object) ['petState' => $adult],
        'terrier_puppy_honey_cash',
        $placedAt,
        $now,
        'consume_kibble',
    ))->toBeNull();

    $fed = PetState::feed(
        (object) ['petState' => $adult],
        'terrier_puppy_honey_cash',
        $placedAt,
        $now,
        'consume_treat',
    );
    expect($fed->petLevel)->toBe(2)
        ->and($fed->kibbleFedCount)->toBe(0)
        ->and($fed->lastFedTime)->toBe($now);
});

it('persists a legitimate adulthood event without trusting a premature one', function (): void {
    $world = UserWorld::query()->create([
        'uid' => '901282',
        'type' => 'farm',
        'sizeX' => 24,
        'sizeY' => 24,
        'objects' => '[]',
        'messageManager' => serialize(['messages' => [], 'allowSendEmails' => true]),
    ]);
    $placedAt = (int) getCurrentTimeMs() - 15 * 86400000;
    $pet = WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 22,
        'class_name' => 'Pet',
        'item_name' => 'terrier_puppy_honey_cash',
        'position_x' => 5,
        'position_y' => 5,
        'position_z' => 0,
        'plant_time' => $placedAt,
        'components' => (object) ['petState' => PetState::initial(
            (object) ['petName' => 'Honey', 'petGender' => 0, 'petType' => '0a'],
            ['className' => 'Pet', 'subtype' => 'pets', 'code' => '0a', 'market' => 'cash'],
            $placedAt,
        )],
        'deleted' => false,
    ]);
    $result = WorldService::performAction(
        new Player($world->uid),
        (object) ['params' => ['adulthoodReached', (object) ['id' => 22], []]],
        null,
    );

    $premature = $pet->replicate();
    $premature->object_id = 23;
    $premature->plant_time = (int) getCurrentTimeMs();
    $premature->components = (object) ['petState' => PetState::initial(
        (object) ['petName' => 'New puppy', 'petGender' => 0, 'petType' => '0a'],
        ['className' => 'Pet', 'subtype' => 'pets', 'code' => '0a', 'market' => 'cash'],
        (int) $premature->plant_time,
    )];
    $premature->position_x = 7;
    $premature->save();
    $rejected = WorldService::performAction(
        new Player($world->uid),
        (object) ['params' => ['adulthoodReached', (object) ['id' => 23], []]],
        null,
    );

    expect($result['data']['success'])->toBeTrue()
        ->and($pet->fresh()->toFlashObject()->petLevel)->toBe(1)
        ->and($pet->fresh()->toFlashObject()->kibbleFedCount)->toBe(0)
        ->and($rejected['data']['success'])->toBeFalse()
        ->and($premature->fresh()->toFlashObject()->petLevel)->toBe(0);
});

it('runs away only when overdue and rescues once for the catalog cash cost', function (): void {
    $world = UserWorld::query()->create([
        'uid' => '901283', 'type' => 'farm', 'sizeX' => 24, 'sizeY' => 24,
        'objects' => '[]', 'messageManager' => serialize(['messages' => []]),
    ]);
    UserMeta::query()->create([
        'uid' => $world->uid, 'firstName' => 'Pet', 'lastName' => 'Tester', 'cash' => 4,
    ]);
    Item::query()->create([
        'name' => 'pet_rescue', 'code' => '10',
        'data' => serialize(['name' => 'pet_rescue', 'code' => '10', 'cash' => 2, 'market' => 'cash']),
    ]);
    Item::clearCache();

    $placedAt = (int) getCurrentTimeMs() - 3 * 86400000;
    $state = PetState::initial(
        (object) ['petName' => 'Rusty', 'petGender' => 1, 'petType' => '71'],
        ['className' => 'Pet', 'subtype' => 'pets', 'code' => '71'], $placedAt,
    );
    $cashPuppy = PetState::initial(
        (object) ['petName' => 'Cash', 'petGender' => 0, 'petType' => '72'],
        ['className' => 'Pet', 'subtype' => 'pets', 'code' => '72', 'market' => 'cash'], $placedAt,
    );
    expect(PetState::runaway((object) ['petState' => $cashPuppy], 'bordercollie_puppy_cash', $placedAt, (int) getCurrentTimeMs()))->toBeNull();
    $pet = WorldObject::query()->create([
        'world_id' => $world->id, 'object_id' => 30, 'class_name' => 'Pet',
        'item_name' => 'bordercollie_puppy_red', 'position_x' => 2, 'position_y' => 2,
        'position_z' => 0, 'plant_time' => $placedAt,
        'components' => (object) ['petState' => $state], 'deleted' => false,
    ]);
    $player = new Player($world->uid);
    $action = static fn (string $name, int $id = 30): array => WorldService::performAction(
        $player, (object) ['params' => [$name, (object) ['id' => $id], []]], null,
    );

    expect($action('rescuePet')['data']['success'])->toBeFalse()
        ->and((int) UserMeta::query()->where('uid', $world->uid)->value('cash'))->toBe(4);
    expect($action('runaway')['data']['success'])->toBeTrue()
        ->and($pet->fresh()->toFlashObject()->isRunAway)->toBeTrue();
    expect($action('rescuePet')['data']['success'])->toBeTrue()
        ->and($pet->fresh()->toFlashObject()->isRunAway)->toBeFalse()
        ->and($pet->fresh()->state)->toBe('sit')
        ->and((int) UserMeta::query()->where('uid', $world->uid)->value('cash'))->toBe(2);
    expect($action('rescuePet')['data']['success'])->toBeFalse()
        ->and($action('runaway')['data']['success'])->toBeFalse()
        ->and((int) UserMeta::query()->where('uid', $world->uid)->value('cash'))->toBe(2);

    WorldObject::query()->create([
        'world_id' => $world->id, 'object_id' => 32, 'class_name' => 'Pet',
        'item_name' => 'bordercollie_puppy_red', 'position_x' => 5, 'position_y' => 5,
        'position_z' => 0, 'plant_time' => $placedAt,
        'components' => (object) ['petState' => $state], 'deleted' => false,
    ]);
    UserMeta::query()->where('uid', $world->uid)->update(['cash' => 0]);
    expect($action('runaway', 32)['data']['success'])->toBeTrue()
        ->and($action('rescuePet', 32)['data']['success'])->toBeFalse()
        ->and(WorldObject::query()->where('world_id', $world->id)->where('object_id', 32)->firstOrFail()->toFlashObject()->isRunAway)->toBeTrue();
});

it('grants one fetch item per day only for an owned level-five fetch pet', function (): void {
    $world = UserWorld::query()->create([
        'uid' => '901284', 'type' => 'farm', 'sizeX' => 24, 'sizeY' => 24,
        'objects' => '[]', 'messageManager' => serialize(['messages' => []]),
    ]);
    UserMeta::query()->create(['uid' => $world->uid, 'firstName' => 'Pet', 'lastName' => 'Tester']);
    Item::query()->create([
        'name' => 'consume_kibble', 'code' => '0O',
        'data' => serialize(['name' => 'consume_kibble', 'code' => '0O', 'className' => 'CPetsKibble']),
    ]);
    Item::clearCache();
    $placedAt = (int) getCurrentTimeMs() - 30 * 86400000;
    $state = PetState::initial(
        (object) ['petName' => 'Goldie', 'petGender' => 0, 'petType' => '71'],
        ['className' => 'Pet', 'subtype' => 'pets', 'code' => '71', 'market' => 'cash'], $placedAt,
    );
    $state->petLevel = 5;
    $pet = WorldObject::query()->create([
        'world_id' => $world->id, 'object_id' => 31, 'class_name' => 'Pet',
        'item_name' => 'goldenretriever_puppy_cash', 'position_x' => 2,
        'position_y' => 2, 'position_z' => 0, 'plant_time' => $placedAt,
        'components' => (object) ['petState' => $state], 'deleted' => false,
    ]);
    $player = new Player($world->uid);
    $request = static fn (int $id, string $name = 'fetch'): array => WorldService::performAction(
        $player,
        (object) ['params' => ['performTrick', (object) ['id' => $id], [(object) ['trickName' => $name, 'harvestObjectIds' => null]]]],
        null,
    );

    expect($request(31)['data'])->toMatchArray(['fetchStatus' => true, 'fetchItem' => 'consume_kibble'])
        ->and($request(31)['data']['fetchStatus'])->toBeFalse()
        ->and($request(99)['data']['fetchStatus'])->toBeFalse()
        ->and($request(31, 'harvest')['data']['harvestLimitedAnimalStatus'])->toBeFalse();
    $giftbox = unserialize(PlayerMeta::getValue($world->uid, 'giftbox'), ['allowed_classes' => false]);
    expect($giftbox['0O'][0])->toBe(1)
        ->and($pet->fresh()->toFlashObject()->lastTrickAt)->toBeGreaterThan(0);
});

it('accepts a valid collie animal-harvest trick without minting a second reward', function (): void {
    $world = UserWorld::query()->create([
        'uid' => '901285', 'type' => 'farm', 'sizeX' => 24, 'sizeY' => 24,
        'objects' => '[]', 'messageManager' => serialize(['messages' => []]),
    ]);
    UserMeta::query()->create([
        'uid' => $world->uid, 'firstName' => 'Pet', 'lastName' => 'Tester', 'gold' => 100,
    ]);
    $placedAt = (int) getCurrentTimeMs() - 30 * 86400000;
    $state = PetState::initial(
        (object) ['petName' => 'Rusty', 'petGender' => 1, 'petType' => '71'],
        ['className' => 'Pet', 'subtype' => 'pets', 'code' => '71'], $placedAt,
    );
    $state->petLevel = 5;
    WorldObject::query()->create([
        'world_id' => $world->id, 'object_id' => 40, 'class_name' => 'Pet',
        'item_name' => 'bordercollie_puppy_red', 'position_x' => 2,
        'position_y' => 2, 'position_z' => 0, 'plant_time' => $placedAt,
        'components' => (object) ['petState' => $state], 'deleted' => false,
    ]);
    WorldObject::query()->create([
        'world_id' => $world->id, 'object_id' => 41, 'class_name' => 'Animal',
        'item_name' => 'duck', 'position_x' => 3, 'position_y' => 3,
        'position_z' => 0, 'deleted' => false,
    ]);
    $player = new Player($world->uid);
    $request = static fn (array $ids): array => WorldService::performAction(
        $player,
        (object) ['params' => ['performTrick', (object) ['id' => 40], [(object) ['trickName' => 'harvest', 'harvestObjectIds' => $ids]]]],
        null,
    );

    expect($request([999])['data']['harvestLimitedAnimalStatus'])->toBeFalse()
        ->and($request([41])['data']['harvestLimitedAnimalStatus'])->toBeTrue()
        ->and($request([41])['data']['harvestLimitedAnimalStatus'])->toBeFalse()
        ->and((int) UserMeta::query()->where('uid', $world->uid)->value('gold'))->toBe(100);
});
