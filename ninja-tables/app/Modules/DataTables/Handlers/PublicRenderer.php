<?php

namespace NinjaTables\App\Modules\DataTables\Handlers;

defined('ABSPATH') || exit;

use NinjaTables\App\App;
use NinjaTables\App\Helper\ColumnHelper;
use NinjaTables\App\Helper\TableCssHelper;
use NinjaTables\App\Services\TranslationService;
use NinjaTables\App\Modules\DataTables\Database\DynamicTableManager;
use NinjaTables\App\Modules\DataTables\Models\DynamicRow;
use NinjaTables\Framework\Support\Arr;

class PublicRenderer
{
    public static $tableCssStatuses = [];

    public static function register()
    {
        $instance = new self();
        add_action('ninja_tables-render-table-datatables', [$instance, 'run']);
        add_action('wp_ajax_ninja_tables_dt_public', [$instance, 'handleAjax']);
        add_action('wp_ajax_nopriv_ninja_tables_dt_public', [$instance, 'handleAjax']);
    }

    public function run($tableArray)
    {
        $tableId    = Arr::get($tableArray, 'table_id');
        $table      = Arr::get($tableArray, 'table');
        $columns    = Arr::get($tableArray, 'columns', []);
        $settings   = Arr::get($tableArray, 'settings', []);
        $renderType = Arr::get($settings, 'render_type', 'ajax_table');

        if (!count($columns)) {
            if (is_user_logged_in() && current_user_can(ninja_table_admin_role())) {
                echo '<div class="ninja-tables-preview-message" style="'
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
                     . esc_html__('This table has no columns configured. Please add columns to display the table.', 'ninja-tables')
                     . '</div>';
            }
            return;
        }

        static $dtInstanceCount = 0;
        $instanceName = 'ninja_dt_instance_' . $dtInstanceCount;
        $dtInstanceCount++;

        $tableArray['uniqueID'] = 'ninja_dt_unique_id_' . wp_rand() . '_' . $tableId;
        $tableArray['provider'] = ninja_table_get_data_provider($tableId);

        TableCssHelper::normalizeColorType($settings);
        $tableArray['settings'] = $settings;

        do_action('ninja_rendering_table_' . Arr::get($tableArray, 'provider'), $tableArray);

        $this->enqueueAssets();

        $formatted_columns = $this->formatColumns($columns, $settings, $tableArray);

        $enableSearch    = Arr::get($settings, 'enable_search', false);
        $globalSorting   = (bool) Arr::get($settings, 'column_sorting', false);
        $sortingType     = Arr::get($settings, 'sorting_type', 'by_created_at');

        $default_sorting = false;
        if ($sortingType == 'manual_sort') {
            $default_sorting = 'manual_sort';
        } elseif (Arr::get($settings, 'default_sorting')) {
            $default_sorting = Arr::get($settings, 'default_sorting');
        }

        $pagingSettings = Arr::get($settings, 'show_all') ? false : Arr::get($settings, 'perPage', 20);

        $configSettings = [
            'filtering'             => $enableSearch,
            'paging'                => $pagingSettings,
            'sorting'               => $globalSorting,
            'default_sorting'       => $default_sorting,
            'i18n'                  => [
                'search_in'      => Arr::get($settings, 'search_in_text')
                    ? sanitize_text_field(Arr::get($settings, 'search_in_text')) : __('Search in', 'ninja-tables'),
                'search'         => Arr::get($settings, 'search_placeholder')
                    ? sanitize_text_field(Arr::get($settings, 'search_placeholder')) : __('Search', 'ninja-tables'),
                'no_result_text' => Arr::get($settings, 'no_result_text')
                    ? sanitize_text_field(Arr::get($settings, 'no_result_text')) : __('No Result Found', 'ninja-tables'),
            ],
            'has_formula'           => Arr::get($settings, 'formula_support', 'no'),
            'skip_rows'             => Arr::get($settings, 'skip_rows', 0),
            'limit_rows'            => Arr::get($settings, 'limit_rows', 0),
            'use_parent_width'      => Arr::get($settings, 'use_parent_width', false),
            'info'                  => Arr::get($tableArray, 'shortCodeData.info', ''),
            'show_row_data_modal'   => Arr::get($settings, 'show_row_data_modal', 'no'),
        ];

        $isStackable = Arr::get($settings, 'stackable', 'no') == 'yes';
        if ($isStackable && count(Arr::get($settings, 'stacks_devices', []))) {
            $configSettings['stack_config'] = [
                'stackable'      => true,
                'stacks_devices' => Arr::get($settings, 'stacks_devices', []),
            ];
        }

        $hasResponsive = false;
        foreach ($columns as $col) {
            $bp = Arr::get($col, 'breakpoints', '');
            if ($bp && $bp !== 'hidden') {
                $hasResponsive = true;
                break;
            }
        }
        if ($hasResponsive) {
            $configSettings['responsive_config'] = [
                'enabled'                => true,
                'togglePosition'         => Arr::get($settings, 'togglePosition', 'first'),
                'expand_type'            => Arr::get($settings, 'expand_type', 'default'),
                'hide_on_empty'          => !!Arr::get($settings, 'hide_on_empty'),
                'hide_responsive_labels' => !!Arr::get($settings, 'hide_responsive_labels'),
            ];

            $this->enqueueResponsiveAssets();
        }

        if (defined('NINJATABLESPRO')) {
            if (Arr::get($settings, 'hide_on_empty')) {
                $configSettings['hide_on_empty'] = true;
            }
            if (Arr::get($settings, 'paginate_to_top')) {
                $configSettings['paginate_to_top'] = true;
            }
            if (Arr::get($settings, 'sticky_header')) {
                $configSettings['sticky_header'] = Arr::get($settings, 'sticky_header');
                $configSettings['sticky_header_offset'] = Arr::get($settings, 'sticky_header_offset', '0');
            }
            $configSettings['disable_sticky_on_mobile'] = Arr::get($settings, 'disable_sticky_on_mobile');
        }

        $table_classes = $this->getTableCssClasses($settings, $tableArray);
        $tableHasColor = '';

        if (defined('NINJATABLESPRO')) {
            if (
                Arr::get($settings, 'table_color_type') == 'pre_defined_color'
                && Arr::get($settings, 'table_color')
                && Arr::get($settings, 'table_color') != 'ninja_no_color_table'
            ) {
                $tableHasColor = 'colored_table';
            }
            if (Arr::get($settings, 'table_color_type') == 'custom_color') {
                $tableHasColor  = 'colored_table';
                $table_classes .= ' ninja_custom_color';
            }
        }

        if ($pagingPosition = Arr::get($settings, 'pagination_position')) {
            $table_classes .= ' ninja-dt-paging-' . sanitize_html_class($pagingPosition);
        }

        $lengthChange = !!Arr::get($settings, 'show_pager', false);
        $perPage = $pagingSettings ? intval($pagingSettings) : 20;

        $rawPageSizes = Arr::get($settings, 'paze_sizes', '');
        if (is_string($rawPageSizes) && $rawPageSizes !== '') {
            $pageSizes = array_values(array_unique(array_filter(array_map('intval', explode(',', $rawPageSizes)))));
            sort($pageSizes);
        } else {
            $pageSizes = [];
        }
        if (empty($pageSizes)) {
            $pageSizes = [10, 20, 50, 100];
        }

        if ($lengthChange && !in_array($perPage, $pageSizes)) {
            $pageSizes[] = $perPage;
            sort($pageSizes);
        }

        $dt_config = [
            'columns'           => $formatted_columns,
            'serverSide'        => ($renderType === 'ajax_table'),
            'searching'         => !!$enableSearch,
            'paging'            => ($pagingSettings !== false),
            'pageLength'        => $perPage,
            'lengthChange'      => $lengthChange,
            'lengthMenu'        => $pageSizes,
            'ordering'          => $globalSorting,
            'sorting_type'      => $sortingType,
            'default_sorting'   => $default_sorting,
            'sorting_column'    => Arr::get($settings, 'sorting_column', ''),
            'sorting_column_by' => strtolower(Arr::get($settings, 'sorting_column_by', 'asc')),
            'language'          => [
                'emptyTable'        => Arr::get($configSettings, 'i18n.no_result_text'),
                'zeroRecords'       => Arr::get($configSettings, 'i18n.no_result_text'),
                'searchPlaceholder' => Arr::get($configSettings, 'i18n.search'),
                'search'            => '',
            ],
        ];

        if ($renderType === 'ajax_table') {
            $dt_config['ajax_url'] = add_query_arg([
                'action'   => 'ninja_tables_dt_public',
                'table_id' => $tableId,
            ], admin_url('admin-ajax.php'));
        }

        $tableCaption = get_post_meta($tableId, '_ninja_table_caption', true);

        $table_vars = [
            'table_id'         => $tableId,
            'title'            => $table->post_title,
            'description'      => $table->post_content,
            'caption'          => $tableCaption,
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
            'render_engine' => Arr::get($tableArray, 'settings.library')
        ];

        $table_vars = apply_filters('ninja_table_rendering_table_vars', $table_vars, $tableId, $tableArray);

        if ($renderType === 'legacy_table') {
            $table_vars['legacy_rows'] = $this->getLegacyRows($tableId, $settings);
        }

        if (!isset(static::$tableCssStatuses[$tableId])) {
            static::$tableCssStatuses[$tableId] = true;
            $this->generateAndInjectCSS($tableArray, $columns, $formatted_columns, $settings);
        }

        add_action('wp_footer', function () use ($table_vars, $instanceName) {
            ?>
            <script type="text/javascript">
                window['<?php echo esc_js($instanceName); ?>'] = <?php echo wp_json_encode($table_vars); ?>;
            </script>
            <?php
        });

        include NINJA_TABLES_DIR_PATH . 'app/Views/public/datatables/ninja-datatable.php';
    }

