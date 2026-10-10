<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use NinjaTables\Framework\Foundation\Application;
use NinjaTables\App\Hooks\Handlers\ActivationHandler;
use NinjaTables\App\Hooks\Handlers\DeactivationHandler;
use NinjaTables\App\Hooks\Handlers\NoticeHandler;

return function ($file) {

    $app = new Application($file);

    register_activation_hook($file, function ($netWorkWide) use ($app) {
        ($app->make(ActivationHandler::class))->handle($netWorkWide);
    });

    register_deactivation_hook($file, function () use ($app) {
        ($app->make(DeactivationHandler::class))->handle();
    });

    add_action('plugins_loaded', function () use ($app) {

        ninja_tables_maybe_disable_contaminated_pro();

        // A Pro build older than the framework rewrite cannot run against this core: stop here.
        if (defined('NINJAPROPLUGIN_VERSION') && !defined('NINJA_TABLE_PRO_FRAMEWORK_VERSION')) {
            add_action('admin_notices', [NoticeHandler::class, 'proUpdateRequired']);
            return;
        }

        do_action('ninjatables_loaded', $app);
    });
};
