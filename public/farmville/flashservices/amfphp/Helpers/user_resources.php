<?php
require_once AMFPHP_ROOTPATH . "Helpers/logger.php";

use App\Models\UserMeta;

class UserResources{
    public const GOLD_FIELD = "gold";
    public const CASH_FIELD = "cash";
    public const XP_FIELD = "xp";
    public const GOLD_MAX = UserMeta::GOLD_MAX;
    public const CASH_MAX = UserMeta::CASH_MAX;
    public const XP_MAX = UserMeta::XP_MAX;
    public const LEVEL_UP_CASH_CAP = 250;

    private static $ALLOWED_FIELDS = [self::GOLD_FIELD, self::CASH_FIELD, self::XP_FIELD];
    private static $resourceCache = [];

    private static function addResource($uid, $amount, $field, $max){
        if (!is_numeric($uid) || !is_int($amount) || $amount < 0){
            return false;
        }
        if ($amount === 0) return true;
        if (!in_array($field, self::$ALLOWED_FIELDS)) return false;

        $result = UserMeta::addResource($uid, $amount, $field, $max);
        self::invalidateCache($uid);
        return $result;
    }

    private static function removeResource($uid, $amount, $field){
        if (!is_numeric($uid) || !is_int($amount) || $amount < 0){
            return false;
        }
        if ($amount === 0) return true;
        if (!in_array($field, self::$ALLOWED_FIELDS)) return false;

        $result = UserMeta::removeResource($uid, $amount, $field);
        self::invalidateCache($uid);
        return $result;
    }

    public static function addGold($uid, $amount){
        return self::addResource($uid, $amount, self::GOLD_FIELD, self::GOLD_MAX);
    }

    public static function addCash($uid, $amount){
        return self::addResource($uid, $amount, self::CASH_FIELD, self::CASH_MAX);
    }

    public static function addXp($uid, $amount){
        if (!is_numeric($uid) || !is_int($amount) || $amount <= 0) {
            return $amount === 0;
        }

        $currentXp = self::getXp($uid);
        $currentLevel = self::getLevelForXp($currentXp);

        $result = self::addResource($uid, $amount, self::XP_FIELD, self::XP_MAX);

        if ($result) {
            $newXp = min($currentXp + $amount, self::XP_MAX);
            $newLevel = self::getLevelForXp($newXp);

            if ($newLevel > $currentLevel) {
                $cashToAdd = 0;

                if ($currentLevel < self::LEVEL_UP_CASH_CAP) {
                    $levelsBeforeCap = min($newLevel, self::LEVEL_UP_CASH_CAP) - $currentLevel;
                    $cashToAdd += $levelsBeforeCap * 3;
                }

                if ($newLevel > self::LEVEL_UP_CASH_CAP) {
                    $levelsAfterCap = $newLevel - max($currentLevel, self::LEVEL_UP_CASH_CAP);
                    $cashToAdd += $levelsAfterCap;
                }

                if ($cashToAdd > 0) {
                    self::addCash($uid, $cashToAdd);
                    Logger::debug('UserResources', "Level up cash awarded: uid=$uid, cash=$cashToAdd, oldLevel=$currentLevel, newLevel=$newLevel");
                }
            }
        }

        return $result;
    }

    public static function getXp($uid) {
        return self::loadResources($uid)['xp'] ?? 0;
    }

    public static function getLevelForXp($xp) {
        static $thresholds = [
            1=>0, 2=>15, 3=>30, 4=>70, 5=>140, 6=>250, 7=>400, 8=>600, 9=>850, 10=>1150,
            11=>1500, 12=>1900, 13=>2400, 14=>3000, 15=>3700, 16=>4500, 17=>5400, 18=>6400,
            19=>7500, 20=>8700, 21=>10000, 22=>11500, 23=>13500, 24=>16000, 25=>19000,
            26=>22500, 27=>26500, 28=>31000, 29=>36000, 30=>42000, 31=>49000, 32=>57000,
            33=>65000, 34=>74000, 35=>83000, 36=>93000, 37=>103000, 38=>113000, 39=>123000,
            40=>133000, 41=>143000, 42=>153000, 43=>163000, 44=>173000, 45=>183000,
            46=>193000, 47=>203000, 48=>213000, 49=>223000, 50=>233000, 51=>243000,
            52=>253000, 53=>263000, 54=>273000, 55=>283000, 56=>293000, 57=>303000,
            58=>313000, 59=>323000, 60=>333000, 61=>343000, 62=>353000, 63=>363000,
            64=>373000, 65=>383000, 66=>393000, 67=>403000, 68=>413000, 69=>423000,
            70=>433000, 71=>443500, 72=>454500, 73=>466000, 74=>478000, 75=>490500,
            76=>504000, 77=>518500, 78=>534000, 79=>550500, 80=>568000, 81=>587000,
            82=>607500, 83=>629500, 84=>653000, 85=>678500, 86=>706000, 87=>735500,
            88=>767000, 89=>801000, 90=>837500, 91=>876500, 92=>918500, 93=>963500,
            94=>1012000, 95=>1064000, 96=>1120000, 97=>1180000, 98=>1244500, 99=>1313500,
            100=>1387500
        ];

        $xp = (int) $xp;
        $level = 1;

        for ($i = 100; $i >= 1; $i--) {
            if ($xp >= $thresholds[$i]) {
                $level = $i;
                break;
            }
        }

        if ($xp >= 1500000) {
            $level = 100 + (int) floor(($xp - 1500000) / 100000) + 1;
        }

        return $level;
    }

