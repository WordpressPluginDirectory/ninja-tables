<?php

namespace NinjaTables\App\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table data/HTML cache invalidation and external data cache.
 */
class TableCache
{
    public static function clearTableDataCache($tableId)
    {
        update_post_meta($tableId, '_ninja_table_cache_object', false);
        update_post_meta($tableId, '_ninja_table_cache_html', false);
        update_post_meta($tableId, '_external_cached_data', false);
        update_post_meta($tableId, '_last_external_cached_time', false);
        update_post_meta($tableId, '__ninja_cached_table_html', false);

        // Bump the DataTables filter-options cache version so the renderer computes
        // a fresh transient key after any mutation. The old nt_dt_fopts_* transients
        // become unreachable and expire on their own TTL — no key to hunt down. If
        // this meta is ever unreadable the key simply never stabilises, which is a
        // cache miss (correct data, just uncached), never stale options.
        update_post_meta(
            $tableId,
            '_nt_dt_fopts_version',
            (int) get_post_meta($tableId, '_nt_dt_fopts_version', true) + 1
        );
    }

    /**
     * Determine if the table's data has been migrated for manual sorting.
     *
     * @param int $tableId
     *
     * @return bool
     */
    public static function dataMigratedForManualSort($tableId)
    {
        // The post meta table would have a flag that the data of
        // the table is migrated to use for the manual sorting.
        $postMetaKey = '_ninja_tables_data_migrated_for_manual_sort';

        return (bool) get_post_meta($tableId, $postMetaKey, true);
    }

    /**
     * Determine if the user wants to disable the caching for the table.
     *
     * @param int $tableId
     *
     * @return bool
     */
    public static function shouldNotCache($tableId)
    {
        $tableSettings = ninja_table_get_table_settings($tableId, 'public');

        return (
            isset($tableSettings['shouldNotCache']) && $tableSettings['shouldNotCache'] == 'yes'
        ) ? true : false;
    }

    public static function getExternalCachedData($tableId)
    {
        $tableSettings = get_post_meta($tableId, '_ninja_table_settings', true);
        if ( ! isset($tableSettings['caching_interval']) && $tableSettings['caching_interval']) {
            return false;
        }
        $intervalMinutes = intval($tableSettings['caching_interval']);
        if ( ! $intervalMinutes) {
            return false;
        }
        $interval       = $intervalMinutes * 60;
        $lastCachedTime = intval(get_post_meta($tableId, '_last_external_cached_time', true));

        if ((time() - $lastCachedTime) < $interval) {
            return get_post_meta($tableId, '_external_cached_data', true);
        }

        return false;
    }

    public static function setExternalCacheData($tableId, $data)
    {
        $tableSettings = get_post_meta($tableId, '_ninja_table_settings', true);
        if ( ! isset($tableSettings['caching_interval']) && $tableSettings['caching_interval']) {
            return false;
        }

        update_post_meta($tableId, '_last_external_cached_time', time());
        update_post_meta($tableId, '_external_cached_data', $data);
    }

    public static function clearAllCache($posts = array())
    {
        $tables = [];

        if ( ! empty($posts)) {
            $tables = $posts;
        } else {
            $tables = \NinjaTables\App\Models\Post::select('ID')
                                                  ->where('post_type', 'ninja-table')
                                                  ->get();
        }

        foreach ($tables as $table) {
            ninjaTablesClearTableDataCache($table->ID);
        }

        return true;
    }

    public static function externalClearPageCaches()
    {
        // clear wp lightspeed caches
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge', 'ninja_tables_light_speed_clear_cache'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        }

        // clear wp redis caches
        if (defined('NGINX_HELPER_BASEURL')) {
            do_action('rt_nginx_helper_purge_all'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        }

        // clear wp rocket caches
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // clear godaddy internal caches
        if (class_exists('\WPaaS\Cache')) {
            if (has_action('shutdown', ['\WPaaS\Cache', 'ban'])) {
                return;
            }

            remove_action('shutdown', ['\WPaaS\Cache', 'purge'], PHP_INT_MAX);
            add_action('shutdown', ['\WPaaS\Cache', 'ban'], PHP_INT_MAX);
        }

        // clear wp-fastest caches
        if (isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) {
            $GLOBALS['wp_fastest_cache']->deleteCache();
        }

        // clear wp cache caches
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }

        // clear autooptimizepress caches
        if (class_exists('autoptimizeCache')) {
            \autoptimizeCache::clearall();
        }

        // clear wp-optimize caches
        if (class_exists('WPO_Page_Cache')) {
            (new \WPO_Page_Cache())->purge();
        }

        // clear SiteGround Optimizer caches
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
        }

        // clear cloudflare caches — prefer the current CloudflareAPO namespace,
        // fall back to the legacy CF namespace for older Cloudflare plugin versions
        if (defined('CLOUDFLARE_PLUGIN_DIR')) {
            if (class_exists('CloudflareAPO\WordPress\Hooks')) {
                (new \CloudflareAPO\WordPress\Hooks())->purgeCacheEverything();
            } elseif (class_exists('CF\WordPress\Hooks')) {
                (new \CF\WordPress\Hooks())->purgeCacheEverything();
            }
        }
    }
}
