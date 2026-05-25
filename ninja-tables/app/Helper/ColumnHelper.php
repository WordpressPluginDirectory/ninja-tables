<?php

namespace NinjaTables\App\Helper;

use NinjaTables\Framework\Support\Arr;

class ColumnHelper
{
    /**
     * Resolve the internal column type from a raw column definition.
     *
     * @param array $column Raw column config from post meta.
     * @return string One of: 'text', 'numeric', 'date', 'html', 'image'.
     */
    public static function getColumnType(array $column): string
    {
        $type          = isset($column['data_type']) ? $column['data_type'] : 'text';
        $acceptedTypes = ['text', 'number', 'date', 'html', 'image'];

        if (in_array($type, $acceptedTypes)) {
            if ($type == 'number') {
                return 'numeric';
            }
            return $type;
        }

        return 'text';
    }

    /**
     * Build the base formatted column array shared by all renderers.
     *
     * Returns a structured array so side-effects (enqueue moment.js, load lightbox)
     * can be handled by the caller rather than this pure utility.
     *
     * @param array  $column        Raw column definition from post meta.
     * @param int    $index         Column index (0-based).
     * @param array  $settings      Table settings array.
     * @param bool   $globalSorting Whether column sorting is globally enabled.
     * @param string $sortingType   One of 'by_created_at', 'by_column', 'manual_sort'.
     *
     * @return array [
     *   'formatted_column' => [...],
     *   'enqueue_moment'   => bool,
     *   'load_lightbox'    => bool,
     *   'iframe_lightbox'  => bool,
     * ]
     */
    public static function formatColumnBase(
        array $column,
        int $index,
        array $settings,
        bool $globalSorting,
        string $sortingType
    ): array {
        $columnType = self::getColumnType($column);

        // Build CSS class list
        $cssColumnName = 'ninja_column_' . $index;
        $columnClasses = [$cssColumnName, 'ninja_clmn_nm_' . $column['key']];

        if (isset($column['classes'])) {
            $userClasses   = explode(' ', $column['classes']);
            $columnClasses = array_unique(array_merge($columnClasses, $userClasses));
        }

        // Column title (supports HTML header content)
        $columnTitle = $column['name'];
        if (Arr::get($column, 'enable_html_content') == 'true') {
            if ($columnContent = Arr::get($column, 'header_html_content')) {
                $columnTitle = wp_kses_post($columnContent);
            }
        }

        $formatted_column = [
            'name'        => $column['key'],
            'key'         => $column['key'],
            'title'       => $columnTitle,
            'breakpoints' => isset($column['breakpoints']) ? $column['breakpoints'] : '',
            'type'        => $columnType,
            'visible'     => (!isset($column['breakpoints']) || $column['breakpoints'] !== 'hidden'),
            'classes'     => $columnClasses,
            'filterable'  => (isset($column['unfilterable']) && $column['unfilterable'] == 'yes') ? false : true,
            'sortable'    => (isset($column['unsortable']) && $column['unsortable'] == 'yes') ? false : $globalSorting,
        ];

        // Pro: value transform
        if (defined('NINJAPROPLUGIN_VERSION') && isset($column['transformed_value'])) {
            $formatted_column['transformed_value'] = $column['transformed_value'];
        }

        // Date type
        $enqueueMoment = false;
        if ($columnType == 'date') {
            $enqueueMoment = true;
            $formatted_column['formatString']   = Arr::get($column, 'dateFormat') ?: 'MM/DD/YYYY';
            $formatted_column['showTime']       = isset($column['showTime']) && $column['showTime'] === 'yes';
            $formatted_column['firstDayOfWeek'] = isset($column['firstDayOfWeek']) && $column['firstDayOfWeek']
                ? $column['firstDayOfWeek'] : 0;

            if ($formatted_column['showTime'] && isset($column['timeFormat']) && $column['timeFormat']) {
                $formatted_column['formatString'] .= ' ' . $column['timeFormat'];
            }
        }

        // Sorting by specific column
        if ($sortingType == 'by_column' && $column['key'] == Arr::get($settings, 'sorting_column')) {
            $formatted_column['sorted']    = true;
            $formatted_column['direction'] = Arr::get($settings, 'sorting_column_by');
        }

        // Numeric type
        if ($columnType == 'numeric') {
            $formatted_column['thousandSeparator'] = isset($column['thousandSeparator'])
                ? $column['thousandSeparator'] : ',';
            $formatted_column['decimalSeparator']  = isset($column['decimalSeparator'])
                ? $column['decimalSeparator'] : '.';
        }

        // Image lightbox detection
        $loadLightbox   = false;
        $iframeLightbox = false;
        if ($columnType == 'image') {
            $linkType = Arr::get($column, 'link_type');
            if ($linkType == 'image_light_box' || $linkType == 'iframe_ligtbox') {
                $loadLightbox = true;
                if ($linkType == 'iframe_ligtbox') {
                    $iframeLightbox = true;
                }
            }
        }

        return [
            'formatted_column' => $formatted_column,
            'enqueue_moment'   => $enqueueMoment,
            'load_lightbox'    => $loadLightbox,
            'iframe_lightbox'  => $iframeLightbox,
        ];
    }
}
