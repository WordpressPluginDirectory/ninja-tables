<?php

namespace NinjaTables\App\Modules\DataTables\Handlers;

defined('ABSPATH') || exit;

use NinjaTables\App\Helper\TableCssHelper;
use NinjaTables\App\Services\Render\CssGenerator;
use NinjaTables\App\Services\Render\DataTablesRenderer;
use NinjaTables\Framework\Support\Arr;

/**
 * Hooks the DataTables engine into the render action. Rendering lives in DataTablesRenderer.
 */
class PublicRenderer
{
    public function register()
    {
        add_action('ninja_tables-render-table-datatables', [$this, 'run']);
    }

    public function run($tableArray)
    {
        (new DataTablesRenderer())->render($tableArray);
    }

    /**
     * Print the table's custom CSS ahead of rendering (used when the shortcode is detected early).
     */
    public static function generateCustomColorCSSForTable($tableArray, $extraCss = '')
    {
        $columns  = Arr::get($tableArray, 'columns', []);
        $settings = Arr::get($tableArray, 'settings', []);

        TableCssHelper::normalizeColorType($settings);
        $tableArray['settings'] = $settings;

        (new DataTablesRenderer())->formatColumns($columns, $settings, $tableArray);

        $tableId = intval(Arr::get($tableArray, 'table_id'));
        CssGenerator::inject(CssGenerator::datatables($tableArray, $columns, $settings), 'ninja_dt_custom_css_' . $tableId);
    }
}
