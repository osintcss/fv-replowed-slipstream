<?php

use App\Models\UserMeta;
use App\Models\WorldCurrency;
use App\Models\WorldCurrencyAudit;
use App\Support\WorldCurrencyService;

function makeWorldCurrencyPlayer(string $uid = '960001'): void
{
    UserMeta::query()->create([
        'uid' => $uid,
        'firstName' => 'Currency',
        'lastName' => 'Tester',
        'gold' => 1000,
        'xp' => 0,
        'cash' => 0,
        'energy' => 10,
        'energyMax' => 10,
    ]);
}

it('keeps world currency balances strict and auditable', function (): void {
    $uid = '960001';
    makeWorldCurrencyPlayer($uid);

    expect(WorldCurrencyService::grant($uid, 'jade', 100, 'test.grant'))->toBeTrue()
        ->and(WorldCurrencyService::spend($uid, 'jade', 40, 'test.spend'))->toBeTrue()
        ->and(WorldCurrencyService::spend($uid, 'jade', 1000, 'test.shortfall'))->toBeFalse();

    $balance = WorldCurrency::query()
        ->where('uid', $uid)
        ->where('currency_unit', 'jade')
        ->firstOrFail();

    expect((int) $balance->total)->toBe(60)
        ->and((int) $balance->earned)->toBe(100)
        ->and(WorldCurrencyAudit::query()->where('uid', $uid)->count())->toBe(2);
});

it('returns both expansion balances in the Flash payload shapes', function (): void {
    $uid = '960002';
    makeWorldCurrencyPlayer($uid);
    WorldCurrencyService::grant($uid, 'coconuts', 6000, 'test.unlock');

    expect(WorldCurrencyService::balancesForClient($uid))->toMatchArray([
        'jade' => ['total' => 0, 'earned' => 0, 'purchased' => 0],
        'coconuts' => ['total' => 6000, 'earned' => 6000, 'purchased' => 0],
    ])->and(WorldCurrencyService::totalsForPostInit($uid))->toMatchArray([
        'jade' => 0,
        'coconuts' => 6000,
    ]);
});
