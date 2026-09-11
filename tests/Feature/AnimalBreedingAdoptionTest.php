<?php

use App\Models\PlayerMeta;
use App\Models\User;

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';
    require_once AMFPHP_ROOTPATH.'Functions/AnimalBreedingService.php';

    PlayerMeta::clearCache();
});

function adoptionTestDna(string $gender, string $hue): array
{
    return [
        'N' => 'Piggy',
        'U' => '',
        'G' => $gender,
        'B' => ['H' => [$hue, $hue], 'S' => ['8', '8'], 'V' => ['e', 'e']],
        'P' => ['H' => ['0', '0'], 'S' => ['8', '8'], 'V' => ['8', '8'], 'T' => ['a']],
    ];
}

function adoptionTestHash(array $dna): string
{
    $method = new ReflectionMethod(AnimalBreedingService::class, 'mutableStateHash');
    $method->setAccessible(true);

    return $method->invoke(null, $dna);
}

function adoptionTestPlayer(string $uid): object
{
    return new class ($uid) {
        public function __construct(private readonly string $uid) {}

        public function getUid(): string
        {
            return $this->uid;
        }
    };
}

it('adopts out only the Giftbox animal identified by its DNA hash', function (): void {
    $user = User::factory()->create();
    $uid = (string) $user->uid;
    $first = adoptionTestDna('F', 'd9');
    $second = adoptionTestDna('M', 'e0');
    $first['U'] = $uid;
    $second['U'] = $uid;
    PlayerMeta::setValue($uid, 'giftbox', serialize([
        'J!' => [2, [$uid, $uid], [json_encode($first), json_encode($second)]],
    ]));

    $request = (object) ['params' => ['J!', adoptionTestHash($second)]];
    $result = AnimalBreedingService::onGiveUpForAdoption(adoptionTestPlayer($uid), $request, null);

    expect($result['data']['success'])->toBeTrue();
    $giftbox = unserialize(PlayerMeta::getValue($uid, 'giftbox'), ['allowed_classes' => false]);
    expect($giftbox['J!'][0])->toBe(1)
        ->and($giftbox['J!'][1])->toBe([$uid])
        ->and($giftbox['J!'][2])->toHaveCount(1)
        ->and(adoptionTestHash(json_decode($giftbox['J!'][2][0], true)))->toBe(adoptionTestHash($first));
});

it('does not remove a different Giftbox animal for a stale adoption hash', function (): void {
    $user = User::factory()->create();
    $uid = (string) $user->uid;
    $piglet = adoptionTestDna('F', 'd9');
    $piglet['U'] = $uid;
    PlayerMeta::setValue($uid, 'giftbox', serialize([
        'J!' => [1, [$uid], [json_encode($piglet)]],
    ]));

    $request = (object) ['params' => ['J!', 'missing-dna-hash']];
    $result = AnimalBreedingService::onGiveUpForAdoption(adoptionTestPlayer($uid), $request, null);

    expect($result['data']['success'])->toBeFalse();
    $giftbox = unserialize(PlayerMeta::getValue($uid, 'giftbox'), ['allowed_classes' => false]);
    expect($giftbox['J!'][0])->toBe(1)
        ->and($giftbox['J!'][2])->toHaveCount(1);
});
