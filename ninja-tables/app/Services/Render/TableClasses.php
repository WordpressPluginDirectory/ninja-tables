<?php

namespace NinjaTables\App\Services\Render;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Helper\Pro;
use NinjaTables\Framework\Support\Arr;

/**
 * CSS class fragments shared by the FooTable and DataTables wrappers.
 */
class TableClasses
{
    /**
     * Classes driven purely by table settings, identical for both engines.
     */
    public static function shared(array $settings)
    {
        $classes = [];

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
        if (Arr::get($settings, 'sticky_first_column') == 'yes') {
            $classes[] = 'nt_sticky_first_column';
        }
        if (Arr::get($settings, 'nt_search_full_width')) {
            $classes[] = 'nt_search_full_width';
        }
        if (Pro::active()) {
            $classes[] = 'ninja_table_pro';
        }

        $classes[] = 'nt_type_' . Arr::get($settings, 'render_type', 'ajax_table');

        return $classes;
    }

    /**
     * The predefined colour scheme class, when one is selected.
     */
    public static function color(array $settings, $requirePro = false)
    {
        if ($requirePro && !Pro::active()) {
            return null;
        }

        if (
            Arr::get($settings, 'table_color_type') == 'pre_defined_color'
            && ($requirePro ? Arr::get($settings, 'table_color') : true)
            && Arr::get($settings, 'table_color') != 'ninja_no_color_table'
        ) {
            return Arr::get($settings, 'table_color');
        }

        return null;
    }

    /**
     * Lightbox classes; fires ninja_tables_load_lightbox so Pro can enqueue its lightbox.
     */
    public static function lightbox(array $settings)
    {
        if (!Arr::get($settings, 'load_lightbox')) {
            return [];
        }

        $classes = ['nt_has_lightbox'];
        if (Arr::get($settings, 'iframe_lightbox')) {
            $classes[] = 'nt_has_iframe_lightbox';
        }
        do_action('ninja_tables_load_lightbox', $settings);

        return $classes;
    }

    /**
     * Stack appearance classes for stackable (mobile card) mode.
     */
    public static function stack(array $settings, $requireDevices = false)
    {
        if (Arr::get($settings, 'stackable', 'no') != 'yes') {
            return [];
        }
        if ($requireDevices && !count(Arr::get($settings, 'stacks_devices', []))) {
            return [];
        }

        $appearances = Arr::get($settings, 'stacks_appearances', []);

        return is_array($appearances) ? array_filter($appearances) : [];
    }

    /**
     * Advanced (Pro custom) filter layout classes. Returns [] when the table has none.
     */
    public static function customFilter($tableId)
    {
        $styling = get_post_meta($tableId, '_ninja_custom_filter_styling', true);
        $filters = get_post_meta($tableId, '_ninja_table_custom_filters', true);

        if (!$styling || !$filters) {
            return [];
        }

        $styling = wp_parse_args($styling, [
            'filter_display_type' => 'inline',
            'filter_columns'      => 'columns_2',
            'filter_column_label' => 'new_line',
        ]);

        $classes = [];
        if (Arr::get($styling, 'filter_display_type') == 'inline') {
            $classes[] = 'ninja_table_afd_inline';
        } else {
            $classes[] = 'ninja_table_afd_' . Arr::get($styling, 'filter_display_type');
            $classes[] = 'ninja_table_afcs_' . Arr::get($styling, 'filter_columns');
            $classes[] = 'ninja_table_afcl_' . Arr::get($styling, 'filter_column_label');
        }
        $classes[] = 'ninja_table_has_custom_filter';

        return $classes;
    }

    public static function toString(array $classes)
    {
        return implode(' ', array_unique(array_filter(array_map('trim', $classes))));
    }
}
