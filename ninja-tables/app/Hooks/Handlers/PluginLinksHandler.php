<?php

namespace NinjaTables\App\Hooks\Handlers;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Helper\Pro;

class PluginLinksHandler
{
    public function register()
    {
        add_filter('plugin_action_links_' . NINJA_TABLES_BASENAME, [$this, 'actionLinks']);
    }

    public function actionLinks($links)
    {
        if (!Pro::active()) {
            $links[] = '<a style="color: green; font-weight: bold;" target="_blank" href="https://wpmanageninja.com/downloads/ninja-tables-pro-add-on/">' . esc_html__('Go Pro', 'ninja-tables') . '</a>';
        }

        $links[] = '<a href="' . esc_url(admin_url('admin.php?page=ninja_tables#/')) . '">' . esc_html__('All Tables', 'ninja-tables') . '</a>';

        return $links;
    }
}
