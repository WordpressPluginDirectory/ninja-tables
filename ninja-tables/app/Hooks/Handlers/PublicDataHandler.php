<?php

namespace NinjaTables\App\Hooks\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use NinjaTables\App\App;
use NinjaTables\App\Services\Render\AbstractTableRenderer;
use NinjaTables\App\Services\Render\CssGenerator;
use NinjaTables\App\Services\Render\InlineScript;
use NinjaTables\App\Services\Vite;
use NinjaTables\App\Models\NinjaTableItem;
use NinjaTables\App\Modules\DataProviders\NinjaFooTable;
use NinjaTables\App\Modules\DataTables\Handlers\PublicRenderer;
use NinjaTables\Framework\Support\Arr;

class PublicDataHandler
{
    public function register()
    {
        add_action('init', [$this, 'registerTableRenderFunctions']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueNinjaTableScript'], 100);
        add_action('ninja_tables-render-table-footable', [$this, 'runFooTable']);
        add_action('ninja_tables_inside_table_render', [$this, 'renderTableInsideTable'], 10, 2);
    }

    public function runFooTable($tableArray)
    {
        NinjaFooTable::run($tableArray);
    }

    public function registerTableRenderFunctions()
    {
        // register the shortcode
        $shortCodeBase = apply_filters('ninja_tables_shortcode_base', 'ninja_tables');
        add_shortcode($shortCodeBase, [$this, 'renderTableShortcode']);

        add_shortcode('ninja_table_info', [$this, 'tableInfoShortcode']);
        add_shortcode('ninja_table_cell', [$this, 'tableCellShortcode']);
        add_shortcode('ninja_table_builder', [$this, 'ninjaTableBuilderShortCode']);
        add_action('ninja_tables_will_render_table', [$this, 'preRenderTableAssets']);
    }

    public function renderTableShortcode($atts, $content = '')
    {
        if (is_preview() && is_user_logged_in()) {
            $role = ninja_table_admin_role();
            if (!current_user_can($role)) {
                return '<div class="ninja-tables-preview-message">' . __('Please publish the page/post to see the table', 'ninja-tables') . '</div>';
            }
        }

        $shortCodeDefaults = [
            'id'               => false,
            'filter'           => false,
            'use_parent_width' => false,
            'info'             => ''
        ];

        $shortCodeDefaults = apply_filters('ninja_tables_shortcode_defaults', $shortCodeDefaults);
        $shortCodeData = shortcode_atts($shortCodeDefaults, $atts);
        $shortCodeData = apply_filters('ninja_tables_shortcode_data', $shortCodeData);

        $tableArray = $this->getTableArray($shortCodeData, $content);

        if (!$tableArray || Arr::get($tableArray, 'error')) {
            $isAdmin = is_user_logged_in() && current_user_can(ninja_table_admin_role());
            if (!$isAdmin) {
                return '';
            }

            $error = Arr::get($tableArray, 'error', 'not_available');
            if ($error === 'trashed') {
                return $this->renderAdminNotice(__('This table is currently in trash. Please restore it to display the table.', 'ninja-tables'));
            } elseif ($error === 'not_found') {
                return $this->renderAdminNotice(__('This table does not exist or has been permanently deleted.', 'ninja-tables'));
            } elseif ($error === 'no_columns') {
                return $this->renderAdminNotice(__('This table has no columns configured. Please add columns to display the table.', 'ninja-tables'));
            } else {
                return $this->renderAdminNotice(__('This table is not published. Please publish it to display the table.', 'ninja-tables'));
            }
        }

        if (Arr::get($tableArray, 'settings.formula_support') == 'yes') {
            do_action('ninja_tables_require_formulajs', $tableArray);
        }

        $tableArray = apply_filters('ninja_table_js_config', $tableArray, $shortCodeData['filter']);

        if (defined('LSCWP_V')) {
            do_action('litespeed_tag_add', 'ninja_tables_light_speed_clear_cache'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        }

        ob_start();
        do_action('ninja_tables-render-table-' . Arr::get($tableArray, 'settings.library'), $tableArray);

        return ob_get_clean();
    }

    public function tableInfoShortcode($atts)
    {

        if (is_preview() && is_user_logged_in()) {
            $role = ninja_table_admin_role();
            if (!current_user_can($role)) {
                return '<div class="ninja-tables-preview-message">' . __('Please publish the page/post to see the table', 'ninja-tables') . '</div>';
            }
        }

        $shortCodeDefaults = [
            'id'    => false,
            'field' => ''
        ];
        $shortCodeData = shortcode_atts($shortCodeDefaults, $atts);
        extract($shortCodeData);
        if (!$id || !$field) {
            return;
        }

        $id = absint($id);
        $table = get_post($id);

        if (!$table || $table->post_type !== 'ninja-table' || $table->post_status !== 'publish') {
            return 'Error: Invalid table ID.';
        }

        $validFields = [
            'title',
            'description',
            'total_rows',
            'last_modified',
            'last_editor'
        ];
        if (!in_array($field, $validFields)) {
            return;
        }

        switch ($field) {
            case 'title':
                return $table->post_title;
                break;
            case 'description':
                return $table->post_content;
                break;
            case 'last_modified':
                $getLastModifiedTime = get_post_meta($table->ID, '_last_edited_time', true);
                $lastEditedTime = apply_filters('ninja_tables_last_edited_time', $getLastModifiedTime, $table->ID);
                if (!$lastEditedTime) {
                    $lastEditedTime = $table->post_modified;
                }

                return $lastEditedTime;
                break;
            case 'last_editor':
                $lastEditorId = get_post_meta($table->ID, '_last_edited_by', true);
                if (!$lastEditorId) {
                    $lastEditorId = $table->post_author;
                }
                if ($lastEditorId) {
                    $user = get_userdata($lastEditorId);
                    if ($user) {
                        return $user->display_name;
                    }
                }
                break;
            case 'total_rows':
                $total = NinjaTableItem::where('table_id', $table->ID)
                    ->count();
                if ($total) {
                    return $total;
                }

                break;
            default:
                return '';
                break;
        }

        return '';
    }

    public function tableCellShortcode($atts)
    {
        // Re-entrancy guard: a cell that renders itself would recurse until the PHP stack overflows.
        static $depth = 0;
        $maxDepth = apply_filters('ninja_tables_cell_shortcode_max_depth', 5);
        if ($depth >= $maxDepth) {
            return '';
        }
        $depth++;
        try {
            return $this->renderTableCellShortcode($atts);
        } finally {
            $depth--;
        }
    }

    private function renderTableCellShortcode($atts)
    {
        if (is_preview() && is_user_logged_in()) {
            $role = ninja_table_admin_role();
            if (!current_user_can($role)) {
                return '<div class="ninja-tables-preview-message">' . __('Please publish the page/post to see the table', 'ninja-tables') . '</div>';
            }
        }

        $shortCodeDefaults = [
            'id'     => 0,
            'row'    => 0,
            'column' => '',
            'row_id' => false
        ];
        $shortCodeData = shortcode_atts($shortCodeDefaults, $atts);

        extract($shortCodeData);

        if (!$id || (!$row && !$row_id) || !$column) {
            return '';
        }

        $id = absint($id);

        if (get_post_type($id) !== 'ninja-table' || get_post_status($id) !== 'publish') {
            return 'Sorry! The table is not available.';
        }

        $executeShortcodes = ninja_tables_provider_executes_shortcodes($id);

        $tableSettings = ninja_table_get_table_settings($id, 'public');

        if ($row_id) {
            $rowData = NinjaTableItem::where('table_id', $id)
                ->where('id', $row_id)
                ->first();
            if (!$rowData) {
                return '';
            }
            $data = json_decode($rowData->value, true);
            if (isset($data[$column])) {
                $value = $data[$column];
                if (is_array($value)) {
                    $value = $this->processCellInfoArray($value, $column, $id);
                }

                return ninja_tables_render_cell_shortcode($value, $executeShortcodes);
            } else {
                $dataArray = array_values($data);

                if (isset($dataArray[$column])) {
                    $value = $data[$column];
                    if (is_array($value)) {
                        $value = $this->processCellInfoArray($value, $column, $id);
                    }

                    return ninja_tables_render_cell_shortcode($value, $executeShortcodes);
                }
            }

            return '';
        }

        $tableColumns = ninja_table_get_table_columns($id, 'public');
        $data = ninjaTablesGetTablesDataByID($id, $tableColumns, $tableSettings['default_sorting'], false, 1, $row - 1);

        if ($data) {
            $data = $data[0];
        } else {
            return '';
        }

        $content = '';
        if (isset($data[$column])) {
            $content = $data[$column];
        } else {
            $arrayValues = array_values($data);
            if (isset($arrayValues[$column])) {
                $content = $arrayValues[$column];
            }
        }
        if (is_array($content)) {
            $content = $this->processCellInfoArray($content, $column, $id);
        }

        return ninja_tables_render_cell_shortcode($content, $executeShortcodes);
    }

    /**
     * Render table specific assets to the header.
     *
     * @param $tableId
     */
    public function preRenderTableAssets($tableId)
    {
        $tableId = intval($tableId);

        $atts = [
            'id'               => $tableId,
            'filter'           => false,
            'use_parent_width' => false,
            'info'             => ''
        ];

        $tableArray = $this->getTableArray($atts, '');

        // No table array or error means the shortcode ID is invalid.
        if (!$tableArray || Arr::get($tableArray, 'error')) return;

        $library = Arr::get($tableArray, 'settings.library', 'footable');

        // Print once per request, whichever engine renders later.
        if (AbstractTableRenderer::isCssPrinted($tableId)) {
            return;
        }
        AbstractTableRenderer::markCssPrinted($tableId);

        if ($library === 'datatables') {
            PublicRenderer::generateCustomColorCSSForTable($tableArray);
            return;
        }

        $columnContentCss = NinjaFooTable::getColumnsCss($tableArray['table_id'], $tableArray['columns']);
        $css              = NinjaFooTable::generateCustomColorCSS($tableArray, $columnContentCss);

        CssGenerator::inject($css, 'ninja_table_custom_css_' . $tableId, 'wp_head', 99);
    }

    public function ninjaTableBuilderShortCode($atts = [], $content = null, $tag = '')
    {
        // normalize attribute keys, lowercase
        $atts = array_change_key_case((array)$atts, CASE_LOWER);
        // override default attributes with user attributes
        $short_code_atts = shortcode_atts([
            'id' => null,
        ], $atts, $tag);
        $id = $short_code_atts['id'];
        $table_id = absint($id);
        if (!$table_id) {
            return;
        }
        $table = get_post($table_id);

        if (!$table || $table->post_type !== 'ninja-table' || $table->post_status !== 'publish') {
            return;
        }

        static $tableBuilderInstances = [];
        $tableInstance = 'ninja_table_builder_instance_' . count($tableBuilderInstances);
        $tableBuilderInstances[] = $tableInstance;

        $ninja_table_builder_html = get_post_meta($table->ID, '_ninja_table_builder_table_html', true);
        $ninja_table_builder_table_data = get_post_meta($table->ID, '_ninja_table_builder_table_data', true);
        $ninja_table_builder_setting = get_post_meta($table->ID, '_ninja_table_builder_table_settings', true);
        $ninja_table_builder_responsive = get_post_meta($table->ID, '_ninja_table_builder_table_responsive', true);
        
        InlineScript::window($tableInstance, [
            'tableId'    => absint($table_id),
            'responsive' => $ninja_table_builder_responsive,
            'settings'   => $ninja_table_builder_setting,
        ]);

        $html = do_shortcode($ninja_table_builder_html);
        $this->enqueueNinjaTableBuilderScript();

        do_action('ninja_table_builder_before_render', $table_id);

        $app = App::getInstance();

        return $app->view->make('public/drag-and-drop-html', [
            'ninja_table_builder_html' => $html,
            'table_data'               => $ninja_table_builder_table_data,
            'setting'                  => $ninja_table_builder_setting,
            'table_id'                 => $table_id,
            'ntb_instance'             => $tableInstance
        ]);
    }

    public function enqueueNinjaTableScript()
    {
        global $post;

        if (is_a($post, 'WP_Post') && $ids = get_post_meta($post->ID, '_has_ninja_tables', true)) {
            Vite::enqueueStyle('footable_styles', is_rtl() ? 'css/ninjatables-public-rtl.css' : 'css/ninjatables-public.css');

            // We have the parsed table IDs from the shortcodes.
            // We should prerender the table assets here.
            if (is_array($ids)) {
                foreach ($ids as $tableId) {
                    do_action('ninja_tables_will_render_table', $tableId);
                }
            }
        }
    }

    private function renderAdminNotice($message)
    {
        return '<div class="ninja-tables-preview-message" style="'
            . 'width: 100%;'
            . 'padding: 14px 18px;'
            . 'background: #f9f9f9;'
            . 'border-left: 4px solid #dba617;'
            . 'box-sizing: border-box;'
            . 'font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;'
            . 'font-size: 14px;'
            . 'line-height: 1.5;'
            . 'color: #3c434a;'
            . '">'
            . esc_html($message)
            . '</div>';
    }

    public function getTableArray($shortCodeData, $content = '')
    {
        extract($shortCodeData);

        $table_id = $shortCodeData['id'];

        if (!$table_id) {
            return;
        }

        $table = get_post($table_id);

        if (!$table || $table->post_type !== 'ninja-table') {
            return ['error' => 'not_found'];
        }

        if ($table->post_status === 'trash') {
            return ['error' => 'trashed'];
        }

        if ($table->post_status !== 'publish') {
            return ['error' => 'not_published'];
        }

        $tableSettings = ninja_table_get_table_settings($table_id, 'public');

        $tableSettings = apply_filters(
            'ninja_tables_rendering_table_settings', $tableSettings, $shortCodeData, $table
        );

        $tableColumns = ninja_table_get_table_columns($table_id, 'public');

        if (!$tableSettings || !$tableColumns) {
            return ['error' => 'no_columns'];
        }

        $tableSettings['use_parent_width'] = $use_parent_width;

        if (isset($tableSettings['columns_only']) && is_array($tableSettings['columns_only'])) {
            $showingColumns = $tableSettings['columns_only'];
            $formattedColumns = [];
            foreach ($tableColumns as $columnIndex => $table_column) {
                if (isset($showingColumns[$table_column['key']])) {
                    $formattedColumns[] = $table_column;
                }
            }
            $tableColumns = $formattedColumns;
        }

        return [
            'table_id'      => $table_id,
            'columns'       => $tableColumns,
            'settings'      => $tableSettings,
            'table'         => $table,
            'content'       => $content,
            'shortCodeData' => $shortCodeData
        ];
    }

    public function enqueueNinjaTableBuilderScript()
    {
        Vite::enqueueScript('ninja_table_builder_js', 'js/ninja-table-builder-public.js', ['jquery']);
        Vite::enqueueStyle('ninja_table_builder_style', 'css/ninja-table-builder-public.css');
    }

    public function renderTableInsideTable($table, $tableVars)
    {
        NinjaFooTable::getTableHTML($table, $tableVars);
    }

    private function processCellInfoArray($value, $column, $tableId)
    {
        $tableColumns = ninja_table_get_table_columns($tableId);
        $targetColumn = [];
        foreach ($tableColumns as $tableColumn) {
            if ($tableColumn['key'] == $column) {
                $targetColumn = $tableColumn;
            }
        }

        if (!empty($targetColumn) && $targetColumn['data_type'] == 'image') {
            if (function_exists('nt_parse_image_column')) {
                return nt_parse_image_column($value, $targetColumn);
            }
        }

        return implode(', ', $value);
    }
}
