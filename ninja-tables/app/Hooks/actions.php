<?php

if (!defined('ABSPATH')) {
    die(__FILE__);
}

/**
 * Hook registration only. Every handler/module exposes register() and owns its
 * add_action/add_filter calls; nothing here should contain logic.
 *
 * @var $app NinjaTables\Framework\Foundation\Application
 */

use NinjaTables\App\Hooks\Handlers;
use NinjaTables\App\Modules;
use NinjaTables\Database\DBMigrator;
use NinjaTables\Database\Migrations\NinjaTableItemsMigrator;

// Core
$app->make(Handlers\CPTHandler::class)->register();
$app->make(Handlers\DataProviderHandler::class)->register();
$app->make(Handlers\SettingHandler::class)->register();
$app->make(Handlers\CompatHandler::class)->register();
$app->addAction('ninja_table_check_db_integrity', [NinjaTableItemsMigrator::class, 'checkDBMigrations']);
$app->addAction('wp_initialize_site', [DBMigrator::class, 'handleNewSite']);

// Admin
$app->make(Handlers\AdminMenuHandler::class)->register();
$app->make(Handlers\StyleHandler::class)->register();
$app->make(Handlers\NoticeHandler::class)->register();
$app->make(Handlers\NinjaTableAdminHandler::class)->register();
$app->make(Handlers\EditorBlockHandler::class)->register();
$app->make(Handlers\DeactivationHandler::class)->register();
$app->make(Handlers\ExportHandler::class)->register();
$app->make(Modules\Lead\LeadFlow::class)->register();

// Frontend rendering
$app->make(Handlers\PublicDataHandler::class)->register();
$app->make(Handlers\PreviewHandler::class)->register();
$app->make(Handlers\AjaxHandler::class)->register();

// Modules / integrations
$app->make(Modules\DataTables\DataTablesModule::class)->register();
$app->make(Modules\Integrations\IntegrationsInit::class)->register();
