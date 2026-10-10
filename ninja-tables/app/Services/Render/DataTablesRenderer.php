<?php

namespace NinjaTables\App\Services\Render;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\App;
use NinjaTables\App\Helper\ColumnHelper;
use NinjaTables\App\Helper\Pro;
use NinjaTables\App\Modules\DataTables\Database\DynamicTableManager;
use NinjaTables\App\Modules\DataTables\Models\DynamicRow;
use NinjaTables\App\Services\TranslationService;
use NinjaTables\App\Services\Vite;
use NinjaTables\Framework\Support\Arr;

/**
 * Frontend renderer for the DataTables engine.
 */
class DataTablesRenderer extends AbstractTableRenderer
{
    protected static $instanceCount = 0;

    public function render(array $tableArray)
    {
        $tableId  = Arr::get($tableArray, 'table_id');
        $table    = Arr::get($tableArray, 'table');
        $columns  = Arr::get($tableArray, 'columns', []);

        if (!count($columns)) {
            $this->renderNoColumnsNotice();

            return;
        }

        $instanceName = 'ninja_dt_instance_' . static::$instanceCount++;

        $tableArray = $this->prepare($tableArray, 'ninja_dt_unique_id_');
        $settings   = $tableArray['settings'];
        $renderType = Arr::get($settings, 'render_type', 'ajax_table');

        $this->enqueueAssets();

        $formatted_columns = $this->formatColumns($columns, $settings, $tableArray);

        $enableSearch   = Arr::get($settings, 'enable_search', false);
        $globalSorting  = (bool) Arr::get($settings, 'column_sorting', false);
        $sortingType    = Arr::get($settings, 'sorting_type', 'by_created_at');
        $defaultSorting = RenderConfig::defaultSorting($settings) ?: false;
        $pagingSettings = RenderConfig::paging($settings);

        $configSettings = [
            'filtering'           => $enableSearch,
            'paging'              => $pagingSettings,
            'sorting'             => $globalSorting,
            'default_sorting'     => $defaultSorting,
            'i18n'                => RenderConfig::i18n($settings, true),
            'has_formula'         => Arr::get($settings, 'formula_support', 'no'),
            'skip_rows'           => Arr::get($settings, 'skip_rows', 0),
            'limit_rows'          => Arr::get($settings, 'limit_rows', 0),
            'use_parent_width'    => Arr::get($settings, 'use_parent_width', false),
            'info'                => Arr::get($tableArray, 'shortCodeData.info', ''),
            'show_row_data_modal' => Arr::get($settings, 'show_row_data_modal', 'no'),
        ];

        if ($stack = RenderConfig::stackConfig($settings)) {
            $configSettings['stack_config'] = $stack;
        }

        if ($this->hasResponsiveColumns($columns)) {
            $configSettings['responsive_config'] = [
                'enabled'                => true,
                'togglePosition'         => Arr::get($settings, 'togglePosition', 'first'),
                'expand_type'            => Arr::get($settings, 'expand_type', 'default'),
                'hide_on_empty'          => (bool)Arr::get($settings, 'hide_on_empty'),
                'hide_responsive_labels' => (bool)Arr::get($settings, 'hide_responsive_labels'),
            ];
            $this->enqueueResponsiveAssets();
        }

        RenderConfig::applyProConfig($configSettings, $settings);
        if (Pro::active() && Arr::get($settings, 'sticky_header') === 'yes') {
            $configSettings['sticky_header']        = Arr::get($settings, 'sticky_header');
            $configSettings['sticky_header_offset'] = Arr::get($settings, 'sticky_header_offset', '0');
        }

        [$table_classes, $tableHasColor] = $this->classes($settings, $tableArray);

        $lengthChange = (bool)Arr::get($settings, 'show_pager', false);
        $perPage      = $pagingSettings ? intval($pagingSettings) : 20;
        $pageSizes    = $this->pageSizes($settings, $lengthChange, $perPage);

        $dt_config = [
            'columns'           => $formatted_columns,
            'serverSide'        => ($renderType === 'ajax_table'),
            'searching'         => (bool)$enableSearch,
            'paging'            => ($pagingSettings !== false),
            'pageLength'        => $perPage,
            'lengthChange'      => $lengthChange,
            'lengthMenu'        => $pageSizes,
            'ordering'          => $globalSorting,
            'sorting_type'      => $sortingType,
            'default_sorting'   => $defaultSorting,
            'sorting_column'    => Arr::get($settings, 'sorting_column', ''),
            'sorting_column_by' => strtolower(Arr::get($settings, 'sorting_column_by', 'asc')),
            'language'          => [
                'emptyTable'        => Arr::get($configSettings, 'i18n.no_result_text'),
                'zeroRecords'       => Arr::get($configSettings, 'i18n.no_result_text'),
                'searchPlaceholder' => Arr::get($configSettings, 'i18n.search'),
                'search'            => '',
            ] + TranslationService::getDataTablesLanguage(),
        ];

        // Shortcode filter attributes: user-editable seeds travel as plain config; the static sf_* filter
        // is a locked restriction and is signed (see DataTablesRowFormatter::buildLockedFilters).
        $defaultFilter = Arr::get($tableArray, 'default_filter');
        if ($defaultFilter === null || $defaultFilter === false) {
            $defaultFilter = Arr::get($tableArray, 'shortCodeData.filter', '');
        }
        if ($defaultFilter) {
            $dt_config['default_filter'] = (string) $defaultFilter;
        }
        if ($filterColumns = Arr::get($settings, 'filter_column')) {
            $dt_config['filter_columns'] = array_values((array) $filterColumns);
        }
        if (Arr::get($tableArray, 'shortCodeData.hide_default_filter') === 'yes') {
            $dt_config['hide_default_filter'] = true;
        }

        $lockedFilters = DataTablesRowFormatter::buildLockedFilters($tableArray);
        if (!empty($lockedFilters)) {
            $lockedJson                      = wp_json_encode($lockedFilters);
            $dt_config['locked_filters']     = $lockedJson;
            $dt_config['locked_filters_sig'] = wp_hash($lockedJson);
        }

        if ($renderType === 'ajax_table') {
            $dt_config['ajax_url']           = add_query_arg(['action' => 'ninja_tables_dt_public', 'table_id' => $tableId], admin_url('admin-ajax.php'));
            $dt_config['filter_options_url'] = add_query_arg(['action' => 'ninja_tables_dt_filter_options', 'table_id' => $tableId], admin_url('admin-ajax.php'));
        }

        $table_vars = [
            'table_id'         => $tableId,
            'title'            => $table->post_title,
            'description'      => $table->post_content,
            'caption'          => get_post_meta($tableId, '_ninja_table_caption', true),
            'columns'          => $formatted_columns,
            'original_columns' => $columns,
            'settings'         => $configSettings,
            'dt_config'        => $dt_config,
            'render_type'      => $renderType,
            'instance_name'    => $instanceName,
            'table_version'    => NINJA_TABLES_VERSION,
            'provider'         => Arr::get($tableArray, 'provider'),
            'uniqueID'         => Arr::get($tableArray, 'uniqueID'),
            'table_classes'    => $table_classes,
            'table_has_color'  => $tableHasColor,
            'render_engine'    => Arr::get($tableArray, 'settings.library'),
        ];

        $table_vars = apply_filters('ninja_table_rendering_table_vars', $table_vars, $tableId, $tableArray);

        if ($renderType === 'legacy_table') {
            // Apply the locked filter server-side so hidden rows never reach the browser.
            $table_vars['legacy_rows'] = $this->legacyRows($tableId, $settings, $lockedFilters);
        }

        $this->cssOnce($tableId, 'ninja_dt_custom_css_' . $tableId, function () use ($tableArray, $columns, $settings) {
            return CssGenerator::datatables($tableArray, $columns, $settings);
        });

        InlineScript::window($instanceName, $table_vars);

        $this->includeView('public/datatables/ninja-datatable', compact(
            'tableId', 'table', 'columns', 'settings', 'renderType', 'instanceName',
            'formatted_columns', 'table_vars', 'tableArray', 'tableHasColor', 'table_classes'
        ));
    }

