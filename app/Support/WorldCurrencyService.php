<?php

namespace App\Support;

use App\Models\WorldCurrency;
use App\Models\WorldCurrencyAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Server-authoritative balances for expansion/world currencies. */
final class WorldCurrencyService
{
    private const WORLD_CURRENCIES = [
        'asia' => 'jade',
        'hawaii' => 'coconuts',
    ];

    private const SUPPORTED_UNITS = [
        'jade',
        'coconuts',
        'trowels',
        'villageCoins',
    ];

    public static function currencyForWorld(?string $worldType): ?string
    {
        $worldType = is_string($worldType) ? trim($worldType) : '';
        return self::WORLD_CURRENCIES[$worldType] ?? null;
    }

    public static function isSupportedUnit(?string $unit): bool
    {
        return is_string($unit) && in_array(trim($unit), self::SUPPORTED_UNITS, true);
    }

    /** Return the nested balance shape consumed by Player.worldCurrencies. */
    public static function balancesForClient(int|string $uid): array
    {
        $rows = WorldCurrency::query()
            ->where('uid', $uid)
            ->get(['currency_unit', 'total', 'earned', 'purchased'])
            ->keyBy('currency_unit');

        $balances = [];
        foreach (self::SUPPORTED_UNITS as $unit) {
            $row = $rows->get($unit);
            $balances[$unit] = [
                'total' => (int) ($row?->total ?? 0),
                'earned' => (int) ($row?->earned ?? 0),
                'purchased' => (int) ($row?->purchased ?? 0),
            ];
        }

        return $balances;
    }

    /** Return the flat balance shape consumed by TPostInit/SpecialQuestManager. */
    public static function totalsForPostInit(int|string $uid): array
    {
        $balances = self::balancesForClient($uid);
        $totals = [];
        foreach ($balances as $unit => $balance) {
            $totals[$unit] = (int) ($balance['total'] ?? 0);
        }

        return $totals;
    }

    /**
     * Create the one-time expansion grant. Existing rows are left untouched,
     * so retrying a claim cannot duplicate the starter balance.
     */
    public static function initializeForWorld(int|string $uid, string $worldType): bool
    {
        $unit = self::currencyForWorld($worldType);
        if ($unit === null) {
            return true;
        }

        return DB::transaction(function () use ($uid, $unit, $worldType): bool {
            $row = WorldCurrency::query()
                ->where('uid', $uid)
                ->where('currency_unit', $unit)
                ->lockForUpdate()
                ->first();

            if ($row !== null) {
                return true;
            }

            $row = WorldCurrency::query()->create([
                'uid' => (string) $uid,
                'currency_unit' => $unit,
                'total' => 6000,
                'earned' => 6000,
                'purchased' => 0,
            ]);

            self::audit($uid, $unit, 6000, 'world_unlock', (int) $row->total, [
                'worldType' => $worldType,
            ]);

            return true;
        });
    }

    public static function grant(
        int|string $uid,
        string $unit,
        int $amount,
        string $source = 'world_currency.grant',
        array $metadata = [],
    ): bool {
        $unit = trim($unit);
        if (!self::isSupportedUnit($unit) || $amount < 0) {
            return false;
        }
        if ($amount === 0) {
            return true;
        }

        return DB::transaction(function () use ($uid, $unit, $amount, $source, $metadata): bool {
            $row = self::lockRow($uid, $unit);
            $row->total = min(PHP_INT_MAX, (int) $row->total + $amount);
            $row->earned = min(PHP_INT_MAX, (int) $row->earned + $amount);
            $row->save();
            self::audit($uid, $unit, $amount, $source, (int) $row->total, $metadata);
            return true;
        });
    }

    /** Strictly spend a balance; never clamp a shortfall to zero. */
    public static function spend(
        int|string $uid,
        string $unit,
        int $amount,
        string $source = 'world_currency.spend',
        array $metadata = [],
    ): bool {
        $unit = trim($unit);
        if (!self::isSupportedUnit($unit) || $amount < 0) {
            return false;
        }
        if ($amount === 0) {
            return true;
        }

        return DB::transaction(function () use ($uid, $unit, $amount, $source, $metadata): bool {
            $row = self::lockRow($uid, $unit);
            if ((int) $row->total < $amount) {
                return false;
            }

            $row->total = (int) $row->total - $amount;
            $row->save();
            self::audit($uid, $unit, -$amount, $source, (int) $row->total, $metadata);
            return true;
        });
    }

    public static function hasSufficient(int|string $uid, string $unit, int $amount): bool
    {
        $unit = trim($unit);
        if (!self::isSupportedUnit($unit) || $amount < 0) {
            return false;
        }
        if ($amount === 0) {
            return true;
        }

        return (int) WorldCurrency::query()
            ->where('uid', $uid)
            ->where('currency_unit', $unit)
            ->value('total') >= $amount;
    }

    public static function canApplyDeltas(int|string $uid, array $deltas): bool
    {
        foreach ($deltas as $unit => $delta) {
            $delta = (int) $delta;
            if (!self::isSupportedUnit((string) $unit)) {
                return false;
            }
            if ($delta < 0 && !self::hasSufficient($uid, (string) $unit, abs($delta))) {
                return false;
            }
        }

        return true;
    }

    /** Apply a set of signed deltas under one transaction. */
    public static function applyDeltas(
        int|string $uid,
        array $deltas,
        string $source = 'world_currency.batch',
        array $metadata = [],
    ): bool {
        $deltas = array_filter(array_map('intval', $deltas), static fn (int $delta): bool => $delta !== 0);
        foreach ($deltas as $unit => $delta) {
            if (!self::isSupportedUnit((string) $unit)) {
                return false;
            }
        }
        if ($deltas === []) {
            return true;
        }

        ksort($deltas, SORT_STRING);

        return DB::transaction(function () use ($uid, $deltas, $source, $metadata): bool {
            $rows = [];
            foreach ($deltas as $unit => $delta) {
                $rows[$unit] = self::lockRow($uid, (string) $unit);
                if ($delta < 0 && (int) $rows[$unit]->total < abs($delta)) {
                    return false;
                }
            }

            foreach ($deltas as $unit => $delta) {
                $row = $rows[$unit];
                $row->total = (int) $row->total + $delta;
                if ($delta > 0) {
                    $row->earned = min(PHP_INT_MAX, (int) $row->earned + $delta);
                }
                $row->save();
                self::audit($uid, (string) $unit, $delta, $source, (int) $row->total, $metadata);
            }

            return true;
        });
    }

    private static function lockRow(int|string $uid, string $unit): WorldCurrency
    {
        $row = WorldCurrency::query()->firstOrCreate(
            ['uid' => (string) $uid, 'currency_unit' => $unit],
            ['total' => 0, 'earned' => 0, 'purchased' => 0],
        );

        return WorldCurrency::query()
            ->whereKey($row->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private static function audit(
        int|string $uid,
        string $unit,
        int $delta,
        string $source,
        int $balance,
        array $metadata = [],
    ): void {
        try {
            WorldCurrencyAudit::query()->create([
                'uid' => (string) $uid,
                'currency_unit' => $unit,
                'source' => substr($source, 0, 100),
                'delta' => $delta,
                'balance' => max(0, $balance),
                'metadata' => $metadata === [] ? null : $metadata,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Could not record world currency audit entry.', [
                'uid' => (string) $uid,
                'currency_unit' => $unit,
                'source' => $source,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
