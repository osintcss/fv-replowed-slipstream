<?php

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
    require_once AMFPHP_ROOTPATH.'Helpers/user_resources.php';
    require_once AMFPHP_ROOTPATH.'Functions/WorldService.php';
});

it('hydrates adult mutable animals from Giftbox requests without a source marker', function (): void {
    $method = new ReflectionMethod(WorldService::class, 'shouldHydrateGiftboxAnimal');
    $method->setAccessible(true);

    expect($method->invoke(null, 'MutableAnimal', false, true))->toBeTrue()
        ->and($method->invoke(null, 'MutableAnimal', false, false))->toBeFalse()
        ->and($method->invoke(null, 'MutableAnimalBaby', false, false))->toBeTrue()
        ->and($method->invoke(null, 'MutableAnimal', true, false))->toBeTrue();
});

it('uses the canonical green hue for Pig Pen starter boars', function (): void {
    $method = new ReflectionMethod(WorldService::class, 'constructionRewardExtraData');
    $method->setAccessible(true);

    $dna = $method->invoke(null, 'pigpen_male_light_green');

    expect($dna->B->H)->toBe(['66', '66'])
        ->and($dna->P->H)->toBe(['66', '66'])
        ->and($dna->P->T)->toBe(['a']);
});