    /**
     * Column config with the DataTables-specific keys (image/button/width/alignment/Pro colours).
     */
    public function formatColumns(array $columns, array &$settings, array $tableArray)
    {
        return ColumnFormatter::format($columns, $settings, $tableArray, function ($fc, $column, $index) {
            $fc['data_type'] = Arr::get($column, 'data_type', 'text');

            if (Arr::get($column, 'enable_html_content') == 'true') {
                $fc['enable_html_content'] = true;
            }

            if (ColumnHelper::getColumnType($column) == 'image') {
                $fc['link_type']       = Arr::get($column, 'link_type', '');
                $fc['image_base_url']  = Arr::get($column, 'image_base_url', '');
                $fc['image_alt_tag']   = Arr::get($column, 'image_alt_tag', '');
                $fc['download_button'] = Arr::get($column, 'download_button', '');
                $fc['link_target']     = Arr::get($column, 'link_target', '_self');
            }

            if (Arr::get($column, 'data_type') == 'button') {
                foreach (['btn_text_color', 'btn_bg_color', 'btn_border_color', 'btn_extra_class', 'button_text', 'force_download', 'relAttributes'] as $key) {
                    $fc[$key] = Arr::get($column, $key, '');
                }
                $fc['link_target'] = Arr::get($column, 'link_target', '_self');
            }

            if ($width = Arr::get($column, 'width')) {
                $fc['width']     = $width;
                $fc['widthUnit'] = Arr::get($column, 'maxWidthUnit', 'px');
            }
            if ($textAlign = Arr::get($column, 'textAlign')) {
                $fc['textAlign'] = $textAlign;
            }
            if ($contentAlign = Arr::get($column, 'contentAlign')) {
                $fc['contentAlign'] = $contentAlign;
            }

            if (Pro::active()) {
                if ($bgColor = Arr::get($column, 'background_color')) {
                    $fc['background_color'] = $bgColor;
                }
                if ($textColor = Arr::get($column, 'text_color')) {
                    $fc['text_color'] = $textColor;
                }
            }

            if (Arr::get($column, 'enableCopyContent')) {
                $fc['enableCopyContent'] = Arr::get($column, 'enableCopyContent');
            }

            return $fc;
        });
    }

