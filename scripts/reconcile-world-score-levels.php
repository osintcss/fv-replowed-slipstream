<?php

declare(strict_types=1);

use App\Models\PlayerMeta;
use App\Support\WorldScoreConfig;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (!defined('AMFPHP_ROOTPATH')) {
    define('AMFPHP_ROOTPATH', public_path('farmville/flashservices/amfphp/').DIRECTORY_SEPARATOR);
}

require_once AMFPHP_ROOTPATH.'Helpers/constants.php';
require_once AMFPHP_ROOTPATH.'Helpers/logger.php';
require_once AMFPHP_ROOTPATH.'Helpers/general_functions.php';

$apply = in_array('--apply', $argv, true);
$groups = [];

foreach (PlayerMeta::query()
    ->where('meta_key', 'like', 'world_score_%')
    ->where('meta_key', 'not like', 'world_score_level_%')
    ->orderBy('uid')
    ->orderBy('id')
    ->cursor() as $row) {
    $suffix = substr((string) $row->meta_key, strlen('world_score_'));
    $worldType = getWorldTypeForScoreUnit($suffix);
    $scoreUnit = $worldType === null ? null : getWorldScoreUnitForWorldType($worldType);
    if ($worldType === null || $worldType === 'farm' || $scoreUnit === null
        || WorldScoreConfig::levelForScore($scoreUnit, 0) === null) {
        continue;
    }

    $groups[(string) $row->uid."\0$worldType"] = [(string) $row->uid, $worldType, $scoreUnit];
}

$repairs = 0;
foreach ($groups as [$uid, $worldType, $scoreUnit]) {
    $suffixes = WorldScoreConfig::metadataSuffixesForWorld($worldType);
    $scoreKeys = array_map(static fn (string $suffix): string => "world_score_$suffix", $suffixes);
    $levelKeys = array_map(static fn (string $suffix): string => "world_score_level_$suffix", $suffixes);
    $rows = PlayerMeta::query()
        ->where('uid', $uid)
        ->whereIn('meta_key', array_merge($scoreKeys, $levelKeys))
        ->get(['meta_key', 'meta_value']);

    $score = 0;
    $needsRepair = false;
    $present = [];
    foreach ($rows as $row) {
        $metaKey = (string) $row->meta_key;
        $present[$metaKey] = true;
        if (str_starts_with($metaKey, 'world_score_level_')) {
            continue;
        }
        if (is_numeric($row->meta_value)) {
            $score = max($score, max(0, (int) $row->meta_value));
        }
    }

    $level = WorldScoreConfig::levelForScore($scoreUnit, $score);
    if ($level === null) {
        continue;
    }

    foreach ($rows as $row) {
        $metaKey = (string) $row->meta_key;
        $expected = str_starts_with($metaKey, 'world_score_level_') ? $level : $score;
        if (!is_numeric($row->meta_value) || (int) $row->meta_value !== $expected) {
            $needsRepair = true;
            break;
        }
    }
    $needsRepair = $needsRepair
        || !isset($present["world_score_$worldType"])
        || !isset($present["world_score_level_$worldType"]);

    if (!$needsRepair) {
        continue;
    }

    $repairs++;
    if ($apply) {
        reconcileWorldScoreLevel($uid, $worldType);
    }
}

$mode = $apply ? 'applied' : 'dry-run';
fwrite(STDOUT, "$mode: $repairs world-score state".($repairs === 1 ? '' : 's')." reconciled\n");
