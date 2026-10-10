<?php

namespace NinjaTables\App\Services\Render;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Prints `window[name] = {...}` on a hook. Used to hand per-table config to the frontend bundles.
 */
class InlineScript
{
    public static function window($name, $data, $hook = 'wp_footer', $priority = 10)
    {
        add_action($hook, function () use ($name, $data) {
            ?>
            <script type="text/javascript">
                window['<?php echo esc_js($name); ?>'] = <?php echo wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            </script>
            <?php
        }, $priority);
    }
}
