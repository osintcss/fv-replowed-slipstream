<?php 
require_once AMFPHP_ROOTPATH . "Helpers/user_resources.php";
require_once AMFPHP_ROOTPATH . "Helpers/general_functions.php";
require_once AMFPHP_ROOTPATH . "Helpers/crafting_helper.php";

use App\Support\WorldCurrencyService;

class MarketTransactions {
    private const BUY_XP_GAIN_RATIO = 0.01;
    private const BUY_XP_GAIN_MIN = 0;

    private $uid = null;
    public function __construct($pid) {
        $this->uid = $pid;
    }

    private function currentWorldCurrency(): ?string
    {
        return WorldCurrencyService::currencyForWorld(getCurrentWorldType($this->uid));
    }

    private static function worldCurrencyFor(?string $worldType): ?string
    {
        return WorldCurrencyService::currencyForWorld($worldType);
    }

    /** Resolve the payment unit used by the current world/item combination. */
    private static function resolveWorldCurrency(
        ?string $requestedCurrency,
        array $item,
        ?string $worldType,
        bool $defaultToWorld = true,
    ): ?string {
        $requestedCurrency = is_string($requestedCurrency) ? trim($requestedCurrency) : '';
        if (WorldCurrencyService::isSupportedUnit($requestedCurrency)) {
            return $requestedCurrency;
        }
        if (in_array($requestedCurrency, ['cash', 'coins', 'gold'], true)) {
            return null;
        }

        $market = is_string($item['market'] ?? null) ? trim($item['market']) : '';
        if (WorldCurrencyService::isSupportedUnit($market)) {
            return $market;
        }

        $worldCurrency = self::worldCurrencyFor($worldType);
        if ($defaultToWorld && $worldCurrency !== null && ($market === '' || $market === 'coins' || $market === 'gold')) {
            return $worldCurrency;
        }

        return null;
    }

    private static function addWorldCurrencyDelta(array &$deltas, ?string $unit, int $delta): void
    {
        if ($unit === null || $delta === 0) {
            return;
        }

        $deltas[$unit] = ($deltas[$unit] ?? 0) + $delta;
    }

    /** Preflight direct placement so an unaffordable plot is never persisted. */
    public function canAfford(string $type, object $data, ?string $currency = null): bool
    {
        if ($type === ACTION_PLOW) {
            $unit = $this->currentWorldCurrency();
            return $unit === null
                ? UserResources::getGold($this->uid) >= 15
                : WorldCurrencyService::hasSufficient($this->uid, $unit, 15);
        }

        if ($type !== ACTION_PLANT) {
            return true;
        }

        $itemName = $data->itemName ?? null;
        if (!is_string($itemName) || $itemName === '') {
            return false;
        }

        $res = null;
        if ($currency === 'cash') {
            $res = getItemByName($itemName . '_cash', 'db');
        }
        $res = $res ?: getItemByName($itemName, 'db');
        if (!is_array($res)) {
            return false;
        }

        $market = is_string($res['market'] ?? null) ? trim($res['market']) : 'coins';
        $cashCost = (int) ($res['cash'] ?? 0);
        $cost = (int) ($res['cost'] ?? 0);
        if (($market === 'cash' || $currency === 'cash') && $cashCost > 0) {
            return UserResources::getCash($this->uid) >= $cashCost;
        }

        $unit = self::resolveWorldCurrency($currency, $res, getCurrentWorldType($this->uid));
        return $unit === null
            ? UserResources::getGold($this->uid) >= $cost
            : WorldCurrencyService::hasSufficient($this->uid, $unit, $cost);
    }

    public function newTransaction(string $type, object $data, ?string $currency = null){
        switch ($type){
            case ACTION_SELL:
                return $this->sellItem($data);
            case ACTION_HARVEST:
                return $this->harvestCrop($data);
            case ACTION_PLANT:
                return $this->buyItem($data, $currency);
            case ACTION_PLOW:
                return $this->plowLand();
            default:
                return false;
        }
    }

