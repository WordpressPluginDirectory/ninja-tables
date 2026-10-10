<?php

namespace NinjaTables\App\Services\Render;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\App;
use NinjaTables\App\Helper\Helper;
use NinjaTables\App\Helper\Pro;
use NinjaTables\App\Models\NinjaTableItem;
use NinjaTables\App\Modules\DataProviders\FluentFormProvider;
use NinjaTables\App\Modules\DataProviders\NinjaFooTable;
use NinjaTables\App\Services\TranslationService;
use NinjaTables\App\Services\Vite;
use NinjaTables\Framework\Support\Arr;

/**
 * Frontend renderer for the FooTable engine.
 */
class FooTableRenderer extends AbstractTableRenderer
{
    // Bump when the cached-HTML rendering policy changes so older caches (e.g. built
    // before the shortcode trust fix) are rejected and regenerated instead of served.
    const CACHE_POLICY_VERSION = 2;

    public function render(array $tableArray)
    {
        global $ninja_table_current_rendering_table;

        $instanceName = 'ninja_table_instance_' . count(NinjaFooTable::$tableInstances);
        NinjaFooTable::$tableInstances[] = $instanceName;

        $tableArray['uniqueID']            = 'ninja_table_unique_id_' . wp_rand() . '_' . $tableArray['table_id'];
        $ninja_table_current_rendering_table = $tableArray;

        NinjaFooTable::enqueuePublicCss();

        $tableArray['table_instance_name'] = $instanceName;
        $tableArray                        = $this->prepare($tableArray, 'ninja_table_unique_id_');

        $this->enqueueAssets();
        $this->renderTable($tableArray);
    }

    protected function enqueueAssets()
    {
        $assets = App::getInstance()['url.assets'];

        wp_enqueue_script('footable', $assets . 'libs/footable/js/footable.min.js', ['jquery'], '3.1.5', true);
        Vite::enqueueScript('footable_init', 'js/ninja-tables-footable.js', ['footable']);

        $localizeData = [
            'ajax_url'                 => admin_url('admin-ajax.php'),
            'tables'                   => [],
            'ninja_version'            => NINJA_TABLES_VERSION,
            'i18n'                     => TranslationService::getSumoSelectI18n() + TranslationService::getPublicUiI18n(),
            'ninja_table_public_nonce' => wp_create_nonce('ninja_table_public_nonce'),
            'site_url'                 => site_url(),
            'delay'                    => apply_filters('ninja_tables_footable_init_delay', 0),
        ];

        if (Pro::version()) {
            $localizeData['pro_version'] = Pro::version();
        }

        wp_localize_script('footable_init', 'ninja_footables', $localizeData);

        // Pro's bundle extends FooTable (e.g. the "~min:max" range filter), so it must load after FooTable.
        // Only done here so DataTables pages (where 'footable' is never enqueued) don't drop the Pro bundle.
        if (wp_script_is('ninja-tables-pro', 'registered')) {
            $proScript = wp_scripts()->registered['ninja-tables-pro'];
            if (!in_array('footable', $proScript->deps, true)) {
                $proScript->deps[] = 'footable';
            }
        }
    }

