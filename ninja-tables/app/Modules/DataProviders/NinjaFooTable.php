<?php

namespace NinjaTables\App\Modules\DataProviders;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Helper\ColumnHelper;
use NinjaTables\App\Helper\TableCssHelper;
use NinjaTables\App\Services\Render\CssGenerator;
use NinjaTables\App\Services\Render\FooTableRenderer;
use NinjaTables\App\Services\Vite;

/**
 * Static facade over FooTableRenderer, kept for the existing call sites and hooks.
 */
class NinjaFooTable
{
    public static $version = NINJA_TABLES_VERSION;

    /** @var string[] instance names rendered on this request */
    public static $tableInstances = [];

    public static function run($tableArray)
    {
        (new FooTableRenderer())->render($tableArray);
    }

    public static function generateCustomColorCSS($tableArray, $extra_css = '')
    {
        return CssGenerator::footable($tableArray, $extra_css);
    }

    /**
     * Column alignment CSS for the table.
     */
    public static function getColumnsCss($tableId, $columns)
    {
        return TableCssHelper::buildColumnAlignmentCss('#footable_' . $tableId, $columns);
    }

    /**
     * Prints the server-rendered body for legacy tables (ninja_tables_inside_table_render).
     */
    public static function getTableHTML($table, $table_vars)
    {
        (new FooTableRenderer())->legacyHtml($table, $table_vars);
    }

    public static function getColumnType($column)
    {
        return ColumnHelper::getColumnType($column);
    }

    public static function getFormattedColumn($column, $index, $settings, $globalSorting, $sortingType)
    {
        $base = ColumnHelper::formatColumnBase($column, $index, $settings, $globalSorting, $sortingType);

        if ($base['enqueue_moment']) {
            wp_enqueue_script('moment');
        }

        $formatted           = $base['formatted_column'];
        $formatted['column'] = $column;

        return $formatted;
    }

    public static function getNinjaTableConfig($tableConfig)
    {
        return (new FooTableRenderer())->initConfig($tableConfig);
    }

    public static function enqueuePublicCss()
    {
        Vite::enqueueStyle('footable_styles', is_rtl() ? 'css/ninjatables-public-rtl.css' : 'css/ninjatables-public.css');
    }
}
