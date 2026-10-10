<?php

namespace NinjaTables\App\Services;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\App;

/**
 * Enqueues plugin assets from either the Vite dev server or the built assets/ folder.
 *
 * Mode comes from config/app.php 'env' ("dev" = vite dev server, anything else = built files);
 * `yarn dev` / `yarn build` flip that value (resources/vite/vite.js).
 *
 * Production files are classic IIFE bundles at the same paths Laravel Mix produced, so callers
 * keep passing the assets/-relative path. In dev the same path is mapped to its source entry
 * and served as an ES module (plus Vite's HMR client).
 */
class Vite
{
    const DEV_URL = 'http://localhost:8881/';

    /** Built asset path (relative to assets/) => source path (relative to resources/). */
    protected static $entries = [
        'js/ninja-tables-boot.js'                     => 'admin/Boot.js',
        'js/ninja-tables-admin.js'                    => 'admin/main.js',
        'js/ninja-tables-footable.js'                 => 'public/js/ninja-tables-footable.js',
        'js/ninja-tables-datatables.js'               => 'public/datatables/ninja-tables-datatables.js',
        'js/ninja-table-tinymce-button.js'            => 'admin/ninja-table-tinymce-button.js',
        'js/ninja-table-builder-public.js'            => 'public/js/ninja-tables-builder.js',
        'js/fluent-cart/fct_table_frontend.js'        => 'public/js/fluent-cart/fct_table_frontend.js',
        'js/fluent-cart/ninja_table_fct_comparison.js' => 'public/js/fluent-cart/ninja_table_fct_comparison.js',
        'blocks/table-block/block.js'                 => 'admin/blocks/table-block/block.js',
        'css/ninjatables-public.css'                  => 'public/css/_public.scss',
        'css/ninjatables-datatables.css'              => 'public/css/datatables/_datatables_public.scss',
        'css/ninjatables-datatables-responsive.css'   => 'public/css/datatables/_datatables_responsive.scss',
        'css/ninja-table-builder-public.css'          => 'public/css/_table_builder.scss',
        'css/ninja-tables-admin.css'                  => 'admin/css/ninja-tables-admin.scss',
        'css/ninja-tables-vendor.css'                 => 'admin/css/vendor.scss',
        'css/ninja-tables-preview.css'                => 'preview/preview.scss',
    ];

    protected static $moduleHandles = [];
    protected static $filterAdded = false;

    public static function isDev()
    {
        return App::getInstance()->config->get('app.env') === 'dev';
    }

    public static function assetsUrl($path = '')
    {
        return NINJA_TABLES_DIR_URL . 'assets/' . ltrim($path, '/');
    }

    public static function enqueueScript($handle, $asset, $deps = [], $inFooter = true)
    {
        if (static::isDev() && ($source = static::devSource($asset))) {
            static::enqueueModule($handle, $source, $deps, $inFooter);
            return;
        }

        wp_enqueue_script($handle, static::assetsUrl($asset), $deps, NINJA_TABLES_VERSION, $inFooter);
    }

    public static function enqueueStyle($handle, $asset, $deps = [], $media = 'all')
    {
        // RTL twins are generated at build time only; dev serves the LTR source.
        $devAsset = preg_replace('/-rtl\.css$/', '.css', $asset);

        if (static::isDev() && ($source = static::devSource($devAsset))) {
            // Vite serves .scss as a JS module that injects the styles, so it goes in as a script.
            // Suffix the handle: style and script handles share names here (e.g. ninja-tables_admin_app).
            static::enqueueModule($handle . '-vite-css', $source, [], false);
            return;
        }

        wp_enqueue_style($handle, static::assetsUrl($asset), $deps, NINJA_TABLES_VERSION, $media);
    }

    protected static function devSource($asset)
    {
        $asset = ltrim($asset, '/');

        return isset(static::$entries[$asset]) ? 'resources/' . static::$entries[$asset] : null;
    }

    protected static function enqueueModule($handle, $source, $deps, $inFooter)
    {
        if (!in_array('vite-client', static::$moduleHandles)) {
            static::$moduleHandles[] = 'vite-client';
            wp_enqueue_script('vite-client', static::DEV_URL . '@vite/client', [], null, false); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- dev server only; Vite handles cache busting
        }

        $deps[] = 'vite-client';
        static::$moduleHandles[] = $handle;
        wp_enqueue_script($handle, static::DEV_URL . $source, $deps, null, $inFooter); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- dev server only; Vite handles cache busting

        if (!static::$filterAdded) {
            static::$filterAdded = true;
            add_filter('script_loader_tag', [static::class, 'moduleTag'], 10, 2);
        }
    }

    /**
     * Dev only: the dev server serves ES modules, so the tag needs type="module".
     * Note modules are deferred, so wp_add_inline_script(..., 'after') snippets run before
     * the module body in dev. Production IIFE bundles are unaffected.
     */
    public static function moduleTag($tag, $handle)
    {
        // $tag also carries wp_add_inline_script before/after snippets, so target the tag with src= only.
        if (in_array($handle, static::$moduleHandles)) {
            $tag = preg_replace('/<script\b(?![^>]*\btype=)([^>]*\bsrc=)/', '<script type="module"$1', $tag, 1);
        }

        return $tag;
    }
}