    protected function renderTable(array $tableArray)
    {
        $columns  = Arr::get($tableArray, 'columns', []);
        $settings = Arr::get($tableArray, 'settings', []);
        $table    = Arr::get($tableArray, 'table');
        $table_id = Arr::get($tableArray, 'table_id');

        if (!count($columns)) {
            return;
        }

        $renderType        = Arr::get($settings, 'render_type', 'ajax_table');
        $default_filter    = Arr::get($tableArray, 'default_filter');
        $instanceName      = $tableArray['table_instance_name'];
        $customCss         = [];

        $formatted_columns = ColumnFormatter::format($columns, $settings, $tableArray, function ($fc, $column, $index) use (&$settings, &$customCss, $tableArray) {
            $cssColumnName             = 'ninja_column_' . $index;
            $customCss[$cssColumnName] = [];
            if ($columnWidth = Arr::get($column, 'width')) {
                $customCss[$cssColumnName]['width'] = $columnWidth . Arr::get($column, 'maxWidthUnit', 'px');
            }

            // Pro shop providers open product images in the lightbox.
            if ((Helper::isProviderActiveAndMatches($tableArray, 'wp_woo') || Helper::isProviderActiveAndMatches($tableArray, 'wp_fct'))
                && Arr::get($column, 'image_permalink_type') == 'lightbox') {
                $settings['load_lightbox'] = true;
            }

            return $fc;
        });

        $pagingSettings = RenderConfig::paging($settings);

        // The filter shortcode filters in the browser, so a row limit cannot be applied while
        // fetching; the rows are fetched unlimited and the limit becomes the page size instead.
        $limitRows     = intval(Arr::get($settings, 'limit_rows', 0));
        $deferRowLimit = $limitRows && !empty($default_filter);

        if ($deferRowLimit) {
            $pagingSettings         = $limitRows;
            $settings['show_pager'] = false;
            $settings['limit_rows'] = 0;
            $settings['skip_rows']  = 0;
        }

        $enableSearch = Arr::get($settings, 'enable_search', false);

        $configSettings = [
            'filtering'             => $enableSearch,
            'togglePosition'        => Arr::get($settings, 'togglePosition', 'first'),
            'paging'                => $pagingSettings,
            'pager'                 => (bool)Arr::get($settings, 'show_pager'),
            'page_sizes'            => explode(',', Arr::get($settings, 'paze_sizes', '10,20,50,100')),
            'sorting'               => true,
            'default_sorting'       => RenderConfig::defaultSorting($settings),
            'defualt_filter'        => isset($default_filter) ? $default_filter : false,
            'defualt_filter_column' => Arr::get($settings, 'filter_column'),
            'expandFirst'           => Arr::get($settings, 'expand_type') == 'expandFirst',
            'expandAll'             => Arr::get($settings, 'expand_type') == 'expandAll',
            'i18n'                  => RenderConfig::i18n($settings),
            'shouldNotCache'        => isset($settings['shouldNotCache']) ? $settings['shouldNotCache'] : false,
            'skip_rows'             => Arr::get($settings, 'skip_rows', 0),
            'limit_rows'            => Arr::get($settings, 'limit_rows', 0),
            'use_parent_width'      => Arr::get($settings, 'use_parent_width', false),
            'info'                  => Arr::get($tableArray, 'shortCodeData.info', ''),
            'enable_html_cache'     => Arr::get($settings, 'enable_html_cache'),
            'html_caching_minutes'  => Arr::get($settings, 'html_caching_minutes'),
            'show_row_data_modal'   => Arr::get($settings, 'show_row_data_modal', 'no'),
        ];

        $settings['info'] = Arr::get($tableArray, 'shortCodeData.info', '');

        [$table_classes, $tableHasColor, $configSettings] = $this->classes($settings, $table_id, $configSettings);

        RenderConfig::applyProConfig($configSettings, $settings);
        if ($stack = RenderConfig::stackConfig($settings)) {
            $configSettings['stack_config'] = $stack;
        }

        $configSettings['has_formula'] = Arr::get($settings, 'formula_support', 'no');

        $tableCaption = get_post_meta($table_id, '_ninja_table_caption', true);

        if (Helper::isProviderActiveAndMatches($tableArray, 'wp_fct')) {
            $configSettings['appearance_settings'] = Arr::get(get_post_meta($table_id, '_ninja_table_fct_appearance_settings', false), 0, []);
        } elseif (Helper::isProviderActiveAndMatches($tableArray, 'wp_woo')) {
            $configSettings['appearance_settings'] = Arr::get(get_post_meta($table_id, '_ninja_table_woo_appearance_settings', false), 0, []);
        }

        $table_vars = [
            'table_id'         => $table_id,
            'title'            => $table->post_title,
            'caption'          => $tableCaption,
            'columns'          => $formatted_columns,
            'original_columns' => $columns,
            'settings'         => $configSettings,
            'render_type'      => $renderType,
            'defer_row_limit'  => $deferRowLimit,
            'custom_css'       => $customCss,
            'instance_name'    => $instanceName,
            'table_version'    => NINJA_TABLES_VERSION,
            'provider'         => $tableArray['provider'],
            'uniqueID'         => $tableArray['uniqueID'],
            'render_engine'    => Arr::get($tableArray, 'settings.library'),
        ];

        $table_vars = apply_filters('ninja_table_rendering_table_vars', $table_vars, $table_id, $tableArray);

        if (Helper::isProviderActiveAndMatches($tableArray, 'wp_woo')) {
            $table_vars['wc_ajax_url'] = add_query_arg(['wc-ajax' => 'add_to_cart', 'ninja_table' => $table_id], home_url('/', 'relative'));

            add_action('wp_footer', function () {
                InlineScript::window('ninjaTableCartItems', WC()->cart->get_cart(), 'wp_footer', PHP_INT_MAX);
            });
        }

        if ($renderType == 'ajax_table') {
            $totalSize = $this->totalSize($tableArray, $table_vars);
            $perChunk  = ninjaTablePerChunk($table_id);

            if ($totalSize > $perChunk) {
                $table_vars['chunks'] = ceil($totalSize / $perChunk) - 1;
            }
        }

        $table_vars['init_config'] = $this->initConfig($table_vars);

        InlineScript::window($instanceName, $table_vars);

        $this->cssOnce($table_id, 'ninja_table_custom_css_' . $table_id, function () use ($tableArray, $columns) {
            return CssGenerator::footable($tableArray, NinjaFooTable::getColumnsCss($tableArray['table_id'], $columns));
        });

        do_action('ninja_table_before_render_table_source', $table, $table_vars, $tableArray);

        $this->includeView('public/ninja-footable', [
            'table'                => $table,
            'table_id'             => $table_id,
            'settings'             => $settings,
            'tableArray'           => $tableArray,
            'tableHasColor'        => $tableHasColor,
            'table_classes'        => $table_classes,
            'table_vars'           => $table_vars,
            'table_instance_name'  => $instanceName,
            'foo_table_attributes' => $this->attributes($table_vars),
            'tableCaption'         => $tableCaption,
            'formatted_columns'    => $formatted_columns,
        ]);
    }

