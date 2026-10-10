<?php

namespace NinjaTables\App\Hooks\Handlers;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\Framework\Support\Arr;

/**
 * Backward-compatibility shims for older Ninja Tables Pro builds.
 */
class CompatHandler
{
    public function register()
    {
        // Pro < 5.0.0 still references the pre-framework ArrayHelper class.
        if (defined('NINJAPROPLUGIN_VERSION') && version_compare(NINJAPROPLUGIN_VERSION, '5.0.0', '<')) {
            add_action('init', [$this, 'aliasLegacyArrayHelper']);
        }
    }

    public function aliasLegacyArrayHelper()
    {
        if (!class_exists('NinjaTables\\Classes\\ArrayHelper', false)) {
            class_alias(Arr::class, 'NinjaTables\\Classes\\ArrayHelper');
        }
    }
}
