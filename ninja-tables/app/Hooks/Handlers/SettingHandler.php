<?php

namespace NinjaTables\App\Hooks\Handlers;

defined('ABSPATH') || exit;

use NinjaTables\Framework\Support\Arr;

class SettingHandler
{
    public function register()
    {
        add_action('ninja_tables/update_table_settings', [$this, 'updateTableSettings'], 10, 2);
    }

    public function updateTableSettings($tableId, $data)
    {
        if (empty($tableId) || empty($data)) {
            return;
        }

        $requestData              = Arr::get($data, 'request_data');
        $tableSettings            = ninjaTablesGetDefaultSettings();
        $tableSettings['library'] = Arr::get($requestData, 'rendering_engine', 'footable');

        update_post_meta($tableId, '_ninja_table_settings', $tableSettings);
    }
}
