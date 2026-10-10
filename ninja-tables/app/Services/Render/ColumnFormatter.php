<?php

namespace NinjaTables\App\Services\Render;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Helper\ColumnHelper;
use NinjaTables\Framework\Support\Arr;

/**
 * Turns raw column definitions into the frontend column config, applying the side effects
 * every engine needs (moment.js for dates, lightbox flags) and the ninja_table_column_attributes filter.
 */
class ColumnFormatter
{
    /**
     * @param array         $columns    Raw column definitions
     * @param array         $settings   Table settings; load_lightbox / iframe_lightbox are set on it
     * @param array         $tableArray Full render payload (for the filter)
     * @param callable|null $extend     fn(array $formatted, array $column, int $index): array — engine-specific keys
     * @return array
     */
    public static function format(array $columns, array &$settings, array $tableArray, ?callable $extend = null)
    {
        $globalSorting = (bool) Arr::get($settings, 'column_sorting', false);
        $sortingType   = Arr::get($settings, 'sorting_type', 'by_created_at');
        $tableId       = Arr::get($tableArray, 'table_id');
        $formatted     = [];

        foreach ($columns as $index => $column) {
            $base   = ColumnHelper::formatColumnBase($column, $index, $settings, $globalSorting, $sortingType);
            $column = (array) $column;
            $fc     = $base['formatted_column'];

            if ($base['enqueue_moment']) {
                wp_enqueue_script('moment');
            }
            if ($base['load_lightbox']) {
                $settings['load_lightbox'] = true;
                if ($base['iframe_lightbox']) {
                    $settings['iframe_lightbox'] = true;
                }
            }

            if ($extend) {
                $fc = $extend($fc, $column, $index);
            }

            $formatted[] = apply_filters('ninja_table_column_attributes', $fc, $column, $tableId, $tableArray);
        }

        return $formatted;
    }
}
