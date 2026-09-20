<?php

$maxRequestBytes = (int) (getenv('AMF_MAX_REQUEST_BYTES') ?: 16 * 1024 * 1024);
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;

if ($maxRequestBytes > 0 && $contentLength > $maxRequestBytes) {
    http_response_code(413);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'AMF request body is too large.';
    exit;
}

include "amfphp/index.php";
