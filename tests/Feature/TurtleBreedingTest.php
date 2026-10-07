<?php

use App\Models\Item;
use App\Models\PlayerMeta;
use App\Models\User;
use App\Models\UserWorld;
use App\Models\WorldObject;

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';
    require_once AMFPHP_ROOTPATH.'Helpers/mutable_animal_completion.php';
    require_once AMFPHP_ROOTPATH.'Functions/AnimalBreedingService.php';
    PlayerMeta::clearCache();

    foreach ([
        ['turtle_baby', '1ka', 'MutableAnimalBaby', 10],
        ['turtle_male', '03f', 'MutableAnimal', 0],
        ['turtle_marron_red_dots', '03g', 'MutableAnimal', 0],
        ['turtle_olive_emerald_dots', '03i', 'MutableAnimal', 0],
        ['sheeppen_ewe', 'Sh', 'MutableAnimal', 0],
        ['bottle', 'B8', 'Consumable', 0],
        ['xuk_animal_love_potion', 'gk', 'CAnimalLovePotion', 0],
    ] as [$name, $code, $className, $matsNeeded]) {
        Item::query()->create([
            'name' => $name,
            'code' => $code,
            'data' => serialize([
                'name' => $name,
                'code' => $code,
                'className' => $className,
                'matsNeeded' => $matsNeeded,
                'cash' => 1,
            ]),
        ]);
    }
    Item::clearCache();
});

function turtleTestParent(string $hue): array
{
    return [
        'N' => '',
        'G' => 'M',
        'B' => ['H' => [$hue, $hue], 'S' => ['8', '8'], 'V' => ['e', 'e']],
        'P' => ['T' => ['b'], 'H' => ['20', '20'], 'S' => ['8', '8'], 'V' => ['e', 'e']],
    ];
}

function turtleTestPen(string $uid): array
{
    $world = UserWorld::query()->create([
        'uid' => $uid,
        'type' => 'farm',
        'sizeX' => 12,
        'sizeY' => 12,
        'messageManager' => '',
    ]);
    $first = turtleTestParent('10');
    $second = turtleTestParent('30');
    $firstHash = '03g:abc12345';
    $secondHash = '03i:def67890';
    // The storage key is authoritative when the legacy client's exact hash
    // representation differs from the server's normalized DNA encoding.
    $pen = WorldObject::query()->create([
        'world_id' => $world->id,
        'object_id' => 1207,
        'class_name' => 'FeatureBuilding',
        'item_name' => 'turtlepen_finished',
        'position_x' => 1,
        'position_y' => 1,
        'position_z' => 0,
        'state' => 'bare',
        'deleted' => false,
        'contents' => [
            (object) ['itemCode' => '03g', 'numItem' => 1],
            (object) ['itemCode' => '03i', 'numItem' => 1],
        ],
        'components' => (object) [
            'storageMetadata' => (object) [
                $firstHash => [json_encode($first)],
                $secondHash => [json_encode($second)],
            ],
        ],
    ]);

    return [$pen, [$firstHash, $secondHash]];
}

function turtleTestRequest(int $buildingId, array $hashes, int $potions, int $slot = 0): object
{
    return (object) ['params' => [(object) [
        'buildingId' => $buildingId,
        'suiteSlot' => $slot,
        'breedObjs' => array_map(static fn (string $hash) => (object) ['hash' => $hash], $hashes),
        'numPotions' => $potions,
        'patternGuarantee' => false,
    ]]];
}

it('begins an instant turtle breed once, awards a DNA-backed baby, and grows it into an adult', function (): void {
    $user = User::factory()->create();
    $uid = (string) $user->uid;
    [$pen, $hashes] = turtleTestPen($uid);
    PlayerMeta::setValue($uid, 'giftbox', serialize(['gk' => [10, [], []]]));
    $player = new class ($uid) {
        public function __construct(private string $uid) {}
        public function getUid(): string { return $this->uid; }
    };
    $request = turtleTestRequest($pen->object_id, $hashes, 5);

    $begin = AnimalBreedingService::onBeginBreeding($player, $request);
    expect($begin['data']['extraDataState']->breedingQueue[0]->numPotions)->toBe(5)
        ->and(getGiftBox($uid)['gk'][0])->toBe(5);
    expect(AnimalBreedingService::onBeginBreeding($player, $request)['data']['success'])->toBeFalse();
    expect(getGiftBox($uid)['gk'][0])->toBe(5);

    $finish = AnimalBreedingService::onFinishBreeding($player, $request);
    expect($finish['data']['success'])->toBeTrue()
        ->and($finish['data']['reward']->itemName)->toBe('turtle_baby');
    PlayerMeta::clearCache();
    $giftbox = getGiftBox($uid);
    expect($giftbox['1ka'][0])->toBe(1)
        ->and($giftbox['1ka'][2])->toHaveCount(1);
    expect(AnimalBreedingService::onFinishBreeding($player, $request)['data']['success'])->toBeFalse();
    expect(getGiftBox($uid)['1ka'][0])->toBe(1);

    $dna = json_decode($finish['data']['reward']->mutableState);
    $baby = new WorldObject();
    $baby->item_name = 'turtle_baby';
    $baby->class_name = 'MutableAnimalBaby';
    $baby->contents = [(object) ['itemCode' => 'B8', 'numItem' => 10]];
    $baby->components = (object) ['mutableAnimalState' => (object) ['dna' => $dna]];
    $adult = MutableAnimalCompletion::forBaby($baby, null, true);
    expect($adult['finishedName'])->toBe('turtle_male')
        ->and($adult['finishedClassName'])->toBe('MutableAnimal')
        ->and($adult['mutableAnimalState']->dna->B)->toEqual($dna->B);
});

