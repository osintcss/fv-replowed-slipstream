<?php

$path = __DIR__.'/../public/farmville/xml/gz/v855038/yimf.xml.gz';
$compressed = file_get_contents($path);

if ($compressed === false) {
    throw new RuntimeException("Unable to read {$path}");
}

$xml = gzuncompress($compressed);
if ($xml === false) {
    throw new RuntimeException("Unable to decompress {$path}");
}

$weights = '18,36,54,55,56,57,58,59';

foreach (['asia', 'hawaii'] as $type) {
    $opening = '<yimf type="'.$type.'">';
    if (substr_count($xml, $opening) !== 1) {
        throw new RuntimeException("Expected exactly one {$type} Yimf entry.");
    }

    $entryStart = strpos($xml, $opening);
    $entryEnd = strpos($xml, '</yimf>', $entryStart);
    if ($entryEnd === false) {
        throw new RuntimeException("{$type} Yimf entry is not closed.");
    }

    $entry = substr($xml, $entryStart, $entryEnd - $entryStart);
    $entry = preg_replace(
        '/<weights>.*?<\/weights>/s',
        "\t\t<weights>{$weights}</weights>",
        $entry,
        1,
        $weightCount,
    );
    if ($weightCount === 0) {
        $entry = str_replace(
            $opening,
            $opening."\n\t\t<weights>{$weights}</weights>",
            $entry,
        );
    }

    $entry = preg_replace(
        '/<tileClass>.*?<\/tileClass>/s',
        "\t\t<tileClass>grass</tileClass>",
        $entry,
        1,
        $tileClassCount,
    );
    if ($tileClassCount === 0) {
        $entry = str_replace(
            $opening,
            $opening."\n\t\t<tileClass>grass</tileClass>",
            $entry,
        );
    }

    $xml = substr_replace($xml, $entry, $entryStart, $entryEnd - $entryStart);
}

$patched = gzcompress($xml, 9);
if ($patched === false) {
    throw new RuntimeException("Unable to recompress {$path}");
}

$temporaryPath = $path.'.tmp';
if (file_put_contents($temporaryPath, $patched) === false || !rename($temporaryPath, $path)) {
    @unlink($temporaryPath);
    throw new RuntimeException("Unable to write {$path}");
}
