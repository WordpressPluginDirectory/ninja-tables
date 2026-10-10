<?php

namespace NinjaTables\App\Helper;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The single seam between free and Ninja Tables Pro. Grep for Pro:: to find every Pro-gated branch.
 */
class Pro
{
    public static function active()
    {
        return defined('NINJATABLESPRO');
    }

    public static function sortable()
    {
        return defined('NINJATABLESPRO_SORTABLE');
    }

    public static function version()
    {
        return defined('NINJAPROPLUGIN_VERSION') ? NINJAPROPLUGIN_VERSION : null;
    }

    public static function url()
    {
        return defined('NINJAPROPLUGIN_URL') ? NINJAPROPLUGIN_URL : null;
    }
}
