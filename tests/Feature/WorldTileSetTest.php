<?php

beforeEach(function (): void {
    if (! defined('AMFPHP_ROOTPATH')) {
        define('AMFPHP_ROOTPATH', dirname(__DIR__, 2).'/public/farmville/flashservices/amfphp/');
    }

    require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';
});

it('uses the authentic completed terrain configuration for Winter Fable', function (): void {
    expect(getTileSetForWorld('winternord'))->toBe('winternord_theme');
});
