<?php
if (!defined('ABSPATH')) {
    return;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
/**
 * DataTables public view template.
 *
 * Variables available from PublicRenderer::run():
 * $tableId, $table, $columns, $settings, $renderType, $instanceName,
 * $formatted_columns, $table_vars, $tableArray, $tableHasColor, $table_classes
 */

$tableCaption = isset($table_vars['caption']) ? $table_vars['caption'] : '';
$tableTitle   = isset($table_vars['title']) ? $table_vars['title'] : '';
$tableDesc    = isset($table_vars['description']) ? $table_vars['description'] : '';
$uniqueID     = isset($table_vars['uniqueID']) ? $table_vars['uniqueID'] : '';
?>
<div id="ninja_dt_parent_<?php echo intval($tableId); ?>"
     class="ninja-tables-dt-wrapper ninja_table_wrapper loading_ninja_table <?php echo esc_attr($tableHasColor); ?> <?php echo esc_attr($table_classes); ?>">

    <?php if (isset($settings['show_title']) && $settings['show_title']) : ?>
        <?php do_action('ninja_tables_before_table_title', $table); ?>
        <h3 class="table_title ninja_dt_title"><?php echo esc_html($tableTitle); ?></h3>
        <?php do_action('ninja_tables_after_table_title', $table); ?>
    <?php endif; ?>

    <?php if (isset($settings['show_description']) && $settings['show_description']) : ?>
        <?php do_action('ninja_tables_before_table_description', $table); ?>
        <div class="table_description ninja_dt_description"><?php echo do_shortcode(wp_kses_post($tableDesc)); ?></div>
        <?php do_action('ninja_tables_after_table_description', $table); ?>
    <?php endif; ?>

    <?php do_action('ninja_tables_before_table_print', $table, $table_vars); ?>

    <table data-ninja_table_instance="<?php echo esc_attr($instanceName); ?>"
           id="ninja_dt_<?php echo intval($tableId); ?>"
           data-unique_identifier="<?php echo esc_attr($uniqueID); ?>"
           class="ninja-dt-table ninja_datatable dt_table_<?php echo intval($tableId); ?> <?php echo esc_attr($uniqueID); ?>"
           style="width:100%"
           <?php if ($tableTitle && !$tableCaption) : ?>aria-label="<?php echo esc_attr($tableTitle); ?>"<?php endif; ?>>
        <?php if ($tableCaption) : ?>
            <caption><?php echo esc_html($tableCaption); ?></caption>
        <?php endif; ?>
        <thead>
            <tr>
                <?php foreach ($formatted_columns as $index => $col) : ?>
                    <?php if ($col['visible']) : ?>
                        <th class="<?php echo esc_attr(implode(' ', $col['classes'])); ?>"
                            <?php if (isset($col['width'])) : ?>
                                style="width: <?php echo esc_attr($col['width'] . (isset($col['widthUnit']) ? $col['widthUnit'] : 'px')); ?>"
                            <?php endif; ?>>
                            <?php echo wp_kses_post($col['title']); ?>
                        </th>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        </tbody>
    </table>

    <?php do_action('ninja_tables_after_table_print', $table, $table_vars); ?>

    <?php if (is_user_logged_in() && ninja_table_admin_role()) : ?>
        <a class="nt_edit_link" href="<?php echo esc_url(admin_url('admin.php?page=ninja_tables#/tables/' . $table->ID)); ?>">
            <?php esc_attr_e('Edit Table', 'ninja-tables'); ?>
        </a>
    <?php endif; ?>

</div>
