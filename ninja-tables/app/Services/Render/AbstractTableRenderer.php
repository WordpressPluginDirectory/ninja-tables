<?php

namespace NinjaTables\App\Services\Render;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Helper\TableCssHelper;
use NinjaTables\Framework\Support\Arr;

/**
 * Steps shared by the FooTable and DataTables frontend renderers. Each engine keeps its own
 * render() so the exact order of side effects (asset enqueues, actions) is preserved.
 */
abstract class AbstractTableRenderer
{
    /**
     * Tables whose custom CSS has already been printed on this request.
     *
     * @var array<int,bool>
     */
    protected static $cssPrinted = [];

    abstract public function render(array $tableArray);

    /**
     * Common per-render preparation: unique id, provider, colour-type normalisation and the
     * ninja_rendering_table_{provider} action.
     */
    protected function prepare(array $tableArray, $uniqueIdPrefix)
    {
        $tableId = Arr::get($tableArray, 'table_id');

        // Keep an already-assigned uniqueID (FooTableRenderer sets it before exposing the
        // table array as the $ninja_table_current_rendering_table global). Regenerating here
        // would desync that global from the rendered table's class, breaking Pro's custom-filter
        // init which resolves the table via FooTable.get('.' + uniqueID).
        if (empty($tableArray['uniqueID'])) {
            $tableArray['uniqueID'] = $uniqueIdPrefix . wp_rand() . '_' . $tableId;
        }
        $tableArray['provider'] = ninja_table_get_data_provider($tableId);

        $settings = Arr::get($tableArray, 'settings', []);
        TableCssHelper::normalizeColorType($settings);
        $tableArray['settings'] = $settings;

        do_action('ninja_rendering_table_' . $tableArray['provider'], $tableArray);

        return $tableArray;
    }

    /**
     * Print the table's custom CSS once per request.
     *
     * @param callable $generate fn(): string|null
     */
    protected function cssOnce($tableId, $styleId, callable $generate, $hook = 'wp_footer')
    {
        if (isset(static::$cssPrinted[$tableId])) {
            return;
        }
        static::$cssPrinted[$tableId] = true;

        CssGenerator::inject($generate(), $styleId, $hook);
    }

    public static function markCssPrinted($tableId)
    {
        static::$cssPrinted[$tableId] = true;
    }

    public static function isCssPrinted($tableId)
    {
        return isset(static::$cssPrinted[$tableId]);
    }

    protected function includeView($file, array $data)
    {
        extract($data); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
        include NINJA_TABLES_DIR_PATH . 'app/Views/' . $file . '.php';
    }

    protected function loadView($file, array $data)
    {
        ob_start();
        $this->includeView($file, $data);

        return ob_get_clean();
    }
}
