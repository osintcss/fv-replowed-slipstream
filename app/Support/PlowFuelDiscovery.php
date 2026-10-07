<?php

namespace App\Support;

use App\Models\PlayerMeta;
use App\Models\UserMeta;
use Illuminate\Support\Facades\DB;

/** Server-authoritative counterpart of the classic TPlow fuel discovery. */
final class PlowFuelDiscovery
{
    // gameSettingsCMS.xml.gz: 2%, six hours, level 12, fuelcans=fuelLoot2.
    private const MIN_LEVEL = 12;
    private const WINNING_ROLLS = 20;
    private const ROLL_SIZE = 1000;
    private const COOLDOWN_SECONDS = 21600;
    private const FUEL_TANKS = 2;
    private const LAST_GRANT_META_KEY = 'fuel_discovery_last_granted_at';

    /** Return the fuel granted, or zero when this plow was not a discovery. */
    public static function grantForPlow(string $uid, int $x, int $y): int
    {
        return DB::transaction(static function () use ($uid, $x, $y): int {
            // Serializing on the player's resource row keeps concurrent plows
            // from both passing the same cooldown and granting fuel twice.
            $player = UserMeta::query()->where('uid', $uid)->lockForUpdate()->first();
            if ($player === null || PlayerLevel::fromXp((int) $player->xp) < self::MIN_LEVEL) {
                return 0;
            }

            // Plot.plow() deducts the plow cost before it constructs TPlow.
            // This is the same post-plow gold value used by the original
            // client's deterministic 0..999 discovery roll.
            $seed = $uid . (int) $player->gold . $x . $y;
            $roll = hexdec(substr(md5($seed), -7)) % self::ROLL_SIZE;
            if ($roll >= self::WINNING_ROLLS) {
                return 0;
            }

            $lastGrant = PlayerMeta::query()
                ->where('uid', $uid)
                ->where('meta_key', self::LAST_GRANT_META_KEY)
                ->lockForUpdate()
                ->first();
            $now = now()->timestamp;
            if ($lastGrant !== null && $now - (int) $lastGrant->meta_value < self::COOLDOWN_SECONDS) {
                return 0;
            }

            $tankFuel = self::FUEL_TANKS * max(0, (int) $player->energyMax);
            $currentEnergy = max(0, (int) $player->energy);
            $newEnergy = min(2147483647, $currentEnergy + $tankFuel);
            $fuelAdded = $newEnergy - $currentEnergy;
            if ($fuelAdded <= 0) {
                return 0;
            }

            $player->energy = $newEnergy;
            $player->save();

            if ($lastGrant === null) {
                PlayerMeta::query()->create([
                    'uid' => $uid,
                    'meta_key' => self::LAST_GRANT_META_KEY,
                    'meta_value' => (string) $now,
                ]);
            } else {
                $lastGrant->meta_value = (string) $now;
                $lastGrant->save();
            }

            UserMeta::invalidateCache($uid);
            PlayerMeta::clearCache($uid, self::LAST_GRANT_META_KEY);

            return $fuelAdded;
        });
    }
}
