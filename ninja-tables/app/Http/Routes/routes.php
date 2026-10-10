<?php

/**
 * @var $router NinjaTables\Framework\Http\Router
 */

if (!defined('ABSPATH')) {
    exit;
}

$router->namespace('NinjaTables\App\Http\Controllers')->withPolicy('AdminPolicy')->group(function ($router) {
    require_once __DIR__ . '/api.php';
});