    public function sellItem(object $data){
        $res = getItemByName($data->itemName, "db");
        
        if ($res){
            $saleValue = (int) ($res["cost"] ?? 0);
            $saleValue = (int) ($saleValue * 0.05);
            return UserResources::addGold($this->uid, $saleValue);
        }

        return false;
    }

    /**
     * Harvest rewards are configured on a building's feature definition,
     * rather than on the item itself.  The item records can be returned from
     * the database with either arrays or stdClass values, so normalize both
     * shapes here before looking up the reward item.
     */
    private static function harvestRewardName(array $itemData): ?string
    {
        $reward = $itemData['harvestReward'] ?? null;

        if ($reward === null) {
            $features = $itemData['features'] ?? null;
            $featureList = [];

            if (is_array($features)) {
                $featureList = $features['feature'] ?? [];
            } elseif (is_object($features)) {
                $featureList = $features->feature ?? [];
            }

            if (is_object($featureList)) {
                $featureList = [$featureList];
            } elseif (is_array($featureList) && !array_key_exists(0, $featureList)) {
                $featureList = [$featureList];
            }

            foreach ($featureList as $feature) {
                if (is_array($feature)) {
                    $candidate = $feature['harvestReward'] ?? null;
                } elseif (is_object($feature)) {
                    $candidate = $feature->harvestReward ?? null;
                } else {
                    $candidate = null;
                }

                if ($candidate !== null) {
                    $reward = $candidate;
                    break;
                }
            }
        }

        if (is_array($reward)) {
            $reward = $reward['name'] ?? null;
        } elseif (is_object($reward)) {
            $reward = $reward->name ?? null;
        }

        $reward = is_string($reward) ? trim($reward) : '';
        return $reward !== '' ? $reward : null;
    }

    private function grantHarvestReward(array $itemData, string $harvestedItemName, int $quantity = 1): ?array
    {
        if ($quantity <= 0) {
            return null;
        }

        $rewardName = self::harvestRewardName($itemData);
        if ($rewardName === null) {
            return null;
        }

        $rewardItem = getItemByName($rewardName, "db");
        $rewardCode = is_array($rewardItem) ? ($rewardItem['code'] ?? '') : '';
        if (!is_string($rewardCode) || trim($rewardCode) === '') {
            Logger::warning('MarketTransactions', sprintf(
                'Harvest reward item missing: uid=%s harvested=%s reward=%s',
                $this->uid,
                $harvestedItemName,
                $rewardName
            ));
            return null;
        }

        addGiftByCode($this->uid, $rewardCode, $quantity, $this->uid, [
            'source' => 'harvest_reward',
            'harvestedItem' => $harvestedItemName,
            'rewardName' => $rewardName,
        ]);

        Logger::debug('MarketTransactions', sprintf(
            'Harvest reward granted: uid=%s harvested=%s reward=%s code=%s quantity=%d',
            $this->uid,
            $harvestedItemName,
            $rewardName,
            $rewardCode,
            $quantity
        ));

        return [
            'name' => $rewardName,
            'code' => $rewardCode,
            'quantity' => $quantity,
        ];
    }

    /**
     * Equipment harvests are submitted as one bulk request, so they do not
     * pass through newTransaction()/harvestCrop() one object at a time.
     * Keep the reward lookup and Giftbox write shared with normal harvests.
     */
    public function grantHarvestRewardsBatch(array $itemNames): array
    {
        $itemCounts = [];

        foreach ($itemNames as $itemName) {
            if (!is_string($itemName) || trim($itemName) === '') {
                continue;
            }

            $itemCounts[$itemName] = ($itemCounts[$itemName] ?? 0) + 1;
        }

        $rewards = [];
        foreach ($itemCounts as $itemName => $quantity) {
            $itemData = getItemByName($itemName, "db");
            if (!is_array($itemData)) {
                continue;
            }

            $reward = $this->grantHarvestReward($itemData, $itemName, $quantity);
            if ($reward !== null) {
                $rewards[] = $reward;
            }
        }

        return $rewards;
    }

