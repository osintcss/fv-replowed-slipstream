<?php

declare(strict_types=1);

// The archived Maple Frontier theme has its original horizon and colors but
// lacks the tile fields required to construct a playable YimfMap.
$path = __DIR__.'/../public/farmville/xml/gz/v855038/yimf.xml.gz';
$compressed = file_get_contents($path);
if ($compressed === false || ($xml = gzuncompress($compressed)) === false) {
    throw new RuntimeException("Unable to read the Yimf catalog: {$path}");
}

$opening = '<yimf type="canada_theme">';
if (substr_count($xml, $opening) !== 1) {
    throw new RuntimeException('Expected exactly one canada_theme Yimf entry.');
}

$start = strpos($xml, $opening);
$end = strpos($xml, '</yimf>', $start);
if ($end === false) {
    throw new RuntimeException('canada_theme Yimf entry is not closed.');
}

$entry = substr($xml, $start, $end - $start);
foreach ([
    'weights' => '18,36,54,55,56,57,58,59',
    'tileClass' => 'grass',
    'backgroundPath' => 'assets/Environment/xcd_themeBackground_80.swf',
    'toolGenerated' => '1',
] as $tag => $value) {
    $replacement = "<{$tag}>{$value}</{$tag}>";
    if (preg_match('/<'.preg_quote($tag, '/').'>.*?<\/'.preg_quote($tag, '/').'>/s', $entry)) {
        $entry = preg_replace('/<'.preg_quote($tag, '/').'>.*?<\/'.preg_quote($tag, '/').'>/s', $replacement, $entry, 1);
    } else {
        $entry = str_replace($opening, $opening."\n\t\t".$replacement, $entry);
    }
}

$xml = substr_replace($xml, $entry, $start, $end - $start);
$patched = gzcompress($xml, 9);
if ($patched === false) {
    throw new RuntimeException('Unable to compress the Maple Frontier Yimf catalog.');
}

$temporaryPath = $path.'.tmp';
if (file_put_contents($temporaryPath, $patched) === false || !rename($temporaryPath, $path)) {
    @unlink($temporaryPath);
    throw new RuntimeException("Unable to write {$path}");
}
