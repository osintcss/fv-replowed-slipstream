<?php

use App\Models\Item;
use App\Models\PlayerMeta;
use App\Models\UserMeta;
use App\Models\UserWorld;
use App\Models\WorldObject;

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }
    require_once AMFPHP_ROOTPATH.'Functions/EquipmentWorldService.php';
    PlayerMeta::clearCache();
    Item::clearCache();

    $this->cropUid = '900020';
    $this->cropWorld = UserWorld::query()->create([
        'uid' => $this->cropUid, 'type' => 'farm', 'sizeX' => 144, 'sizeY' => 144,
        'objects' => '[]', 'messageManager' => serialize(['messages' => []]),
    ]);
    UserMeta::query()->create([
        'uid' => $this->cropUid, 'firstName' => 'Harvest', 'lastName' => 'Fixture',
        'gold' => 100000, 'xp' => 0, 'cash' => 0, 'energy' => 1000, 'energyMax' => 1000,
    ]);
    PlayerMeta::setValue($this->cropUid, 'currentWorldType', 'farm');
    invalidateWorldCache($this->cropUid, 'farm');
    $this->cropData = [
        'name' => 'equipment_mastery_crop', 'code' => 'EMC1', 'type' => 'seed',
        'className' => 'Plot', 'cost' => 1, 'coinYield' => 2, 'growTime' => 1,
        'masteryLevel' => [['count' => 70], ['count' => 670], ['count' => 1870]],
    ];
    Item::query()->create([
        'name' => $this->cropData['name'], 'code' => $this->cropData['code'],
        'data' => serialize($this->cropData),
    ]);
    $this->cropPlayer = new class ($this->cropUid) {
        public function __construct(private readonly string $uid) {}
        public function getUid(): string { return $this->uid; }
    };
});

function equipmentHarvestPlot(UserWorld $world, int $id, int $x, int $y): WorldObject
{
    return WorldObject::query()->create([
        'world_id' => $world->id, 'object_id' => $id, 'class_name' => 'Plot',
        'item_name' => 'equipment_mastery_crop', 'state' => PLOT_STATE_GROWN,
        'position_x' => $x, 'position_y' => $y, 'position_z' => 0,
        'plant_time' => 1, 'deleted' => false,
    ]);
}

function equipmentHarvestPayload(WorldObject $plot): object
{
    return (object) [
        'id' => $plot->object_id,
        'position' => (object) ['x' => $plot->position_x, 'y' => $plot->position_y, 'z' => 0],
    ];
}

it('credits all 436 crops harvested and replanted in two combine batches', function (): void {
    $plots = [];
    for ($i = 0; $i < 436; $i++) {
        $plots[] = equipmentHarvestPayload(equipmentHarvestPlot(
            $this->cropWorld, $i + 1, ($i % 25) * 4, intdiv($i, 25) * 4,
        ));
    }
    $expectedMasteryCount = 0;
    foreach (array_chunk($plots, 300) as $batch) {
        $result = EquipmentWorldService::onUseEquipment($this->cropPlayer, (object) ['params' => [
            ACTION_COMBINE, (object) [], $batch, $this->cropData['name'],
        ]], null);
        expect(array_filter($result['data']['harvest']['data']))->toHaveCount(count($batch));
        $expectedMasteryCount += count($batch);
        expect($result['data']['harvest']['data'][0]['goalCounters'])->toBe([
            ['type' => 'Mastery', 'code' => 'EMC1', 'count' => $expectedMasteryCount],
        ]);
    }
    PlayerMeta::clearCache();
    expect(getMasteryData($this->cropUid)['masteryCounters']['EMC1'])->toBe(436)
        ->and(getMasteryData($this->cropUid)['mastery']['EMC1'])->toBe(0)
        ->and(WorldObject::query()->where('world_id', $this->cropWorld->id)->where('state', PLOT_STATE_PLANTED)->count())->toBe(436);

    // The same immature crops must not grant a second harvest or mastery credit.
    $replay = EquipmentWorldService::onUseEquipment($this->cropPlayer, (object) ['params' => [
        ACTION_COMBINE, (object) [], $plots, $this->cropData['name'],
    ]], null);
    expect(array_filter($replay['data']['harvest']['data']))->toBe([])
        ->and(getMasteryData($this->cropUid)['masteryCounters']['EMC1'])->toBe(436);
});

it('caps mastery bonuses per crop before multiplying the harvest count', function (): void {
    for ($i = 0; $i < 6; $i++) {
        $buffName = 'equipment_mastery_buff_'.$i;
        $decoName = 'equipment_mastery_deco_'.$i;
        Item::query()->create(['name' => $buffName, 'code' => 'EMB'.$i, 'data' => serialize([
            'masteryTypes' => ['masteryType' => [['type' => 'seed']]],
        ])]);
        Item::query()->create(['name' => $decoName, 'code' => 'EMD'.$i, 'data' => serialize([
            'className' => 'PermanentBuffDecoration', 'buff' => $buffName,
        ])]);
        equipmentHarvestPlot($this->cropWorld, $i + 1, $i, 0)->update([
            'class_name' => 'PermanentBuffDecoration', 'item_name' => $decoName,
        ]);
    }
    processMastery($this->cropUid, $this->cropData, 12);
    expect(getMasteryData($this->cropUid)['masteryCounters']['EMC1'])->toBe(60);
    processMastery($this->cropUid, $this->cropData, 0);
    processMastery($this->cropUid, $this->cropData, -1);
    expect(getMasteryData($this->cropUid)['masteryCounters']['EMC1'])->toBe(60);
    processMastery($this->cropUid, $this->cropData, 1);
    expect(getMasteryData($this->cropUid)['masteryCounters']['EMC1'])->toBe(65);
});

