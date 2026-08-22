<?php

namespace NinjaTables\App\Hooks\Handlers;

use NinjaTables\App\Library\Csv\Writer;
use NinjaTables\App\Models\NinjaTableItem;
use NinjaTables\App\Modules\DataTables\Models\DynamicRow;
use NinjaTables\App\Modules\DataTables\Database\DynamicTableManager;
use NinjaTables\Framework\Support\Arr;
use NinjaTables\Framework\Support\Sanitizer;

class ExportHandler
{
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

        $format = Sanitizer::sanitizeTextField(Arr::get($request, 'format'));
        $tableTitle = get_the_title($tableId);
        $fileName   = Sanitizer::sanitizeTitle($tableTitle);
        $tableData  = get_post_meta($tableId, '_ninja_table_builder_table_data', true);

        if ($format === 'csv') {
            static::exportCSV($tableData, $fileName);
        } elseif ($format === 'json') {
            static::exportJSON($tableId, $fileName);
        }
    }

    public static function exportCSV($tableData, $fileName = null)
    {
        $rows = [];
        foreach ($tableData['data'] as $row) {
            $cols = [];
            foreach ($row['rows'] as $columns) {
                $values = '';
                foreach ($columns['columns'] as $key => $item) {
                    if (is_array($item['data']['value'])) {
                        $tmp = [];
                        foreach ($item['data']['value'] as $value) {
                            $tmp[] = ninjaTablesSanitizeForCSV($value);
                        }

                        $values .= implode(",", $tmp);
                    } else {
                        $values .= " " . ninjaTablesSanitizeForCSV($item['data']['value']);
                    }
                }
                $cols[] = $values;
            }
            $rows[] = $cols;
        }

        static::exportAsCSV($rows, $fileName);
    }

    public static function exportJSON($tableId, $fileName = null)
    {
        $table_settings   = get_post_meta($tableId, '_ninja_table_builder_table_settings', true);
        $table_responsive = get_post_meta($tableId, '_ninja_table_builder_table_responsive', true);
        $table_data       = get_post_meta($tableId, '_ninja_table_builder_table_data', true);
        $table_html       = get_post_meta($tableId, '_ninja_table_builder_table_html', true);
        $data             = [
            'table_id'         => $tableId,
            'table_name'       => $fileName,
            'table_settings'   => $table_settings,
            'table_responsive' => $table_responsive,
            'table_data'       => $table_data,
            'table_html'       => $table_html
        ];

        static::exportAsJSON($data, $fileName);
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

        $tableTitle = get_the_title($tableId);

        $fileName = sanitize_title($tableTitle, 'Export-Table-' . gmdate('Y-m-d-H-i-s'), 'preview');

        $tableColumns = ninja_table_get_table_columns($tableId, 'admin');

        $tableSettings = ninja_table_get_table_settings($tableId, 'admin');

        // Check if this is a DataTables table
        $dataProvider = ninja_table_get_data_provider($tableId);
        $isDataTables = $dataProvider === 'default'
                        && isset($tableSettings['library'])
                        && $tableSettings['library'] === 'datatables';

        // Only stream tables large enough that building the whole dataset in
        // memory would risk OOM. Smaller tables keep the exact provider path
        // below, so their row order and the provider hook chain are unchanged.
        $streamThreshold = (int) apply_filters('ninja_tables_export_stream_threshold', 20000, $tableId);
        $shouldStream    = !$externalSource
                           && $dataProvider === 'default'
                           && static::exportRowCount($tableId, $isDataTables) >= $streamThreshold;

        if ($shouldStream) {
            if ($format == 'csv') {
                static::streamCsv($tableId, $tableColumns, $tableSettings, $isDataTables, $fileName);
                return;
            } elseif ($format == 'json') {
                static::streamJson($tableId, $tableColumns, $tableSettings, $dataProvider, $isDataTables, $fileName);
                return;
            }
        }

        if ($format == 'csv') {
            if ($isDataTables) {
                $data = static::getDataTablesCsvData($tableId);
            } else {
                $sortingType = Arr::get($tableSettings, 'sorting_type', 'by_created_at');
                $data = ninjaTablesGetTablesDataByID($tableId, $tableColumns, $sortingType, true);
            }

            $header = array();

            foreach ($tableColumns as $item) {
                $header[$item['key']] = $item['name'];
            }

            $exportData = array();

            foreach ($data as $item) {
                $temp = array();
                foreach ($header as $accessor => $name) {
                    $value = Arr::get($item, $accessor);
                    if (is_array($value)) {
                        $value = implode(', ', $value);
                    }
                    $temp[] = ninjaTablesSanitizeForCSV($value);
                }
                array_push($exportData, $temp);
            }

            static::exportAsCSV($exportData, $fileName, array_values($header));
        } elseif ($format == 'json') {
            $table = get_post($tableId);
            $rows  = array();

            if ($isDataTables) {
                $rows = static::getDataTablesRows($tableId, $tableColumns);
            } elseif ($dataProvider == 'default') {
                $rawRows = NinjaTableItem::selectedRows($tableId);

                foreach ($rawRows as $row) {
                    $row->value = json_decode($row->value, true);
                    $rows[]     = $row;
                }
            }

            $matas   = get_post_meta($tableId);
            $allMeta = array();

            $excludedMetaKeys = array(
                '_ninja_table_cache_object',
                '_ninja_table_cache_html',
                '_external_cached_data',
                '_last_external_cached_time',
                '_last_edited_by',
                '_last_edited_time',
                '__ninja_cached_table_html'
            );

            foreach ($matas as $metaKey => $metaValue) {
                if (!in_array($metaKey, $excludedMetaKeys)) {
                    if (isset($metaValue[0])) {
                        $metaValue         = maybe_unserialize($metaValue[0]);
                        $allMeta[$metaKey] = $metaValue;
                    }
                }
            }

            $exportData = array(
                'post'          => $table,
                'columns'       => $tableColumns,
                'settings'      => $tableSettings,
                'data_provider' => $dataProvider,
                'metas'         => $allMeta,
                'rows'          => array(),
                'original_rows' => $rows
            );

            if ($externalSource) {
                return $exportData;
            }
            static::exportAsJSON($exportData, $fileName);
        }
    }

    /**
     * Row count for the export streaming decision. Cheap per-table on the
     * DataTables dynamic table; a filtered count on the shared items table.
     *
     * @param int  $tableId
     * @param bool $isDataTables
     * @return int
     */
    private static function exportRowCount($tableId, $isDataTables)
    {
        if ($isDataTables) {
            $tableManager = new DynamicTableManager($tableId);
            if (!$tableManager->tableExists()) {
                return 0;
            }

            return (int) (new DynamicRow($tableId))->count();
        }

        return (int) (new NinjaTableItem)->where('table_id', $tableId)->count();
    }

    /**
     * Prepare the request to stream a download: drop any output buffers, lift
     * the time limit and send no-cache headers. Callers must send their own
     * Content-Type/Content-Disposition before the first echo.
     */
    private static function prepareStream()
    {
        while (ob_get_level()) {
            ob_end_clean();
        }

        ignore_user_abort(true);

        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- streamed export must not time out
        }

        nocache_headers();
        header('X-Content-Type-Options: nosniff', true);
    }

    /**
     * Stream a CSV download, paging the rows in chunks so memory stays bounded
     * regardless of table size.
     *
     * @param int    $tableId
     * @param array  $tableColumns
     * @param array  $tableSettings
     * @param bool   $isDataTables
     * @param string $fileName
     */
    private static function streamCsv($tableId, $tableColumns, $tableSettings, $isDataTables, $fileName)
    {
        header('Content-Type: text/csv; charset=utf-8', true);
        header('Content-Disposition: attachment; filename="' . $fileName . '.csv"', true);
        static::prepareStream();

        $buffer = fopen('php://memory', 'r+'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory stream for fputcsv encoding, not a filesystem file

        // Header row from column names (kept unsanitized, matching the previous
        // exporter which passed array_values($header) raw).
        $header = array();
        foreach ($tableColumns as $item) {
            $header[$item['key']] = $item['name'];
        }
        static::emitCsvLine($buffer, array_values($header));

        $flushEvery = ninjaTablePerChunk($tableId);
        $counter    = 0;

        foreach (static::rowIterator($tableId, $tableColumns, $tableSettings, $isDataTables, false) as $assocRow) {
            $cells = array();
            foreach ($header as $accessor => $name) {
                $value = Arr::get($assocRow, $accessor);
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $cells[] = ninjaTablesSanitizeForCSV($value);
            }
            static::emitCsvLine($buffer, $cells);

            if (++$counter % $flushEvery === 0) {
                if (function_exists('ob_flush')) {
                    @ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }
                flush();
                if (connection_aborted()) {
                    break;
                }
            }
        }

        fclose($buffer); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the in-memory buffer above
        wp_die();
    }

    /**
     * Encode one CSV row and echo it, preserving the previous exporter's
     * \r\n line ending (fputcsv only emits \n).
     *
     * @param resource $buffer A reusable php://memory handle.
     * @param array    $row
     */
    private static function emitCsvLine($buffer, $row)
    {
        ftruncate($buffer, 0);
        rewind($buffer);
        // Pass enclosure/escape explicitly: matches the previous League\Csv
        // defaults and avoids the PHP 8.4 deprecation for the escape argument.
        fputcsv($buffer, $row, ',', '"', '\\');
        rewind($buffer);
        $line = stream_get_contents($buffer);

        echo rtrim($line, "\n") . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Stream a JSON download. The small envelope (post, columns, settings,
     * metas) is written once, then original_rows is streamed in chunks so the
     * whole dataset is never held in memory.
     *
     * @param int    $tableId
     * @param array  $tableColumns
     * @param array  $tableSettings
     * @param string $dataProvider
     * @param bool   $isDataTables
     * @param string $fileName
     */
    private static function streamJson($tableId, $tableColumns, $tableSettings, $dataProvider, $isDataTables, $fileName)
    {
        header('Content-Type: application/json; charset=utf-8', true);
        header('Content-Disposition: attachment; filename="' . $fileName . '.json"', true);
        static::prepareStream();

        $envelope = array(
            'post'          => get_post($tableId),
            'columns'       => $tableColumns,
            'settings'      => $tableSettings,
            'data_provider' => $dataProvider,
            'metas'         => static::exportableMetas($tableId),
            'rows'          => array(),
        );

        // Emit the envelope minus its closing brace, then append the streamed
        // original_rows array. original_rows is guaranteed absent from the
        // envelope, so appending a fresh key is always valid JSON.
        $prefix = json_encode($envelope);
        echo substr($prefix, 0, -1) . ',"original_rows":['; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $flushEvery = ninjaTablePerChunk($tableId);
        $counter    = 0;
        $first      = true;

        foreach (static::rowIterator($tableId, $tableColumns, $tableSettings, $isDataTables, true) as $rowObj) {
            echo ($first ? '' : ',') . json_encode($rowObj); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            $first = false;

            if (++$counter % $flushEvery === 0) {
                if (function_exists('ob_flush')) {
                    @ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }
                flush();
                if (connection_aborted()) {
                    break;
                }
            }
        }

        echo ']}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        wp_die();
    }

    /**
     * Build the exportable post-meta map, mirroring the excluded keys used by
     * the non-streamed JSON export.
     *
     * @param int $tableId
     * @return array
     */
    private static function exportableMetas($tableId)
    {
        $excludedMetaKeys = array(
            '_ninja_table_cache_object',
            '_ninja_table_cache_html',
            '_external_cached_data',
            '_last_external_cached_time',
            '_last_edited_by',
            '_last_edited_time',
            '__ninja_cached_table_html'
        );

        $allMeta = array();
        foreach (get_post_meta($tableId) as $metaKey => $metaValue) {
            if (!in_array($metaKey, $excludedMetaKeys) && isset($metaValue[0])) {
                $allMeta[$metaKey] = maybe_unserialize($metaValue[0]);
            }
        }

        return $allMeta;
    }

    /**
     * Yield table rows one chunk at a time for streaming export.
     *
     * @param int   $tableId
     * @param array $tableColumns
     * @param array $tableSettings
     * @param bool  $isDataTables
     * @param bool  $wantObjects  true for JSON (row objects), false for CSV
     *                            (associative row keyed by column key).
     * @return \Generator
     */
    private static function rowIterator($tableId, $tableColumns, $tableSettings, $isDataTables, $wantObjects)
    {
        $perChunk = ninjaTablePerChunk($tableId);

        if ($isDataTables) {
            $tableManager = new DynamicTableManager($tableId);
            if (!$tableManager->tableExists()) {
                return;
            }

            $dynamicRow = new DynamicRow($tableId);
            $idColumn   = DynamicTableManager::COL_ID;
            $lastId     = 0;

            // Keyset by the dynamic table's primary key (id ASC): stable under
            // concurrent inserts/deletes, unlike offset paging which can skip/
            // duplicate rows. Oldest-first matches the non-streamed export order.
            do {
                $rawRows = $dynamicRow->newQuery()
                    ->where($idColumn, '>', $lastId)
                    ->orderBy($idColumn, 'ASC')
                    ->limit($perChunk)
                    ->get();
                $rawRows = is_array($rawRows) ? $rawRows : $rawRows->toArray();

                $count = 0;
                foreach ($rawRows as $rawRow) {
                    $mapped = $dynamicRow->mapRowToUserKeys($rawRow);
                    $lastId = (int)$mapped['id'];
                    $count++;

                    if ($wantObjects) {
                        yield (object)array(
                            'id'         => $mapped['id'],
                            'position'   => $mapped['position'] ?? 0,
                            'owner_id'   => $mapped['owner_id'] ?? 0,
                            'value'      => $mapped['values'],
                            'settings'   => !empty($mapped['settings']) ? $mapped['settings'] : null,
                            'created_at' => $mapped['created_at'] ?? '',
                            'updated_at' => $mapped['updated_at'] ?? '',
                        );
                    } else {
                        yield $mapped['values'];
                    }
                }
            } while ($count === $perChunk);

            return;
        }

        // Default engine: keyset-page the raw items table by primary key so
        // large tables stream at flat memory. The unique PK order can't
        // drop/duplicate rows across chunks. JSON keeps selectedRows' shape
        // (no id in output).
        $select = $wantObjects
            ? array('id', 'position', 'owner_id', 'attribute', 'value', 'settings', 'created_at', 'updated_at')
            : array('id', 'value');
        $lastId = 0;

        do {
            $rows = (new NinjaTableItem)
                ->select($select)
                ->where('table_id', $tableId)
                ->where('id', '>', $lastId)
                ->orderBy('id', 'ASC')
                ->limit($perChunk)
                ->get();

            $count = 0;
            foreach ($rows as $row) {
                $lastId = (int)$row->id;
                $count++;

                if ($wantObjects) {
                    // toArray() applies the model's date serialization; going
                    // through attributes directly would emit updated_at as a
                    // DateTime object. Drop the cursor-only id from the output.
                    $arr = $row->toArray();
                    unset($arr['id']);
                    $arr['value'] = json_decode($row->value, true);
                    yield (object)$arr;
                } else {
                    yield json_decode($row->value, true);
                }
            }
        } while ($count === $perChunk);
    }

    private static function exportAsCSV($data, $fileName = null, $header = null)
    {
        $fileName = ($fileName) ? $fileName . '.csv' : 'export-data-' . gmdate('d-m-Y') . '.csv';

        $writer = Writer::createFromFileObject(new \SplTempFileObject());
        $writer->setDelimiter(",");
        $writer->setNewline("\r\n");
        $header !== null ? $writer->insertOne($header) : '';
        $writer->insertAll($data);
        $writer->output($fileName);
        wp_die();
    }

    private static function exportAsJSON($data, $fileName = null)
    {
        $fileName = ($fileName) ? $fileName . '.json' : 'export-data-' . gmdate('d-m-Y') . '.json';

        header('Content-disposition: attachment; filename=' . $fileName);
        header('Content-type: application/json');
        header('X-Content-Type-Options: nosniff');

        echo json_encode($data);

        wp_die();
    }

    /**
     * Get flat row data from DataTables dynamic table for CSV export
     *
     * @param int $tableId Table ID
     *
     * @return array Array of associative arrays keyed by column key
     */
    private static function getDataTablesCsvData($tableId)
    {
        $tableManager = new DynamicTableManager($tableId);

        if (!$tableManager->tableExists()) {
            return [];
        }

        $dynamicRow = new DynamicRow($tableId);
        $total      = $dynamicRow->count();
        $rawRows    = $dynamicRow->getAll(max($total, 1), 1, DynamicTableManager::COL_POSITION, 'ASC');

        $data = [];
        foreach ($rawRows as $rawRow) {
            $mapped = $dynamicRow->mapRowToUserKeys($rawRow);
            $data[] = $mapped['values'];
        }

        return $data;
    }

    /**
     * Get rows from DataTables dynamic table for export
     *
     * @param int $tableId Table ID
     * @param array $tableColumns Column definitions
     *
     * @return array Formatted rows for export
     */
    private static function getDataTablesRows($tableId, $tableColumns)
    {
        $tableManager = new DynamicTableManager($tableId);

        if (!$tableManager->tableExists()) {
            return [];
        }

        $dynamicRow = new DynamicRow($tableId);
        $total      = $dynamicRow->count();
        $rawRows    = $dynamicRow->getAll(max($total, 1), 1, DynamicTableManager::COL_POSITION, 'ASC');

        $rows = [];
        foreach ($rawRows as $rawRow) {
            $mapped = $dynamicRow->mapRowToUserKeys($rawRow);

            // Format to match existing export structure
            $rows[] = (object)[
                'id'         => $mapped['id'],
                'position'   => $mapped['position'] ?? 0,
                'owner_id'   => $mapped['owner_id'] ?? 0,
                'value'      => $mapped['values'],
                'settings'   => !empty($mapped['settings']) ? $mapped['settings'] : null,
                'created_at' => $mapped['created_at'] ?? '',
                'updated_at' => $mapped['updated_at'] ?? '',
            ];
        }

        return $rows;
    }
}
