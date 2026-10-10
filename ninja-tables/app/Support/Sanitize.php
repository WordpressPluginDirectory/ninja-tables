<?php

namespace NinjaTables\App\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanitising, escaping and allowed-HTML rules.
 */
class Sanitize
{
    public static function isValidUrl($url)
    {
        return preg_match(NINJA_TABLES_URL_FORMAT, $url);
    }

    public static function allowedHtmlTags()
    {
        $tags = wp_kses_allowed_html('post');

        // form fields - input
        $tags['input'] = [
            'class' => [],
            'id'    => [],
            'name'  => [],
            'value' => [],
            'type'  => [],
            'src'   => []
        ];
        // select
        $tags['select'] = [
            'class' => [],
            'id'    => [],
            'name'  => [],
            'value' => [],
            'type'  => [],
        ];
        // select options
        $tags['option'] = [
            'selected' => [],
        ];
        // style
        $tags['style'] = [
            'types' => [],
        ];
        // iframe
        $tags['iframe'] = [
            'width'           => [],
            'height'          => [],
            'src'             => [],
            // 'srcdoc' removed — allows arbitrary HTML/JS execution
            'title'           => [],
            'frameborder'     => [],
            'allow'           => [],
            'class'           => [],
            'id'              => [],
            'allowfullscreen' => [],
            'style'           => [],
        ];
        // form — action removed to prevent form hijacking
        $tags['form'] = [
            'target' => [],
            'method' => [],
        ];
        //button — onclick removed to prevent stored XSS

        //svg
        if (empty($tags['svg'])) {
            $svg_args = array(
                'svg'   => array(
                    'class'           => true,
                    'aria-hidden'     => true,
                    'aria-labelledby' => true,
                    'role'            => true,
                    'xmlns'           => true,
                    'width'           => true,
                    'height'          => true,
                    'viewbox'         => true, // <= Must be lower case!
                ),
                'g'     => array('fill' => true),
                'title' => array('title' => true),
                'path'  => array(
                    'd'    => true,
                    'fill' => true,
                )
            );
            $tags     = array_merge($tags, $svg_args);
        }

        return apply_filters('ninja_tables/allowed_html_tags', $tags);
    }

    public static function allowedCssProperties()
    {
        add_filter('safe_style_css', function ($styles) {
            $style_tags = ['display', 'opacity', 'visibility'];
            $style_tags = apply_filters('ninja_tables/allowed_css_properties', $style_tags);

            foreach ($style_tags as $tag) {
                $styles[] = $tag;
            }

            return $styles;
        });
    }

    public static function sanitizeArray(array $array)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = ninja_tables_sanitize_array($value);
            } else {
                $array[$key] = wp_kses((string) $value, ninja_tables_allowed_html_tags());
            }
        }

        return $array;
    }

    public static function sanitizeTableContentArray(array $array, $tableId)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = ninja_tables_sanitize_array($value);
            } else {
                $array[$key] = sanitize_post_field('post_content', $value, $tableId, 'db');
            }
        }

        return $array;
    }

    public static function printSafeVar($content, $esc_func = false)
    {
        if ($esc_func) {
            echo call_user_func($esc_func, $content); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }
        // PHPCS - This content var is hardcoded variable or already escaped the contents by esc_* functions.
        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function escCss($css)
    {
        if (preg_match('#</?\w+#', $css)) {
            return '';
        }

        return $css;
    }

    /**
     * Sanitizes CSV value
     *
     * @param string $content
     *
     * @return string $content
     */
    public static function sanitizeForCSV($content)
    {
        $formulas = ['=', '-', '+', '@', "\t", "\r"];

        if (ninjaTablesStartsWith($content, $formulas)) {
            $content = "'" . $content;
        }

        return $content;
    }

    /**
     * @param string $data
     *
     * @return mixed $data
     */
    public static function escapeScript($data)
    {
        return preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $data);
    }

    public static function canUnfilteredHTML()
    {
        return current_user_can('unfiltered_html') || apply_filters('ninja_tables_disable_fields_sanitize', false);
    }

    /**
     * Validate nonce.
     */
    public static function validateNonce($key = 'ninja_table_admin_nonce')
    {
        $nonce = sanitize_text_field(\NinjaTables\Framework\Support\Arr::get($_REQUEST, $key)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! wp_verify_nonce($nonce, $key)) {
            $errors = apply_filters('ninja_tables_nonce_error', [
                '_ninjatablesnonce' => [
                    __('Nonce verification failed, please try again.', 'ninja-tables')
                ]
            ]);

            wp_send_json(['errors' => $errors], 422);
        }
    }
}