    public function handleAjax()
    {
        $request = ninjaTablesRequest();

        $tableId = intval(Arr::get($request, 'table_id', 0));
        $draw    = intval(Arr::get($request, 'draw', 1));

        $emptyResponse = [
            'draw'            => $draw,
            'recordsTotal'    => 0,
            'recordsFiltered' => 0,
            'data'            => [],
        ];

        if (!$tableId) {
            wp_send_json($emptyResponse);
        }

        $post = get_post($tableId);
        if (!$post || $post->post_type !== 'ninja-table' || $post->post_status !== 'publish') {
            wp_send_json($emptyResponse);
        }

        try {
            $start  = intval(Arr::get($request, 'start', 0));
            $length = intval(Arr::get($request, 'length', 20));

            // DataTables sends length=-1 when paging is disabled (meaning "all rows")
            if ($length < 1) {
                $length = apply_filters('ninja_tables_dt_max_length_all', 10000);
            }

            $search = substr(sanitize_text_field(Arr::get($request, 'search.value', '')), 0, 200);

            $customFilters = [];
            $ninjaFilters  = Arr::get($request, 'ninja_filters', '');
            if (!empty($ninjaFilters)) {
                $rawFilters = json_decode(wp_unslash($ninjaFilters), true);
                if (is_array($rawFilters)) {
                    $customFilters = $rawFilters;
                }
            }

            $orderColIdx = intval(Arr::get($request, 'order.0.column', 0));
            $orderDirRaw = strtoupper(sanitize_text_field(Arr::get($request, 'order.0.dir', 'asc')));
            $orderDir    = ($orderDirRaw === 'DESC') ? 'DESC' : 'ASC';

            $tableColumns = ninja_table_get_table_columns($tableId, 'public');
            if (!$tableColumns) {
                wp_send_json($emptyResponse);
            }

            $visibleColumns = array_values(array_filter($tableColumns, function ($col) {
                return Arr::get($col, 'breakpoints') !== 'hidden';
            }));

            $orderByColumn = DynamicTableManager::COL_POSITION;
            $requestedColName = sanitize_text_field(Arr::get($request, "columns.{$orderColIdx}.name", ''));
            if ($requestedColName && $requestedColName !== '____editing____' && $requestedColName !== '____responsive_toggle____') {
                foreach ($visibleColumns as $col) {
                    if (Arr::get($col, 'key') === $requestedColName) {
                        $isSortable = Arr::get($col, 'unsortable') !== 'yes';
                        if ($isSortable) {
                            $orderByColumn = $requestedColName;
                        }
                        break;
                    }
                }
            } elseif (isset($visibleColumns[$orderColIdx])) {
                $col = $visibleColumns[$orderColIdx];
                $isSortable = Arr::get($col, 'unsortable') !== 'yes';
                if ($isSortable) {
                    $orderByColumn = Arr::get($col, 'key');
                }
            }

            $dynamicRow = new DynamicRow($tableId);
            $dataProvider = ninja_table_get_data_provider($tableId);

            if (!$dynamicRow->tableExists()) {
                wp_send_json($emptyResponse);
            }

            $recordsTotal    = $dynamicRow->count();
            $recordsFiltered = ($search || !empty($customFilters))
                ? $dynamicRow->count($search, $customFilters)
                : $recordsTotal;

            $page    = ($start / max($length, 1)) + 1;
            $orderBy = $dynamicRow->getTableManager()->sanitizeColumnName($orderByColumn);
            $rows    = $dynamicRow->getAll($length, $page, $orderBy, $orderDir, $search ?: null, $customFilters);

            $columnTypeMap = $this->buildColumnTypeMap($tableColumns);

            $data    = [];
            $counter = $start;
            foreach ($rows as $row) {
                $mapped  = $dynamicRow->mapRowToUserKeys($row);
                $rowData = Arr::get($mapped, 'values', []);

                $shortcodesRendered = false;
                if ($dataProvider === 'default') {
                    $rowData = $this->renderShortcodesInRowValues($rowData, $columnTypeMap);
                    $shortcodesRendered = true;
                }

                $rowData = $this->escapeRowValues($rowData, $columnTypeMap, $shortcodesRendered);

                $colId = DynamicTableManager::COL_ID;
                $rowData['DT_RowClass'] = 'ninja_table_row_' . $counter . ' nt_row_id_' . $row->{$colId};

                if (!empty(Arr::get($mapped, 'settings', []))) {
                    $rowData['___row_settings___'] = Arr::get($mapped, 'settings', []);
                }

                $data[] = $rowData;
                $counter++;
            }

            wp_send_json([
                'draw'            => $draw,
                'recordsTotal'    => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data'            => $data,
            ]);
        } catch (\Throwable $e) {
            wp_send_json($emptyResponse);
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    private function renderShortcodesInRowValues(array $rowData, array $columnTypeMap = []): array
    {
        foreach ($rowData as $key => &$value) {
            if (!is_string($value)) {
                continue;
            }

            $type = Arr::get($columnTypeMap, $key, 'text');

            if ($type === 'text' || $type === 'html') {
                $value = do_shortcode($value);
            }
        }
        unset($value);

        return $rowData;
    }

    private function escapeRowValues(array $rowData, array $columnTypeMap, bool $shortcodesRendered = false): array
    {
        foreach ($rowData as $key => &$value) {
            if (in_array($key, ['DT_RowClass', '___row_settings___'], true)) {
                continue;
            }

            $type = Arr::get($columnTypeMap, $key, 'text');

            if ($type === 'image' && is_array($value)) {
                if (isset($value['permalink'])) {
                    $value['permalink'] = esc_url($value['permalink']);
                }
                if (isset($value['image_thumb'])) {
                    $value['image_thumb'] = esc_url($value['image_thumb']);
                }
                if (isset($value['image_full'])) {
                    $value['image_full'] = esc_url($value['image_full']);
                }
                if (isset($value['alt_text'])) {
                    $value['alt_text'] = esc_attr($value['alt_text']);
                }
                continue;
            }

            if ($type === 'button' && is_string($value)) {
                $value = esc_url($value);
                continue;
            }

            // When shortcodes have been rendered, trust their output (matches FooTable behavior).
            // Shortcode handlers are responsible for their own escaping.
            if ($shortcodesRendered) {
                continue;
            }

            if (is_string($value)) {
                $value = wp_kses_post($value);
            }
        }
        unset($value);

        return $rowData;
    }

    private function buildColumnTypeMap(array $columns): array
    {
        $map = [];
        foreach ($columns as $col) {
            $key  = Arr::get($col, 'key', '');
            $type = Arr::get($col, 'data_type', 'text');

            if (Arr::get($col, 'enable_html_content') == 'true') {
                $type = 'html';
            }

            $map[$key] = $type;
        }

        return $map;
    }

    private function enqueueAssets()
    {
        $app    = App::getInstance();
        $assets = $app['url.assets'];

        wp_enqueue_style(
            'datatables_core_css',
            $assets . 'libs/datatables/datatables.min.css',
            [], NINJA_TABLES_VERSION
        );

        wp_enqueue_style(
            'ninjatables_dt_css',
            NINJA_TABLES_DIR_URL . 'assets/css/ninjatables-datatables.css',
            ['datatables_core_css'], NINJA_TABLES_VERSION
        );

        wp_enqueue_script(
            'datatables_core_js',
            $assets . 'libs/datatables/datatables.min.js',
            ['jquery'], NINJA_TABLES_VERSION, true
        );

        wp_enqueue_script(
            'ninja_dt_init',
            $assets . 'js/ninja-tables-datatables.js',
            ['datatables_core_js'], NINJA_TABLES_VERSION, true
        );

        wp_localize_script('ninja_dt_init', 'ninja_datatables', [
            'site_url' => site_url(),
            'i18n'     => TranslationService::getSumoSelectI18n(),
        ]);

        // Polyfill window.ninja_footables so Pro's SumoSelect/Pikaday/EditTable.js work in DataTables mode
        $polyfillData = [
            'i18n'                    => TranslationService::getSumoSelectI18n(),
            'ajax_url'                => admin_url('admin-ajax.php'),
            'ninja_table_public_nonce' => wp_create_nonce('ninja_table_public_nonce'),
        ];
        $polyfillJson = wp_json_encode($polyfillData);
        wp_add_inline_script('jquery-core', 'if(!window.ninja_footables){window.ninja_footables=' . $polyfillJson . ';}', 'after');
    }

    private function enqueueResponsiveAssets()
    {
        $app    = App::getInstance();
        $assets = $app['url.assets'];

        wp_enqueue_style(
            'datatables_responsive_css',
            $assets . 'libs/datatables/responsive.dataTables.min.css',
            ['datatables_core_css'], NINJA_TABLES_VERSION
        );

        wp_enqueue_style(
            'ninjatables_dt_responsive_css',
            NINJA_TABLES_DIR_URL . 'assets/css/ninjatables-datatables-responsive.css',
            ['datatables_responsive_css', 'ninjatables_dt_css'], NINJA_TABLES_VERSION
        );

        wp_enqueue_script(
            'datatables_responsive_js',
            $assets . 'libs/datatables/dataTables.responsive.min.js',
            ['datatables_core_js'], NINJA_TABLES_VERSION, true
        );
    }

    private function formatColumns($columns, &$settings, $tableArray)
    {
        $formatted      = [];
        $globalSorting  = (bool) Arr::get($settings, 'column_sorting', false);
        $sortingType    = Arr::get($settings, 'sorting_type', 'by_created_at');

        foreach ($columns as $index => $column) {
            $base = ColumnHelper::formatColumnBase($column, $index, $settings, $globalSorting, $sortingType);
            $formatted_column = Arr::get($base, 'formatted_column', []);

            if (Arr::get($base, 'enqueue_moment')) {
                wp_enqueue_script('moment');
            }
            if (Arr::get($base, 'load_lightbox')) {
                $settings['load_lightbox'] = true;
                if (Arr::get($base, 'iframe_lightbox')) {
                    $settings['iframe_lightbox'] = true;
                }
            }

            $formatted_column['data_type'] = Arr::get($column, 'data_type', 'text');

            if (Arr::get($column, 'enable_html_content') == 'true') {
                $formatted_column['enable_html_content'] = true;
            }

            if (ColumnHelper::getColumnType($column) == 'image') {
                $formatted_column['link_type']        = Arr::get($column, 'link_type', '');
                $formatted_column['image_base_url']   = Arr::get($column, 'image_base_url', '');
                $formatted_column['image_alt_tag']    = Arr::get($column, 'image_alt_tag', '');
                $formatted_column['download_button']  = Arr::get($column, 'download_button', '');
                $formatted_column['link_target']      = Arr::get($column, 'link_target', '_self');
            }

            if (Arr::get($column, 'data_type') == 'button') {
                $formatted_column['btn_text_color']   = Arr::get($column, 'btn_text_color', '');
                $formatted_column['btn_bg_color']     = Arr::get($column, 'btn_bg_color', '');
                $formatted_column['btn_border_color'] = Arr::get($column, 'btn_border_color', '');
                $formatted_column['btn_extra_class']  = Arr::get($column, 'btn_extra_class', '');
                $formatted_column['button_text']      = Arr::get($column, 'button_text', '');
                $formatted_column['link_target']      = Arr::get($column, 'link_target', '_self');
                $formatted_column['force_download']   = Arr::get($column, 'force_download', '');
                $formatted_column['relAttributes']    = Arr::get($column, 'relAttributes', '');
            }

            if ($width = Arr::get($column, 'width')) {
                $formatted_column['width']    = $width;
                $formatted_column['widthUnit'] = Arr::get($column, 'maxWidthUnit', 'px');
            }
            if ($textAlign = Arr::get($column, 'textAlign')) {
                $formatted_column['textAlign'] = $textAlign;
            }
            if ($contentAlign = Arr::get($column, 'contentAlign')) {
                $formatted_column['contentAlign'] = $contentAlign;
            }

            if (defined('NINJATABLESPRO')) {
                if ($bgColor = Arr::get($column, 'background_color')) {
                    $formatted_column['background_color'] = $bgColor;
                }
                if ($textColor = Arr::get($column, 'text_color')) {
                    $formatted_column['text_color'] = $textColor;
                }
            }

            if (Arr::get($column, 'enableCopyContent')) {
                $formatted_column['enableCopyContent'] = Arr::get($column, 'enableCopyContent');
            }

            $formatted[] = apply_filters(
                'ninja_table_column_attributes', $formatted_column, $column, Arr::get($tableArray, 'table_id'), $tableArray
            );
        }

        return $formatted;
    }

    private function getLegacyRows($tableId, $settings)
    {
        $dynamicRow = new DynamicRow($tableId);
        $dataProvider = ninja_table_get_data_provider($tableId);

        if (!$dynamicRow->tableExists()) {
            return [];
        }

        $defaultSorting = Arr::get($settings, 'default_sorting', 'new_first');
        $orderDir       = ($defaultSorting === 'new_first') ? 'DESC' : 'ASC';

        $total = $dynamicRow->count();
        $rows  = $dynamicRow->getAll($total ?: 1000, 1, DynamicTableManager::COL_POSITION, $orderDir);

        $tableColumns  = ninja_table_get_table_columns($tableId, 'public');
        $columnTypeMap = $this->buildColumnTypeMap($tableColumns ?: []);

        $result  = [];
        $counter = 0;
        foreach ($rows as $row) {
            $mapped  = $dynamicRow->mapRowToUserKeys($row);
            $rowData = Arr::get($mapped, 'values', []);

            $shortcodesRendered = false;
            if ($dataProvider === 'default') {
                $rowData = $this->renderShortcodesInRowValues($rowData, $columnTypeMap);
                $shortcodesRendered = true;
            }

            $rowData = $this->escapeRowValues($rowData, $columnTypeMap, $shortcodesRendered);

            $colId = DynamicTableManager::COL_ID;
            $rowData['DT_RowClass'] = 'ninja_table_row_' . $counter . ' nt_row_id_' . $row->{$colId};

            if (!empty(Arr::get($mapped, 'settings', []))) {
                $rowData['___row_settings___'] = Arr::get($mapped, 'settings', []);
            }

            $result[] = $rowData;
            $counter++;
        }

        return $result;
    }

    private function getTableCssClasses($settings, $tableArray)
    {
        $classes = ['ninja-dt-table-container'];

        if (
            defined('NINJATABLESPRO')
            && Arr::get($settings, 'table_color_type') == 'pre_defined_color'
            && Arr::get($settings, 'table_color')
            && Arr::get($settings, 'table_color') != 'ninja_no_color_table'
        ) {
            $classes[] = Arr::get($settings, 'table_color');
        }

        if (Arr::get($settings, 'load_lightbox')) {
            $classes[] = 'nt_has_lightbox';
            if (Arr::get($settings, 'iframe_lightbox')) {
                $classes[] = 'nt_has_iframe_lightbox';
            }
            do_action('ninja_tables_load_lightbox', $settings);
        }

        if (Arr::get($settings, 'hide_all_borders')) {
            $classes[] = 'hide_all_borders';
        }

        if (Arr::get($settings, 'hide_header_row')) {
            $classes[] = 'ninjatable_hide_header_row';
        }

        if (!Arr::get($settings, 'enable_search', false)) {
            $classes[] = 'ninja_table_search_disabled';
        }

        if ($searchPos = Arr::get($settings, 'search_position')) {
            $classes[] = 'ninja_search_' . $searchPos;
        }

        if (Arr::get($settings, 'stackable') == 'yes') {
            $stackAppearances = Arr::get($settings, 'stacks_appearances', []);
            if (is_array($stackAppearances) && $stackAppearances) {
                $classes[] = implode(' ', $stackAppearances);
            }
        }

        if (defined('NINJATABLESPRO')) {
            $classes[] = 'ninja_table_pro';
        }

        $advancedFilterSettings = get_post_meta(Arr::get($tableArray, 'table_id'), '_ninja_custom_filter_styling', true);
        $advancedFilters        = get_post_meta(Arr::get($tableArray, 'table_id'), '_ninja_table_custom_filters', true);
        if ($advancedFilterSettings && $advancedFilters) {
            $defaultStyling         = [
                'filter_display_type' => 'inline',
                'filter_columns'      => 'columns_2',
                'filter_column_label' => 'new_line',
            ];
            $advancedFilterSettings = wp_parse_args($advancedFilterSettings, $defaultStyling);
            if (Arr::get($advancedFilterSettings, 'filter_display_type') == 'inline') {
                $classes[] = 'ninja_table_afd_inline';
            } else {
                $classes[] = 'ninja_table_afd_' . Arr::get($advancedFilterSettings, 'filter_display_type');
                $classes[] = 'ninja_table_afcs_' . Arr::get($advancedFilterSettings, 'filter_columns');
                $classes[] = 'ninja_table_afcl_' . Arr::get($advancedFilterSettings, 'filter_column_label');
            }
            $classes[] = 'ninja_table_has_custom_filter';
        }

        if (Arr::get($settings, 'sticky_first_column') == 'yes') {
            $classes[] = 'nt_sticky_first_column';
        }

        if (Arr::get($settings, 'nt_search_full_width')) {
            $classes[] = 'nt_search_full_width';
        }

        $classes[] = 'nt_type_' . Arr::get($settings, 'render_type', 'ajax_table');

        $extraCssClass = trim(Arr::get($settings, 'extra_css_class', ''));
        if ($extraCssClass) {
            $classes[] = sanitize_html_class($extraCssClass);
        }

        $cssClasses = Arr::get($settings, 'css_classes', []);
        if (is_array($cssClasses) && !empty($cssClasses)) {
            $classes = array_merge($classes, $cssClasses);
        }

        return implode(' ', array_unique($classes));
    }

    private function generateAndInjectCSS($tableArray, $columns, $formatted_columns, $settings)
    {
        $tableId          = intval(Arr::get($tableArray, 'table_id'));
        $cssPrefix        = '#ninja_dt_' . $tableId;
        $cssParentPrefix  = '#ninja_dt_parent_' . $tableId;

        $columnContentCss = TableCssHelper::buildColumnAlignmentCss($cssPrefix, $columns);
        $customColumnCss  = TableCssHelper::buildPerColumnColorCss($cssPrefix, $columns);
        $fonts            = TableCssHelper::extractFontSettings($settings);
        $colors           = TableCssHelper::buildColorPalette($settings);

        $custom_css = str_replace('NT_ID', $tableId, get_post_meta($tableId, '_ninja_tables_custom_css', true));
        $custom_css .= $columnContentCss . $customColumnCss;

        $cellStyles = TableCssHelper::getCellStyles($tableId, 'datatables');

        $hasStackable = Arr::get($settings, 'stackable') == 'yes';

        if (!Arr::get($fonts, 'table_font_size') && !Arr::get($fonts, 'table_font_family') && !$colors && !$custom_css && !count($cellStyles)) {
            return;
        }

        $css_prefix        = $cssPrefix;
        $css_parent_prefix = $cssParentPrefix;
        ob_start();
        include NINJA_TABLES_DIR_PATH . 'app/Views/public/datatables/ninja-datatable-css.php';
        $css = ob_get_clean();

        if ($css) {
            add_action('wp_footer', function () use ($css, $tableId) {
                ?>
                <style type="text/css" id="ninja_dt_custom_css_<?php echo esc_attr($tableId); ?>">
                    <?php echo ninjaTablesEscCss($css); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </style>
                <?php
            });
        }
    }

    public static function generateCustomColorCSSForTable($tableArray, $extraCss = '')
    {
        $instance = new self();
        $columns  = Arr::get($tableArray, 'columns', []);
        $settings = Arr::get($tableArray, 'settings', []);

        TableCssHelper::normalizeColorType($settings);
        $tableArray['settings'] = $settings;

        $formatted_columns = $instance->formatColumns($columns, $settings, $tableArray);
        $instance->generateAndInjectCSS($tableArray, $columns, $formatted_columns, $settings);
    }
}
