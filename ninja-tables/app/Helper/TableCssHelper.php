<?php

namespace NinjaTables\App\Helper;

use NinjaTables\App\Models\NinjaTableItem;
use NinjaTables\App\Modules\DataTables\Database\DynamicTableManager;
use NinjaTables\App\Modules\DataTables\Models\DynamicRow;
use NinjaTables\Framework\Support\Arr;

class TableCssHelper
{
    public static function normalizeColorType(array &$settings): void
    {
        if (!Arr::get($settings, 'table_color_type')) {
            if (Arr::get($settings, 'table_color') == 'ninja_table_custom_color') {
                $settings['table_color_type'] = 'custom_color';
            } else {
                $settings['table_color_type'] = 'pre_defined_color';
            }
        }
    }

    public static function buildColorPalette(array $settings)
    {
        if (Arr::get($settings, 'table_color_type') != 'custom_color' || !defined('NINJATABLESPRO')) {
            return false;
        }

        return [
            'table_color_primary'          => Arr::get($settings, 'table_color_primary'),
            'table_color_secondary'        => Arr::get($settings, 'table_color_secondary'),
            'table_color_border'           => Arr::get($settings, 'table_color_border'),

            'table_color_primary_hover'    => Arr::get($settings, 'table_color_primary_hover'),
            'table_color_secondary_hover'  => Arr::get($settings, 'table_color_secondary_hover'),
            'table_color_border_hover'     => Arr::get($settings, 'table_color_border_hover'),

            'table_search_color_primary'   => Arr::get($settings, 'table_search_color_primary'),
            'table_search_color_secondary' => Arr::get($settings, 'table_search_color_secondary'),
            'table_search_color_border'    => Arr::get($settings, 'table_search_color_border'),

            'table_header_color_primary'   => Arr::get($settings, 'table_header_color_primary'),
            'table_color_header_secondary' => Arr::get($settings, 'table_color_header_secondary'),
            'table_color_header_border'    => Arr::get($settings, 'table_color_header_border'),

            'alternate_color_status'       => Arr::get($settings, 'alternate_color_status'),

            'table_alt_color_primary'      => Arr::get($settings, 'table_alt_color_primary'),
            'table_alt_color_secondary'    => Arr::get($settings, 'table_alt_color_secondary'),
            'table_alt_color_hover'        => Arr::get($settings, 'table_alt_color_hover'),

            'table_alt_2_color_primary'    => Arr::get($settings, 'table_alt_2_color_primary'),
            'table_alt_2_color_secondary'  => Arr::get($settings, 'table_alt_2_color_secondary'),
            'table_alt_2_color_hover'      => Arr::get($settings, 'table_alt_2_color_hover'),

            'table_footer_bg'              => Arr::get($settings, 'table_footer_bg'),
            'table_footer_active'          => Arr::get($settings, 'table_footer_active'),
            'table_footer_border'          => Arr::get($settings, 'table_footer_border'),
        ];
    }

    public static function buildColumnAlignmentCss(string $cssPrefix, array $columns): string
    {
        $css            = '';
        $allowedAligns  = ['left', 'center', 'right', 'justify'];

        foreach ($columns as $index => $column) {
            $contentAlign = Arr::get($column, 'contentAlign');
            if ($contentAlign && in_array($contentAlign, $allowedAligns, true)) {
                $css .= $cssPrefix . ' td.ninja_column_' . intval($index)
                    . ' { text-align: ' . $contentAlign . '; }';
            }

            $textAlign = Arr::get($column, 'textAlign');
            if ($textAlign && in_array($textAlign, $allowedAligns, true)) {
                $css .= $cssPrefix . ' th.ninja_column_' . intval($index)
                    . ' { text-align: ' . $textAlign . '; }';
            }
        }

        return $css;
    }

    public static function buildPerColumnColorCss(string $cssPrefix, array $columns): string
    {
        $css = '';

        if (!defined('NINJATABLESPRO')) {
            return $css;
        }

        foreach ($columns as $index => $column) {
            $bgColor   = self::sanitizeCssColor(Arr::get($column, 'background_color'));
            $textColor = self::sanitizeCssColor(Arr::get($column, 'text_color'));

            if ($bgColor || $textColor) {
                $idx      = intval($index);
                $selector = $cssPrefix . ' thead tr th.ninja_column_' . $idx . ','
                    . $cssPrefix . ' tbody tr td.ninja_column_' . $idx . ','
                    . $cssPrefix . ' tbody tr.child li.ninja_column_' . $idx;

                if ($bgColor && $textColor) {
                    $css .= $selector . '{ background-color: ' . $bgColor . '; color: ' . $textColor . '; }';
                } elseif ($bgColor) {
                    $css .= $selector . '{ background-color: ' . $bgColor . '; }';
                } elseif ($textColor) {
                    $css .= $selector . '{ color: ' . $textColor . '; }';
                }
            }
        }

        return $css;
    }

    /**
     * Validate a CSS color value against safe patterns.
     *
     * @param string|null $color
     * @return string Empty string if invalid.
     */
    private static function sanitizeCssColor($color)
    {
        if (!$color || !is_string($color)) {
            return '';
        }

        $color = trim($color);

        // Allow hex colors (#fff, #ffffff, #ffffffff)
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            return $color;
        }

        // Allow rgb/rgba
        if (preg_match('/^rgba?\(\s*[\d,.\s%]+\)$/', $color)) {
            return $color;
        }

        // Allow named CSS colors (alphabetic only)
        if (preg_match('/^[a-zA-Z]+$/', $color)) {
            return $color;
        }

        return '';
    }

    public static function extractFontSettings(array $settings): array
    {
        return [
            'table_font_family' => Arr::get($settings, 'table_font_family'),
            'table_font_size'   => Arr::get($settings, 'table_font_size'),
        ];
    }

    public static function getCellStyles(int $tableId, string $library)
    {
        $provider = ninja_table_get_data_provider($tableId);

        if ($provider !== 'default') {
            return [];
        }

        if ($library === 'datatables') {
            $dynamicRow = new DynamicRow($tableId);
            if (!$dynamicRow->tableExists()) {
                return [];
            }
            return $dynamicRow->newQuery()
                ->select([DynamicTableManager::COL_ID, DynamicTableManager::COL_SETTINGS])
                ->whereNotNull(DynamicTableManager::COL_SETTINGS)
                ->where(DynamicTableManager::COL_SETTINGS, '!=', '')
                ->get()
                ->toArray();
        }

        return NinjaTableItem::select(['id', 'settings'])
            ->where('table_id', $tableId)
            ->whereNotNull('settings')
            ->get();
    }
}
