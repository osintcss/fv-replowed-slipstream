<?php

namespace App\Support;

use App\Models\ResourceAudit as ResourceAuditModel;
use App\Models\UserMeta;
use Illuminate\Support\Facades\Log;

/** Append-only balance ledger; logging must not break a game action. */
final class ResourceAudit
{
    public static function record(
        int|string $uid,
        string $source,
        int $goldDelta = 0,
        int $xpDelta = 0,
        int $cashDelta = 0,
        array $metadata = [],
    ): void {
        if (!is_numeric($uid) || ($goldDelta === 0 && $xpDelta === 0 && $cashDelta === 0)) {
            return;
        }

        try {
            $resources = UserMeta::query()
                ->where('uid', $uid)
                ->first(['gold', 'xp', 'cash']);
            if ($resources === null) {
                return;
            }

            ResourceAuditModel::query()->create([
                'uid' => (string) $uid,
                'source' => substr($source, 0, 100),
                'gold_delta' => $goldDelta,
                'xp_delta' => $xpDelta,
                'cash_delta' => $cashDelta,
                'gold_balance' => (int) $resources->gold,
                'xp_balance' => (int) $resources->xp,
                'cash_balance' => (int) $resources->cash,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Could not record resource audit entry.', [
                'uid' => (string) $uid,
                'source' => $source,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
