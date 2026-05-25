<?php

namespace NinjaTables\App\Http\Controllers;

use NinjaTables\App\App;
use NinjaTables\App\Traits\ImportTrait;
use NinjaTables\App\Modules\DataTables\Models\DynamicRow;
use NinjaTables\App\Modules\DataTables\Database\DynamicTableManager;
use NinjaTables\Database\Migrations\NinjaTablesSupsysticTableMigration;
use NinjaTables\Database\Migrations\NinjaTablesTablePressMigration;
use NinjaTables\Framework\Http\Request\Request;
use NinjaTables\Framework\Support\Arr;
use NinjaTables\Framework\Support\Sanitizer;
use NinjaTables\App\Library\Csv\Reader;
use NinjaTables\App\Models\NinjaTableItem;

class ImportController extends Controller
{
    use ImportTrait;

    private $cpt_name = 'ninja-table';

    private static $tableName = 'ninja_table_items';
    private static $importJobPrefix = 'ninja_tables_csv_import_job_';

    public function tableBuilderImport(Request $request)
    {
        return $this->extracted($request->all());
    }

    public function defaultImport(Request $request)
    {
        try {
            $format       = Sanitizer::sanitizeTextField(Arr::get($request->all(), 'format'));
            $doUnicode    = Sanitizer::sanitizeTextField(Arr::get($request->all(), 'do_unicode'));
            $renderEngine = Sanitizer::sanitizeTextField(Arr::get($request->all(), 'render_engine', 'footable'));

            if ($format == 'dragAndDrop') {
                return $this->extracted($request->all());
            }

            if (!isset($_FILES['file'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                return $this->sendError([
                    'errors'  => array(),
                    'message' => __('Please upload a file.', 'ninja-tables')
                ], 423);
            }

            $fileName = Sanitizer::sanitizeTextField(
                Arr::get($_FILES, 'file.name') // phpcs:ignore WordPress.Security.NonceVerification.Missing
            );

            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if ($fileExtension === 'csv' && $format == 'csv') {
                return $this->uploadTableCsv($doUnicode, $renderEngine);
            } elseif ($fileExtension === 'json' && $format == 'json') {
                return $this->uploadTableJson($renderEngine);
            } elseif ($fileExtension === 'json' && $format == 'ninjaJson') {
                return $this->uploadTableNinjaJson($renderEngine);
            }

            return $this->json([
                'message' => __('No appropriate driver found for the import format.', 'ninja-tables')
            ], 423);
        } catch (\Throwable $throwable) {
            return $this->sendImportExceptionResponse($throwable);
        }
    }

    public function initDefaultCsvImport(Request $request)
    {
        try {
            $doUnicode = Sanitizer::sanitizeTextField(Arr::get($request->all(), 'do_unicode', 'no'));
            $renderEngine = Sanitizer::sanitizeTextField(Arr::get($request->all(), 'render_engine', 'footable'));
            $renderEngine = in_array($renderEngine, ['footable', 'datatables'], true) ? $renderEngine : 'footable';
            $sourceInfo = $this->resolveCsvImportSource($request);
            if (is_wp_error($sourceInfo)) {
                return $this->sendError([
                    'data' => ['message' => $sourceInfo->get_error_message()]
                ], 422);
            }

            $attachmentId = (int)Arr::get($sourceInfo, 'attachment_id');
            $filePath     = Arr::get($sourceInfo, 'file_path');
            $fileName     = Arr::get($sourceInfo, 'file_name');

            if (!$this->isValidImportPath($filePath)) {
                return $this->sendError([
                    'data' => ['message' => __('Uploaded file path is not valid.', 'ninja-tables')]
                ], 422);
            }

            $fileStats = $this->scanCsvFile($filePath);
            if (is_wp_error($fileStats)) {
                return $this->sendError([
                    'data' => ['message' => $fileStats->get_error_message()]
                ], 422);
            }

            $header          = Arr::get($fileStats, 'header', []);
            $totalRows       = (int)Arr::get($fileStats, 'total_rows', 0);
            $initialCursor   = (int)Arr::get($fileStats, 'cursor_after_header', 0);
            $formattedHeader = ninja_table_format_header($header);

            $tableId = $this->createTable(array(
                'post_title'   => $fileName,
                'post_content' => '',
                'post_type'    => $this->cpt_name,
                'post_status'  => 'publish'
            ));

            $this->storeTableConfigWhenImporting($tableId, $formattedHeader, $renderEngine, 'ajax_table');
            $token = wp_generate_password(32, false, false);
            $jobTtl = $this->getImportJobTtl();
            $job   = [
                'owner_id'       => get_current_user_id(),
                'attachment_id'  => $attachmentId,
                'file_path'      => $filePath,
                'table_id'       => (int)$tableId,
                'header_keys'    => array_keys($formattedHeader),
                'render_engine'  => $renderEngine,
                'do_unicode'     => $doUnicode === 'yes' ? 'yes' : 'no',
                'processed_rows' => 0,
                'total_rows'     => $totalRows,
                'cursor'         => $initialCursor,
                'created_at'     => time()
            ];

            set_transient(self::$importJobPrefix . $token, $job, $jobTtl);

            return $this->json([
                'message' => __('CSV uploaded. Import has started.', 'ninja-tables'),
                'data'    => [
                    'token'      => $token,
                    'tableId'    => (int)$tableId,
                    'total_rows' => $totalRows
                ]
            ], 200);
        } catch (\Throwable $throwable) {
            return $this->sendImportExceptionResponse($throwable);
        }
    }

    public function processDefaultCsvImportChunk(Request $request)
    {
        try {
            global $wpdb;

            $token            = Sanitizer::sanitizeTextField(Arr::get($request->all(), 'token'));
            $requestedChunk   = intval(Arr::get($request->all(), 'chunk_size', 0));
            $defaultChunkSize = (int)apply_filters('ninja_tables_csv_import_chunk_size', 20000);
            $chunkSize        = $requestedChunk > 0 ? $requestedChunk : $defaultChunkSize;
            $maxChunkSize     = (int)apply_filters('ninja_tables_csv_import_max_chunk_size', 50000);
            $maxChunkSize     = max(100, $maxChunkSize);
            $chunkSize        = max(100, min($chunkSize, $maxChunkSize));

            if (!$token) {
                return $this->sendError([
                    'data' => ['message' => __('Import token is missing.', 'ninja-tables')]
                ], 422);
            }

            $job = get_transient(self::$importJobPrefix . $token);
            if (!$job || !is_array($job)) {
                return $this->sendError([
                    'data' => ['message' => __('Import session expired. Please start again.', 'ninja-tables')]
                ], 410);
            }

            if ((int)Arr::get($job, 'owner_id') !== get_current_user_id()) {
                return $this->sendError([
                    'data' => ['message' => __('You are not allowed to process this import job.', 'ninja-tables')]
                ], 403);
            }

            $filePath = Arr::get($job, 'file_path');
            if (!$this->isValidImportPath($filePath)) {
                return $this->sendError([
                    'data' => ['message' => __('CSV file is not accessible anymore.', 'ninja-tables')]
                ], 422);
            }

            $headerKeys = Arr::get($job, 'header_keys', []);
            if (empty($headerKeys) || !is_array($headerKeys)) {
                return $this->sendError([
                    'data' => ['message' => __('Import header is not valid.', 'ninja-tables')]
                ], 422);
            }

            $processedRows = (int)Arr::get($job, 'processed_rows', 0);
            $totalRows     = (int)Arr::get($job, 'total_rows', 0);
            $tableId       = (int)Arr::get($job, 'table_id');
            $renderEngine  = Arr::get($job, 'render_engine', 'footable');
            $doUnicode     = Arr::get($job, 'do_unicode') === 'yes';
            $cursor        = (int)Arr::get($job, 'cursor', 0);

            if ($processedRows >= $totalRows) {
                delete_transient(self::$importJobPrefix . $token);
                ninjaTablesClearTableDataCache($tableId);

                return $this->json([
                    'message' => __('Import completed successfully.', 'ninja-tables'),
                    'data'    => [
                        'completed'      => true,
                        'tableId'        => $tableId,
                        'processed_rows' => $processedRows,
                        'total_rows'     => $totalRows
                    ]
                ], 200);
            }

            $chunkResult = $this->readCsvChunk($filePath, $cursor, $chunkSize);
            if (is_wp_error($chunkResult)) {
                return $this->sendError([
                    'data' => ['message' => $chunkResult->get_error_message()]
                ], 422);
            }

            $chunkRows = Arr::get($chunkResult, 'rows', []);
            $nextCursor = (int)Arr::get($chunkResult, 'next_cursor', $cursor);

            $data      = [];
            $userId    = get_current_user_id();
            $timeStamp = time() - (count($chunkRows) * 100);

            foreach ($chunkRows as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $item = array_slice(array_pad($item, count($headerKeys), ''), 0, count($headerKeys));

                if ($doUnicode) {
                    $item = array_map(function ($value) {
                        return is_string($value) ? mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1') : $value;
                    }, $item);
                }

                $itemTemp = array_combine($headerKeys, $item);
                $itemTemp = ninja_tables_sanitize_array($itemTemp);
                if ($renderEngine === 'datatables') {
                    $data[] = $itemTemp;
                } else {
                    $data[] = array(
                        'table_id'   => $tableId,
                        'attribute'  => 'value',
                        'owner_id'   => $userId,
                        'value'      => wp_json_encode($itemTemp, JSON_UNESCAPED_UNICODE),
                        'created_at' => gmdate('Y-m-d H:i:s', $timeStamp),
                        'updated_at' => gmdate('Y-m-d H:i:s')
                    );
                }

                $timeStamp = $timeStamp + 100;
            }

            if ($data) {
                if ($renderEngine === 'datatables') {
                    $tableManager = new DynamicTableManager($tableId);
                    if (!$tableManager->tableExists()) {
                        $tableManager->createTable();
                    }

                    // Schema can't change mid-import, so only sync on the first chunk.
                    if (empty($job['schema_synced'])) {
                        $columns = get_post_meta($tableId, '_ninja_table_columns', true) ?: [];
                        $tableManager->syncColumns($columns);
                        $job['schema_synced'] = true;
                    }

                    $dynamicRow = new DynamicRow($tableId);
                    $startPosition = $dynamicRow->getNextPosition();
                    $dynamicRow->batchInsert($data, $startPosition);
                } else {
                    $tableName = $wpdb->prefix . static::$tableName;
                    ninjaTablesBatchInsert($tableName, $data);
                }
            }

            $processedRows += count($chunkRows);
            $job['processed_rows'] = $processedRows;
            $job['cursor']         = $nextCursor;
            set_transient(self::$importJobPrefix . $token, $job, $this->getImportJobTtl());

            $completed = $processedRows >= $totalRows || (empty($chunkRows) && $nextCursor <= $cursor);
            if ($completed) {
                delete_transient(self::$importJobPrefix . $token);
                ninjaTablesClearTableDataCache($tableId);
            }

            return $this->json([
                'message' => $completed
                    ? __('Import completed successfully.', 'ninja-tables')
                    : __('Import chunk processed.', 'ninja-tables'),
                'data'    => [
                    'completed'      => $completed,
                    'tableId'        => $tableId,
                    'processed_rows' => $processedRows,
                    'total_rows'     => $totalRows
                ]
            ], 200);
        } catch (\Throwable $throwable) {
            if (!empty($token)) {
                delete_transient(self::$importJobPrefix . $token);
            }

            return $this->sendImportExceptionResponse($throwable);
        }
    }

    private function uploadTableCsv($doUnicode, $renderEngine = 'footable')
    {
        $mimes = array(
            'text/csv',
            'text/plain',
            'application/csv',
            'text/comma-separated-values',
            'application/excel',
            'application/vnd.ms-excel',
            'application/vnd.msexcel',
            'text/anytext',
            'application/octet-stream',
            'application/txt'
        );

        $fileType = Sanitizer::sanitizeTextField(
            Arr::get($_FILES, 'file.type') // phpcs:ignore WordPress.Security.NonceVerification.Missing
        );
        if (!in_array($fileType, $mimes)) {
            return $this->sendError([
                'data' => [
                    'errors'  => array(),
                    'message' => __('Please upload valid CSV', 'ninja-tables')
                ]
            ], 423);
        }

        $tmpName  = Arr::get($_FILES, 'file.tmp_name'); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $fileName = Sanitizer::sanitizeTextField(
            Arr::get($_FILES, 'file.name') // phpcs:ignore WordPress.Security.NonceVerification.Missing
        );

        if (!$tmpName || !is_uploaded_file($tmpName)) {
            return $this->sendError([
                'data' => ['message' => __('Invalid file upload.', 'ninja-tables')]
            ], 423);
        }

        try {
            if ($doUnicode && $doUnicode == 'yes') {
                $data   = file_get_contents($tmpName);
                $data   = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
                $reader = Reader::createFromString($data)->fetchAll();
            } else {
                $reader = Reader::createFromPath($tmpName, 'r')->fetchAll();
            }
        } catch (\Throwable $exception) {
            return $this->sendError([
                'data' => [
                    'errors'  => __('Unable to parse the uploaded CSV file.', 'ninja-tables'),
                    'message' => __('CSV parsing failed. Please verify delimiter/encoding and try again.', 'ninja-tables')
                ]
            ], 423);
        }

        if (empty($reader) || !is_array($reader)) {
            return $this->sendError([
                'data' => ['message' => __('The uploaded CSV appears to be empty.', 'ninja-tables')]
            ], 423);
        }

        $header = array_shift($reader);
        if (empty($header) || !is_array($header)) {
            return $this->sendError([
                'data' => ['message' => __('Unable to read CSV header row.', 'ninja-tables')]
            ], 423);
        }

        $tableId = $this->createTable(array(
            'post_title'   => $fileName,
            'post_content' => '',
            'post_type'    => $this->cpt_name,
            'post_status'  => 'publish'
        ));

        $header = ninja_table_format_header($header);

        $this->storeTableConfigWhenImporting($tableId, $header, $renderEngine);

        if ($renderEngine === 'datatables') {
            $this->insertDataToDataTable($tableId, $reader, $header);
        } else {
            ninjaTableInsertDataToTable($tableId, $reader, $header);
        }

        $this->json([
            'message' => __('Successfully added a table.', 'ninja-tables'),
            'tableId' => $tableId
        ], 200);
    }

    private function createTable($data = null)
    {
        return wp_insert_post(
            $data
                ? $data
                : array(
                'post_title'   => __('Temporary table name', 'ninja-tables'),
                'post_content' => __(
                    'Temporary table description',
                    'ninja-tables'
                ),
                'post_type'    => $this->cpt_name,
                'post_status'  => 'publish'
            )
        );
    }

    private function storeTableConfigWhenImporting($tableId, $header, $renderEngine = 'footable', $renderType = 'legacy_table')
    {
        $ninjaTableColumns = array();

        foreach ($header as $key => $name) {
            $ninjaTableColumns[] = array(
                'key'         => $key,
                'name'        => $name,
                'breakpoints' => ''
            );
        }

        if ($renderEngine === 'datatables') {
            $reservedKeys = DynamicTableManager::getReservedKeys($ninjaTableColumns);
            if ($reservedKeys) {
                wp_send_json_error([
                    'message' => sprintf(
                        // translators: %s is the reserved column name(s) that conflict
                        __('CSV header "%s" conflicts with a reserved system column. Please rename it.', 'ninja-tables'),
                        implode('", "', $reservedKeys)
                    ),
                ], 400);
            }
        }

        update_post_meta($tableId, '_ninja_table_columns', $ninjaTableColumns);
        $ninjaTableSettings            = ninja_table_get_table_settings($tableId, 'admin');
        $ninjaTableSettings['library'] = $renderEngine;
        $ninjaTableSettings['render_type'] = $renderType;
        update_post_meta($tableId, '_ninja_table_settings', $ninjaTableSettings);
        ninjaTablesClearTableDataCache($tableId);
    }

    private function uploadTableJson($renderEngine = 'footable')
    {
        $tableId = $this->createTable();
        $tmpName = Arr::get($_FILES, 'file.tmp_name'); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if (!$tmpName || !is_uploaded_file($tmpName)) {
            return $this->sendError([
                'data' => ['message' => __('Invalid file upload.', 'ninja-tables')]
            ], 423);
        }

        $content = json_decode(file_get_contents($tmpName), true);

        $reverse_content = array_reverse($content);
        $header          = array_keys(array_pop($reverse_content));

        $formattedHeader = array();
        foreach ($header as $head) {
            $formattedHeader[$head] = $head;
        }

        $this->storeTableConfigWhenImporting($tableId, $formattedHeader, $renderEngine);

        if ($renderEngine === 'datatables') {
            $this->insertDataToDataTable($tableId, $content, $formattedHeader);
        } else {
            ninjaTableInsertDataToTable($tableId, $content, $formattedHeader);
        }

        $this->json([
            'message' => __('Successfully added a table.', 'ninja-tables'),
            'tableId' => $tableId
        ], 200);
    }

    private function uploadTableNinjaJson($renderEngine = 'footable')
    {
        $tmpName = Arr::get($_FILES, 'file.tmp_name'); // phpcs:ignore WordPress.Security.NonceVerification.Missing

        if (!$tmpName || !is_uploaded_file($tmpName)) {
            return $this->sendError([
                'data' => ['message' => __('Invalid file upload.', 'ninja-tables')]
            ], 423);
        }

        $parsedContent = file_get_contents($tmpName);

        $content = json_decode($parsedContent, true);

        if (json_last_error()) {
            for ($i = 0; $i <= 31; ++$i) {
                $parsedContent = str_replace(chr($i), "", $parsedContent);
            }
            $parsedContent = str_replace(chr(127), "", $parsedContent);
            if (0 === strpos(bin2hex($parsedContent), 'efbbbf')) {
                $parsedContent = substr($parsedContent, 3);
            }
            $content = json_decode($parsedContent, true);
        }

        if (!Arr::get($content, 'post') || !Arr::get($content, 'columns') || !Arr::get($content, 'settings')) {
            $this->json([
                'message' => __(
                    'Please upload a JSON file exported from Ninja Tables',
                    'ninja-tables'
                )
            ], 423);
            return;
        }

        if ($renderEngine === 'datatables') {
            $dataProvider = Arr::get($content, 'data_provider', 'default');
            if ($dataProvider !== 'default') {
                $this->json([
                    'message' => sprintf(
                        // translators: %s is the data provider name
                        __('This table uses the "%s" data provider which is not supported by the DataTables engine. Only tables with the "default" provider can be imported as DataTables.', 'ninja-tables'),
                        $dataProvider
                    )
                ], 422);
                return;
            }
        }

        $tableAttributes = array(
            'post_title'   => Sanitizer::ksesPost($content['post']['post_title']),
            'post_content' => wp_kses_post($content['post']['post_content']),
            'post_type'    => $this->cpt_name,
            'post_status'  => 'publish'
        );

        $tableId = $this->createTable($tableAttributes);

        update_post_meta($tableId, '_ninja_table_columns', $content['columns']);

        $metas = $content['metas'];
        foreach ($metas as $meta_key => $meta_value) {
            update_post_meta($tableId, $meta_key, $meta_value);
        }

        $settings            = $content['settings'];
        $settings['library'] = $renderEngine;
        update_post_meta($tableId, '_ninja_table_settings', $settings);

        $isDataTables = $renderEngine === 'datatables';

        $header = [];
        foreach ($content['columns'] as $column) {
            $header[$column['key']] = $column['name'];
        }

        if (!empty($content['rows'])) {
            if ($isDataTables) {
                $this->insertDataToDataTable($tableId, $content['rows'], $header);
            } else {
                ninjaTableInsertDataToTable($tableId, $content['rows'], $header);
            }
        }

        if ($isDataTables) {
            if (empty($content['rows']) && !empty($content['original_rows'])) {
                $convertedRows = [];
                foreach ($content['original_rows'] as $row) {
                    if (!empty($row['value']) && is_array($row['value'])) {
                        $convertedRows[] = $row['value'];
                    }
                }
                if ($convertedRows) {
                    $this->insertDataToDataTable($tableId, $convertedRows, $header);
                }
            }
        } else {
            global $wpdb;
            if (isset($content['original_rows']) && $originalRows = $content['original_rows']) {
                $tableName = $wpdb->prefix . static::$tableName;
                foreach ($originalRows as $row) {
                    $row['table_id'] = $tableId;
                    $row['value']    = wp_json_encode($row['value'], JSON_UNESCAPED_UNICODE);
                    $wpdb->insert($tableName, $row, false); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                }
            }
        }

        $this->json([
            'message' => __('Successfully added a table.', 'ninja-tables'),
            'tableId' => $tableId
        ], 200);
    }

    public function getTablesFromOtherPlugin(Request $request)
    {
        $plugin = Sanitizer::sanitizeTextField(Arr::get($request->all(), 'plugin'));
        if ($plugin == 'TablePress') {
            $libraryClass = new NinjaTablesTablePressMigration();
        } elseif ($plugin == 'supsystic') {
            $libraryClass = new NinjaTablesSupsysticTableMigration();
        } else {
            return false;
        }

        $tables = $libraryClass->getTables();

        $this->json([
            'tables' => $tables
        ], 200);
    }

    public function importTableFromOtherPlugin(Request $request)
    {
        $plugin  = Sanitizer::sanitizeTextField(Arr::get($request->all(), 'plugin'));
        $tableId = intval(Arr::get($request->all(), 'tableId'));

        if ($plugin == 'TablePress') {
            if (get_post_type($tableId) !== 'tablepress_table') {
                return $this->sendError([
                    'data' => ['message' => __('Invalid table.', 'ninja-tables')]
                ], 423);
            }
            $libraryClass = new NinjaTablesTablePressMigration();
        } elseif ($plugin == 'supsystic') {
            $libraryClass = new NinjaTablesSupsysticTableMigration();
        } else {
            return false;
        }

        $tableId = $libraryClass->migrateTable($tableId);
        if (is_wp_error($tableId)) {
            return $this->sendError([
                'data' => [
                    'message' => 'Something Went Wrong When Migrating'
                ]
            ], 423);
        }

        $message = __(
            'Successfully imported. Please go to all tables and review your newly imported table.',
            'ninja-tables'
        );

        return $this->sendSuccess([
            'data' => [
                'message' => $message,
                'tableId' => $tableId
            ]
        ], 200);
    }


    public function uploadCsvInExistingTable(Request $request)
    {
        try {
            global $wpdb;
            $tableId = intval(Arr::get($request->all(), 'table_id'));

            if (!$tableId || get_post_type($tableId) !== $this->cpt_name) {
                return $this->sendError([
                    'data' => ['message' => __('Invalid table.', 'ninja-tables')]
                ], 423);
            }

            $tmpName = Arr::get($_FILES, 'file.tmp_name'); // phpcs:ignore WordPress.Security.NonceVerification.Missing

            if (!$tmpName || !is_uploaded_file($tmpName)) {
                return $this->sendError([
                    'data' => ['message' => __('Invalid file upload.', 'ninja-tables')]
                ], 423);
            }

            $doUnicode = Arr::get($request->all(), 'do_unicode') === 'yes';

            if ($doUnicode) {
                $data   = file_get_contents($tmpName);
                $data   = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
                $reader = Reader::createFromString($data)->fetchAll();
            } else {
                $reader = Reader::createFromPath($tmpName, 'r')->fetchAll();
            }

            $csvHeader = array_shift($reader);
            $csvHeader = array_map('esc_attr', $csvHeader);

            $config = get_post_meta($tableId, '_ninja_table_columns', true);

            if (!$config) {
                return $this->json(array(
                    'message' => __('Please set table configuration first', 'ninja-tables')
                ), 423);
            }

            $header = array_map(function ($item) {
                return $item['key'];
            }, $config);

            if (count($header) != count($csvHeader)) {
                return $this->sendError([
                    'data' => [
                        'message' => __('Please use the provided CSV header structure font face.', 'ninja-tables')
                    ]
                ], 423);
            }

            $replace = Arr::get($request->all(), 'replace') === 'true';

            $tableSettings = ninja_table_get_table_settings($tableId, 'public');
            $library       = isset($tableSettings['library']) ? $tableSettings['library'] : 'footable';

            if ($library === 'datatables') {
                return $this->uploadCsvToDataTable($tableId, $reader, $header, $replace);
            }

            $data      = array();
            $userId    = get_current_user_id();
            $timeStamp = time() - (count($reader) * 100);

            foreach ($reader as $item) {
                if (count($header) === count($item)) {
                    $itemTemp = array_combine($header, $item);
                    $itemTemp = ninja_tables_sanitize_array($itemTemp);
                    $data[]   = array(
                        'table_id'   => $tableId,
                        'attribute'  => 'value',
                        'owner_id'   => $userId,
                        'value'      => wp_json_encode($itemTemp, JSON_UNESCAPED_UNICODE),
                        'created_at' => gmdate('Y-m-d H:i:s', $timeStamp),
                        'updated_at' => gmdate('Y-m-d H:i:s')
                    );
                } else {
                    continue;
                }

                $timeStamp = $timeStamp + 100;
            }

            $app  = App::getInstance();
            $data = $app->applyFilters('ninja_tables_import_table_data', $data, $tableId);

            if ($replace) {
                NinjaTableItem::where('table_id', $tableId)->delete();
            }

            $tableName = $wpdb->prefix . static::$tableName;
            foreach (array_chunk($data, 3000) as $chunk) {
                ninjaTablesBatchInsert($tableName, $chunk);
            }

            ninjaTablesClearTableDataCache($tableId);

            return $this->json([
                'data' => [
                    'message' => __('Successfully uploaded data.', 'ninja-tables')
                ]
            ]);
        } catch (\Throwable $throwable) {
            return $this->sendImportExceptionResponse($throwable);
        }
    }

    protected function uploadCsvToDataTable($tableId, $rows, $header, $replace)
    {
        $reservedKeys = DynamicTableManager::getReservedKeys($header);
        if ($reservedKeys) {
            wp_send_json_error([
                'message' => sprintf(
                    // translators: %s is the reserved column name(s) that conflict
                    __('CSV header "%s" conflicts with a reserved system column. Please rename it.', 'ninja-tables'),
                    implode('", "', $reservedKeys)
                ),
            ], 400);
        }

        $tableManager = new DynamicTableManager($tableId);

        if (!$tableManager->tableExists()) {
            $columns = get_post_meta($tableId, '_ninja_table_columns', true) ?: [];
            $tableManager->createTable();
            $tableManager->syncColumns($columns);
        }

        if ($replace) {
            $tableManager->truncateTable();
        }

        $rowModel = new DynamicRow($tableId);
        $startPosition = $rowModel->getNextPosition();

        $preparedRows = [];
        foreach ($rows as $item) {
            if (count($header) === count($item)) {
                $rowData        = array_combine($header, $item);
                $preparedRows[] = ninja_tables_sanitize_array($rowData);
            }
        }

        $inserted = $rowModel->batchInsert($preparedRows, $startPosition);

        ninjaTablesClearTableDataCache($tableId);

        return $this->json([
            'data' => [
                'message' => sprintf(
                /* translators: %d is the number of rows imported */
                    __('Successfully imported %d rows.', 'ninja-tables'),
                    $inserted
                )
            ]
        ]);
    }

    protected function insertDataToDataTable($tableId, $rows, $header)
    {
        $columns = get_post_meta($tableId, '_ninja_table_columns', true) ?: [];

        $tableManager = new DynamicTableManager($tableId);
        if (!$tableManager->tableExists()) {
            $tableManager->createTable();
        }
        $tableManager->syncColumns($columns);

        $dynamicRow  = new DynamicRow($tableId);
        $headerKeys  = array_keys($header);
        $headerCount = count($headerKeys);

        $preparedRows = [];
        foreach ($rows as $item) {
            if (!is_array($item) || empty($item)) {
                continue;
            }

            reset($item);
            if (is_int(key($item))) {
                if (count($item) !== $headerCount) {
                    continue;
                }
                $values = array_combine($headerKeys, $item);
            } else {
                $values = $item;
            }

            $preparedRows[] = ninja_tables_sanitize_array($values);
        }

        $dynamicRow->batchInsert($preparedRows);
    }

    /**
     * @param Request $request
     *
     * @return mixed
     */
    public function extracted($data)
    {
        $fileName = 'Ninja-tables' . gmdate('d-m-Y');
        $url      = sanitize_url(Arr::get($data, 'url', ''));

        if (!empty($url)) {
            $data = static::importFromURL($url);
        } else {
            $data = static::getData();
        }

        $data = ninja_tables_sanitize_array($data);

        $tableId = $this->savedDragAndDropTable($data, $fileName);

        return $this->sendSuccess([
            'data' => [
                'id' => $tableId
            ]
        ], 200);
    }

    private function sendImportExceptionResponse(\Throwable $throwable)
    {
        $errorMessage = $this->getUserFriendlyImportError($throwable);

        return $this->sendError([
            'data' => [
                'message' => $errorMessage,
                'errors'  => []
            ]
        ], 500);
    }

    private function getUserFriendlyImportError(\Throwable $throwable)
    {
        $message = strtolower($throwable->getMessage());

        if (strpos($message, 'allowed memory size') !== false) {
            return __(
                'Import failed because the server ran out of memory. Try a smaller file/chunked import or increase PHP memory_limit.',
                'ninja-tables'
            );
        }

        if (strpos($message, 'maximum execution time') !== false) {
            return __(
                'Import timed out on the server. Try a smaller file/chunked import or increase PHP max_execution_time.',
                'ninja-tables'
            );
        }

        return __(
            'Import failed due to a server error while processing the file. Please check file format/size and try again.',
            'ninja-tables'
        );
    }

    private function resolveCsvImportSource(Request $request)
    {
        $attachmentId = intval(Arr::get($request->all(), 'attachment_id', 0));
        if ($attachmentId > 0) {
            $attachment = get_post($attachmentId);
            if (!$attachment || $attachment->post_type !== 'attachment') {
                return new \WP_Error('invalid_attachment', __('Invalid media file selected.', 'ninja-tables'));
            }

            if (!current_user_can('edit_post', $attachmentId)) {
                return new \WP_Error('forbidden_attachment', __('You do not have permission to use this media file.', 'ninja-tables'));
            }

            $filePath = get_attached_file($attachmentId);
            if (!$filePath || !$this->isValidImportPath($filePath)) {
                return new \WP_Error('invalid_attachment_path', __('Selected media file is not accessible.', 'ninja-tables'));
            }

            $fileName = sanitize_file_name(basename($filePath));
            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($extension !== 'csv') {
                return new \WP_Error('invalid_attachment_type', __('Selected media file must be a CSV.', 'ninja-tables'));
            }

            $allowedCsvMimes = ['text/csv', 'application/csv', 'text/plain', 'application/vnd.ms-excel'];
            $attachmentMime  = (string)get_post_mime_type($attachmentId);
            $checkedMime     = (string)Arr::get(wp_check_filetype($fileName), 'type');
            if (
                ($attachmentMime && !in_array($attachmentMime, $allowedCsvMimes, true)) &&
                ($checkedMime && !in_array($checkedMime, $allowedCsvMimes, true))
            ) {
                return new \WP_Error('invalid_attachment_mime', __('Selected media file is not a valid CSV type.', 'ninja-tables'));
            }

            return [
                'attachment_id' => $attachmentId,
                'file_path'     => $filePath,
                'file_name'     => $fileName
            ];
        }

        return new \WP_Error(
            'missing_media_attachment',
            __('Please select a CSV file from WordPress Media Library for large import.', 'ninja-tables')
        );
    }

    private function scanCsvFile($filePath)
    {
        // WP_Filesystem doesn't support streaming (fgetcsv/ftell/fseek); loading
        // the entire large import CSV into memory would defeat the purpose of chunked import.
        $handle = fopen($filePath, 'r'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$handle) {
            return new \WP_Error('csv_open_failed', __('Unable to open CSV file.', 'ninja-tables'));
        }

        $header = fgetcsv($handle);
        if (!is_array($header) || empty($header)) {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            return new \WP_Error('csv_header_failed', __('Unable to read CSV header row.', 'ninja-tables'));
        }

        $cursorAfterHeader = ftell($handle);
        $totalRows         = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if ($this->isCsvRowEmpty($row)) {
                continue;
            }

            $totalRows++;
        }

        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        return [
            'header'              => $header,
            'total_rows'          => $totalRows,
            'cursor_after_header' => $cursorAfterHeader > 0 ? (int)$cursorAfterHeader : 0
        ];
    }

    private function readCsvChunk($filePath, $cursor, $chunkSize)
    {
        // Streaming CSV read requires native fopen/fseek/fgetcsv; WP_Filesystem has no equivalent.
        $handle = fopen($filePath, 'r'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        if (!$handle) {
            return new \WP_Error('csv_read_failed', __('Unable to read CSV file.', 'ninja-tables'));
        }

        if (fseek($handle, max(0, (int)$cursor)) !== 0) {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            return new \WP_Error('csv_seek_failed', __('Unable to seek CSV file.', 'ninja-tables'));
        }

        $rows = [];
        while (count($rows) < $chunkSize && ($row = fgetcsv($handle)) !== false) {
            if ($this->isCsvRowEmpty($row)) {
                continue;
            }

            $rows[] = $row;
        }

        $nextCursor = ftell($handle);
        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        return [
            'rows'       => $rows,
            'next_cursor'=> $nextCursor > 0 ? (int)$nextCursor : (int)$cursor
        ];
    }

    private function isCsvRowEmpty($row)
    {
        if (!is_array($row) || $row === []) {
            return true;
        }

        foreach ($row as $value) {
            if ($value !== null && trim((string)$value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function isValidImportPath($path)
    {
        if (!$path || !file_exists($path)) {
            return false;
        }

        $uploadsDir = wp_upload_dir();
        $baseDir    = realpath(Arr::get($uploadsDir, 'basedir', ''));
        $realPath   = realpath($path);

        if (!$baseDir || !$realPath) {
            return false;
        }

        return strpos($realPath, $baseDir) === 0;
    }

    private function getImportJobTtl()
    {
        $ttl = (int)apply_filters('ninja_tables_csv_import_job_ttl_seconds', 2 * HOUR_IN_SECONDS);
        return max(10 * MINUTE_IN_SECONDS, $ttl);
    }
}