it('keeps a second breeding slot after finishing the first one', function (): void {
    $user = User::factory()->create();
    $uid = (string) $user->uid;
    [$pen, $firstHashes] = turtleTestPen($uid);
    $secondHashes = ['03g:third123', '03i:fourth45'];
    $contents = $pen->contents;
    $contents[0]->numItem = 2;
    $contents[1]->numItem = 2;
    $pen->contents = $contents;
    $components = $pen->components;
    $components->storageMetadata->{$secondHashes[0]} = [json_encode(turtleTestParent('50'))];
    $components->storageMetadata->{$secondHashes[1]} = [json_encode(turtleTestParent('70'))];
    $pen->components = $components;
    $pen->save();
    PlayerMeta::setValue($uid, 'giftbox', serialize(['gk' => [20, [], []]]));
    $player = new class ($uid) {
        public function __construct(private string $uid) {}
        public function getUid(): string { return $this->uid; }
    };
    $first = turtleTestRequest($pen->object_id, $firstHashes, 5, 0);
    $second = turtleTestRequest($pen->object_id, $secondHashes, 5, 1);

    expect(AnimalBreedingService::onBeginBreeding($player, $first)['data']['extraDataState']->breedingQueue)->toHaveCount(1);
    expect(AnimalBreedingService::onBeginBreeding($player, $second)['data']['extraDataState']->breedingQueue)->toHaveCount(2);
    expect(AnimalBreedingService::onFinishBreeding($player, $first)['data']['success'])->toBeTrue();
    $remaining = $pen->fresh()->components->extraDataState->breedingQueue;
    expect($remaining)->toBeArray()
        ->toHaveCount(1)
        ->and($remaining[0]->suiteSlot)->toBe(1);
    expect(AnimalBreedingService::onBeginBreeding($player, $second)['data']['success'])->toBeFalse()
        ->and(getGiftBox($uid)['gk'][0])->toBe(10);
    expect(AnimalBreedingService::onFinishBreeding($player, $second)['data']['success'])->toBeTrue()
        ->and(getGiftBox($uid)['1ka'][0])->toBe(2)
        ->and(getGiftBox($uid)['gk'][0])->toBe(10);
});

it('can finish a legacy sparse breeding queue saved as a JSON object', function (): void {
    $user = User::factory()->create();
    $uid = (string) $user->uid;
    [$pen, $hashes] = turtleTestPen($uid);
    PlayerMeta::setValue($uid, 'giftbox', serialize(['gk' => [10, [], []]]));
    $player = new class ($uid) {
        public function __construct(private string $uid) {}
        public function getUid(): string { return $this->uid; }
    };
    $request = turtleTestRequest($pen->object_id, $hashes, 5, 1);

    expect(AnimalBreedingService::onBeginBreeding($player, $request)['data']['extraDataState']->breedingQueue)->toHaveCount(1);
    $components = $pen->fresh()->components;
    $components->extraDataState->breedingQueue = (object) ['1' => $components->extraDataState->breedingQueue[0]];
    $pen->components = $components;
    $pen->save();

    expect(AnimalBreedingService::onBeginBreeding($player, $request)['data']['success'])->toBeFalse()
        ->and(getGiftBox($uid)['gk'][0])->toBe(5);
    expect(AnimalBreedingService::onFinishBreeding($player, $request)['data']['success'])->toBeTrue()
        ->and(getGiftBox($uid)['1ka'][0])->toBe(1);
});

it('does not spend potions when the turtle pen cannot start breeding', function (): void {
    $user = User::factory()->create();
    $uid = (string) $user->uid;
    [$pen, $hashes] = turtleTestPen($uid);
    PlayerMeta::setValue($uid, 'giftbox', serialize(['gk' => [4, [], []]]));
    $player = new class ($uid) {
        public function __construct(private string $uid) {}
        public function getUid(): string { return $this->uid; }
    };

    $result = AnimalBreedingService::onBeginBreeding($player, turtleTestRequest($pen->object_id, $hashes, 5));
    expect($result['data']['success'])->toBeFalse()
        ->and(getGiftBox($uid)['gk'][0])->toBe(4)
        ->and($pen->fresh()->components->extraDataState ?? null)->toBeNull();
});

it('rejects a non-turtle stored in a turtle pen without spending potions', function (): void {
    $user = User::factory()->create();
    $uid = (string) $user->uid;
    [$pen, $hashes] = turtleTestPen($uid);
    $contents = $pen->contents;
    $contents[1]->itemCode = 'Sh';
    $pen->contents = $contents;
    $components = $pen->components;
    $components->storageMetadata->{'Sh:def67890'} = $components->storageMetadata->{$hashes[1]};
    unset($components->storageMetadata->{$hashes[1]});
    $pen->components = $components;
    $pen->save();
    $hashes[1] = 'Sh:def67890';
    PlayerMeta::setValue($uid, 'giftbox', serialize(['gk' => [10, [], []]]));
    $player = new class ($uid) {
        public function __construct(private string $uid) {}
        public function getUid(): string { return $this->uid; }
    };

    $result = AnimalBreedingService::onBeginBreeding($player, turtleTestRequest($pen->object_id, $hashes, 5));
    expect($result['data']['success'])->toBeFalse()
        ->and(getGiftBox($uid)['gk'][0])->toBe(10);
});
