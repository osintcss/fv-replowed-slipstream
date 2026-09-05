<?php

require_once AMFPHP_ROOTPATH . 'Helpers/general_functions.php';
require_once AMFPHP_ROOTPATH . 'Helpers/user_resources.php';

/** Server persistence for FarmVille's original Greenhouse seed projects. */
class BreedingService
{
    private const FEATURE = 'greenhousebuildable_finished';
    private const STATE_KEY = 'greenhouse_breeding_state';
    private const PROJECT_SECONDS = 259200;
    private const SEED_QUANTITY = 50;
    private const PACKAGE_QUANTITY = 1;

    public static function beginNewBreedingProject($playerObj, $request): array
    {
        $feature = (string) ($request->params[0] ?? '');
        $tray = (int) ($request->params[1] ?? -1);
        $ingredients = $request->params[2] ?? [];
        $recipe = self::recipeForIngredients($ingredients);
        if ($feature !== self::FEATURE || $tray < 0 || $tray >= 8 || $recipe === null) {
            return ['data' => ['success' => false]];
        }
        $uid = $playerObj->getUid();
        $state = self::state($uid);
        if (isset($state['trays'][$tray])) return ['data' => ['success' => false]];
        if (!UserResources::removeGold($uid, $recipe['cost'])) return ['data' => ['success' => false]];

        $trayInfo = [
            'itemIngredients' => [
                ['code' => $recipe['ingredients'][0], 'quantity' => self::SEED_QUANTITY],
                ['code' => $recipe['ingredients'][1], 'quantity' => self::SEED_QUANTITY],
            ],
            'startTime' => (string) time(),
            'helpingFriendIds' => [],
            'purchaseHelp' => 0,
        ];
        $state['trays'][$tray] = ['tray' => $trayInfo, 'trayResult' => $recipe['resultCode']];
        self::saveState($uid, $state);
        return ['data' => ['featureName' => self::FEATURE, 'trayIndex' => $tray, 'trayInfo' => $trayInfo, 'trayResult' => $recipe['resultCode']]];
    }

    public static function finishBreedingProject($playerObj, $request): array
    {
        $feature = (string) ($request->params[0] ?? '');
        $tray = (int) ($request->params[1] ?? -1);
        $uid = $playerObj->getUid();
        $state = self::state($uid);
        $entry = $state['trays'][$tray] ?? null;
        if ($feature !== self::FEATURE || !is_array($entry) || !is_array($entry['tray'] ?? null)) return ['data' => ['success' => false]];
        $info = $entry['tray'];
        $reducedDays = count($info['helpingFriendIds'] ?? []) + (int) ($info['purchaseHelp'] ?? 0);
        if (time() < (int) ($info['startTime'] ?? 0) + self::PROJECT_SECONDS - (86400 * $reducedDays)) return ['data' => ['success' => false]];
        $packageCode = (string) ($entry['trayResult'] ?? '');
        $package = getItemByCode($packageCode);
        if (!is_array($package) || empty($package['code'])) return ['data' => ['success' => false]];
        addToInventoryStorage($uid, $package['code'], self::SEED_QUANTITY);
        unset($state['trays'][$tray]);
        self::saveState($uid, $state);
        return ['data' => ['quantityCreated' => self::PACKAGE_QUANTITY, 'bredItemCode' => $package['code']]];
    }

    public static function addPurchasedHelp($playerObj, $request): array
    {
        $feature = (string) ($request->params[0] ?? '');
        $tray = (int) ($request->params[1] ?? -1);
        $uid = $playerObj->getUid(); $state = self::state($uid);
        if ($feature !== self::FEATURE || !isset($state['trays'][$tray]['tray'])) return ['data' => null];
        if (!UserResources::removeCash($uid, 2)) return ['data' => null];
        ++$state['trays'][$tray]['tray']['purchaseHelp']; self::saveState($uid, $state);
        return ['data' => ['price' => 2, 'amount' => 1]];
    }

    private static function state($uid): array { $raw = get_meta($uid, self::STATE_KEY); $state = is_string($raw) ? (@unserialize($raw) ?: []) : []; return ['trays' => is_array($state['trays'] ?? null) ? $state['trays'] : []]; }
    private static function saveState($uid, array $state): void { set_meta($uid, self::STATE_KEY, serialize($state)); }
    private static function recipeForIngredients($ingredients): ?array
    {
        if (!is_array($ingredients) || count($ingredients) !== 2) return null;
        $codes = []; foreach ($ingredients as $ingredient) { $code = is_object($ingredient) ? ($ingredient->code ?? '') : ($ingredient['code'] ?? ''); if (!is_string($code) || $code === '') return null; $codes[] = $code; } sort($codes);
        foreach ([['strapsberry','strawberry','raspberry'],['purpletomato','blueberry','tomato'],['long_onion','onion','leeks'],['sunpoppy','sunflowers','goldenpoppy'],['whiskeypete','rye','corn'],['firepeppers','peppers','jalapeno'],['squashkin','pumpkin','squash'],['redspinach','spinach','rhubarb'],['lilacdaffy','lilac','daffodils']] as [$resultName,$firstName,$secondName]) {
            $result=getItemByName($resultName,'db'); $first=getItemByName($firstName,'db'); $second=getItemByName($secondName,'db');
            if (!is_array($result)||!is_array($first)||!is_array($second)||empty($result['code'])||empty($first['code'])||empty($second['code'])) continue;
            $expected=[$first['code'],$second['code']]; sort($expected); if ($codes !== $expected) continue;
            $package = getItemByName($resultName . '_seedpackage', 'db');
            if (!is_array($package) || empty($package['code'])) continue;
            return ['resultCode'=>$package['code'],'ingredients'=>[$first['code'],$second['code']], 'cost'=>self::SEED_QUANTITY*((int)($first['cost']??0)+(int)($second['cost']??0))];
        }
        return null;
    }
}