    protected function hasResponsiveColumns(array $columns)
    {
        foreach ($columns as $col) {
            $bp = Arr::get($col, 'breakpoints', '');
            if ($bp && $bp !== 'hidden') {
                return true;
            }
        }

        return false;
    }

    protected function pageSizes(array $settings, $lengthChange, $perPage)
    {
        $raw       = Arr::get($settings, 'paze_sizes', '');
        $pageSizes = [];

        if (is_string($raw) && $raw !== '') {
            $pageSizes = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
            sort($pageSizes);
        }
        if (empty($pageSizes)) {
            $pageSizes = [10, 20, 50, 100];
        }
        if ($lengthChange && !in_array($perPage, $pageSizes)) {
            $pageSizes[] = $perPage;
            sort($pageSizes);
        }

        return $pageSizes;
    }

    /**
     * @return array [classes string, colored_table flag]
     */
    protected function classes(array $settings, array $tableArray)
    {
        $classes = ['ninja-dt-table-container'];

        if ($color = TableClasses::color($settings, true)) {
            $classes[] = $color;
        }
        $classes = array_merge(
            $classes,
            TableClasses::lightbox($settings),
            TableClasses::shared($settings),
            TableClasses::stack($settings),
            TableClasses::customFilter(Arr::get($tableArray, 'table_id'))
        );

        if ($extra = trim(Arr::get($settings, 'extra_css_class', ''))) {
            $classes[] = sanitize_html_class($extra);
        }
        // Existing records may still hold FooTable style names; map them for this render.
        $settings = ninja_tables_normalize_dt_css_classes($settings);
        $classes  = array_merge($classes, (array) Arr::get($settings, 'css_classes', []));

        $tableHasColor = '';
        if (Pro::active()) {
            if (TableClasses::color($settings, true)) {
                $tableHasColor = 'colored_table';
            }
            if (Arr::get($settings, 'table_color_type') == 'custom_color') {
                $tableHasColor = 'colored_table';
                $classes[]     = 'ninja_custom_color';
            }
        }
        if ($pagingPosition = Arr::get($settings, 'pagination_position')) {
            $classes[] = 'ninja-dt-paging-' . sanitize_html_class($pagingPosition);
        }

        return [TableClasses::toString($classes), $tableHasColor];
    }