    /**
     * @return array [classes string, colored_table flag, config with extra_css_class]
     */
    protected function classes(array $settings, $tableId, array $configSettings)
    {
        $classes = [
            $this->libraryClass(Arr::get($settings, 'css_lib')),
            Arr::get($settings, 'extra_css_class', ''),
        ];

        if ($lightbox = TableClasses::lightbox($settings)) {
            $classes = array_merge($classes, $lightbox);
        } elseif (in_array('nt_has_lightbox', $classes)) {
            do_action('ninja_tables_load_lightbox', $settings);
        }

        if (Arr::get($settings, 'info')) {
            $classes[] = 'ninja_has_count_format';
        }
        if ($color = TableClasses::color($settings)) {
            $classes[] = $color;
        }
        if (Arr::get($settings, 'hide_responsive_labels')) {
            $classes[] = 'nt_hide_breakpoint_labels';
        }

        $classes = array_merge($classes, TableClasses::shared($settings), (array) Arr::get($settings, 'css_classes', []));

        $tableHasColor                     = '';
        $configSettings['extra_css_class'] = '';

        if (TableClasses::color($settings) !== null) {
            $tableHasColor                     = 'colored_table';
            $configSettings['extra_css_class'] = 'inverted';
        }
        if (Arr::get($settings, 'table_color_type') == 'custom_color') {
            $tableHasColor                     = 'colored_table';
            $classes[]                         = 'ninja_custom_color';
            $configSettings['extra_css_class'] = 'inverted';
        }
        $classes[] = $configSettings['extra_css_class'];
        $classes[] = 'footable-paging-' . (Arr::get($settings, 'pagination_position') ?: 'right');
        $classes   = array_merge($classes, TableClasses::stack($settings, true));

        $customFilterClasses = TableClasses::customFilter($tableId);
        if ($customFilterClasses) {
            $classes = array_merge($classes, $customFilterClasses);
        } elseif ($configSettings['defualt_filter']) {
            $classes[] = 'ninja_has_filter';
        }

        return [TableClasses::toString($classes), $tableHasColor, $configSettings];
    }

    protected function libraryClass($lib)
    {
        switch ($lib) {
            case 'bootstrap3':
            case 'bootstrap4':
                return 'table';
            case 'semantic_ui':
                return 'ui table';
            default:
                return '';
        }
    }

