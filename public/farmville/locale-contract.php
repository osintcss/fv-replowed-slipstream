<?php

// Temporary trace for the locale loader. The rewrite targets only en_US.swf;
// all other XML and asset requests stay on the normal full-tree alias.
$asset = __DIR__ . '/xml/gz/v855038/en_US.swf';

if (!is_file($asset)) {
    http_response_code(404);
    exit;
}

error_log('[FarmVille locale] locale SWF requested by Flash');

header('Content-Type: application/vnd.adobe.flash.movie');
header('Content-Length: ' . filesize($asset));
header('Cache-Control: no-store');
readfile($asset);