it('harvests the requested object when two crops share coordinates', function (string $action): void {
    $first = equipmentHarvestPlot($this->cropWorld, 1, 4, 8);
    $overlap = equipmentHarvestPlot($this->cropWorld, 2, 4, 8);
    $other = equipmentHarvestPlot($this->cropWorld, 3, 12, 8);
    $result = EquipmentWorldService::onUseEquipment($this->cropPlayer, (object) ['params' => [
        $action, (object) [], [equipmentHarvestPayload($first), equipmentHarvestPayload($other)], $this->cropData['name'],
    ]], null);
    $results = $action === ACTION_COMBINE ? $result['data']['harvest']['data'] : $result['data'];
    expect(array_column($results, 'id'))->toBe([1, 3])
        ->and($overlap->fresh()->state)->toBe(PLOT_STATE_GROWN)
        ->and($first->fresh()->state)->toBe($action === ACTION_COMBINE ? PLOT_STATE_PLANTED : PLOT_STATE_FALLOW)
        ->and($other->fresh()->state)->toBe($first->fresh()->state)
        ->and($results[0]['goalCounters'])->toBe([
            ['type' => 'Mastery', 'code' => 'EMC1', 'count' => 2],
        ])
        ->and(getMasteryData($this->cropUid)['masteryCounters']['EMC1'])->toBe(2);
})->with(['harvest', 'combine']);

it('returns the updated absolute mastery counter for an ordinary harvest', function (): void {
    $result = (new MarketTransactions($this->cropUid))->harvestCrop((object) [
        'itemName' => $this->cropData['name'],
    ]);

    expect($result['goalCounters'])->toBe([
        ['type' => 'Mastery', 'code' => 'EMC1', 'count' => 1],
    ]);
});

it('harvests each overlapping object once even when a bundle repeats an ID', function (): void {
    $first = equipmentHarvestPlot($this->cropWorld, 1, 4, 8);
    $second = equipmentHarvestPlot($this->cropWorld, 2, 4, 8);
    $result = EquipmentWorldService::onUseEquipment($this->cropPlayer, (object) ['params' => [
        ACTION_HARVEST, (object) [], [equipmentHarvestPayload($first), equipmentHarvestPayload($second), equipmentHarvestPayload($first)], null,
    ]], null);
    expect($result['data'])->toBe([
        [
            'id' => 1,
            'data' => ['id' => 1],
            'goalCounters' => [['type' => 'Mastery', 'code' => 'EMC1', 'count' => 2]],
        ],
        [
            'id' => 2,
            'data' => ['id' => 2],
            'goalCounters' => [['type' => 'Mastery', 'code' => 'EMC1', 'count' => 2]],
        ],
        null,
    ])->and(getMasteryData($this->cropUid)['masteryCounters']['EMC1'])->toBe(2);
});

it('rejects an unknown or mismatched ID and ambiguous position-only targets', function (): void {
    $first = equipmentHarvestPlot($this->cropWorld, 1, 4, 8);
    equipmentHarvestPlot($this->cropWorld, 2, 4, 8);
    $other = equipmentHarvestPlot($this->cropWorld, 3, 12, 8);
    $payload = equipmentHarvestPayload($first);
    $unknown = clone $payload;
    $unknown->id = 999;
    $mismatch = clone $payload;
    $mismatch->id = 3;
    unset($payload->id);
    $unique = equipmentHarvestPayload($other);
    unset($unique->id);
    $result = EquipmentWorldService::onUseEquipment($this->cropPlayer, (object) ['params' => [
        ACTION_HARVEST, (object) [], [$unknown, $mismatch, $payload, $unique], null,
    ]], null);
    expect($result['data'])->toBe([
        null,
        null,
        null,
        [
            'id' => 3,
            'data' => ['id' => 3],
            'goalCounters' => [['type' => 'Mastery', 'code' => 'EMC1', 'count' => 1]],
        ],
    ])
        ->and($first->fresh()->state)->toBe(PLOT_STATE_GROWN)
        ->and(getMasteryData($this->cropUid)['masteryCounters']['EMC1'])->toBe(1);
});

it('does not award mastery when a stale equipment batch rolls back', function (): void {
    $first = equipmentHarvestPlot($this->cropWorld, 1, 4, 8);
    $missing = equipmentHarvestPlot($this->cropWorld, 2, 12, 8);
    getWorldByType($this->cropUid, 'farm');
    $missing->delete();
    $result = EquipmentWorldService::onUseEquipment($this->cropPlayer, (object) ['params' => [
        ACTION_HARVEST, (object) [], [equipmentHarvestPayload($first), equipmentHarvestPayload($missing)], null,
    ]], null);
    expect($result['data'])->toBe([null, null])
        ->and($first->fresh()->state)->toBe(PLOT_STATE_GROWN)
        ->and(getMasteryData($this->cropUid)['masteryCounters'])->toBe([]);
});