    public function harvestCrop(object $data){
        $res = getItemByName($data->itemName, "db");

        if ($res){
            $coinYield = (int) ($res["coinYield"] ?? 0);
            $worldCurrency = $this->currentWorldCurrency();
            $success = $worldCurrency !== null
                ? WorldCurrencyService::grant($this->uid, $worldCurrency, $coinYield, 'harvest')
                : UserResources::addGold($this->uid, $coinYield);

            $masteryLevelUp = processMastery($this->uid, $res, 1);
            $harvestReward = $this->grantHarvestReward($res, (string) $data->itemName);

            return [
                'success' => $success,
                'masteryLevelUp' => $masteryLevelUp,
                'harvestReward' => $harvestReward
            ];
        }

        return ['success' => false, 'masteryLevelUp' => null, 'harvestReward' => null];
    }

    public function buyItem(object $data, ?string $currency = null){
        $itemName = $data->itemName ?? null;
        $res = null;
        $baseRes = null;

        if ($currency === "cash" && $itemName) {
            $cashVariant = $itemName . "_cash";
            $res = getItemByName($cashVariant, "db");
            if ($res) {
                $baseRes = getItemByName($itemName, "db");
            }
        }

        if (!$res && $itemName) {
            $res = getItemByName($itemName, "db");
        }

        if ($res){
            $market = $res["market"] ?? "coins";
            $cashCost = (int) ($res["cash"] ?? 0);
            $goldCost = (int) ($res["cost"] ?? 0);

            $costForXp = $baseRes ? (int) ($baseRes["cost"] ?? 0) : $goldCost;
            $buyXp = (int) floor($costForXp * self::BUY_XP_GAIN_RATIO);
            $buyXp = max($buyXp, self::BUY_XP_GAIN_MIN);

            $explicitXp = $res["plantXp"] ?? $res["buyXp"] ?? ($baseRes ? ($baseRes["plantXp"] ?? $baseRes["buyXp"] ?? null) : null);
            if ($explicitXp !== null && $explicitXp !== "") {
                $buyXp = (int) $explicitXp;
            }

            $worldCurrency = self::resolveWorldCurrency(
                $currency,
                $res,
                getCurrentWorldType($this->uid),
            );

            if (($market === "cash" || $currency === "cash") && $cashCost > 0) {
                $result1 = UserResources::removeCash($this->uid, $cashCost);
            } elseif ($worldCurrency !== null) {
                $result1 = WorldCurrencyService::spend(
                    $this->uid,
                    $worldCurrency,
                    $goldCost,
                    'market.buy',
                    ['itemName' => $itemName],
                );
            } else {
                $result1 = UserResources::removeGold($this->uid, $goldCost);
            }
            if (!$result1) return false;

            $result2 = UserResources::addXp($this->uid, $buyXp);
            return $result2;
        }

        return false;
    }

