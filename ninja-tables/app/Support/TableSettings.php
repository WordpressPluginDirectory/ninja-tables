<?php

namespace NinjaTables\App\Support;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Helper\Pro;
use NinjaTables\Framework\Support\Arr;

/**
 * Table settings, columns, providers and plugin-level options.
 */
class TableSettings
{
    /**
     * Globally-accessible functions
     *
     * @link           https://authlab.io
     * @since          1.0.0
     *
     * @package        wp_table_data_press
     * @subpackage     wp_table_data_press/includes
     *
     * @param        $tableId
     * @param string $scope
     *
     * @return array
     */
    public static function getTableColumns($tableId, $scope = 'public')
    {
        $tableColumns = get_post_meta($tableId, '_ninja_table_columns', true);
        if ( ! $tableColumns || ! is_array($tableColumns)) {
            $tableColumns = array();
        }

        $tableColumns = self::normalizeColumns($tableColumns);

        return apply_filters('ninja_get_table_columns_' . $scope, $tableColumns, $tableId);
    }

    /**
     * Fill in the column keys older import paths left out.
     *
     * Imported tables were stored with only key/name/breakpoints, so consumers that
     * read the meta straight through (the Gutenberg block, the Design preview) saw an
     * undefined column type where PHP renderers quietly defaulted it. Normalising here
     * gives every consumer the same shape a natively-created table has, without a
     * migration. Existing values are never overwritten.
     *
     * @param array $columns
     *
     * @return array
     */
    protected static function normalizeColumns($columns)
    {
        foreach ($columns as $index => $column) {
            if ( ! is_array($column)) {
                continue;
            }

            if (empty($column['data_type'])) {
                $column['data_type'] = 'text';
            }

            // native tables store null here; imports stored '', which JS consumers
            // treat as a real (empty-named) breakpoint rather than "none"
            if ( ! isset($column['breakpoints']) || $column['breakpoints'] === '') {
                $column['breakpoints'] = null;
            }

            foreach (['dateFormat', 'header_html_content', 'contentAlign', 'textAlign'] as $key) {
                if ( ! array_key_exists($key, $column)) {
                    $column[$key] = null;
                }
            }

            if ( ! array_key_exists('enable_html_content', $column)) {
                $column['enable_html_content'] = false;
            }

            if ( ! array_key_exists('original_name', $column) && isset($column['key'])) {
                $column['original_name'] = $column['key'];
            }

            $columns[$index] = $column;
        }

        return $columns;
    }

    public static function getTableSettings($tableId, $scope = 'public')
    {
        $tableSettings        = get_post_meta($tableId, '_ninja_table_settings', true);
        $defaultTableSettings = ninjaTablesGetDefaultSettings();
        if ( ! $tableSettings) {
            $tableSettings = $defaultTableSettings;
        } else {
            if (empty($tableSettings['css_classes'])) {
                $tableSettings['css_classes'] = array();
            }

            if (empty($tableSettings['stacks_devices'])) {
                $tableSettings['stacks_devices'] = array();
            }

            if (empty($tableSettings['stacks_appearances'])) {
                $tableSettings['stacks_appearances'] = array();
            }
        }

        return apply_filters('ninja_get_table_settings_' . $scope, $tableSettings, $tableId);
    }

