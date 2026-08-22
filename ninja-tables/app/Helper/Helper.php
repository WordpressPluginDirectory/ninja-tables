<?php

namespace NinjaTables\App\Helper;

use NinjaTables\Framework\Support\Arr;

class Helper
{
    public static function isProviderActiveAndMatches($tableArray, $provider)
    {
        $tableProvider = Arr::get($tableArray, 'provider');

        if ($tableProvider !== $provider) {
            return false;
        }

        switch ($provider) {
            case 'wp_woo':
            case 'wp_woo_reviews':
                return defined('WC_PLUGIN_FILE') && WC_PLUGIN_FILE;

            case 'wp_fct':
                return defined('FLUENTCART_VERSION') && FLUENTCART_VERSION;

            case 'fluent-form':
                return defined('FLUENTFORM_VERSION') && FLUENTFORM_VERSION;

            default:
                return false;
        }
    }

    public static function isValidUrl( $url )
    {
        if (!filter_var( $url, FILTER_VALIDATE_URL )) {
            return false;
        }

        $parsed = wp_parse_url($url);
        if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            return false;
        }

        return true;
    }

    // esc_url() prepends http:// to scheme-less values, breaking slug/relative links
    // (e.g. Google Sheets columns storing slugs). Only escape values with a scheme.
    public static function sanitizeLinkValue($value)
    {
        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return $value;
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $trimmed) || strpos($trimmed, '//') === 0) {
            return esc_url($trimmed);
        }

        return wp_kses_bad_protocol($trimmed, wp_allowed_protocols());
    }
}
