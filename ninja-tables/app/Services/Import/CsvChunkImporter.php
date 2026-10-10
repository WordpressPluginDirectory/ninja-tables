<?php

namespace NinjaTables\App\Services\Import;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Services\Table\Engine;
use NinjaTables\App\Services\Table\RowWriter;
use NinjaTables\App\Support\Csv;
use NinjaTables\Framework\Support\Arr;
use NinjaTables\Framework\Support\Sanitizer;

/**
 * Large CSV / Excel (.xlsx) import from the media library, processed in chunks tracked by a transient job.
 * Excel jobs first run a 'converting' phase (XlsxToCsvConverter, one slice per request) and then
 * the same 'importing' phase as CSV, on the converted temp CSV.
 * Errors are thrown as ImportException carrying the response the controller should send.
 */
class CsvChunkImporter
{
    const CPT = 'ninja-table';
    const JOB_PREFIX = 'ninja_tables_csv_import_job_';
    const WORK_DIR = 'ninja-tables-import';

    /** @var array|null the lock the current /process request holds (fencing checks use it) */
    private $activeLock = null;

    // Staged direct uploads carry the converter's temp suffix, so the stale sweep
    // also removes an upload whose request died before conversion started.
    const UPLOAD_SUFFIX = '-upload' . XlsxToCsvConverter::TEMP_SUFFIX;