    public static function getDefaultSettings()
    {
        $renderType = Pro::active() ? 'legacy_table' : 'ajax_table';
        $settings   = get_option('_ninja_table_default_appearance_settings');
        $defaults   = array(
            "perPage"            => 20,
            "show_all"           => '0',
            "library"            => 'footable',
            "css_lib"            => 'semantic_ui',
            "enable_ajax"        => '0',
            "css_classes"        => array(),
            "enable_search"      => '1',
            "column_sorting"     => '1',
            "default_sorting"    => 'old_first',
            "sorting_type"       => "by_created_at",
            "table_color"        => 'ninja_no_color_table',
            "render_type"        => $renderType,
            "frontend_loader"    => 'yes',
            "table_color_type"   => 'pre_defined_color',
            "expand_type"        => 'default',
            // Explicit defaults so admin switches never receive an undefined value
            "show_title"             => '0',
            "show_description"       => '0',
            "hide_header_row"        => '0',
            "show_row_data_modal"    => 'no',
            "hide_on_empty"          => '0',
            "hide_responsive_labels" => '0',
            'stackable'          => 'no',
            'stacks_devices'     => array(),
            'stacks_appearances' => array(),
            'table_font_family'  => 'inherit',
            'table_font_size'    => 14,
        );
        if ( ! $settings) {
            $defaults['css_classes'] = array(
                'selectable',
                'striped',
                'vertical_centered'
            );
        }
        if ( ! $settings) {
            $settings = array();
        }
        $settings = wp_parse_args($settings, $defaults);

        return apply_filters('ninja_tables/get_default_ninja_table_settings', $settings);
    }

    /**
     * Legacy FooTable style class (Semantic UI and Bootstrap 3/4 keys) → DataTables equivalent.
     * 'hide_horizonal_borders' is the (misspelled) key the FooTable style list stores.
     */
    const DT_LEGACY_CSS_CLASS_MAP = array(
        'selectable'              => 'dt_hover',
        'striped'                 => 'dt_striped',
        'celled'                  => 'dt_bordered',
        'hide_horizonal_borders'  => 'dt_hide_horizontal_borders',
        'hide_horizontal_borders' => 'dt_hide_horizontal_borders',
        'hide_vertical_borders'   => 'dt_hide_vertical_borders',
        'compact'                 => 'dt_compact',
        'vertical_centered'       => 'dt_vertical_centered',
        // Bootstrap 3/4 FooTable style keys (Default Appearance can store these)
        'table-hover'             => 'dt_hover',
        'table-striped'           => 'dt_striped',
        'table-bordered'          => 'dt_bordered',
        'table-sm'                => 'dt_compact',
        'table-condensed'         => 'dt_compact',
    );

    /**
     * DataTables tables are seeded with FooTable style names (defaults / Default Appearance),
     * which the DataTables stylesheet does not know. Map them to dt_* and drop the rest.
     * No-op for FooTable tables.
     */
    public static function normalizeDataTablesCssClasses($settings)
    {
        if (!is_array($settings) || Arr::get($settings, 'library') !== 'datatables') {
            return $settings;
        }

        $normalized = array();
        foreach ((array) Arr::get($settings, 'css_classes', array()) as $class) {
            if (!is_string($class) || $class === '') {
                continue;
            }
            if (isset(self::DT_LEGACY_CSS_CLASS_MAP[$class])) {
                $class = self::DT_LEGACY_CSS_CLASS_MAP[$class];
            } elseif (strpos($class, 'dt_') !== 0) {
                continue; // FooTable-only style with no DataTables equivalent
            }
            $normalized[] = $class;
        }

        $settings['css_classes'] = array_values(array_unique($normalized));

        return $settings;
    }

    public static function getDataProvider($tableId)
    {
        $provider = get_post_meta($tableId, '_ninja_tables_data_provider', true);
        if ( ! $provider) {
            $provider = 'default';
        }

        return $provider;
    }

    /**
     * Only admin-authored cells (default provider) are trusted to run shortcodes.
     * Other providers can hold attacker-controlled values (e.g. Fluent Forms entries).
     */
    public static function providerExecutesShortcodes($tableId)
    {
        $provider = self::getDataProvider($tableId);

        $trusted = apply_filters('ninja_tables_shortcode_trusted_providers', array('default'), $tableId, $provider);
        $allowed = in_array($provider, (array)$trusted, true);

        return (bool)apply_filters('ninja_tables_provider_allows_shortcodes', $allowed, $provider, $tableId);
    }

