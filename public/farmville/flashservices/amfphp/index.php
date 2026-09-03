<?php
// Timing: capture request arrival time
$_SERVER['REQUEST_TIME_FLOAT'] = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
$GLOBALS['_amf_start'] = microtime(true);
try {
    $GLOBALS['_amf_request_id'] = bin2hex(random_bytes(8));
} catch (\Throwable $e) {
    $GLOBALS['_amf_request_id'] = substr(sha1(uniqid('', true)), 0, 16);
}

/**
 * Bootstrap Laravel for Eloquent ORM
 * This allows AMFPHP to use Laravel's Eloquent models, DB facade, and caching
 */
require_once __DIR__ . '/../../../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

/**
 *  This file is part of amfPHP
 *
 * LICENSE
 *
 * This source file is subject to the license that is bundled
 * with this package in the file license.txt.
 */

/**
*  includes
 * @package Amfphp
*  */
require_once dirname(__FILE__) . '/ClassLoader.php';
require_once AMFPHP_ROOTPATH . 'Helpers/logger.php';

Logger::initialize();
register_shutdown_function(function () {
    $lastError = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    if ($lastError !== null && in_array($lastError['type'], $fatalTypes, true)) {
        Logger::error('AMFRequest', 'Fatal error during AMF request', [
            'request_id' => Logger::requestId(),
            'duration_ms' => round((microtime(true) - ($GLOBALS['_amf_start'] ?? microtime(true))) * 1000, 1),
            'type' => $lastError['type'],
            'message' => $lastError['message'],
            'file' => $lastError['file'],
            'line' => $lastError['line'],
        ]);
    }
});

/* 
 * main entry point (gateway) for service calls. instanciates the gateway class and uses it to handle the call.
 * 
 * @package Amfphp
 * @author Ariel Sommeria-klein
 */
$gateway = Amfphp_Core_HttpRequestGatewayFactory::createGateway();

//use this to change the current folder to the services folder. Be careful of the case.
//This was done in 1.9 and can be used to support relative includes, and should be used when upgrading from 1.9 to 2.0 if you use relative includes
//chdir(dirname(__FILE__) . '/Services');

try {
    $gateway->service();
    $gateway->output();
} catch (\Throwable $exception) {
    Logger::error('AMFRequest', 'Uncaught exception during AMF request', [
        'request_id' => Logger::requestId(),
        'duration_ms' => round((microtime(true) - ($GLOBALS['_amf_start'] ?? microtime(true))) * 1000, 1),
        'class' => get_class($exception),
        'message' => $exception->getMessage(),
    ]);

    throw $exception;
}


?>