    // WordPress reports .xlsx as the OOXML type; some hosts fall back to zip/octet-stream.
    const XLSX_MIMES = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/octet-stream',
    ];

    /** @var TableImporter */
    protected $tables;

    public function __construct(?TableImporter $tables = null)
    {
        $this->tables = $tables ?: new TableImporter();
    }

    public function init(array $input)
    {
        try {
            $doUnicode = Sanitizer::sanitizeTextField(Arr::get($input, 'do_unicode', 'no'));
            $renderEngine = Sanitizer::sanitizeTextField(Arr::get($input, 'render_engine', 'footable'));
            $renderEngine = in_array($renderEngine, ['footable', 'datatables'], true) ? $renderEngine : 'footable';
            $sourceInfo = $this->resolveCsvImportSource($input);
            if (is_wp_error($sourceInfo)) {
                throw new ImportException([
                    'data' => ['message' => $sourceInfo->get_error_message()]
                ], 422);
            }

            $attachmentId = (int)Arr::get($sourceInfo, 'attachment_id');
            $filePath     = Arr::get($sourceInfo, 'file_path');
            $fileName     = Arr::get($sourceInfo, 'file_name');

            if (!$this->isValidImportPath($filePath)) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
                throw new ImportException([
                    'data' => ['message' => __('Uploaded file path is not valid.', 'ninja-tables')]
                ], 422);
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            // Expired transients and fatal timeouts run no cleanup, so leftovers are swept here.
            XlsxToCsvConverter::sweepStale($this->workDir(), $this->getImportJobTtl());

            if (Arr::get($sourceInfo, 'type') === 'xlsx') {
                return $this->initXlsx($attachmentId, $filePath, $fileName, $renderEngine);
            }

            $fileStats = $this->scanCsvFile($filePath);
            if (is_wp_error($fileStats)) {
                throw new ImportException([
                    'data' => ['message' => $fileStats->get_error_message()]
                ], 422);
            }

            $header          = Arr::get($fileStats, 'header', []);
            $totalRows       = (int)Arr::get($fileStats, 'total_rows', 0);
            $initialCursor   = (int)Arr::get($fileStats, 'cursor_after_header', 0);
            $formattedHeader = ninja_table_format_header($header);

            $tableId = $this->tables->createTable(array(
                'post_title'   => $fileName,
                'post_content' => '',
                'post_type'    => self::CPT,
                'post_status'  => 'publish'
            ));

            $this->tables->storeTableConfigWhenImporting($tableId, $formattedHeader, $renderEngine, 'ajax_table');
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

            set_transient(self::JOB_PREFIX . $token, $job, $jobTtl);

            return [
                'message' => __('CSV uploaded. Import has started.', 'ninja-tables'),
                'data'    => [
                    'token'      => $token,
                    'tableId'    => (int)$tableId,
                    'total_rows' => $totalRows
                ]
            ];
        } catch (\Throwable $throwable) {
            throw $throwable;
        }
    }

    /**
     * Process one step of a job. Requests for the same job are serialized: two
     * overlapping requests would otherwise convert or import the same checkpoint
     * twice (truncating each other's temp files, or each creating a table).
     * A request that finds the job busy gets a normal progress response and the
     * UI simply retries; the job and its files are left alone.
     */
    public function processChunk(array $input)
    {
        $token = Sanitizer::sanitizeTextField(Arr::get($input, 'token'));
        $lock  = $token ? $this->acquireJobLock($token) : null;

        if ($token && !$lock) {
            return $this->busyResponse($token);
        }

        $this->activeLock = $lock;

        try {
            // The job is read only after the lock is held, so it is always the latest state.
            return $this->processLockedChunk($input);
        } catch (ImportException $exception) {
            // The lock was lost mid-request: another worker owns the job now. Nothing
            // was written past the last ownership check; let the UI simply retry.
            if ($this->isLockLost($exception)) {
                return $this->busyResponse($token);
            }
            throw $exception;
        } finally {
            if ($lock) {
                $this->releaseJobLock($token, $lock);
            }
            $this->activeLock = null;
        }
    }

    private function processLockedChunk(array $input)
    {
        $ownedJob = null; // set once the caller is confirmed as the job's owner
        try {
            $token            = Sanitizer::sanitizeTextField(Arr::get($input, 'token'));
            $requestedChunk   = intval(Arr::get($input, 'chunk_size', 0));
            $defaultChunkSize = (int)apply_filters('ninja_tables_csv_import_chunk_size', 20000);
            $chunkSize        = $requestedChunk > 0 ? $requestedChunk : $defaultChunkSize;
            $maxChunkSize     = (int)apply_filters('ninja_tables_csv_import_max_chunk_size', 50000);
            $maxChunkSize     = max(100, $maxChunkSize);
            $chunkSize        = max(100, min($chunkSize, $maxChunkSize));

            if (!$token) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
                throw new ImportException([
                    'data' => ['message' => __('Import token is missing.', 'ninja-tables')]
                ], 422);
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            $job = $this->loadJobUnderLock($token);
            if (!$job || !is_array($job)) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
                throw new ImportException([
                    'data' => ['message' => __('Import session expired. Please start again.', 'ninja-tables')]
                ], 410);
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            if ((int)Arr::get($job, 'owner_id') !== get_current_user_id()) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
                throw new ImportException([
                    'data' => ['message' => __('You are not allowed to process this import job.', 'ninja-tables')]
                ], 403);
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            $ownedJob = $job;

            if (Arr::get($job, 'phase') === 'converting') {
                return $this->convertChunk($token, $job);
            }

            $filePath = Arr::get($job, 'file_path');
            if (!$this->isValidImportPath($filePath)) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
                throw new ImportException([
                    'data' => ['message' => __('CSV file is not accessible anymore.', 'ninja-tables')]
                ], 422);
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            $headerKeys = Arr::get($job, 'header_keys', []);
            if (empty($headerKeys) || !is_array($headerKeys)) {
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
                throw new ImportException([
                    'data' => ['message' => __('Import header is not valid.', 'ninja-tables')]
                ], 422);
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            $processedRows = (int)Arr::get($job, 'processed_rows', 0);
            $totalRows     = (int)Arr::get($job, 'total_rows', 0);
            $tableId       = (int)Arr::get($job, 'table_id');
            $renderEngine  = Arr::get($job, 'render_engine', 'footable');
            $doUnicode     = Arr::get($job, 'do_unicode') === 'yes';
            $cursor        = (int)Arr::get($job, 'cursor', 0);

            if ($processedRows >= $totalRows) {
                $this->endJob($token, $job);
                ninjaTablesClearTableDataCache($tableId);

                return [
                    'message' => __('Import completed successfully.', 'ninja-tables'),
                    'data'    => [
                        'completed'      => true,
                        'tableId'        => $tableId,
                        'processed_rows' => $processedRows,
                        'total_rows'     => $totalRows,
                        'phase'          => 'importing'
                    ]
                ];
            }

            $this->keepJobFilesFresh($job);

            // Every job is read as RFC 4180 (Csv::ESCAPE): user CSVs and Excel's temp CSV alike.
            $escape = (string)Arr::get($job, 'csv_escape', Csv::ESCAPE);

            // Batches are bounded by cells and bytes as well as rows: a wide or text-heavy
            // file at a flat 20k rows can exhaust a 128 MB request (CSV and Excel alike).
            $maxCells  = max(1000, (int)apply_filters('ninja_tables_import_batch_max_cells', 50000));
            $maxBytes  = max(65536, (int)apply_filters('ninja_tables_import_batch_max_bytes', 4 * MB_IN_BYTES));
            $chunkSize = max(1, min($chunkSize, intdiv($maxCells, max(1, count($headerKeys)))));

            $chunkResult = $this->readCsvChunk($filePath, $cursor, $chunkSize, $escape, $maxBytes);
            if (is_wp_error($chunkResult)) {
                throw new ImportException([
                    'data' => ['message' => $chunkResult->get_error_message()]
                ], 422);
            }

            $chunkRows = Arr::get($chunkResult, 'rows', []);
            $nextCursor = (int)Arr::get($chunkResult, 'next_cursor', $cursor);

            $rows = [];
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

                $rows[] = array_combine($headerKeys, $item);
            }

            if ($rows) {
                $this->assertStillLocked(); // fencing: never insert rows without the lock
                $engine = $renderEngine === 'datatables' ? Engine::DATATABLES : Engine::FOOTABLE;
                (new RowWriter($tableId, $engine))->insert($rows);
            }

            $processedRows += count($chunkRows);
            $job['processed_rows'] = $processedRows;
            $job['cursor']         = $nextCursor;
            $this->assertStillLocked(); // fencing: never publish a checkpoint without the lock
            set_transient(self::JOB_PREFIX . $token, $job, $this->getImportJobTtl());

            $completed = $processedRows >= $totalRows || (empty($chunkRows) && $nextCursor <= $cursor);
            if ($completed) {
                $this->endJob($token, $job);
                ninjaTablesClearTableDataCache($tableId);
            }

            return [
                'message' => $completed
                    ? __('Import completed successfully.', 'ninja-tables')
                    : __('Import chunk processed.', 'ninja-tables'),
                'data'    => [
                    'completed'      => $completed,
                    'tableId'        => $tableId,
                    'processed_rows' => $processedRows,
                    'total_rows'     => $totalRows,
                    'phase'          => 'importing'
                ]
            ];
        } catch (\Throwable $throwable) {
            if ($this->isLockLost($throwable)) {
                throw $throwable; // another worker owns the job: leave it and its files alone
            }
            if (!empty($token)) {
                delete_transient(self::JOB_PREFIX . $token);
            }
            // Only the owner's failure may delete the job's temp files.
            if ($ownedJob) {
                $this->cleanupJobFiles($ownedJob);
            }

            throw $throwable;
        }
    }

    /**
     * Direct .xlsx upload (not via the Media Library): validate the $_FILES entry,
     * move it into the protected work dir, and start the same Excel job as init().
     *
     * @param array  $file one $_FILES entry (name, tmp_name, error, size)
     * @param string $renderEngine
     */
    public function initFromUpload(array $file, $renderEngine)
    {
        $error = (int)Arr::get($file, 'error', UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => ['message' => sprintf(
                /* translators: %s: maximum upload size, e.g. "8 MB" */
                __('This file is larger than the server upload limit (%s). Ask your host to raise it, or export the sheet as CSV.', 'ninja-tables'),
                size_format(wp_max_upload_size())
            )]], 422);
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ($error !== UPLOAD_ERR_OK) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => ['message' => __('Invalid file upload.', 'ninja-tables')]], 423);
        }

        $fileName  = sanitize_file_name(basename((string)Arr::get($file, 'name')));
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension === 'xls') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => ['message' => __('Old Excel .xls files are not supported. Re-save the file as .xlsx or export it as CSV.', 'ninja-tables')]], 422);
        }
        if ($extension !== 'xlsx') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => ['message' => __('Please upload an Excel (.xlsx) file.', 'ninja-tables')]], 422);
        }

        // Same guard as the other importers: only a file PHP itself received in this request.
        $tmpName = (string)Arr::get($file, 'tmp_name');
        if (!$tmpName || !is_uploaded_file($tmpName)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => ['message' => __('Invalid file upload.', 'ninja-tables')]], 423);
        }

        $workDir = $this->workDir();
        XlsxToCsvConverter::ensureWorkDir($workDir);
        XlsxToCsvConverter::sweepStale($workDir, $this->getImportJobTtl());

        // Store it with WordPress's upload API (it re-checks is_uploaded_file() and moves the
        // file itself), steered into our private work dir under a random name.
        $stagedName = bin2hex(random_bytes(16)) . self::UPLOAD_SUFFIX;
        $toWorkDir  = function ($dirs) use ($workDir) {
            $dirs['path']   = untrailingslashit($workDir);
            $dirs['url']    = '';
            $dirs['subdir'] = '';
            $dirs['error']  = false;
            return $dirs;
        };
        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        add_filter('upload_dir', $toWorkDir);
        try {
            $stored = wp_handle_upload($file, [
                'test_form'                => false,
                'mimes'                    => ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                'unique_filename_callback' => function () use ($stagedName) {
                    return $stagedName;
                },
            ]);
        } finally {
            remove_filter('upload_dir', $toWorkDir);
        }
        if (!is_array($stored) || !empty($stored['error']) || empty($stored['file'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => ['message' => __('Could not store the uploaded file. Please try again.', 'ninja-tables')]], 500);
        }
        $staged = $stored['file'];

        return $this->initStagedUpload($staged, $fileName, $renderEngine);
    }

    /**
     * Start an Excel job from an upload already staged in the work dir. The
     * staged file is deleted once the converter has opened it (or failed to):
     * open() extracts everything later slices need.
     */
    public function initStagedUpload($stagedPath, $fileName, $renderEngine)
    {
        $realPath = realpath((string)$stagedPath);
        $realDir  = realpath($this->workDir());
        $suffix   = self::UPLOAD_SUFFIX;
        if (
            !$realPath || !$realDir
            || strpos($realPath, trailingslashit($realDir)) !== 0
            || substr($realPath, -strlen($suffix)) !== $suffix
        ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException(['data' => ['message' => __('Uploaded file path is not valid.', 'ninja-tables')]], 422);
        }

        $renderEngine = in_array($renderEngine, ['footable', 'datatables'], true) ? $renderEngine : 'footable';

        try {
            return $this->initXlsx(0, $realPath, sanitize_file_name((string)$fileName), $renderEngine);
        } finally {
            @unlink($realPath); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- staged copy in our own work dir
        }
    }

    /**
     * Start an Excel job: validate and stage the workbook. The table is only
     * created once conversion finishes, so a failed conversion leaves no empty table.
     */
    private function initXlsx($attachmentId, $filePath, $fileName, $renderEngine)
    {
        $workDir = $this->workDir();
        XlsxToCsvConverter::ensureWorkDir($workDir);

        $conversion = (new XlsxToCsvConverter())->open($filePath, $workDir);

        $token = wp_generate_password(32, false, false);
        $job   = [
            'owner_id'       => get_current_user_id(),
            'attachment_id'  => (int)$attachmentId,
            'file_path'      => '',
            'file_name'      => $fileName,
            'table_id'       => 0,
            'header_keys'    => [],
            'render_engine'  => $renderEngine,
            'do_unicode'     => 'no', // .xlsx text is always UTF-8
            'processed_rows' => 0,
            'total_rows'     => (int)$conversion['estimated_rows'],
            'cursor'         => 0,
            'created_at'     => time(),
            'phase'          => 'converting',
            'conversion'     => $conversion,
            'csv_escape'     => XlsxToCsvConverter::CSV_ESCAPE,
        ];

        if (!set_transient(self::JOB_PREFIX . $token, $job, $this->getImportJobTtl())) {
            XlsxToCsvConverter::cleanup($conversion);
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException([
                'data' => ['message' => __('Could not start the import. Please try again.', 'ninja-tables')]
            ], 500);
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        return [
            'message' => __('Excel file selected. Conversion has started.', 'ninja-tables'),
            'data'    => [
                'token'      => $token,
                'tableId'    => 0,
                'total_rows' => (int)$conversion['estimated_rows'],
                'phase'      => 'converting',
                'progress'   => 0
            ]
        ];
    }

    /**
     * One conversion slice. Contract with the converter: save the returned
     * state first, and only then release the inputs it no longer needs.
     */
    private function convertChunk($token, array $job)
    {
        $conversion = $job['conversion'];
        $this->keepJobFilesFresh($job);

        $sliceRows    = max(100, (int)apply_filters('ninja_tables_xlsx_convert_slice_rows', 20000));
        $sliceSeconds = max(1, (int)apply_filters('ninja_tables_xlsx_convert_slice_seconds', 20));

        $this->assertStillLocked(); // fencing: never touch the job's files without the lock
        $conversion        = (new XlsxToCsvConverter())->convertSlice($conversion, $sliceRows, $sliceSeconds);
        $job['conversion'] = $conversion;
        $createdTableId    = 0;

        if ($conversion['phase'] === 'done') {
            $this->assertStillLocked(); // fencing: never create the table without the lock
            $header  = ninja_table_format_header($conversion['header']);
            $tableId = $this->tables->createTable([
                'post_title'   => Arr::get($job, 'file_name'),
                'post_content' => '',
                'post_type'    => self::CPT,
                'post_status'  => 'publish'
            ]);
            $this->tables->storeTableConfigWhenImporting($tableId, $header, $job['render_engine'], 'ajax_table');
            $createdTableId = (int)$tableId;

            $job['phase']          = 'importing';
            $job['table_id']       = $createdTableId;
            $job['header_keys']    = array_keys($header);
            $job['file_path']      = $conversion['files']['csv'];
            $job['total_rows']     = (int)$conversion['rows_written'];
            $job['processed_rows'] = 0;
            $job['cursor']         = 0; // the converted CSV has no header line
        }

        try {
            $this->assertStillLocked(); // fencing: never publish a checkpoint without the lock
        } catch (ImportException $lost) {
            if ($createdTableId) {
                wp_delete_post($createdTableId, true); // the new owner will create its own
            }
            throw $lost;
        }

        if (!set_transient(self::JOB_PREFIX . $token, $job, $this->getImportJobTtl())) {
            if ($createdTableId) {
                wp_delete_post($createdTableId, true);
            }
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException([
                'data' => ['message' => __('Could not save import progress. Please try again.', 'ninja-tables')]
            ], 500);
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        XlsxToCsvConverter::releaseCompletedInputs($conversion);

        $converting = $job['phase'] === 'converting';

        return [
            'message' => $converting
                ? __('Converting Excel file.', 'ninja-tables')
                : __('Excel file converted. Import has started.', 'ninja-tables'),
            'data'    => [
                'completed'      => false,
                'tableId'        => (int)$job['table_id'],
                'processed_rows' => $converting ? (int)$conversion['rows_written'] : 0,
                'total_rows'     => $converting
                    ? max((int)$conversion['estimated_rows'], (int)$conversion['rows_written'])
                    : (int)$job['total_rows'],
                'phase'          => $job['phase'],
                'progress'       => $converting ? (int)floor(XlsxToCsvConverter::progress($conversion) * 100) : 0
            ]
        ];
    }

    /**
     * Per-job lock tied to the worker's lifetime: a MySQL named lock belongs to
     * this request's database connection, so MySQL releases it the moment the
     * worker ends or dies — no timeout has to guess whether a slow worker is
     * still running, so a live worker's write can never be handed to another.
     *
     * There is deliberately no time-based fallback: an expiring lease could pass
     * the job to a second worker while the first is still inside a row insert.
     * A server without named locks gets a clear error and the job is kept.
     *
     * @return array|null ['name' => ...] to release with, or null when a live worker holds it
     */
    private function acquireJobLock($token)
    {
        global $wpdb;

        $name = self::jobLockName($token);
        $got  = $this->lockQuery($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name), true);
        if ((string)$got === '1') {
            return ['name' => $name];
        }
        if ((string)$got === '0') {
            return null; // a live worker holds it
        }

        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
        throw new ImportException([
            'data' => [
                'message' => __('This import could not continue: the database server does not support the locking it needs (MySQL GET_LOCK). Please ask your host, or import the file as smaller parts.', 'ninja-tables'),
                'code'    => 'lock_unsupported'
            ]
        ], 500);
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    /**
     * MySQL named locks are server-wide (shared by every database on the server),
     * so the name includes this site's database and table prefix. <= 64 chars.
     */
    public static function jobLockName($token)
    {
        global $wpdb;

        return 'nt_imp_' . md5(DB_NAME . '|' . $wpdb->prefix . '|' . $token);
    }

    /**
     * Read the job fresh from the database after the lock is taken. Without a
     * persistent object cache WordPress keeps each option read in a per-process
     * cache, so a long-running process (WP-CLI, a worker loop) could otherwise
     * act on a checkpoint another request has already moved past. With a
     * persistent cache the transient lives in that shared cache, which the
     * previous lock holder already updated, so it is read as-is (deleting it
     * there would delete the job).
     */
    private function loadJobUnderLock($token)
    {
        if (!wp_using_ext_object_cache()) {
            wp_cache_delete('_transient_' . self::JOB_PREFIX . $token, 'options');
            wp_cache_delete('_transient_timeout_' . self::JOB_PREFIX . $token, 'options');
        }

        return get_transient(self::JOB_PREFIX . $token);
    }

    private function releaseJobLock($token, array $lock)
    {
        global $wpdb;

        // If this fails the lock still ends with the connection, at the end of the request.
        $this->lockQuery($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock['name']), true);
    }

    /**
     * Does this request still hold its job lock? A named lock can only be lost if
     * the database connection dropped (wpdb then reconnects without it).
     */
    private function ownsJobLock()
    {
        global $wpdb;

        $lock = $this->activeLock;
        if (!$lock) {
            return false;
        }

        return (string)$this->lockQuery($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $lock['name']), true) === '1';
    }

    /**
     * Fencing: called right before every write (job files, table creation, row
     * inserts, checkpoint). A request that lost its lock stops before writing.
     */
    private function assertStillLocked()
    {
        if (!$this->ownsJobLock()) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException([
                'data' => [
                    'message' => __('Another request took over this import. Retrying.', 'ninja-tables'),
                    'code'    => 'lock_lost'
                ]
            ], 409);
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    private function isLockLost($throwable)
    {
        return $throwable instanceof ImportException
            && Arr::get($throwable->getPayload(), 'data.code') === 'lock_lost';
    }

    /**
     * Run a lock statement without printing DB errors (e.g. GET_LOCK unsupported):
     * with WP_DEBUG display on, wpdb would print them into the JSON response.
     * Callers check the result.
     *
     * @return int|string|false|null rows affected (or the value when $getVar), false on error
     */
    private function lockQuery($sql, $getVar = false)
    {
        global $wpdb;

        $suppressed = $wpdb->suppress_errors(true);
        try {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared by callers; named locks have no WordPress API
            return $getVar ? $wpdb->get_var($sql) : $wpdb->query($sql);
        } finally {
            $wpdb->suppress_errors($suppressed);
        }
    }

    /**
     * Progress for a job another request is processing right now. Only the owner
     * may see it; nothing is changed or deleted.
     */
    private function busyResponse($token)
    {
        $job = get_transient(self::JOB_PREFIX . $token);
        if (!$job || !is_array($job)) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException([
                'data' => ['message' => __('Import session expired. Please start again.', 'ninja-tables')]
            ], 410);
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        if ((int)Arr::get($job, 'owner_id') !== get_current_user_id()) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
            throw new ImportException([
                'data' => ['message' => __('You are not allowed to process this import job.', 'ninja-tables')]
            ], 403);
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $converting = Arr::get($job, 'phase') === 'converting';
        $conversion = $converting ? (array)Arr::get($job, 'conversion', []) : [];

        return [
            'message' => __('Another request is still processing this import. Retrying.', 'ninja-tables'),
            'data'    => [
                'completed'      => false,
                'busy'           => true,
                'tableId'        => (int)Arr::get($job, 'table_id'),
                'processed_rows' => $converting ? (int)Arr::get($conversion, 'rows_written') : (int)Arr::get($job, 'processed_rows'),
                'total_rows'     => $converting
                    ? max((int)Arr::get($conversion, 'estimated_rows'), (int)Arr::get($conversion, 'rows_written'))
                    : (int)Arr::get($job, 'total_rows'),
                'phase'          => $converting ? 'converting' : 'importing',
                'progress'       => $converting ? (int)floor(XlsxToCsvConverter::progress($conversion) * 100) : 0
            ]
        ];
    }

    private function endJob($token, array $job)
    {
        delete_transient(self::JOB_PREFIX . $token);
        $this->cleanupJobFiles($job);
    }

    private function cleanupJobFiles(array $job)
    {
        if (!empty($job['conversion']) && is_array($job['conversion'])) {
            XlsxToCsvConverter::cleanup($job['conversion']);
        }
    }

    /**
     * sweepStale() goes by mtime, and an active job only reads its files, so
     * refresh them on every request to keep a live job from being swept.
     */
    private function keepJobFilesFresh(array $job)
    {
        if (empty($job['conversion']['files']) || !is_array($job['conversion']['files'])) {
            return;
        }

        foreach ($job['conversion']['files'] as $path) {
            if ($path && is_file($path)) {
                @touch($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- best effort
            }
        }
    }

    private function workDir()
    {
        return trailingslashit((string)Arr::get(wp_upload_dir(), 'basedir', '')) . self::WORK_DIR;
    }

    private function resolveCsvImportSource(array $input)
    {
        $attachmentId = intval(Arr::get($input, 'attachment_id', 0));
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
            if ($extension === 'xls') {
                return new \WP_Error('unsupported_xls', __('Old Excel .xls files are not supported. Re-save the file as .xlsx or export it as CSV.', 'ninja-tables'));
            }
            if (!in_array($extension, ['csv', 'xlsx'], true)) {
                return new \WP_Error('invalid_attachment_type', __('Selected media file must be a CSV or Excel (.xlsx) file.', 'ninja-tables'));
            }

            $allowedMimes   = $extension === 'xlsx'
                ? self::XLSX_MIMES
                : ['text/csv', 'application/csv', 'text/plain', 'application/vnd.ms-excel'];
            $attachmentMime = (string)get_post_mime_type($attachmentId);
            $checkedMime    = (string)Arr::get(wp_check_filetype($fileName), 'type');
            if (
                ($attachmentMime && !in_array($attachmentMime, $allowedMimes, true)) &&
                ($checkedMime && !in_array($checkedMime, $allowedMimes, true))
            ) {
                return new \WP_Error('invalid_attachment_mime', $extension === 'xlsx'
                    ? __('Selected media file is not a valid Excel (.xlsx) file.', 'ninja-tables')
                    : __('Selected media file is not a valid CSV type.', 'ninja-tables'));
            }

            return [
                'attachment_id' => $attachmentId,
                'file_path'     => $filePath,
                'file_name'     => $fileName,
                'type'          => $extension
            ];
        }

        return new \WP_Error(
            'missing_media_attachment',
            __('Please select a CSV or Excel (.xlsx) file from WordPress Media Library for large import.', 'ninja-tables')
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

        $header = fgetcsv($handle, 0, ',', '"', Csv::ESCAPE);
        if (!is_array($header) || empty($header)) {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            return new \WP_Error('csv_header_failed', __('Unable to read CSV header row.', 'ninja-tables'));
        }

        $cursorAfterHeader = ftell($handle);
        $totalRows         = 0;

        while (($row = fgetcsv($handle, 0, ',', '"', Csv::ESCAPE)) !== false) {
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

    /**
     * @param int $maxBytes stop once this many CSV bytes are read (0 = rows only); always reads at least one row
     */
    private function readCsvChunk($filePath, $cursor, $chunkSize, $escape = Csv::ESCAPE, $maxBytes = 0)
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

        $rows  = [];
        $start = (int)$cursor;
        while (count($rows) < $chunkSize && ($row = fgetcsv($handle, 0, ',', '"', $escape)) !== false) {
            if ($this->isCsvRowEmpty($row)) {
                continue;
            }

            $rows[] = $row;

            if ($maxBytes > 0 && ftell($handle) - $start >= $maxBytes) {
                break;
            }
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
