<?php

namespace NinjaTables\App\Services\Render;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Helper\Pro;
use NinjaTables\Framework\Support\Arr;

/**
 * Settings → frontend config fragments common to both engines.
 */
class RenderConfig
{
    /**
     * @param bool $defaultWhenEmpty true: an empty custom string falls back to the default (DataTables behaviour)
     */
    public static function i18n(array $settings, $defaultWhenEmpty = false)
    {
        $pick = function ($key, $default) use ($settings, $defaultWhenEmpty) {
            $value = Arr::get($settings, $key);
            $set   = $defaultWhenEmpty ? (bool) $value : array_key_exists($key, $settings);

            return $set ? sanitize_text_field($value) : $default;
        };

        return [
            'search_in'        => $pick('search_in_text', __('Search in', 'ninja-tables')),
            'search'           => $pick('search_placeholder', __('Search', 'ninja-tables')),
            'no_result_text'   => $pick('no_result_text', __('No Result Found', 'ninja-tables')),
            'row_details'      => __('Row Details', 'ninja-tables'),
            'close'            => __('Close', 'ninja-tables'),
            'row_details_hint' => __('Press Enter to view the details of this row in a dialog.', 'ninja-tables'),
        ];
    }

    public static function defaultSorting(array $settings)
    {
        if (Arr::get($settings, 'sorting_type', 'by_created_at') == 'manual_sort') {
            return 'manual_sort';
        }

        return array_key_exists('default_sorting', $settings) ? $settings['default_sorting'] : false;
    }

    /**
     * false when every row is shown, otherwise the page size.
     */
    public static function paging(array $settings)
    {
        return Arr::get($settings, 'show_all') ? false : Arr::get($settings, 'perPage', 20);
    }

    /**
     * Pro-only frontend flags, applied to the config in place.
     */
    public static function applyProConfig(array &$config, array $settings)
    {
        if (!Pro::active()) {
            return;
        }

        if (Arr::get($settings, 'hide_on_empty')) {
            $config['hide_on_empty'] = true;
        }
        if (Arr::get($settings, 'paginate_to_top')) {
            $config['paginate_to_top'] = true;
        }
        $config['disable_sticky_on_mobile'] = Arr::get($settings, 'disable_sticky_on_mobile');
    }

    public static function stackConfig(array $settings)
    {
        if (Arr::get($settings, 'stackable', 'no') != 'yes' || !count(Arr::get($settings, 'stacks_devices', []))) {
            return null;
        }

        return [
            'stackable'      => true,
            'stacks_devices' => Arr::get($settings, 'stacks_devices', []),
        ];
    }
}
