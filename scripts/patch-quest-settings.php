<?php

declare(strict_types=1);

/**
 * FarmQuest's client prediction resets non-sticky task counters after each
 * world action. Mark the actions backed by our server-side tracker as
 * authoritative so Flash uses the QuestComponent returned by the AMF response
 * instead. The archived file is zlib-compressed despite its .gz extension.
 */
$path = __DIR__ . '/../public/farmville/xml/gz/v855038/questSettings_0.xml.gz';
$compressed = @file_get_contents($path);

if ($compressed === false) {
    throw new RuntimeException("Quest settings archive not found: {$path}");
}

$xml = @gzuncompress($compressed);
if ($xml === false) {
    throw new RuntimeException("Could not decompress quest settings archive: {$path}");
}

// Only mark actions that the PHP implementation persists authoritatively.
$serverTrackedActions = [
    'harvestByCode',
    'harvestByCategory',
    'plantCropByCode',
    'plantCropByCategory',
    'plowPlot',
    'useItemByCode',
];

$patchedActions = [];

foreach ($serverTrackedActions as $action) {
    $pattern = '/<taskType\\b(?=[^>]*\\bname="' . preg_quote($action, '/') . '")[^>]*>/';
    $replacementCount = 0;
    $xml = preg_replace_callback($pattern, static function (array $match) use (&$replacementCount): string {
        $replacementCount++;
        $tag = $match[0];

        if (preg_match('/\\brequireServerReponse="[^"]*"/', $tag) === 1) {
            return preg_replace('/\\brequireServerReponse="[^"]*"/', 'requireServerReponse="true"', $tag, 1);
        }

        return substr($tag, 0, -1) . ' requireServerReponse="true">';
    }, $xml);

    if ($replacementCount === 0) {
        throw new RuntimeException("Task type was not found in quest settings: {$action}");
    }

    $patchedActions[] = $action;
}

$patched = gzcompress($xml, 9);
if ($patched === false || file_put_contents($path, $patched) === false) {
    throw new RuntimeException("Could not write patched quest settings archive: {$path}");
}

fwrite(STDOUT, 'Patched quest settings for server-authoritative task progress: '
    . implode(', ', $patchedActions) . ".\n");
