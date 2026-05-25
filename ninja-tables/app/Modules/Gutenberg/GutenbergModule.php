<?php

namespace NinjaTables\App\Modules\Gutenberg;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use NinjaTables\App\App;
use NinjaTables\App\Models\Post;
use NinjaTables\Framework\Support\Arr;

class GutenbergModule
{
    public function register()
    {
        add_action('enqueue_block_editor_assets', [$this, 'enqueueAssets']);
        add_action('save_post', [$this, 'updateTableConfig'], 10, 3);
    }

    public function enqueueAssets()
    {
        // Main block script
        wp_enqueue_script(
            'ninja-tables-gutenberg-table-block',
            NINJA_TABLES_DIR_URL . 'assets/blocks/table-block/block.js',
            array('wp-blocks', 'wp-i18n', 'wp-element', 'wp-components', 'wp-editor', 'wp-api-fetch', 'jquery'),
            NINJA_TABLES_VERSION,
            true
        );

        $app    = App::getInstance();
        $assets = $app['url.assets'];
        wp_localize_script(
            'ninja-tables-gutenberg-table-block',
            'ninja_table_admin',
            [
                'availableTables'          => $this->getAvailableTables(),
                'rest'                     => $this->getRestInfo($app),
                'hasPro'                   => defined('NINJATABLESPRO'),
                'preview_required_scripts' => array(
                    $assets . "css/ninjatables-public.css",
                    $assets . "css/ninja-table-builder-public.css",
                    includes_url('/js/dist/vendor/moment.min.js'),
                    $assets . "libs/footable/js/footable.min.js",
                    $assets . "js/ninja-tables-footable.js",
                ),
                'dt_preview_required_scripts' => array(
                    $assets . "libs/datatables/datatables.min.css",
                    $assets . "libs/datatables/responsive.dataTables.min.css",
                    $assets . "css/ninjatables-datatables.css",
                    $assets . "libs/datatables/datatables.min.js",
                    $assets . "libs/datatables/dataTables.responsive.min.js",
                    $assets . "js/ninja-tables-datatables.js",
                ),
            ],
        );
    }

    protected function getRestInfo($app)
    {
        $ns  = $app->config->get('app.rest_namespace');
        $ver = $app->config->get('app.rest_version');

        return [
            'base_url'  => esc_url_raw(rest_url()),
            'url'       => rest_url($ns . '/' . $ver),
            'nonce'     => wp_create_nonce('wp_rest'),
            'namespace' => $ns,
            'version'   => $ver
        ];
    }

    private function getAvailableTables()
    {
        $args = array(
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_type'      => 'ninja-table',
            'post_status'    => 'any'
        );

        $tables    = get_posts($args);
        $formatted = array();

        $title = __('Select a Table', 'ninja-tables');
        if (!$tables) {
            $title = __('No Tables found. Please add a table first', 'ninja-tables');
        }
        $formatted[] = array(
            'label' => $title,
            'value' => ''
        );

        foreach ($tables as $table) {
            $formatted[] = array(
                'label'       => esc_attr($table->post_title),
                'value'       => $table->ID,
                'data_source' => esc_attr(ninja_table_get_data_provider($table->ID))
            );
        }

        return $formatted;
    }

    public function updateTableConfig($postId, $post, $update)
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $blocks = parse_blocks($post->post_content);

        if (!is_array($blocks) || empty($blocks)) {
            return;
        }

        foreach ($blocks as $block) {
            if (isset($block['blockName']) && isset($block['attrs']) && $block['blockName'] === 'ninja-tables/guten-block') {
                $this->updateTableSettings($block['attrs']);
            }
        }
    }

    public function updateTableSettings($data)
    {
        $tableSettings = Arr::get($data, 'tableSettings', []);

        $tableId         = intval(Arr::get($data, 'tableId', 0));
        $rawColumns      = '';
        $tablePreference = [];

        if (!$tableId) {
            return;
        }

        if (get_post_type($tableId) !== 'ninja-table') {
            return;
        }

        if (!current_user_can(ninja_table_admin_role())) {
            return;
        }

        if ($tableSettings) {
            $tablePreference = ninja_tables_sanitize_array($tableSettings);
        }

        $existingTableSettings = ninja_table_get_table_settings($tableId, 'admin');
        if (!is_array($existingTableSettings)) {
            $existingTableSettings = [];
        }

        // Keep previously saved table settings for keys not present in Gutenberg block settings.
        $mergedSettings = wp_parse_args($tablePreference, $existingTableSettings);
        $mergedSettings = wp_parse_args($mergedSettings, ninjaTablesGetDefaultSettings());

        if (!empty($mergedSettings['sorting_column_by'])) {
            $direction = strtoupper($mergedSettings['sorting_column_by']);
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                $mergedSettings['sorting_column_by'] = 'DESC';
            }
        }

        if (!empty($mergedSettings['sorting_column'])) {
            $mergedSettings['sorting_column'] = sanitize_key($mergedSettings['sorting_column']);
        }

        if ($mergedSettings === $existingTableSettings) {
            return;
        }

        Post::updatedSettings($tableId, $rawColumns, $mergedSettings);
    }
}