    /**
     * Row count used to split ajax loading into chunks.
     */
    protected function totalSize(array $tableArray, array $table_vars)
    {
        $tableId  = $tableArray['table_id'];
        $provider = $tableArray['provider'];

        if (Helper::isProviderActiveAndMatches($tableArray, 'fluent-form')) {
            return (new FluentFormProvider())->getEntryCount($tableId);
        }
        if (in_array($provider, ['google-csv', 'csv']) && Pro::active()) {
            return count($this->proProviderRows('CsvProvider', $tableId));
        }
        if (Helper::isProviderActiveAndMatches($tableArray, 'wp_woo')) {
            return count($this->proProviderRows('WoocommercePostsProvider', $tableId));
        }
        if ($provider == 'wp-posts') {
            return count($this->proProviderRows('WPPostsProvider', $tableId));
        }

        $query = NinjaTableItem::where('table_id', $tableId);
        $query = apply_filters('ninja_tables_total_size_query', $query, $table_vars);

        return $query->count();
    }

    /**
     * Rows from a Ninja Tables Pro data provider, without a hard dependency on Pro.
     */
    protected function proProviderRows($provider, $tableId)
    {
        $class = 'NinjaTablesPro\\App\\Modules\\DataProviders\\' . $provider;

        if (!class_exists($class)) {
            return [];
        }

        return (array) (new $class())->data([], $tableId, false);
    }

    protected function attributes(array $tableVars)
    {
        $tableId = $tableVars['table_id'];
        $atts    = [
            'data-footable_id'  => $tableId,
            'data-filter-delay' => apply_filters('ninja_table_search_time_delay', 1000, $tableId),
        ];

        if ($tableVars['title'] && !$tableVars['caption']) {
            $atts['aria-label'] = $tableVars['title'];
        }

        $atts   = apply_filters('ninja_table_attributes', $atts, $tableId);
        $string = '';
        foreach ((array) $atts as $name => $value) {
            $string .= $name . '="' . esc_attr($value) . '" ';
        }

        return $string;
    }

    /**
     * FooTable.js init configuration.
     */
    public function initConfig(array $tableConfig)
    {
        $tableId       = $tableConfig['table_id'];
        $tableSettings = $tableConfig['settings'];

        $initConfig = [
            'toggleColumn'   => Arr::get($tableSettings, 'togglePosition'),
            'cascade'        => true,
            'useParentWidth' => (bool)Arr::get($tableSettings, 'use_parent_width'),
            'columns'        => Arr::get($tableConfig, 'columns'),
            'expandFirst'    => Arr::get($tableSettings, 'expandFirst'),
            'expandAll'      => Arr::get($tableSettings, 'expandAll'),
            'empty'          => Arr::get($tableSettings, 'i18n.no_result_text'),
            'sorting'        => ['enabled' => (bool)Arr::get($tableSettings, 'sorting')],
        ];

        if (Arr::get($tableConfig, 'render_type') !== 'legacy_table') {
            $params = [
                'action'                   => 'wp_ajax_ninja_tables_public_action',
                'table_id'                 => $tableId,
                'target_action'            => 'get-all-data',
                'default_sorting'          => Arr::get($tableSettings, 'default_sorting'),
                'skip_rows'                => Arr::get($tableSettings, 'skip_rows'),
                'limit_rows'               => Arr::get($tableSettings, 'limit_rows'),
                'ninja_table_public_nonce' => wp_create_nonce('ninja_table_public_nonce'),
            ];

            if (Arr::get($tableConfig, 'editing.check_editing') == 'yes' && Arr::get($tableConfig, 'editing.own_data_only') == 'yes') {
                $params['own_only'] = 'yes';
            }
            if (Arr::get($tableConfig, 'chunks', 0) > 0) {
                $params['chunk_number'] = 0;
            }

            $initConfig['data_request_url'] = add_query_arg($params, admin_url('admin-ajax.php'));
        }

        $defaultFilter = Arr::get($tableSettings, 'defualt_filter');

        $initConfig['filtering'] = [
            'enabled'       => (bool)Arr::get($tableSettings, 'filtering') || (bool) $defaultFilter,
            'delay'         => 1,
            'dropdownTitle' => Arr::get($tableSettings, 'i18n.search_in'),
            'placeholder'   => Arr::get($tableSettings, 'i18n.search'),
            'connectors'    => false,
            'ignoreCase'    => true,
        ];

        if ($defaultFilter) {
            if ($defaultFilter == "'0'") {
                $defaultFilter = '0';
            }
            $filterColumns = Arr::get($tableSettings, 'defualt_filter_column');
            $validColumns  = [];
            if ($filterColumns && count($filterColumns)) {
                foreach ($tableConfig['columns'] as $column) {
                    if (in_array(Arr::get($column, 'name'), $filterColumns)) {
                        $validColumns[] = Arr::get($column, 'name');
                    }
                }
            }
            $initConfig['filtering']['filters'] = [[
                'name'    => 'ninja_table_default_filter',
                'hidden'  => Arr::get($tableSettings, 'hide_default_filter') == 'yes',
                'query'   => $defaultFilter,
                'columns' => $validColumns,
            ]];
        }

        $pageSize = Arr::get($tableSettings, 'paging');

        $initConfig['paging'] = [
            'enabled'     => (bool)$pageSize,
            'position'    => 'right',
            'size'        => $pageSize,
            'container'   => '#footable_parent_' . $tableId . ' .paging-ui-container',
            'countFormat' => Arr::get($tableSettings, 'info', ' '),
        ];

        $config = apply_filters('ninja_tables_js_init_config', $initConfig, $tableConfig, $tableId);

        return apply_filters('ninja_tables_js_init_config_' . $tableId, $config, $tableConfig, $tableId);
    }

