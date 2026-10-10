<?php

/**
 * REST API: /wp-json/ninjatables/v2/*
 * One prefix()->group() block per resource; AdminPolicy is applied once on the
 * outer namespace group in routes.php and inherited by every nested group.
 *
 * @var $router NinjaTables\Framework\Http\Router
 */

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Http\Controllers\FluentFormsController;
use NinjaTables\App\Http\Controllers\ImportController;
use NinjaTables\App\Http\Controllers\PluginInstallerController;
use NinjaTables\App\Http\Controllers\SettingsController;
use NinjaTables\App\Http\Controllers\TableBuilderController;
use NinjaTables\App\Http\Controllers\TableItemsController;
use NinjaTables\App\Http\Controllers\TablesController;
use NinjaTables\App\Http\Controllers\ToolsController;
use NinjaTables\App\Http\Controllers\WPPostsController;

// Tables
$router->prefix('tables')->group(function ($router) {
    $router->get('/', [TablesController::class, 'index']);
    $router->post('/', [TablesController::class, 'store']);
    $router->post('/dismiss-fluent-suggest', [TablesController::class, 'dismissFluentSuggest']);
    $router->delete('/bulk-delete', [TablesController::class, 'bulkDeleteTables']);
    $router->post('/bulk-restore', [TablesController::class, 'bulkRestoreTables']);

    // Tools (declared before /{id} so "tools" is never matched as an id)
    $router->prefix('/tools')->group(function ($router) {
        $router->get('/default-settings', [ToolsController::class, 'getDefaultSettings']);
        $router->post('/default-settings', [ToolsController::class, 'saveDefaultSettings']);
        $router->get('/permission', [ToolsController::class, 'getAccessRoles']);
        $router->get('/global-settings', [ToolsController::class, 'getGlobalSettings']);
        $router->post('/global-settings', [ToolsController::class, 'updateGlobalSettings']);
        $router->post('/clear-table-cache', [ToolsController::class, 'clearTableCache']);
        $router->post('/clear-external-cache', [ToolsController::class, 'clearExternalTableCache']);
    });

    $router->prefix('/{id}')->group(function ($router) {
        $router->delete('/', [TablesController::class, 'delete'])->int('id');
        $router->delete('/bulk-delete-columns', [TablesController::class, 'bulkDeleteColumns'])->int('id');
        $router->post('/duplicate', [TablesController::class, 'duplicate'])->int('id');
        $router->get('/table-inner-html', [TablesController::class, 'tableInnerHtml'])->int('id');
        $router->get('drag_and_drop_html', [TablesController::class, 'dragAndDropHtml'])->int('id');

        // Rows
        $router->prefix('/item')->group(function ($router) {
            $router->get('/', [TableItemsController::class, 'index'])->int('id');
            $router->post('/', [TableItemsController::class, 'store'])->int('id');
            $router->post('/update', [TableItemsController::class, 'update'])->int('id');
            $router->post('/delete', [TableItemsController::class, 'delete'])->int('id');
        });
    });
});

// Per-table settings
$router->prefix('settings/{id}')->group(function ($router) {
    $router->get('/', [SettingsController::class, 'getTableSettings'])->int('id');
    $router->post('/', [SettingsController::class, 'updateTableSettings'])->int('id');
    $router->get('/button', [SettingsController::class, 'getButtonSettings'])->int('id');
    $router->post('/button', [SettingsController::class, 'updateButtonSettings'])->int('id');
    $router->get('/custom-styles', [SettingsController::class, 'getCustomCSSJS'])->int('id');
    $router->post('/custom-styles', [SettingsController::class, 'saveCustomCSSJS'])->int('id');
});

// Drag & drop table builder
$router->prefix('table-builder')->group(function ($router) {
    $router->get('/', [TableBuilderController::class, 'index']);
    $router->post('/', [TableBuilderController::class, 'store']);
    $router->post('/{id}', [TableBuilderController::class, 'update'])->int('id');
    $router->get('/{id}', [TableBuilderController::class, 'show'])->int('id');
});

// Data providers
$router->prefix('fluent-forms')->group(function ($router) {
    $router->get('/', [FluentFormsController::class, 'index']);
    $router->post('/save', [FluentFormsController::class, 'store']);
    $router->get('/{id}', [FluentFormsController::class, 'getFormsFields'])->int('id');
});

$router->prefix('wp-posts')->group(function ($router) {
    $router->get('/', [WPPostsController::class, 'getPostTypes']);
    $router->get('/authors', [WPPostsController::class, 'getPostTypesAuthor']);
});

// Import
$router->prefix('import')->group(function ($router) {
    $router->post('/default', [ImportController::class, 'defaultImport']);
    $router->post('/default-csv/init', [ImportController::class, 'initDefaultCsvImport']);
    $router->post('/default-csv/process', [ImportController::class, 'processDefaultCsvImportChunk']);
    $router->post('/table-builder', [ImportController::class, 'tableBuilderImport']);
    $router->post('/get-tables-from-other-plugin', [ImportController::class, 'getTablesFromOtherPlugin']);
    $router->post('/import-table-from-other-plugin', [ImportController::class, 'importTableFromOtherPlugin']);
    $router->post('/upload-csv-in-existing-table', [ImportController::class, 'uploadCsvInExistingTable']);
});

// Companion plugin installers
$router->prefix('install')->group(function ($router) {
    $router->post('/fluent-forms', [PluginInstallerController::class, 'installFluentForms']);
    $router->post('/ninja-charts', [PluginInstallerController::class, 'installNinjaCharts']);
});
