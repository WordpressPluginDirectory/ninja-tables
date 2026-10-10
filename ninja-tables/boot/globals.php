<?php

if (!defined('ABSPATH')) {
    die(__FILE__);
}

/**
 ***** DO NOT CALL ANY FUNCTIONS DIRECTLY FROM THIS FILE ******
 *
 * This file will be loaded even before the framework is loaded
 * so the $app is not available here, only declare functions here.
 */

if ($app->config->get('app.env') == 'dev') {

    $ninjaTablesGlobalsDevFile = dirname(__DIR__) . '/dev/globals.php';
    
    is_readable($ninjaTablesGlobalsDevFile) && include $ninjaTablesGlobalsDevFile;
}

if (!function_exists('dd')) {
    function dd() // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
    {
        foreach (func_get_args() as $arg) {
            echo "<pre>";
            print_r($arg); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
            echo "</pre>";
        }
        die();
    }
}

include_once dirname(__DIR__) . '/app/Functions/globals.php';
