<?php

namespace App\Http\Controllers;

use App\Models\PlayerMeta;
use App\Models\UserMeta;
use App\Support\PlayerLevel;
use App\Support\WorldCurrencyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class WorldShopController extends Controller
{
    private const META_KEY = 'unlocked_worlds';
    private const LEVEL_CLAIMS_META_KEY = 'world_unlock_claims';
    private const FIRST_LEVEL_UNLOCK = 5;
    private const CURRENT_WORLD_META_KEY = 'currentWorldType';
    private const FREE_WORLDS = ['farm'];
    private const PURCHASABLE_WORLDS = [
        'england', 'fisherman', 'winterwonderland', 'australia',
        'space', 'candy', 'fforest', 'hlights', 'rainforest', 'oz',
        'mediterranean', 'oasis', 'storybook', 'sleepyhollow', 'toyland',
        'village', 'glen', 'atlantis', 'hallow', 'winternord',
        'asia', 'hawaii'
    ];

    public function status()
    {
        $user = Auth::user();
        $uid = $user->uid;

        $userMeta = UserMeta::where('uid', $uid)->first();
        $cash = $userMeta ? (int) $userMeta->cash : 0;
        $playerLevel = PlayerLevel::fromXp((int) ($userMeta?->xp ?? 0));
        $purchasedWorlds = $this->getPurchasedWorlds($uid);
        $allUnlocked = self::getAllUnlockedWorlds($uid);
        $claims = $this->getLevelUnlockClaims($uid);
        $status = $this->getClaimStatus($playerLevel, $claims, $allUnlocked);

        return response()->json([
            'cash' => $cash,
            'playerLevel' => $playerLevel,
            'availableClaims' => $status['availableClaims'],
            'nextUnlockLevel' => $status['nextUnlockLevel'],
            'levelUnlocks' => $claims,
            'unlockedWorlds' => $allUnlocked,
            'freeWorlds' => self::FREE_WORLDS,
            'purchasedWorlds' => $purchasedWorlds,
            'currentWorldType' => PlayerMeta::where('uid', $uid)
                ->where('meta_key', self::CURRENT_WORLD_META_KEY)
                ->value('meta_value') ?: 'farm',
        ]);
    }

    public function purchase(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Worlds are unlocked by reaching level milestones, not by spending Farm Cash.',
        ], 410);
    }

    public function claim(Request $request)
    {
        $user = Auth::user();
        $uid = $user->uid;
        $worldId = $request->input('worldId');

        if (!is_string($worldId) || !in_array($worldId, self::PURCHASABLE_WORLDS, true)) {
            return response()->json(['success' => false, 'message' => 'Invalid world'], 400);
        }

        $result = DB::transaction(function () use ($uid, $worldId): array {
            $userMeta = UserMeta::where('uid', $uid)->lockForUpdate()->first();
            $playerLevel = PlayerLevel::fromXp((int) ($userMeta?->xp ?? 0));
            $claimsMeta = PlayerMeta::where('uid', $uid)
                ->where('meta_key', self::LEVEL_CLAIMS_META_KEY)
                ->lockForUpdate()
                ->first();
            $claims = $this->decodeLevelUnlockClaims($claimsMeta?->meta_value);

            $unlockedMeta = PlayerMeta::where('uid', $uid)
                ->where('meta_key', self::META_KEY)
                ->lockForUpdate()
                ->first();
            $unlockedWorlds = $this->decodeWorldList($unlockedMeta?->meta_value);
            $allUnlocked = array_values(array_unique(array_merge(self::FREE_WORLDS, $unlockedWorlds)));

            if (in_array($worldId, $allUnlocked, true)) {
                return [
                    'error' => 'World already unlocked',
                    'status' => 409,
                ];
            }

            $status = $this->getClaimStatus($playerLevel, $claims, $allUnlocked);
            if ($status['claimLevel'] === null) {
                if ($playerLevel < self::FIRST_LEVEL_UNLOCK) {
                    return [
                        'error' => "Reach level " . self::FIRST_LEVEL_UNLOCK . " to choose a world",
                        'status' => 403,
                    ];
                }

                return [
                    'error' => 'No world choices are currently available',
                    'status' => 409,
                ];
            }

            $claims[$status['claimLevel']] = $worldId;
            ksort($claims, SORT_NUMERIC);
            $unlockedWorlds[] = $worldId;
            $unlockedWorlds = array_values(array_unique($unlockedWorlds));

            PlayerMeta::updateOrCreate(
                ['uid' => $uid, 'meta_key' => self::LEVEL_CLAIMS_META_KEY],
                ['meta_value' => serialize($claims)],
            );
            PlayerMeta::updateOrCreate(
                ['uid' => $uid, 'meta_key' => self::META_KEY],
                ['meta_value' => serialize($unlockedWorlds)],
            );

            // Expansion config grants the first world-currency bundle when a
            // world is unlocked. Keep the grant idempotent so a retried claim
            // cannot mint another 6,000 units.
            WorldCurrencyService::initializeForWorld($uid, $worldId);

            // Jade Falls also starts its Zen score at one. Store the canonical
            // world-type key used by the AMF score loader, preserving any
            // score that may already exist from an older client.
            if ($worldId === 'asia') {
                $scoreMeta = PlayerMeta::where('uid', $uid)
                    ->where('meta_key', 'world_score_asia')
                    ->lockForUpdate()
                    ->first();
                if ($scoreMeta === null) {
                    PlayerMeta::create([
                        'uid' => $uid,
                        'meta_key' => 'world_score_asia',
                        'meta_value' => '1',
                    ]);
                } elseif ((int) $scoreMeta->meta_value < 1) {
                    $scoreMeta->update(['meta_value' => '1']);
                }
            }

            $updatedStatus = $this->getClaimStatus($playerLevel, $claims, array_merge(self::FREE_WORLDS, $unlockedWorlds));

            return [
                'worldId' => $worldId,
                'unlockLevel' => $status['claimLevel'],
                'remainingClaims' => $updatedStatus['availableClaims'],
                'levelUnlocks' => $claims,
            ];
        });

        if (isset($result['error'])) {
            return response()->json([
                'success' => false,
                'message' => $result['error'],
            ], $result['status']);
        }

        return response()->json([
            'success' => true,
            'worldId' => $result['worldId'],
            'unlockLevel' => $result['unlockLevel'],
            'remainingClaims' => $result['remainingClaims'],
            'levelUnlocks' => $result['levelUnlocks'],
            'message' => "World unlocked at level {$result['unlockLevel']}!",
        ]);
    }

    public function travel(Request $request)
    {
        $user = Auth::user();
        $uid = $user->uid;
        $worldId = $request->input('worldId');

        if (!is_string($worldId)
            || (!in_array($worldId, self::FREE_WORLDS, true)
                && !in_array($worldId, self::PURCHASABLE_WORLDS, true))) {
            return response()->json(['success' => false, 'message' => 'Invalid world'], 400);
        }

        if (!in_array($worldId, self::getAllUnlockedWorlds($uid), true)) {
            return response()->json(['success' => false, 'message' => 'World is not unlocked'], 403);
        }

        PlayerMeta::setValue($uid, self::CURRENT_WORLD_META_KEY, $worldId);

        return response()->json([
            'success' => true,
            'worldId' => $worldId,
            'message' => "Traveling to {$worldId}!",
        ]);
    }

    private function getPurchasedWorlds(string $uid): array
    {
        $meta = PlayerMeta::where('uid', $uid)
            ->where('meta_key', self::META_KEY)
            ->first();

        if (!$meta || empty($meta->meta_value)) {
            return [];
        }

        return $this->decodeWorldList($meta->meta_value);
    }

    public static function getAllUnlockedWorlds(string $uid): array
    {
        $meta = PlayerMeta::where('uid', $uid)
            ->where('meta_key', self::META_KEY)
            ->first();

        $purchasedWorlds = [];
        if ($meta && !empty($meta->meta_value)) {
            $worlds = @unserialize($meta->meta_value);
            if (is_array($worlds)) {
                $purchasedWorlds = array_values(array_unique(array_filter(
                    $worlds,
                    static fn ($world): bool => is_string($world)
                        && in_array($world, self::PURCHASABLE_WORLDS, true),
                )));
            }
        }

        return array_merge(self::FREE_WORLDS, $purchasedWorlds);
    }

    private function getLevelUnlockClaims(string $uid): array
    {
        $meta = PlayerMeta::where('uid', $uid)
            ->where('meta_key', self::LEVEL_CLAIMS_META_KEY)
            ->first();

        return $this->decodeLevelUnlockClaims($meta?->meta_value);
    }

    private function decodeLevelUnlockClaims(?string $serialized): array
    {
        if (!$serialized) {
            return [];
        }

        $claims = @unserialize($serialized);
        if (!is_array($claims)) {
            return [];
        }

        $validClaims = [];
        foreach ($claims as $level => $worldId) {
            $level = (int) $level;
            if ($level < self::FIRST_LEVEL_UNLOCK
                || !is_string($worldId)
                || !in_array($worldId, self::PURCHASABLE_WORLDS, true)
                || isset($validClaims[$level])
                || in_array($worldId, $validClaims, true)) {
                continue;
            }

            $validClaims[$level] = $worldId;
        }

        ksort($validClaims, SORT_NUMERIC);
        return $validClaims;
    }

    private function decodeWorldList(?string $serialized): array
    {
        if (!$serialized) {
            return [];
        }

        $worlds = @unserialize($serialized);
        if (!is_array($worlds)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $worlds,
            static fn ($world): bool => is_string($world)
                && in_array($world, self::PURCHASABLE_WORLDS, true),
        )));
    }

    private function getClaimStatus(int $playerLevel, array $claims, array $unlockedWorlds): array
    {
        $eligibleLevels = [];
        for ($level = self::FIRST_LEVEL_UNLOCK; $level <= $playerLevel; $level++) {
            if (!array_key_exists($level, $claims)) {
                $eligibleLevels[] = $level;
            }
        }

        $claimableWorlds = array_values(array_diff(self::PURCHASABLE_WORLDS, $unlockedWorlds));
        $availableClaims = min(count($eligibleLevels), count($claimableWorlds));

        return [
            'availableClaims' => $availableClaims,
            'claimLevel' => $availableClaims > 0 ? $eligibleLevels[0] : null,
            'nextUnlockLevel' => $availableClaims > 0
                ? $eligibleLevels[0]
                : (count($claimableWorlds) > 0 ? max(self::FIRST_LEVEL_UNLOCK, $playerLevel + 1) : null),
        ];
    }
}
