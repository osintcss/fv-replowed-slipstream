<?php

declare(strict_types=1);

/**
 * FarmVille's original coin farm expansions require a Facebook-neighbour
 * count. The restored service has no Facebook graph, so Flash filters every
 * coin expansion out of the Market. Keep the original sizes and coin prices,
 * but turn their unlock into the normal level-1 unlock used by the cash
 * alternative. This leaves the progression and FarmService purchase path
 * intact while making expansion possible offline.
 */

$basePath = __DIR__ . '/../public/farmville/xml/gz/v855038';
$files = [
    ['path' => $basePath . '/items.xml', 'compressed' => false],
    ['path' => $basePath . '/items1.xml', 'compressed' => false],
    ['path' => $basePath . '/items.xml.gz', 'compressed' => true],
    ['path' => $basePath . '/items.xml.gz1', 'compressed' => true],
];

$patchExpansions = static function (string $xml): array {
    $patchedCount = 0;
    $xml = preg_replace_callback(
        '#<item\b(?=[^>]*\bname="farm\d+")(?=[^>]*\bsubtype="expand_farm")[^>]*>.*?</item>#s',
        static function (array $match) use (&$patchedCount): string {
            $item = $match[0];

            // Cash alternatives already have a level unlock. Only the coin
            // alternative depended on the defunct Facebook neighbour graph.
            if (str_contains($item, '_cash"')) {
                return $item;
            }

            $changed = false;
            if (preg_match('/\sunlock="(?:neighbor|level_and_neighbor)"/', $item)) {
                $item = preg_replace(
                    '/\sunlock="(?:neighbor|level_and_neighbor)"/',
                    ' unlock="level"',
                    $item,
                    1
                );
                $changed = true;
            }

            // The larger expansions add old live-service gates such as
            // I001, plus experiment gates. Flash dereferences those IDs from
            // a legacy table that is no longer supplied, causing Error #1069
            // while it populates the Market. They have no local equivalent.
            $withoutGates = preg_replace(
                [
                    '/\sgateCode="[^"]*"/',
                    '#\s*<(?:minneighbors|gateCode|experimentGate)>.*?</(?:minneighbors|gateCode|experimentGate)>#is',
                ],
                '',
                $item
            );
            if ($withoutGates === null) {
                throw new RuntimeException('Could not remove obsolete farm expansion gates.');
            }
            $changed = $changed || $withoutGates !== $item;
            $item = $withoutGates;

            if (!str_contains($item, '<requiredLevel>')) {
                $item = preg_replace('/>/', ">\n\t\t\t<requiredLevel>1</requiredLevel>", $item, 1);
                $changed = true;
            }

            if ($changed) {
                $patchedCount++;
            }
            return $item;
        },
        $xml
    );

    if ($xml === null) {
        throw new RuntimeException('Could not patch farm expansion XML.');
    }

    return [$xml, $patchedCount];
};

$totalPatched = 0;
foreach ($files as $file) {
    if (!is_file($file['path'])) {
        throw new RuntimeException("Expansion settings file not found: {$file['path']}");
    }

    $contents = file_get_contents($file['path']);
    if ($contents === false) {
        throw new RuntimeException("Could not read expansion settings: {$file['path']}");
    }

    $xml = $file['compressed'] ? @gzuncompress($contents) : $contents;
    if ($xml === false) {
        throw new RuntimeException("Could not decompress expansion settings: {$file['path']}");
    }

    [$patchedXml, $patchedCount] = $patchExpansions($xml);
    if ($patchedCount === 0) {
        throw new RuntimeException("No neighbour-gated farm expansions found in {$file['path']}");
    }

    $output = $file['compressed'] ? gzcompress($patchedXml, 9) : $patchedXml;
    if ($output === false || file_put_contents($file['path'], $output) === false) {
        throw new RuntimeException("Could not write expansion settings: {$file['path']}");
    }

    $totalPatched += $patchedCount;
}

fwrite(STDOUT, "Patched {$totalPatched} neighbour-gated farm expansion entries for offline play.\n");