    public static function renderCellShortcode($value, $execute)
    {
        // Untrusted values are returned verbatim, never run through do_shortcode.
        if ($execute && is_string($value)) {
            return do_shortcode($value);
        }

        return $value;
    }

    public static function adminRole()
    {
        if (current_user_can('administrator')) {
            return 'administrator';
        }
        $roles = apply_filters('ninja_table_admin_role', array('administrator'));
        if (is_string($roles)) {
            $roles = array($roles);
        }
        foreach ($roles as $role) {
            if (current_user_can($role)) {
                return $role;
            }
        }

        return false;
    }

    public static function dbTableName()
    {
        return 'ninja_table_items';
    }

    public static function isInProductionMood()
    {
        return apply_filters('ninja_table_is_in_production_mood', false);
    }

    /**
     * Get the ninja table icon url.
     *
     * @return string
     */
    public static function getIconUrl()
    {
        return 'data:image/svg+xml;base64,'
               . base64_encode('<svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px"
              viewBox="0 0 80 80" xml:space="preserve">
                  <g>
                     <g>
                        <polyline points="0.6,51.2 18.4,51.2 18.4,38.6 0.6,38.6" fill="#ffffff" />
                        <path d="M0.6,63.1c0,1,0,1.9,0.2,2.8h17.6V53.5H0.6" fill="#ffffff"/>
                        <path d="M0.6,22.6" fill="#ffffff"/>
                        <path d="M0.6,21.5h78.8v-4.7c0-9-7.5-16.2-16.7-16.2H20.4h-3C8.1,0.5,0.6,7.8,0.6,16.7" fill="#ffffff"/>
                        <polyline points="0.6,36.3 18.4,36.3 18.4,23.8 0.6,23.8" fill="#ffffff"/>
                        <rect x="20.6" y="38.6" width="58.8" height="12.5" fill="#ffffff"/>
                        <rect x="20.6" y="23.8" width="58.8" height="12.4" fill="#ffffff"/>
                        <path d="M79.3,65.9c0.1-1.1,0.1-1.8,0.1-2.7v-9.7H20.6v12.4L79.3,65.9" fill="#ffffff"/>
                     </g>
                        <path d="M18.4,79.3L18.4,79.3v-11H1.5v0.1c2.2,6.4,8.5,11,15.9,11L18.4,79.3L18.4,79.3z" fill="#ffffff"/>
                        <path d="M78.6,68.3h-58v11v0.1h42.1C70.1,79.4,76.4,74.8,78.6,68.3C78.6,68.4,78.6,68.4,78.6,68.3" fill="#ffffff"/>
                  </g>
                </svg>');
    }

    public static function getFluentFormMenuIcon()
    {
        $icon = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><defs><style>.cls-1{fill:#fff;}</style></defs><title>dashboard_icon</title><g id="Layer_2" data-name="Layer 2"><g id="Layer_1-2" data-name="Layer 1"><path class="cls-1" d="M15.57,0H4.43A4.43,4.43,0,0,0,0,4.43V15.57A4.43,4.43,0,0,0,4.43,20H15.57A4.43,4.43,0,0,0,20,15.57V4.43A4.43,4.43,0,0,0,15.57,0ZM12.82,14a2.36,2.36,0,0,1-1.66.68H6.5A2.31,2.31,0,0,1,7.18,13a2.36,2.36,0,0,1,1.66-.68l4.66,0A2.34,2.34,0,0,1,12.82,14Zm3.3-3.46a2.36,2.36,0,0,1-1.66.68H3.21a2.25,2.25,0,0,1,.68-1.64,2.36,2.36,0,0,1,1.66-.68H16.79A2.25,2.25,0,0,1,16.12,10.53Zm0-3.73a2.36,2.36,0,0,1-1.66.68H3.21a2.25,2.25,0,0,1,.68-1.64,2.36,2.36,0,0,1,1.66-.68H16.79A2.25,2.25,0,0,1,16.12,6.81Z"/></g></g></svg>');

        return apply_filters('ninja_tables/fluent_form_menu_icon', $icon);
    }

    public static function getPostStatuses()
    {
        $post_status = [
            ['key' => 'publish', 'label' => 'Publish'],
            ['key' => 'pending', 'label' => 'Pending'],
            ['key' => 'draft', 'label' => 'Draft'],
            ['key' => 'auto-draft', 'label' => __('Auto Draft', 'ninja-tables')],
            ['key' => 'future', 'label' => 'Future'],
            ['key' => 'private', 'label' => 'Private'],
            ['key' => 'inherit', 'label' => 'Inherit'],
            ['key' => 'trash', 'label' => 'Trash'],
            ['key' => 'any', 'label' => 'Any']
        ];

        return apply_filters('ninja_table_post_status', $post_status);
    }

    public static function perChunk($table_id = false)
    {
        return apply_filters('ninja_table_per_chunk', 3000, $table_id);
    }

    public static function boot()
    {
        return true;
    }

    public static function request()
    {
        return \NinjaTables\Framework\Foundation\App::getInstance('request')->all();
    }

    public static function isNotice($key = 'admin_notice')
    {
        $prefix = 'ninja_tables_';

        if (isset($_COOKIE[$prefix . $key])) {
            $plugin_version = sanitize_text_field(wp_unslash($_COOKIE[$prefix . $key]));

            if ($plugin_version == NINJA_TABLES_VERSION) {
                return false;
            }
        }

        return true;
    }

    public static function maybeDisableContaminatedPro()
    {
        $unsafeProFile = WP_PLUGIN_DIR . '/ninja-tables-pro/app/Library/updater/NinjaTableDataSync.php';

        if (!is_file($unsafeProFile)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        deactivate_plugins(
            'ninja-tables-pro/ninja-tables-pro.php',
            true
        );

        $message = sprintf(
            /* translators: %1$s-%2$s, %3$s-%4$s and %5$s-%6$s are opening/closing HTML anchor tags for the dashboard, support ticket and incident report links. */
            __('<strong>Ninja Tables Pro has been deactivated for security reasons.</strong> Delete the existing plugin and install a fresh copy from your %1$sWPManageNinja dashboard%2$s. Your Ninja Tables data will remain intact. We recommend %3$sopening a support ticket%4$s so we can help clean up your site. Read the %5$sincident report%6$s for details.', 'ninja-tables'),
            '<a class="nt-link" href="' . esc_url(add_query_arg('nt_deactivation_error', '1', 'https://wpmanageninja.com/account/downloads')) . '" target="_blank" rel="noopener noreferrer">',
            '</a>',
            '<a class="nt-link" href="' . esc_url(add_query_arg('nt_deactivation_error', '1', 'https://wpmanageninja.com/account/support-tickets/submit-ticket/')) . '" target="_blank" rel="noopener noreferrer">',
            '</a>',
            '<a class="nt-link" href="' . esc_url(add_query_arg('nt_deactivation_error', '1', 'https://wpmanageninja.com/security-incident-on-31-july-2026/')) . '" target="_blank" rel="noopener noreferrer">',
            '</a>'
        );

        add_filter('ninja_tables_admin_notices', function ($notices) use ($message) {
            $notices['contaminated_pro_disabled'] = [
                'type'      => 'permanent',
                'condition' => true,
                'callback'  => function () use ($message) {
                    return '<div class="notice notice-error"><p>' . $message . '</p></div>';
                },
            ];

            return $notices;
        });

        add_action('admin_notices', function () use ($message) {
            if (! current_user_can('activate_plugins')) {
                return;
            }
            ?>
        <div class="notice notice-error">
            <p>
                <?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Admin notice with HTML links ?>
            </p>
        </div>
        <?php
    });
    }
}
