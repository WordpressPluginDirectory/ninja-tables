<?php

namespace NinjaTables\App\Services\Render;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Helper\TableCssHelper;
use NinjaTables\Framework\Support\Arr;

/**
 * Per-table custom CSS (colors, fonts, per-column/cell styles, user CSS) for both render engines,
 * plus the single place that prints it into the page.
 */
class CssGenerator
{
    /**
     * FooTable engine. Returns the CSS string or null when the table has nothing custom.
     *
     * @param array  $tableArray
     * @param string $extra_css
     * @return string|null
     */
    public static function footable($tableArray, $extra_css = '')
    {
        $tableId    = intval($tableArray['table_id']);
        $css_prefix = '#footable_' . $tableId;
        $columns    = Arr::get($tableArray, 'columns', []);
        $settings   = $tableArray['settings'];

        // Shared CSS utilities
        $cellStyles      = TableCssHelper::getCellStyles($tableId, 'footable');
        $customColumnCss = TableCssHelper::buildPerColumnColorCss($css_prefix, $columns);
        $fonts           = TableCssHelper::extractFontSettings($settings);
        $colors          = TableCssHelper::buildColorPalette($settings);

        // The custom body border colour must follow the "Bordered table" toggle: FooTable/Bootstrap
        // gives every cell a default border, so a lingering custom border-color would otherwise stay
        // visible after the toggle is switched off. (DataTables has no default cell borders, so it
        // self-corrects and needs no equivalent gate.)
        $borderedClasses  = ['celled', 'table-bordered'];
        $hasBorderedClass = (bool) array_intersect($borderedClasses, (array) Arr::get($settings, 'css_classes', []));

        $hasStackable = false;
        if (Arr::get($settings, 'stackable') == 'yes') {
            $hasStackable = true;
            $stackPrefix  = '#footable_' . $tableId . ' .footable-details';
        }

        $custom_css = str_replace('NT_ID', $tableId, get_post_meta($tableId, '_ninja_tables_custom_css', true));
        $custom_css .= $extra_css . $customColumnCss;

        if ( ! $fonts['table_font_size'] && ! $colors && ! $custom_css && ! $cellStyles) {
            return null;
        }
        ob_start();
        include NINJA_TABLES_DIR_PATH . 'app/Views/public/ninja-footable-css.php';

        return ob_get_clean();
    }

    /**
     * DataTables engine. Returns the CSS string or null when the table has nothing custom.
     *
     * @param array $tableArray
     * @param array $columns  Raw column definitions
     * @param array $settings Normalised settings (see TableCssHelper::normalizeColorType)
     * @return string|null
     */
    public static function datatables($tableArray, $columns, $settings)
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
            return null;
        }

        $css_prefix        = $cssPrefix;
        $css_parent_prefix = $cssParentPrefix;
        ob_start();
        include NINJA_TABLES_DIR_PATH . 'app/Views/public/datatables/ninja-datatable-css.php';
        $css = ob_get_clean();

        return $css ?: null;
    }

    /**
     * Print a <style> block for the table on the given hook.
     */
    public static function inject($css, $styleId, $hook = 'wp_footer', $priority = 10)
    {
        if (!$css) {
            return;
        }

        add_action($hook, function () use ($css, $styleId) {
            ?>
            <style type="text/css" id="<?php echo esc_attr($styleId); ?>">
                <?php echo ninjaTablesEscCss($css); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </style>
            <?php
        }, $priority);
    }
}
