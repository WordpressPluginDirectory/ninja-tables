<?php defined('ABSPATH') || exit; ?>
<?php if($fonts && ($fonts['table_font_family'] || $fonts['table_font_size'])): ?>
    <?php echo esc_attr($css_prefix); ?> {
    <?php if($fonts['table_font_family']): ?>font-family: <?php echo esc_attr($fonts['table_font_family']); ?>;<?php endif; ?>
    <?php if($fonts['table_font_size']): ?>font-size: <?php echo esc_attr($fonts['table_font_size']); ?>px;<?php endif; ?>
    }
<?php endif; ?>

<?php if($colors): ?>

    /* Table body colors — whole table */
    <?php echo esc_attr($css_prefix); ?>,
    <?php echo esc_attr($css_prefix); ?> table {
    background-color: <?php echo esc_attr($colors['table_color_primary']); ?> !important;
    color: <?php echo esc_attr($colors['table_color_secondary']); ?> !important;
    border-color: <?php echo esc_attr($colors['table_color_border']); ?> !important;
    }
    <?php echo esc_attr($css_prefix); ?> tbody tr:not(.child) {
    background-color: <?php echo esc_attr($colors['table_color_primary']); ?> !important;
    color: <?php echo esc_attr($colors['table_color_secondary']); ?> !important;
    }
    <?php echo esc_attr($css_prefix); ?> tbody tr:not(.child) td {
    color: inherit !important;
    }

    /* Search / top bar area */
    <?php echo esc_attr($css_parent_prefix); ?> .ninja-dt-top {
    background-color: <?php echo esc_attr($colors['table_search_color_primary']); ?> !important;
    color: <?php echo esc_attr($colors['table_search_color_secondary']); ?> !important;
    }
    <?php echo esc_attr($css_parent_prefix); ?> .dataTables_filter input {
    color: <?php echo esc_attr($colors['table_search_color_secondary']); ?> !important;
    }
    <?php echo esc_attr($css_parent_prefix); ?> .dataTables_length select {
    color: <?php echo esc_attr($colors['table_search_color_secondary']); ?> !important;
    }
    <?php if($colors['table_search_color_border']): ?>
        <?php echo esc_attr($css_parent_prefix); ?> .ninja-dt-top {
        border: 1px solid <?php echo esc_attr($colors['table_search_color_border']); ?> !important;
        }
    <?php endif; ?>

    /* Header */
    <?php echo esc_attr($css_prefix); ?> thead tr th {
    background-color: <?php echo esc_attr($colors['table_header_color_primary']); ?> !important;
    color: <?php echo esc_attr($colors['table_color_header_secondary']); ?> !important;
    }
    <?php if($colors['table_color_header_border']): ?>
        <?php echo esc_attr($css_prefix); ?>:not(.hide_all_borders) thead tr th {
        border-color: <?php echo esc_attr($colors['table_color_header_border']); ?> !important;
        }
    <?php endif; ?>

    /* Body border */
    <?php if(isset($colors['table_color_border']) && $colors['table_color_border']): ?>
        <?php echo esc_attr($css_prefix); ?>:not(.hide_all_borders) tbody tr td {
        border-color: <?php echo esc_attr($colors['table_color_border']); ?> !important;
        }
        <?php echo esc_attr($css_prefix); ?>:not(.hide_all_borders) tbody tr:last-child td {
        border-bottom: 1px solid <?php echo esc_attr($colors['table_color_border']); ?> !important;
        }
    <?php endif; ?>

    /* Hover */
    <?php echo esc_attr($css_prefix); ?> tbody tr:not(.child):hover {
    background-color: <?php echo esc_attr($colors['table_color_primary_hover']); ?> !important;
    color: <?php echo esc_attr($colors['table_color_secondary_hover']); ?> !important;
    }

    /* Alternating rows — overrides body colors on odd/even */
    <?php if(isset($colors['alternate_color_status']) && $colors['alternate_color_status'] == 'yes'): ?>
        <?php echo esc_attr($css_prefix); ?> tbody tr:not(.child):nth-child(even) {
        background-color: <?php echo esc_attr($colors['table_alt_color_primary']); ?> !important;
        color: <?php echo esc_attr($colors['table_alt_color_secondary']); ?> !important;
        }
        <?php echo esc_attr($css_prefix); ?> tbody tr:not(.child):nth-child(odd) {
        background-color: <?php echo esc_attr($colors['table_alt_2_color_primary']); ?> !important;
        color: <?php echo esc_attr($colors['table_alt_2_color_secondary']); ?> !important;
        }
        <?php echo esc_attr($css_prefix); ?> tbody tr:not(.child):nth-child(even):hover {
        background-color: <?php echo esc_attr($colors['table_alt_color_hover']); ?> !important;
        }
        <?php echo esc_attr($css_prefix); ?> tbody tr:not(.child):nth-child(odd):hover {
        background-color: <?php echo esc_attr($colors['table_alt_2_color_hover']); ?> !important;
        }
        /* Child rows inherit parent alternating colors */
        <?php echo esc_attr($css_prefix); ?> tbody tr:not(.child):nth-child(even) + tr.child {
        background-color: <?php echo esc_attr($colors['table_alt_color_primary']); ?> !important;
        color: <?php echo esc_attr($colors['table_alt_color_secondary']); ?> !important;
        }
        <?php echo esc_attr($css_prefix); ?> tbody tr:not(.child):nth-child(odd) + tr.child {
        background-color: <?php echo esc_attr($colors['table_alt_2_color_primary']); ?> !important;
        color: <?php echo esc_attr($colors['table_alt_2_color_secondary']); ?> !important;
        }
    <?php endif; ?>

    /* Footer / bottom bar */
    <?php echo esc_attr($css_parent_prefix); ?> .ninja-dt-bottom {
    background-color: <?php echo esc_attr($colors['table_footer_bg']); ?> !important;
    }
    <?php echo esc_attr($css_parent_prefix); ?> .dataTables_paginate .paginate_button.current {
    background-color: <?php echo esc_attr($colors['table_footer_active']); ?> !important;
    }
    <?php if($colors['table_footer_border']): ?>
        <?php echo esc_attr($css_parent_prefix); ?> .ninja-dt-bottom {
        border-color: <?php echo esc_attr($colors['table_footer_border']); ?> !important;
        }
        <?php echo esc_attr($css_parent_prefix); ?> .dataTables_paginate .paginate_button {
        border-color: <?php echo esc_attr($colors['table_footer_border']); ?> !important;
        }
    <?php endif; ?>

    /* Responsive detail row — stackable-style label */
    <?php echo esc_attr($css_prefix); ?> > tbody > tr.child .dtr-title {
    background-color: <?php echo esc_attr($colors['table_header_color_primary']); ?> !important;
    color: <?php echo esc_attr($colors['table_color_header_secondary']); ?> !important;
    }
    <?php echo esc_attr($css_prefix); ?> > tbody > tr.child ul.dtr-details > li {
    border-color: <?php echo esc_attr($colors['table_color_border'] ?: 'rgba(0,0,0,0.05)'); ?> !important;
    }

