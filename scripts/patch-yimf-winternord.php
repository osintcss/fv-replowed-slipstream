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

$opening = '<yimf type="winternord_theme">';
$weights = '<weights>10,19,29,38,47,56,57,58,59,60</weights>';
$tileClass = '<tileClass>snow</tileClass>';
$toolGenerated = '<toolGenerated>1</toolGenerated>';
$backgroundPath = '<backgroundPath>assets/Environment/xwx_themeBackground_80.swf</backgroundPath>';

if (substr_count($xml, $opening) !== 1) {
    throw new RuntimeException('Expected exactly one winternord_theme Yimf entry.');
}

$entryStart = strpos($xml, $opening);
$entryEnd = strpos($xml, '</yimf>', $entryStart);
if ($entryEnd === false) {
    throw new RuntimeException('winternord_theme Yimf entry is not closed.');
}

$entry = substr($xml, $entryStart, $entryEnd - $entryStart);
if (strpos($entry, '<weights>') === false || strpos($entry, '<tileClass>') === false) {
    $replacement = $opening."\n\t\t{$weights}\n\t\t{$tileClass}";
    $xml = substr_replace($xml, $replacement, $entryStart, strlen($opening));

    $entryStart = strpos($xml, $opening);
    $entryEnd = strpos($xml, '</yimf>', $entryStart);
    $entry = substr($xml, $entryStart, $entryEnd - $entryStart);
}

if (strpos($entry, '<toolGenerated>') === false) {
    $entry .= "\n\t\t{$toolGenerated}";
}

if (preg_match('/<backgroundPath>.*?<\/backgroundPath>/s', $entry) === 1) {
    $entry = preg_replace(
        '/<backgroundPath>.*?<\/backgroundPath>/s',
        $backgroundPath,
        $entry,
        1,
    );
} else {
    $entry .= "\n\t\t{$backgroundPath}";
}

$xml = substr_replace($xml, $entry, $entryStart, $entryEnd - $entryStart);

$patched = gzcompress($xml, 9);
if ($patched === false) {
    throw new RuntimeException("Unable to recompress {$path}");
}

$temporaryPath = $path.'.tmp';
if (file_put_contents($temporaryPath, $patched) === false || !rename($temporaryPath, $path)) {
    @unlink($temporaryPath);
    throw new RuntimeException("Unable to write {$path}");
}
