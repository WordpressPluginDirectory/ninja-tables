<?php

namespace NinjaTables\App\Modules\DataTables;

defined('ABSPATH') || exit;

use NinjaTables\App\Modules\DataTables\Database\DynamicTableManager;
use NinjaTables\App\Modules\DataTables\Handlers\PublicRenderer;
use NinjaTables\Framework\Support\Arr;

class DataTablesModule
{
    public function register()
    {
        add_action('ninja_tables/after_table_created', [$this, 'onTableCreated'], 10, 2);
        add_action('ninja_table_before_update_columns', [$this, 'onColumnsUpdated'], 10, 3);
        add_action('before_delete_post', [$this, 'onBeforeDeletePost'], 10, 1);
        add_action('delete_post', [$this, 'onDeletePost'], 10, 1);

        PublicRenderer::register();
    }

    public function onTableCreated($tableId, $data)
    {
        $requestData     = Arr::get($data, 'request_data', []);
        $renderingEngine = sanitize_key(Arr::get($requestData, 'rendering_engine'));

        if ($renderingEngine !== 'datatables') {
            return;
        }

        $tableManager = new DynamicTableManager($tableId);
        $tableManager->createTable();
    }

    public function onColumnsUpdated($columns, $rawColumns, $tableId)
    {
        $settings = ninja_table_get_table_settings($tableId, 'public');
        $library  = Arr::get($settings, 'library', 'footable');

        if ($library !== 'datatables') {
            return;
        }

        $reservedKeys = DynamicTableManager::getReservedKeys($columns);

        if ($reservedKeys) {
            wp_send_json_error([
                'message' => sprintf(
                    // translators: %s is the reserved column key name
                    __('Column key "%s" is reserved for system use. Please use a different key.', 'ninja-tables'),
                    implode('", "', $reservedKeys)
                ),
            ], 400);
        }

        $tableManager = new DynamicTableManager($tableId);

        if (!$tableManager->tableExists()) {
            $tableManager->createTable();
        }

        $tableManager->syncColumns($columns);
    }

    public function onBeforeDeletePost($postId)
    {
        $this->maybeDropDynamicTable($postId);
    }

    public function onDeletePost($postId)
    {
        $this->maybeDropDynamicTable($postId);
    }

    protected function maybeDropDynamicTable($postId)
    {
        if (get_post_type($postId) !== 'ninja-table') {
            return;
        }

        $tableManager = new DynamicTableManager($postId);
        if ($tableManager->tableExists()) {
            $tableManager->dropTable();
        }
    }
}