    public static function removeGold($uid, $amount){
        return self::removeResource($uid, $amount, self::GOLD_FIELD);
    }

    public static function removeCash($uid, $amount){
        return self::removeResource($uid, $amount, self::CASH_FIELD);
    }

    
    public static function batchUpdate($uid, int $goldDelta = 0, int $xpDelta = 0, int $cashDelta = 0) {
        if (!is_numeric($uid)) {
            Logger::error('UserResources', "batchUpdate: invalid uid");
            return false;
        }

        if ($goldDelta === 0 && $xpDelta === 0 && $cashDelta === 0) {
            Logger::debug('UserResources', "batchUpdate: no changes, skipping");
            return true;
        }

        $levelUpCash = 0;
        if ($xpDelta > 0) {
            $currentXp = self::getXp($uid);
            $currentLevel = self::getLevelForXp($currentXp);
            $newXp = min($currentXp + $xpDelta, self::XP_MAX);
            $newLevel = self::getLevelForXp($newXp);

            if ($newLevel > $currentLevel && $currentLevel < self::LEVEL_UP_CASH_CAP) {
                $levelUpCash = min($newLevel, self::LEVEL_UP_CASH_CAP) - $currentLevel;
                Logger::debug('UserResources', "Level up cash: uid=$uid, levels=$levelUpCash, oldLevel=$currentLevel, newLevel=$newLevel");
            }
        }

        $totalCashDelta = $cashDelta + $levelUpCash;
        Logger::debug('UserResources', "batchUpdate: uid=$uid, gold=$goldDelta, xp=$xpDelta, cash=$totalCashDelta (base=$cashDelta, levelUp=$levelUpCash)");

        $success = UserMeta::batchUpdateResources($uid, $goldDelta, $xpDelta, $totalCashDelta);

        Logger::debug('UserResources', "batchUpdate executed: success=" . ($success ? 'true' : 'false'));
        self::invalidateCache($uid);
        return $success;
    }

    private static function loadResources($uid) {
        if (isset(self::$resourceCache[$uid])) {
            return self::$resourceCache[$uid];
        }

        if (!is_numeric($uid)) {
            return ['gold' => 0, 'cash' => 0, 'xp' => 0];
        }

        $data = UserMeta::loadResources($uid);
        self::$resourceCache[$uid] = $data;
        return $data;
    }

    public static function invalidateCache($uid) {
        unset(self::$resourceCache[$uid]);
        UserMeta::invalidateCache($uid);
    }

    public static function getCash($uid) {
        return self::loadResources($uid)['cash'];
    }

    public static function getGold($uid) {
        return self::loadResources($uid)['gold'];
    }

    public static function getEnergy($uid) {
        if (!is_numeric($uid)) {
            return 0;
        }
        $meta = UserMeta::where('uid', $uid)->first();
        return $meta ? (int) $meta->energy : 0;
    }

    public static function removeEnergy($uid, $amount) {
        if (!is_numeric($uid) || !is_numeric($amount) || $amount <= 0) {
            return false;
        }

        $amount = (int) $amount;

        $updated = UserMeta::where('uid', $uid)
            ->where('energy', '>=', $amount)
            ->update([
                'energy' => \DB::raw("energy - {$amount}")
            ]);

        return $updated > 0;
    }

    public static function addEnergy($uid, $amount) {
        if (!is_numeric($uid) || !is_numeric($amount) || $amount <= 0) {
            return false;
        }

        $amount = (int) $amount;

        $updated = UserMeta::where('uid', $uid)
            ->update([
                'energy' => \DB::raw("LEAST(energy + {$amount}, energyMax)")
            ]);

        return $updated > 0;
    }
}
