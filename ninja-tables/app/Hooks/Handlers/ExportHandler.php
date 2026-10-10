<?php

namespace NinjaTables\App\Hooks\Handlers;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Services\Export\TableExporter;
use NinjaTables\Framework\Support\Arr;
use NinjaTables\Framework\Support\Sanitizer;

class ExportHandler
{
    public function register()
    {
        add_action('wp_ajax_ninja-tables-default-export', [$this, 'defaultExport']);
        add_action('wp_ajax_ninja-tables-drag-and-drop-export', [$this, 'dragAndDropExport']);
    }

    public function dragAndDropExport()
    {
        if (!current_user_can(ninja_table_admin_role())) {
            return;
        }

        $request = ninjaTablesRequest();

        $nonce = Arr::get($request, '_wpnonce', '');
        if (!wp_verify_nonce($nonce, 'ninja_table_admin_nonce')) {
            wp_die(esc_html(__('Security check failed.', 'ninja-tables')), 403);
        }

        $tableId = intval(Arr::get($request, 'table_id'));

        if (!$tableId) {
            return;
        }

        $format     = Sanitizer::sanitizeTextField(Arr::get($request, 'format'));
        $tableTitle = get_the_title($tableId);
        $fileName   = Sanitizer::sanitizeTitle($tableTitle);
        $tableData  = get_post_meta($tableId, '_ninja_table_builder_table_data', true);

        if ($format === 'csv') {
            TableExporter::exportCSV($tableData, $fileName);
        } elseif ($format === 'json') {
            TableExporter::exportJSON($tableId, $fileName);
        }
    }

    public function defaultExport($externalSource = false)
    {
        if (!current_user_can(ninja_table_admin_role())) {
            return;
        }

        $request = ninjaTablesRequest();

        if (!$externalSource) {
            $nonce = Arr::get($request, '_wpnonce', '');
            if (!wp_verify_nonce($nonce, 'ninja_table_admin_nonce')) {
                wp_die(esc_html(__('Security check failed.', 'ninja-tables')), 403);
            }
        }

        $tableId = intval(Arr::get($request, 'table_id'));
        $format  = Sanitizer::sanitizeTextField(Arr::get($request, 'format'));

        return TableExporter::export($tableId, $format, $externalSource);
    }
}
