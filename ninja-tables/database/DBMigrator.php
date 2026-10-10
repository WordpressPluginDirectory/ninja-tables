<?php

namespace NinjaTables\Database;

use NinjaTables\Database\Migrations\NinjaTableItemsMigrator;

class DBMigrator
{
    /**
     * Run migrations; on a network-wide activation run them for every site.
     */
    public static function run($networkWide = false)
    {
        if ($networkWide && function_exists('get_sites')) {
            foreach (get_sites(['fields' => 'ids', 'network_id' => get_current_network_id()]) as $siteId) {
                switch_to_blog($siteId);
                static::migrate();
                restore_current_blog();
            }

            return;
        }

        static::migrate();
    }

    public static function migrate()
    {
        NinjaTableItemsMigrator::migrate();
    }

    /**
     * wp_initialize_site: migrate a site created after the plugin was network-activated.
     *
     * @param \WP_Site|int $site
     */
    public static function handleNewSite($site)
    {
        $siteId = $site instanceof \WP_Site ? (int) $site->blog_id : (int) $site;

        if (!$siteId || !is_plugin_active_for_network(NINJA_TABLES_BASENAME)) {
            return;
        }

        switch_to_blog($siteId);
        static::migrate();
        restore_current_blog();
    }
}
