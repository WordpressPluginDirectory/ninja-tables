<?php

namespace NinjaTables\App\Services\Import;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Support\Csv;
use NinjaTables\App\Modules\DataTables\Database\DynamicTableManager;
use NinjaTables\App\Services\Table\Engine;
use NinjaTables\App\Services\Table\RowWriter;
use NinjaTables\Framework\Support\Arr;
use NinjaTables\Framework\Support\Sanitizer;

/**
 * Whole-file imports (CSV, JSON, Ninja Tables JSON) and CSV append into an existing table.
 * Errors are thrown as ImportException carrying the response the controller should send.
 */
class TableImporter
{
    const CPT = 'ninja-table';

    const CSV_MIMES = [
        'text/csv', 'text/plain', 'application/csv', 'text/comma-separated-values', 'application/excel',
        'application/vnd.ms-excel', 'application/vnd.msexcel', 'text/anytext', 'application/octet-stream', 'application/txt',
    ];

    /**
     * @return array{message: string, tableId: int}
     */
    public function fromCsv($doUnicode, $renderEngine = 'footable')
    {
        $fileType = Sanitizer::sanitizeTextField(Arr::get($_FILES, 'file.type')); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!in_array($fileType, self::CSV_MIMES)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => ['errors' => [], 'message' => __('Please upload valid CSV', 'ninja-tables')]], 423);
        }

        $fileName = Sanitizer::sanitizeTextField(Arr::get($_FILES, 'file.name')); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $rows     = $this->readUploadedCsv($doUnicode === 'yes');

        if (empty($rows)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => ['message' => __('The uploaded CSV appears to be empty.', 'ninja-tables')]], 423);
        }

        $header = array_shift($rows);
        if (empty($header) || !is_array($header)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => ['message' => __('Unable to read CSV header row.', 'ninja-tables')]], 423);
        }

        $header  = ninja_table_format_header($header);
        $tableId = $this->createTable(['post_title' => $fileName, 'post_content' => '', 'post_type' => self::CPT, 'post_status' => 'publish']);

        $this->storeTableConfigWhenImporting($tableId, $header, $renderEngine);
        $this->writeIndexedRows($tableId, $renderEngine, array_keys($header), $rows);

        return $this->created($tableId);
    }

    public function fromJson($renderEngine = 'footable')
    {
        $tableId = $this->createTable();
        $content = json_decode(file_get_contents($this->uploadedFilePath()), true);

        $reversed = array_reverse($content);
        $header   = array_keys(array_pop($reversed));
        $header   = array_combine($header, $header);

        $this->storeTableConfigWhenImporting($tableId, $header, $renderEngine);
        $this->writeIndexedRows($tableId, $renderEngine, array_keys($header), $content);

        return $this->created($tableId);
    }

    public function fromNinjaJson($renderEngine = 'footable')
    {
        $raw     = file_get_contents($this->uploadedFilePath());
        $content = json_decode($raw, true);

        if (json_last_error()) {
            // Strip control characters and a BOM, then retry.
            for ($i = 0; $i <= 31; ++$i) {
                $raw = str_replace(chr($i), '', $raw);
            }
            $raw = str_replace(chr(127), '', $raw);
            if (0 === strpos(bin2hex($raw), 'efbbbf')) {
                $raw = substr($raw, 3);
            }
            $content = json_decode($raw, true);
        }

        if (!Arr::get($content, 'post') || !Arr::get($content, 'columns') || !Arr::get($content, 'settings')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['message' => __('Please upload a JSON file exported from Ninja Tables', 'ninja-tables')], 423, true);
        }

        $isDataTables = $renderEngine === 'datatables';

        if ($isDataTables && ($provider = Arr::get($content, 'data_provider', 'default')) !== 'default') {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['message' => sprintf(
                // translators: %s is the data provider name
                __('This table uses the "%s" data provider which is not supported by the DataTables engine. Only tables with the "default" provider can be imported as DataTables.', 'ninja-tables'),
                $provider
            )], 422, true);
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $tableId = $this->createTable([
            'post_title'   => Sanitizer::ksesPost($content['post']['post_title']),
            'post_content' => wp_kses_post($content['post']['post_content']),
            'post_type'    => self::CPT,
            'post_status'  => 'publish',
        ]);

        update_post_meta($tableId, '_ninja_table_columns', ninja_tables_sanitize_array($content['columns']));

        foreach ($content['metas'] as $metaKey => $metaValue) {
            if ($metaKey === '_ninja_table_columns') {
                continue; // stored sanitised above
            }
            update_post_meta($tableId, $metaKey, $metaValue);
        }

        $settings            = $content['settings'];
        $settings['library'] = $renderEngine;
        $settings            = ninja_tables_normalize_dt_css_classes($settings);
        update_post_meta($tableId, '_ninja_table_settings', $settings);

        $header = [];
        foreach ($content['columns'] as $column) {
            $header[$column['key']] = $column['name'];
        }

        $writer = new RowWriter($tableId, $isDataTables ? Engine::DATATABLES : Engine::FOOTABLE);

        if (!empty($content['rows'])) {
            $writer->insert($this->normalizeRows(array_keys($header), $content['rows']));
        } elseif (!empty($content['original_rows'])) {
            if ($isDataTables) {
                $values = array_column(array_filter($content['original_rows'], fn($r) => !empty($r['value']) && is_array($r['value'])), 'value');
                $writer->insert($values);
            } else {
                $this->restoreOriginalRows($tableId, $content['original_rows']);
            }
        }

        return $this->created($tableId);
    }

    /**
     * Append (or replace) rows of an existing table from an uploaded CSV whose header matches the table columns.
     */
    public function appendCsv(array $input)
    {
        $tableId = intval(Arr::get($input, 'table_id'));

        if (!$tableId || get_post_type($tableId) !== self::CPT) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => ['message' => __('Invalid table.', 'ninja-tables')]], 423);
        }

        $rows      = $this->readUploadedCsv(Arr::get($input, 'do_unicode') === 'yes');
        $csvHeader = array_map('esc_attr', array_shift($rows));

        $config = get_post_meta($tableId, '_ninja_table_columns', true);
        if (!$config) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['message' => __('Please set table configuration first', 'ninja-tables')], 423, true);
        }

        $header = array_map(fn($item) => $item['key'], $config);

        if (count($header) != count($csvHeader)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => ['message' => __('Please use the provided CSV header structure font face.', 'ninja-tables')]], 423);
        }

        $replace = Arr::get($input, 'replace') === 'true';
        $writer  = new RowWriter($tableId);

        if ($writer->engine() === Engine::DATATABLES) {
            $this->assertNoReservedKeys($header);
        }

        // Only rows with exactly the expected column count are imported (legacy behaviour).
        $rows    = array_values(array_filter($rows, fn($row) => is_array($row) && count($row) === count($header)));
        $rows    = array_map(fn($row) => array_combine($header, $row), $rows);
        $options = ['filter' => 'ninja_tables_import_table_data'];

        $inserted = $replace ? $writer->replace($rows, $options) : $writer->insert($rows, $options);

        ninjaTablesClearTableDataCache($tableId);

        if ($writer->engine() === Engine::DATATABLES) {
            return ['data' => ['message' => sprintf(
                /* translators: %d is the number of rows imported */
                __('Successfully imported %d rows.', 'ninja-tables'),
                $inserted
            )]];
        }

        return ['data' => ['message' => __('Successfully uploaded data.', 'ninja-tables')]];
    }

    public function createTable($data = null)
    {
        return wp_insert_post($data ?: [
            'post_title'   => __('Temporary table name', 'ninja-tables'),
            'post_content' => __('Temporary table description', 'ninja-tables'),
            'post_type'    => self::CPT,
            'post_status'  => 'publish',
        ]);
    }

    /**
     * Store columns + settings for a freshly imported table.
     *
     * @param array $header key => name
     */
    public function storeTableConfigWhenImporting($tableId, $header, $renderEngine = 'footable', $renderType = 'legacy_table')
    {
        $columns = [];
        foreach ($header as $key => $name) {
            // keep in sync with BaseAdapter::initTableConfiguration(); a missing
            // data_type leaves JS consumers with an undefined column type
            $columns[] = ['key' => $key, 'name' => $name, 'breakpoints' => '', 'data_type' => 'text'];
        }

        if ($renderEngine === 'datatables') {
            $this->assertNoReservedKeys($columns);
        }

        update_post_meta($tableId, '_ninja_table_columns', $columns);

        $settings                = ninja_table_get_table_settings($tableId, 'admin');
        $settings['library']     = $renderEngine;
        $settings['render_type'] = $renderType;
        $settings                = ninja_tables_normalize_dt_css_classes($settings);
        update_post_meta($tableId, '_ninja_table_settings', $settings);

        ninjaTablesClearTableDataCache($tableId);
    }

    /**
     * Write CSV-style rows (indexed or already associative) into a table.
     */
    public function writeIndexedRows($tableId, $renderEngine, array $headerKeys, array $rows)
    {
        $writer = new RowWriter($tableId, $renderEngine === 'datatables' ? Engine::DATATABLES : Engine::FOOTABLE);

        return $writer->insert($this->normalizeRows($headerKeys, $rows));
    }

    protected function normalizeRows(array $headerKeys, array $rows)
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !$row) {
                continue;
            }
            reset($row);
            $out[] = is_int(key($row)) ? RowWriter::combine($headerKeys, $row) : $row;
        }

        return $out;
    }

    /**
     * Ninja JSON exports carry the raw item rows; restore them verbatim (ids, timestamps, positions).
     */
    protected function restoreOriginalRows($tableId, array $originalRows)
    {
        global $wpdb;
        $tableName = $wpdb->prefix . ninja_tables_db_table_name();

        foreach ($originalRows as $row) {
            $row['table_id'] = $tableId;
            $row['value']    = wp_json_encode($row['value'], JSON_UNESCAPED_UNICODE);
            $wpdb->insert($tableName, $row, false); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        }
    }

    protected function assertNoReservedKeys($columnsOrKeys)
    {
        $reserved = DynamicTableManager::getReservedKeys($columnsOrKeys);
        if ($reserved) {
            wp_send_json_error([
                'message' => sprintf(
                    // translators: %s is the reserved column name(s) that conflict
                    __('CSV header "%s" conflicts with a reserved system column. Please rename it.', 'ninja-tables'),
                    implode('", "', $reserved)
                ),
            ], 400);
        }
    }

    protected function uploadedFilePath()
    {
        $tmpName = Arr::get($_FILES, 'file.tmp_name'); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if (!$tmpName || !is_uploaded_file($tmpName)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => ['message' => __('Invalid file upload.', 'ninja-tables')]], 423);
        }

        return $tmpName;
    }

    /**
     * @return array[] rows including the header row
     */
    protected function readUploadedCsv($convertFromLatin1)
    {
        $tmpName = $this->uploadedFilePath();

        try {
            if ($convertFromLatin1) {
                $data = mb_convert_encoding(file_get_contents($tmpName), 'UTF-8', 'ISO-8859-1');

                return Csv::readerFromString($data)->fetchAll();
            }

            return Csv::reader($tmpName)->fetchAll();
        } catch (\Throwable $exception) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => [
                'errors'  => __('Unable to parse the uploaded CSV file.', 'ninja-tables'),
                'message' => __('CSV parsing failed. Please verify delimiter/encoding and try again.', 'ninja-tables'),
            ]], 423);
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    protected function created($tableId)
    {
        return ['message' => __('Successfully added a table.', 'ninja-tables'), 'tableId' => $tableId];
    }
}
