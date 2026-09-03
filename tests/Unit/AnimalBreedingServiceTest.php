<?php

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';
    require_once AMFPHP_ROOTPATH.'Functions/AnimalBreedingService.php';
});

function breedingPrivate(string $method): ReflectionMethod
{
    $reflection = new ReflectionMethod(AnimalBreedingService::class, $method);
    $reflection->setAccessible(true);

    return $reflection;
}

it('uses each habitat XML timing table by love-potion count', function (): void {
    $config = breedingPrivate('breedingConfig')->invoke(null, 'pigpenv2_finished');

    expect($config['breedTimes'])->toBe([24, 12, 6, 3, 1, 0])
        ->and(breedingPrivate('breedingDurationSeconds')->invoke(null, $config, 0))->toBe(86400)
        ->and(breedingPrivate('breedingDurationSeconds')->invoke(null, $config, 2))->toBe(21600)
        ->and(breedingPrivate('breedingDurationSeconds')->invoke(null, $config, 5))->toBe(0);
});

it('rejects unsupported potion counts and applies their configured success bonus', function (): void {
    $config = breedingPrivate('breedingConfig')->invoke(null, 'xuk_sheep_pen_finished');

    expect(breedingPrivate('requestedPotionCount')->invoke(null, (object) ['numPotions' => 5], $config))->toBe(5)
        ->and(breedingPrivate('requestedPotionCount')->invoke(null, (object) ['numPotions' => 6], $config))->toBeNull()
        ->and(breedingPrivate('breedingOutcome')->invoke(null, ['baseSuccessChance' => 0, 'lovePotionBonusChance' => 0], 0))->toBeFalse()
        ->and(breedingPrivate('breedingOutcome')->invoke(null, ['baseSuccessChance' => 1, 'lovePotionBonusChance' => 0], 0))->toBeTrue();
});

it('requires exact persisted DNA for one female and one male parent', function (): void {
    $female = [
        'G' => 'F',
        'B' => ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
        'P' => ['T' => ['a'], 'H' => ['20', '20'], 'S' => ['8', '8'], 'V' => ['8', '8']],
    ];
    $male = [
        'G' => 'M',
        'B' => ['H' => ['30', '30'], 'S' => ['8', '8'], 'V' => ['8', '8']],
        'P' => ['T' => ['b'], 'H' => ['40', '40'], 'S' => ['8', '8'], 'V' => ['8', '8']],
    ];
    $hash = breedingPrivate('mutableStateHash');
    $femaleHash = 'sheeppen_ewe:'.$hash->invoke(null, $female);
    $maleHash = 'sheeppen_ram:'.$hash->invoke(null, $male);
    $components = (object) ['storageMetadata' => (object) [
        $femaleHash => [json_encode($female)],
        $maleHash => [json_encode($male)],
    ]];

    expect(breedingPrivate('validatedParents')->invoke(null, [$femaleHash, $maleHash], $components))
        ->toBe([$female, $male])
        ->and(breedingPrivate('validatedParents')->invoke(null, [$femaleHash, $femaleHash], $components))
        ->toBeNull()
        ->and(breedingPrivate('validatedParents')->invoke(null, ['sheeppen_ewe:missing', $maleHash], $components))
        ->toBeNull();
});

it('trusts a persisted metadata key when legacy DNA hashing differs', function (): void {
    $female = [
        'G' => 'F',
        'B' => ['H' => ['10', '10'], 'S' => ['8', '8'], 'V' => ['8', '8']],
        'P' => ['T' => ['a'], 'H' => ['20', '20'], 'S' => ['8', '8'], 'V' => ['8', '8']],
    ];
    $male = [
        'G' => 'M',
        'B' => ['H' => ['30', '30'], 'S' => ['8', '8'], 'V' => ['8', '8']],
        'P' => ['T' => ['b'], 'H' => ['40', '40'], 'S' => ['8', '8'], 'V' => ['8', '8']],
    ];
    $femaleHash = 'sheeppen_ewe:12345678';
    $maleHash = 'sheeppen_ram:87654321';
    $components = (object) ['storageMetadata' => (object) [
        $femaleHash => [json_encode($female)],
        $maleHash => [json_encode($male)],
    ]];

    expect(breedingPrivate('validatedParents')->invoke(null, [$femaleHash, $maleHash], $components))
        ->toBe([$female, $male]);
});
