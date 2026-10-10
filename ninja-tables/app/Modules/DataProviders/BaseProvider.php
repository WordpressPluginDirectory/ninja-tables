<?php

namespace NinjaTables\App\Modules\DataProviders;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wires the ninja_tables_*_{key} hook family a data provider must answer:
 *  - ninja_tables_get_table_{key}            -> getTableSettings($table)
 *  - ninja_tables_get_table_data_{key}       -> getTableData(...)      (optional)
 *  - ninja_tables_fetching_table_rows_{key}  -> data(...)
 */
abstract class BaseProvider
{
    /**
     * Provider key as stored in _ninja_tables_data_provider (e.g. "default", "fluent-form").
     */
    abstract public function key();

    public function register()
    {
        $key = $this->key();

        add_filter('ninja_tables_get_table_' . $key, [$this, 'getTableSettings']);

        if (method_exists($this, 'getTableData')) {
            add_filter('ninja_tables_get_table_data_' . $key, [$this, 'getTableData'], 10, 4);
        }

        add_filter('ninja_tables_fetching_table_rows_' . $key, [$this, 'data'], 10, 6);
    }

    /**
     * Legacy entry point.
     */
    public function boot()
    {
        $this->register();
    }
}
