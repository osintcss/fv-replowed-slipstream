<?php

require_once AMFPHP_ROOTPATH . 'Helpers/user_resources.php';

use App\Models\UserMeta;
use App\Support\ResourceAudit;

/**
 * Compatibility endpoint for the shipped Flash developer console.
 *
 * ConsoleStandardCommandsModule.setCash/setGold/setXp updates Flash's local
 * player state, then sends InGameConsoleService.adminCall with
 * CPanelUserStatsController.setPlayerAttributes. Persist those values here so
 * later authoritative AMF transactions and a reload see the same balances.
 */
class InGameConsoleService
{
    public static function adminCall($playerObj, $request, $market = null): array
    {
        $rawParams = $request->params[0] ?? null;
        $params = is_string($rawParams) ? json_decode($rawParams, true) : null;

        if (!is_array($params)
            || ($params['adminController'] ?? null) !== 'CPanelUserStatsController'
            || ($params['adminCommand'] ?? null) !== 'setPlayerAttributes') {
            return ['data' => ['errorMessage' => 'Unsupported console command.']];
        }

        $limits = [
            'gold' => UserMeta::GOLD_MAX,
            'cash' => UserMeta::CASH_MAX,
            'xp' => UserMeta::XP_MAX,
        ];
        $updates = [];

        foreach ($limits as $field => $maximum) {
            if (!array_key_exists($field, $params)) {
                continue;
            }

            $value = $params[$field];
            if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value))) {
                return ['data' => ['errorMessage' => "Invalid {$field} value."]];
            }

            $updates[$field] = min((int) $value, $maximum);
        }

        if ($updates === []) {
            return ['data' => ['errorMessage' => 'No supported player attributes supplied.']];
        }

        $uid = $playerObj->getUid();
        $updated = \DB::transaction(function () use ($uid, $updates): bool {
            $meta = UserMeta::query()->where('uid', $uid)->lockForUpdate()->first();
            if ($meta === null) {
                return false;
            }

            $before = [
                'gold' => (int) $meta->gold,
                'cash' => (int) $meta->cash,
                'xp' => (int) $meta->xp,
            ];
            // Preserve the legacy self-grant console, but never let a stale
            // Flash snapshot lower a real balance.  `std.addCash` and peers
            // arrive as a higher absolute value and remain supported; lower
            // supplied fields become no-ops rather than overwrites.
            $safeUpdates = [];
            $blockedDecreases = [];
            foreach ($updates as $field => $requestedValue) {
                $currentValue = $before[$field];
                if ($requestedValue < $currentValue) {
                    $safeUpdates[$field] = $currentValue;
                    $blockedDecreases[] = $field;
                } else {
                    $safeUpdates[$field] = $requestedValue;
                }
            }

            $meta->fill($safeUpdates);
            $meta->save();

            ResourceAudit::record(
                $uid,
                'developer_console',
                (int) $meta->gold - $before['gold'],
                (int) $meta->xp - $before['xp'],
                (int) $meta->cash - $before['cash'],
                [
                    'fields' => array_keys($updates),
                    'blocked_decreases' => $blockedDecreases,
                ],
            );

            return true;
        });
        if (!$updated) {
            return ['data' => ['errorMessage' => 'Player balance could not be updated.']];
        }

        UserResources::invalidateCache($uid);

        return ['data' => [
            'success' => true,
            'gold' => UserResources::getGold($uid),
            'cash' => UserResources::getCash($uid),
            'xp' => UserResources::getXp($uid),
        ]];
    }
}
