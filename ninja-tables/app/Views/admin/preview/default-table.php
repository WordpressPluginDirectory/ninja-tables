<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<?php $ninja_tables_embed = !empty($embed); // rendered inside the admin Table Design tab ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
        "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" <?php language_attributes(); ?>>
<head>
    <meta http-equiv="Content-type" content="text/html; charset=utf-8"/>
    <meta http-equiv="Imagetoolbar" content="No"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>
        <?php esc_attr_e('Preview Table', 'ninja-tables') ?>
    </title>
    <?php
    wp_head();
    ?>
    <style type="text/css">
        <?php if ($ninja_tables_embed): ?>
        html, body { margin: 0; padding: 0; background: transparent; }
        .nt_embed_body { padding: 4px; }
        /* The sticky-header panes are 70vh by default; inside the auto-sized iframe that would
           feed back into its own height. Pin them so the pane (and its scrollbar) stay stable. */
        :root { --nt-sticky-max-height: 560px; --nt-dt-sticky-max-height: 560px; }
        <?php endif; ?>
    </style>
</head>

<body<?php echo $ninja_tables_embed ? ' class="nt_embed_body"' : ''; ?>>

<?php if ($ninja_tables_embed): ?>
    <div id="nt_embed_root">
    <?php
    echo do_shortcode(
        '[ninja_tables id="' . esc_attr($table_id) . '"]'
    ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped  ?>
    </div>
<?php else: ?>
<div class="nt_preview_wrapper">
    <div class="nt_preview">
        <div class="nt_preview_header">
            <div class="nt_preview_header_title">
                [ninja_tables id="<?php
                echo esc_attr($table_id); ?>"]
            </div>
            <div class="nt_preview_header_action">
                <a href="<?php
                echo esc_url_raw(admin_url('admin.php?page=ninja_tables#/tables/' . $table_id)) ?>"> <?php
                    esc_attr_e('Edit', 'ninja-tables') ?> </a>
            </div>
        </div>

        <div class="nt_preview_body">
            <div class="nt_preview_body_wrapper">
                <?php
                // The shortcode HTML is already escaped line by line at table_inner_html.php  ?>
                <?php
                echo do_shortcode(
                    '[ninja_tables id="' . esc_attr($table_id) . '"]'
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped  ?>
            </div>

        </div>

        <div class="nt_preview_footer">
            <p class="nt_preview_footer_text">You are seeing preview version of Ninja Tables. This table is only
                accessible for Admin users. Other users
                may not access this page. To use this in a page please use the following shortcode: [ninja_tables
                id='<?php echo esc_attr($table_id) ?>']</p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php wp_footer(); ?>

<?php if ($ninja_tables_embed): ?>
<?php
// Echoed back so the Table Design tab can tell this render's messages from a previous page
// still sitting in the same iframe.
$ninja_tables_render_token = isset($_POST['nt_render']) ? intval($_POST['nt_render']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
?>
<script>
    // Tell the Table Design tab how tall the rendered table is so the iframe can fit it.
    (function () {
        if (window.parent === window) return;
        var origin = window.location.origin;
        var root = document.getElementById('nt_embed_root');
        var render = <?php echo (int) $ninja_tables_render_token; ?>;
        // Measure the table wrapper, not the document: the document is never shorter than the
        // iframe viewport, so its height would only ever ratchet up.
        var post = function () {
            var height = Math.ceil(root.getBoundingClientRect().height) + 8; // body padding
            window.parent.postMessage({type: 'nt:height', height: height, render: render}, origin);
        };
        if ('ResizeObserver' in window) {
            new ResizeObserver(post).observe(root);
        }
        window.addEventListener('load', post);
        post();
    })();
</script>
<?php endif; ?>

</body>
</html>
