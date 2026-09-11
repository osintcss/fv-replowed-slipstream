<?php

use App\Models\Item;
use App\Models\PlayerMeta;
use App\Models\UserMeta;
use App\Models\WorldObject;
use App\Helpers\JsonHelper;

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Functions/UGCDecoService.php';
    require_once AMFPHP_ROOTPATH.'Functions/UGCItemService.php';

    PlayerMeta::clearCache();
    Item::clearCache();
});

function ugcTestPlayer(int $uid): object
{
    return new class($uid)
    {
        public function __construct(private int $uid)
        {
        }

        public function getUid(): int
        {
            return $this->uid;
        }
    };
}

function ugcTestRequest(array $params): object
{
    return (object) ['params' => $params];
}

function ugcTestItem(string $name, string $code, array $data): void
{
    Item::query()->create([
        'name' => $name,
        'code' => $code,
        'data' => serialize($data),
    ]);
}

it('persists the selected blueprint through the Flash feature-options contract', function (): void {
    $uid = 710001;
    UserMeta::query()->create([
        'uid' => $uid,
        'firstName' => 'UGC',
        'lastName' => 'Tester',
    ]);
    ugcTestItem('xhw_ugc_deco_spookyshack', '7DV', [
        'className' => 'UGCDecoration',
        'gameSettingsFeatureName' => 'xhw_UGCDeco',
    ]);

    $response = UGCDecoService::setLastEditedBlueprint(
        ugcTestPlayer($uid),
        ugcTestRequest(['xhw_UGCDeco', 'xhw_ugc_deco_spookyshack']),
    );

    expect($response['data'])->toBeArray()
        ->and($response['metadata']['FeatureOptions']['UGCDeco']['UGCDeco']['ugc_lastEdited']['xhw_UGCDeco'])
        ->toBe('xhw_ugc_deco_spookyshack')
        ->and(ugcLoadFeatureData($uid)['ugc_lastEdited']['xhw_UGCDeco'])
        ->toBe('xhw_ugc_deco_spookyshack');
});

it('resolves a UGC blueprint when its legacy code collides by case', function (): void {
    ugcTestItem('legacy-seven-dv', '7dv', [
        'className' => 'Decoration',
    ]);
    ugcTestItem('xhw_ugc_deco_spookyshack', '7DV', [
        'className' => 'UGCDecoration',
    ]);

    expect(ugcItemRecordByCode('7DV')['name'])
        ->toBe('xhw_ugc_deco_spookyshack');
});

it('creates a predefined UGC building state and consumes its materials', function (): void {
    $uid = 710002;
    UserMeta::query()->create([
        'uid' => $uid,
        'firstName' => 'UGC',
        'lastName' => 'Builder',
        'cash' => 100,
    ]);
    ugcTestItem('xhw_ugc_deco_spookyshack', '7DV', [
        'className' => 'UGCDecoration',
        'gameSettingsFeatureName' => 'xhw_UGCDeco',
        'materialPrice' => [
            'material' => [
                ['name' => 'xhw_ugc_deco_darkwood', 'quantity' => '9'],
                ['name' => 'xhw_ugc_deco_slime', 'quantity' => '8'],
                ['name' => 'xhw_ugc_deco_cobweb', 'quantity' => '0'],
                ['name' => 'xhw_ugc_deco_decorator', 'quantity' => '8'],
            ],
        ],
    ]);
    ugcSaveFeatureData($uid, [
        'ugc_materials' => [
            'xhw_ugc_deco_darkwood' => 9,
            'xhw_ugc_deco_slime' => 9,
            'xhw_ugc_deco_decorator' => 8,
        ],
        'ugc_completed' => [],
    ]);

    $response = UGCDecoService::purchaseUGCDecoration(
        ugcTestPlayer($uid),
        ugcTestRequest([(object) ['I' => '7DV'], 0]),
    );

    expect($response)->not->toHaveKey('data');

    $state = $response['ugcItemState'];
    expect($state['I'])->toBe('7DV')
        ->and($state['N'])->toBe('xhw_ugc_deco_spookyshack')
        ->and($state['U'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i')
        ->and($response['featureData']['ugc_materials']['xhw_ugc_deco_darkwood'])->toBe(0)
        ->and($response['featureData']['ugc_materials']['xhw_ugc_deco_slime'])->toBe(1)
        ->and($response['featureData']['ugc_materials']['xhw_ugc_deco_decorator'])->toBe(0)
        ->and($response['featureData']['ugc_completed']['xhw_ugc_deco_spookyshack'])->toBeTrue()
        ->and($response['storageData'][GIFTBOX_STORAGE_KEY]['7DV'][0])->toBe(1)
        ->and($response['storageData'][GIFTBOX_STORAGE_KEY]['7DV'][2][0])->toBe($state['U'])
        ->and(getGiftBox($uid)['7DV'][2][0])->toBe($state['U'])
        ->and(ugcGetStateByUuid($uid, $state['U'])['I'])->toBe('7DV');
});

it('purchases one UGC material using the catalog cash price', function (): void {
    $uid = 710003;
    UserMeta::query()->create([
        'uid' => $uid,
        'firstName' => 'UGC',
        'lastName' => 'Materials',
        'cash' => 10,
    ]);
    ugcTestItem('xhw_ugc_deco_slime', '8ON', [
        'className' => 'ACUGCDecorationMaterial',
        'cash' => '4',
    ]);

    $response = UGCDecoService::purchasePart(
        ugcTestPlayer($uid),
        ugcTestRequest(['xhw_ugc_deco_slime', 1]),
    );

    expect($response['metadata']['FeatureOptions']['UGCDeco']['UGCDeco']['ugc_materials']['xhw_ugc_deco_slime'])
        ->toBe(1)
        ->and(UserMeta::query()->where('uid', $uid)->value('cash'))
        ->toBe(6);
});

it('round-trips a UGC UUID through world-object persistence', function (): void {
    $uuid = '12345678-1234-4234-8234-123456789abc';
    $flashObject = (object) [
        'id' => 9,
        'className' => 'UGCDecoration',
        'itemName' => 'xhw_ugc_deco_spookyshack',
        'position' => (object) ['x' => 4, 'y' => 5, 'z' => 0],
        'state' => 'built',
        'ugcItemUUID' => $uuid,
    ];

    $data = WorldObject::fromFlashObject($flashObject, 1);
    $components = JsonHelper::safeDecode($data['components']);
    $object = new WorldObject(array_merge($data, ['components' => $components]));
    $roundTrip = $object->toFlashObject();

    expect($components->ugcItemUUID)->toBe($uuid)
        ->and($roundTrip->ugcItemUUID)->toBe($uuid);
});

it('persists temporary UGC editor state by item code without creating a UUID', function (): void {
    $uid = 710004;
    ugcTestItem('xhw_ugc_deco_spookyshack', '7DV', [
        'className' => 'UGCDecoration',
    ]);

    $response = UGCItemService::saveTemporaryUGCState(
        ugcTestPlayer($uid),
        ugcTestRequest([[
            'I' => '7DV',
            'N' => 'xhw_ugc_deco_spookyshack',
            'D' => [
                'base' => ['I' => '7DV'],
            ],
        ]]),
    );

    expect($response['data'])->toBeArray()
        ->and(ugcLoadItemData($uid)['7DV']['I'])->toBe('7DV')
        ->and(ugcLoadItemData($uid)['7DV']['U'])->toBeNull();
});