    /**
     * Legacy (server-rendered) table body, with optional HTML caching.
     */
    public function legacyHtml($table, array $table_vars)
    {
        if ($table_vars['render_type'] != 'legacy_table') {
            return;
        }

        $tableId   = $table->ID;
        $limitRows = Arr::get($table_vars, 'settings.limit_rows', false);
        $skipRows  = Arr::get($table_vars, 'settings.skip_rows', false);
        $ownOnly   = Arr::get($table_vars, 'editing.own_data_only') == 'yes';

        $isHtmlCacheEnabled = Arr::get($table_vars, 'settings.enable_html_cache', true) == 'yes'
                              && Arr::get($table_vars, 'settings.shouldNotCache', true) != 'yes';

        if (!$ownOnly && $isHtmlCacheEnabled && ($cached = $this->cachedHtml($tableId, $table_vars))) {
            ninjaTablesPrintSafeVar($cached);

            return;
        }

        // With a client-side (shortcode) filter the rows are fetched unlimited; see renderTable().
        $clientSideLimit = (bool) Arr::get($table_vars, 'defer_row_limit');

        $rows = ninjaTablesGetTablesDataByID(
            $tableId,
            $table_vars['columns'],
            $table_vars['settings']['default_sorting'],
            false,
            $clientSideLimit ? 0 : $limitRows,
            $clientSideLimit ? 0 : $skipRows,
            $ownOnly
        );
        $rows = apply_filters('ninja_tables_get_public_data', $rows, $table->ID);

        $html = $this->loadView('public/table-inner-html', [
            'table_columns'      => $table_vars['columns'],
            'table_rows'         => $rows,
            'execute_shortcodes' => ninja_tables_provider_executes_shortcodes($tableId),
        ]);

        if ($isHtmlCacheEnabled) {
            update_post_meta($tableId, '__last_ninja_table_last_cached_time', time());
        }
        update_post_meta($tableId, '__ninja_cached_table_html', $html);
        update_post_meta($tableId, '__ninja_cached_table_html_policy', self::CACHE_POLICY_VERSION);

        ninjaTablesPrintSafeVar($html);
    }

    protected function cachedHtml($tableId, array $table_vars)
    {
        // Reject caches built under an older rendering policy so a payload cached before
        // the shortcode trust fix is regenerated rather than served.
        $cachePolicy = intval(get_post_meta($tableId, '__ninja_cached_table_html_policy', true));
        if ($cachePolicy !== self::CACHE_POLICY_VERSION) {
            return false;
        }

        $lastCachedTime = intval(get_post_meta($tableId, '__last_ninja_table_last_cached_time', true));
        $minutes        = floatval(Arr::get($table_vars, 'settings.html_caching_minutes', true));

        if (time() >= $lastCachedTime + ($minutes * 60)) {
            return false;
        }

        $html = get_post_meta($tableId, '__ninja_cached_table_html', true);

        return strpos($html, 'ninja_tobody_rendering_done') ? $html . '<!--ninja_cached_data-->' : false;
    }
}
