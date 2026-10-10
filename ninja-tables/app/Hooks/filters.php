<?php

if (!defined('ABSPATH')) {
    die(__FILE__);
}

/**
 * Filter registration only — see actions.php for the convention.
 *
 * @var $app NinjaTables\Framework\Foundation\Application
 */

use NinjaTables\App\Hooks\Handlers;

$app->make(Handlers\PluginLinksHandler::class)->register();