<?php endif; ?>

<?php if($cellStyles): ?>
    <?php foreach ($cellStyles as $ninja_tables_cellStyle): ?>
        <?php
        $ninja_tables_raw_settings = isset($ninja_tables_cellStyle->__settings) ? $ninja_tables_cellStyle->__settings : (isset($ninja_tables_cellStyle->settings) ? $ninja_tables_cellStyle->settings : '');
        $ninja_tables_cell = is_serialized($ninja_tables_raw_settings)
            ? unserialize($ninja_tables_raw_settings, ['allowed_classes' => false])
            : $ninja_tables_raw_settings;
        if (!is_array($ninja_tables_cell)) {
            $ninja_tables_cell = [];
        }
        $ninja_tables_row_id = isset($ninja_tables_cellStyle->__id) ? $ninja_tables_cellStyle->__id : (isset($ninja_tables_cellStyle->id) ? $ninja_tables_cellStyle->id : 0);
        $ninja_tables_cellPrefix = $css_prefix . ' tbody tr.nt_row_id_' . $ninja_tables_row_id;
        ?>
        <?php echo esc_attr($ninja_tables_cellPrefix); ?> {
        <?php if(isset($ninja_tables_cell['row_bg'])): ?>background: <?php echo esc_attr($ninja_tables_cell['row_bg'] . '!important;'); endif; ?>
        <?php if(isset($ninja_tables_cell['text_color'])): ?>color: <?php echo esc_attr($ninja_tables_cell['text_color'] . '!important;'); endif; ?>}
        <?php if($ninja_tables_cell && isset($ninja_tables_cell['cell']) && is_array($ninja_tables_cell['cell'])): foreach ($ninja_tables_cell['cell'] as $ninja_tables_cell_key => $ninja_tables_values): ?>
            <?php $ninja_tables_specCellPrefix = $ninja_tables_cellPrefix . ' .ninja_clmn_nm_' . $ninja_tables_cell_key; ?>
            <?php echo esc_attr($ninja_tables_specCellPrefix); ?> {
            <?php foreach ($ninja_tables_values as $ninja_tables_value_key => $ninja_tables_value): ?>
                <?php if($ninja_tables_value): echo esc_attr($ninja_tables_value_key); ?> : <?php echo esc_attr($ninja_tables_value . ' !important;'); endif; ?>
            <?php endforeach; ?>
            }
            <?php echo esc_attr($ninja_tables_specCellPrefix); ?> > * { color: inherit }
        <?php endforeach; endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php if($hasStackable): ?>
    <?php echo esc_attr($css_parent_prefix); ?>.ninja-dt-stacked <?php echo esc_attr($css_prefix); ?>,
    <?php echo esc_attr($css_parent_prefix); ?>.ninja-dt-stacked <?php echo esc_attr($css_prefix); ?> > tbody {
    background: transparent !important;
    }
    <?php if($colors): ?>
        /* Stacked card body colors */
        <?php echo esc_attr($css_parent_prefix); ?>.ninja-dt-stacked <?php echo esc_attr($css_prefix); ?> tbody tr {
        background-color: <?php echo esc_attr($colors['table_color_primary']); ?> !important;
        color: <?php echo esc_attr($colors['table_color_secondary']); ?> !important;
        }
        /* Stacked label — matches original column header style */
        <?php echo esc_attr($css_parent_prefix); ?>.ninja-dt-stacked <?php echo esc_attr($css_prefix); ?> tbody tr td:before {
        background-color: <?php echo esc_attr($colors['table_header_color_primary']); ?> !important;
        color: <?php echo esc_attr($colors['table_color_header_secondary']); ?> !important;
        }
        <?php if(isset($colors['alternate_color_status']) && $colors['alternate_color_status'] == 'yes'): ?>
            /* Stacked alternating card colors */
            <?php echo esc_attr($css_parent_prefix); ?>.ninja-dt-stacked <?php echo esc_attr($css_prefix); ?> tbody tr:nth-child(even) {
            background-color: <?php echo esc_attr($colors['table_alt_color_primary']); ?> !important;
            color: <?php echo esc_attr($colors['table_alt_color_secondary']); ?> !important;
            }
            <?php echo esc_attr($css_parent_prefix); ?>.ninja-dt-stacked <?php echo esc_attr($css_prefix); ?> tbody tr:nth-child(odd) {
            background-color: <?php echo esc_attr($colors['table_alt_2_color_primary']); ?> !important;
            color: <?php echo esc_attr($colors['table_alt_2_color_secondary']); ?> !important;
            }
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php echo ninjaTablesEscCss($custom_css); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
