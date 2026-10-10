<?php

namespace NinjaTables\App\Services\Table;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\Framework\Support\Arr;

/**
 * Which storage/render engine a table uses. The one place that knows the rule
 * "default provider + library=datatables => dynamic table, otherwise the shared items table".
 */
class Engine
{
    const FOOTABLE   = 'footable';
    const DATATABLES = 'datatables';

    public static function of($tableId)
    {
        if (ninja_table_get_data_provider($tableId) !== 'default') {
            return self::FOOTABLE;
        }

        $settings = ninja_table_get_table_settings($tableId, 'public');

        return Arr::get($settings, 'library') === self::DATATABLES ? self::DATATABLES : self::FOOTABLE;
    }

    public static function isDataTables($tableId)
    {
        return self::of($tableId) === self::DATATABLES;
    }
}