    protected function enqueueAssets()
    {
        $assets = App::getInstance()['url.assets'];

        wp_enqueue_style('datatables_core_css', $assets . 'libs/datatables/datatables.min.css', [], NINJA_TABLES_VERSION);
        Vite::enqueueStyle('ninjatables_dt_css', 'css/ninjatables-datatables.css', ['datatables_core_css']);
        wp_enqueue_script('datatables_core_js', $assets . 'libs/datatables/datatables.min.js', ['jquery'], NINJA_TABLES_VERSION, true);
        Vite::enqueueScript('ninja_dt_init', 'js/ninja-tables-datatables.js', ['datatables_core_js']);

        wp_localize_script('ninja_dt_init', 'ninja_datatables', [
            'site_url' => site_url(),
            'i18n'     => TranslationService::getSumoSelectI18n() + TranslationService::getPublicUiI18n(),
        ]);

        // Polyfill window.ninja_footables so Pro's SumoSelect/Pikaday/EditTable.js work in DataTables mode.
        // Attached to our own footer script (not jquery-core, which is usually already printed).
        $polyfill = wp_json_encode([
            'i18n'                     => TranslationService::getSumoSelectI18n() + TranslationService::getPublicUiI18n(),
            'ajax_url'                 => admin_url('admin-ajax.php'),
            'ninja_table_public_nonce' => wp_create_nonce('ninja_table_public_nonce'),
        ]);
        wp_add_inline_script('ninja_dt_init', 'if(!window.ninja_footables){window.ninja_footables=' . $polyfill . ';}', 'before');
    }

    protected function enqueueResponsiveAssets()
    {
        $assets = App::getInstance()['url.assets'];

        wp_enqueue_style('datatables_responsive_css', $assets . 'libs/datatables/responsive.dataTables.min.css', ['datatables_core_css'], NINJA_TABLES_VERSION);
        Vite::enqueueStyle('ninjatables_dt_responsive_css', 'css/ninjatables-datatables-responsive.css', ['datatables_responsive_css', 'ninjatables_dt_css']);
        wp_enqueue_script('datatables_responsive_js', $assets . 'libs/datatables/dataTables.responsive.min.js', ['datatables_core_js'], NINJA_TABLES_VERSION, true);
    }

    protected function legacyRows($tableId, array $settings, $lockedFilters = [])
    {
        $dynamicRow = new DynamicRow($tableId);

        if (!$dynamicRow->tableExists()) {
            return [];
        }

        $orderDir      = Arr::get($settings, 'default_sorting', 'new_first') === 'new_first' ? 'DESC' : 'ASC';
        $lockedFilters = is_array($lockedFilters) ? $lockedFilters : [];
        $total         = $dynamicRow->count(null, $lockedFilters);
        $rows          = $dynamicRow->getAll($total ?: 1000, 1, DynamicTableManager::COL_POSITION, $orderDir, null, $lockedFilters);
        $columnTypeMap = DataTablesRowFormatter::buildColumnTypeMap(ninja_table_get_table_columns($tableId, 'public') ?: []);

        $result            = [];
        $counter           = 0;
        $executeShortcodes = ninja_tables_provider_executes_shortcodes($tableId);
        foreach ($rows as $row) {
            $mapped  = $dynamicRow->mapRowToUserKeys($row);
            $rowData = Arr::get($mapped, 'values', []);

            $shortcodesRendered = false;
            if ($executeShortcodes) {
                $rowData            = DataTablesRowFormatter::renderShortcodesInRowValues($rowData, $columnTypeMap);
                $shortcodesRendered = true;
            }

            $rowData                = DataTablesRowFormatter::escapeRowValues($rowData, $columnTypeMap, $shortcodesRendered);
            $rowData['DT_RowClass'] = 'ninja_table_row_' . $counter . ' nt_row_id_' . $row->{DynamicTableManager::COL_ID};

            if (!empty(Arr::get($mapped, 'settings', []))) {
                $rowData['___row_settings___'] = Arr::get($mapped, 'settings', []);
            }

            $result[] = $rowData;
            $counter++;
        }

        return $result;
    }

    protected function renderNoColumnsNotice()
    {
        if (!is_user_logged_in() || !current_user_can(ninja_table_admin_role())) {
            return;
        }

        echo '<div class="ninja-tables-preview-message" style="width:100%;padding:14px 18px;background:#f9f9f9;border-left:4px solid #dba617;box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;font-size:14px;line-height:1.5;color:#3c434a;">'
             . esc_html__('This table has no columns configured. Please add columns to display the table.', 'ninja-tables')
             . '</div>';
    }
}