    public function plowLand(){
        $cost = 15;
        $plowXp = 1;
        $worldCurrency = $this->currentWorldCurrency();
        $result1 = $worldCurrency !== null
            ? WorldCurrencyService::spend($this->uid, $worldCurrency, $cost, 'plow')
            : UserResources::removeGold($this->uid, $cost);
        if (!$result1) return false;
        $result2 = UserResources::addXp($this->uid, $plowXp);
        return $result2;
    }

    
    public function plowLandBatch(int $count){
        if ($count <= 0) return true;
        $totalCost = 15 * $count;
        $totalXp = 1 * $count;
        $worldCurrency = $this->currentWorldCurrency();
        $result1 = $worldCurrency !== null
            ? WorldCurrencyService::spend($this->uid, $worldCurrency, $totalCost, 'plow.batch')
            : UserResources::removeGold($this->uid, $totalCost);
        if (!$result1) return false;
        $result2 = UserResources::addXp($this->uid, $totalXp);
        return $result2;
    }

    
    public static function calculatePlowDeltas(int $count, ?string $worldType = null): array {
        if ($count <= 0) return ['goldDelta' => 0, 'xpDelta' => 0, 'worldCurrencyDeltas' => []];

        $worldCurrency = self::worldCurrencyFor($worldType);
        return [
            'goldDelta' => $worldCurrency === null ? -(15 * $count) : 0,
            'xpDelta' => 1 * $count,
            'worldCurrencyDeltas' => $worldCurrency === null
                ? []
                : [$worldCurrency => -(15 * $count)],
        ];
    }

    
    public function harvestCropBatch(array $itemNames){
        if (empty($itemNames)) return ['success' => true, 'masteryLevelUps' => []];

        $totalCoins = 0;
        $itemCounts = [];
        $masteryLevelUps = [];

        foreach ($itemNames as $itemName) {
            $res = getItemByName($itemName, "db");
            if ($res) {
                $totalCoins += (int) ($res["coinYield"] ?? 0);
                $itemCounts[$itemName] = ($itemCounts[$itemName] ?? 0) + 1;
            }
        }

        if ($totalCoins > 0) {
            $worldCurrency = $this->currentWorldCurrency();
            if ($worldCurrency !== null) {
                WorldCurrencyService::grant($this->uid, $worldCurrency, $totalCoins, 'harvest.batch');
            } else {
                UserResources::addGold($this->uid, $totalCoins);
            }
        }

        foreach ($itemCounts as $itemName => $count) {
            $itemData = getItemByName($itemName, "db");
            if ($itemData) {
                $levelUp = processMastery($this->uid, $itemData, $count);
                if ($levelUp) {
                    $masteryLevelUps[] = $levelUp;
                }
            }
        }

        return ['success' => true, 'masteryLevelUps' => $masteryLevelUps];
    }

    
    public static function calculateHarvestDeltas(array $itemNames, ?string $worldType = null): array {
        if (empty($itemNames)) return ['goldDelta' => 0, 'xpDelta' => 0, 'itemCounts' => [], 'worldCurrencyDeltas' => []];

        $totalCoins = 0;
        $itemCounts = [];

        foreach ($itemNames as $itemName) {
            $res = getItemByName($itemName, "db");
            if ($res) {
                $totalCoins += (int) ($res["coinYield"] ?? 0);
                $itemCounts[$itemName] = ($itemCounts[$itemName] ?? 0) + 1;
            }
        }

        $worldCurrency = self::worldCurrencyFor($worldType);
        return [
            'goldDelta' => $worldCurrency === null ? $totalCoins : 0,
            'xpDelta' => 0,
            'itemCounts' => $itemCounts,
            'worldCurrencyDeltas' => $worldCurrency === null || $totalCoins === 0
                ? []
                : [$worldCurrency => $totalCoins],
        ];
    }

    
    public function buyItemBatch(string $itemName, int $count, ?string $currency = null){
        if ($count <= 0 || empty($itemName)) return true;

        $res = null;
        $baseRes = null;

        if ($currency === "cash") {
            $cashVariant = $itemName . "_cash";
            $res = getItemByName($cashVariant, "db");
            if ($res) {
                $baseRes = getItemByName($itemName, "db");
            }
        }

        if (!$res) {
            $res = getItemByName($itemName, "db");
        }

        if (!$res) return false;

        $market = $res["market"] ?? "coins";
        $cashCost = (int) ($res["cash"] ?? 0);
        $goldCost = (int) ($res["cost"] ?? 0);

        $costForXp = $baseRes ? (int) ($baseRes["cost"] ?? 0) : $goldCost;
        $buyXp = (int) floor($costForXp * self::BUY_XP_GAIN_RATIO);
        $buyXp = max($buyXp, self::BUY_XP_GAIN_MIN);

        $explicitXp = $res["plantXp"] ?? $res["buyXp"] ?? ($baseRes ? ($baseRes["plantXp"] ?? $baseRes["buyXp"] ?? null) : null);
        if ($explicitXp !== null && $explicitXp !== "") {
            $buyXp = (int) $explicitXp;
        }

        $totalGold = $goldCost * $count;
        $totalCash = $cashCost * $count;
        $totalXp = $buyXp * $count;
        $worldCurrency = self::resolveWorldCurrency(
            $currency,
            $res,
            getCurrentWorldType($this->uid),
        );

        if (($market === "cash" || $currency === "cash") && $totalCash > 0) {
            $result1 = UserResources::removeCash($this->uid, $totalCash);
        } elseif ($worldCurrency !== null) {
            $result1 = WorldCurrencyService::spend(
                $this->uid,
                $worldCurrency,
                $totalGold,
                'market.buy.batch',
                ['itemName' => $itemName, 'count' => $count],
            );
        } else {
            $result1 = UserResources::removeGold($this->uid, $totalGold);
        }
        if (!$result1) return false;

        if ($totalXp > 0) {
            UserResources::addXp($this->uid, $totalXp);
        }

        return true;
    }

    
    public static function calculateBuyDeltas(
        string $itemName,
        int $count,
        ?string $currency = null,
        ?string $worldType = null,
    ): array {
        if ($count <= 0 || empty($itemName)) {
            return ['goldDelta' => 0, 'xpDelta' => 0, 'cashDelta' => 0, 'worldCurrencyDeltas' => []];
        }

        $res = null;
        $baseRes = null;

        if ($currency === "cash") {
            $cashVariant = $itemName . "_cash";
            $res = getItemByName($cashVariant, "db");
            if ($res) {
                $baseRes = getItemByName($itemName, "db");
            }
        }

        if (!$res) {
            $res = getItemByName($itemName, "db");
        }

        if (!$res) {
            return ['goldDelta' => 0, 'xpDelta' => 0, 'cashDelta' => 0, 'worldCurrencyDeltas' => []];
        }

        $market = $res["market"] ?? "coins";
        $cashCost = (int) ($res["cash"] ?? 0);
        $goldCost = (int) ($res["cost"] ?? 0);

        $costForXp = $baseRes ? (int) ($baseRes["cost"] ?? 0) : $goldCost;
        $buyXp = (int) floor($costForXp * self::BUY_XP_GAIN_RATIO);
        $buyXp = max($buyXp, self::BUY_XP_GAIN_MIN);

        $explicitXp = $res["plantXp"] ?? $res["buyXp"] ?? ($baseRes ? ($baseRes["plantXp"] ?? $baseRes["buyXp"] ?? null) : null);
        if ($explicitXp !== null && $explicitXp !== "") {
            $buyXp = (int) $explicitXp;
        }

        $totalXp = $buyXp * $count;
        $worldCurrency = self::resolveWorldCurrency($currency, $res, $worldType);

        if (($market === "cash" || $currency === "cash") && $cashCost > 0) {
            return [
                'goldDelta' => 0,
                'xpDelta' => $totalXp,
                'cashDelta' => -($cashCost * $count),
                'worldCurrencyDeltas' => [],
            ];
        } elseif ($worldCurrency !== null) {
            return [
                'goldDelta' => 0,
                'xpDelta' => $totalXp,
                'cashDelta' => 0,
                'worldCurrencyDeltas' => [$worldCurrency => -($goldCost * $count)],
            ];
        } else {
            return [
                'goldDelta' => -($goldCost * $count),
                'xpDelta' => $totalXp,
                'cashDelta' => 0,
                'worldCurrencyDeltas' => [],
            ];
        }
    }
}
