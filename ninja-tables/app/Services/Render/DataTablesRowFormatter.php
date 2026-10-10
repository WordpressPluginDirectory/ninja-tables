<?php

namespace NinjaTables\App\Services\Render;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Helper\Helper;
use NinjaTables\Framework\Support\Arr;

/**
 * Row/column shaping shared by the DataTables page renderer and its AJAX endpoints:
 * shortcode rendering, output escaping, column type map and the signed shortcode-locked filter.
 */
class DataTablesRowFormatter
{
    public static function renderShortcodesInRowValues(array $rowData, array $columnTypeMap = []): array
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

    public static function escapeRowValues(array $rowData, array $columnTypeMap, bool $shortcodesRendered = false): array
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
                $value = Helper::sanitizeLinkValue($value);
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

    public static function buildColumnTypeMap(array $columns): array
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

    /**
     * Translate the static sf_* shortcode attributes into DynamicRow filter
     * entries. Returns [] when the table/shortcode has no static filter (the
     * free build, which never registers sf_*, always lands here).
     */
    public static function buildLockedFilters($tableArray)
    {
        $shortCodeData = Arr::get($tableArray, 'shortCodeData', []);
        $sfColumn      = trim((string) Arr::get($shortCodeData, 'sf_column', ''));
        $sfFilter      = Arr::get($shortCodeData, 'sf_filter', '');
        $sfMatch       = Arr::get($shortCodeData, 'sf_match', 'equal');

        if ($sfColumn === '' || $sfFilter === '' || $sfFilter === false) {
            return [];
        }

        // Dynamic placeholders (current user, date, …). No-op without Pro.
        $sfFilter = apply_filters('ninja_parse_placeholder', $sfFilter);

        $operatorMap = [
            'equal'      => 'exact',
            'contains'   => 'like',
            'startswith' => 'starts_with',
            'lt'         => 'lt',
            'gt'         => 'gt',
        ];

        return [[
            'column'   => $sfColumn,
            'operator' => Arr::get($operatorMap, $sfMatch, 'exact'),
            'value'    => (string) $sfFilter,
        ]];
    }

    /**
     * Read the signed locked-filter payload off the AJAX request and return it
     * only when its signature matches. Fails closed (returns []) on any mismatch
     * so a tampered payload can never widen the visible dataset.
     */
    public static function getVerifiedLockedFilters($request)
    {
        $raw = Arr::get($request, 'ninja_locked_filters', '');
        $sig = Arr::get($request, 'ninja_locked_filters_sig', '');

        // Guard against array/non-string input (e.g. ninja_locked_filters[]=x),
        // which would otherwise trip a TypeError inside wp_hash() on PHP 8.
        if (!is_string($raw) || !is_string($sig) || $raw === '' || $sig === '') {
            return [];
        }

        $raw = wp_unslash($raw);
        if (!hash_equals(wp_hash($raw), $sig)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
