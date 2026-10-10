<?php

namespace NinjaTables\App\Http\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Services\Import\CsvChunkImporter;
use NinjaTables\App\Services\Import\ImportException;
use NinjaTables\App\Services\Import\TableBuilderImporter;
use NinjaTables\App\Services\Import\TableImporter;
use NinjaTables\App\Services\Import\ThirdPartyImporter;
use NinjaTables\Framework\Http\Request\Request;
use NinjaTables\Framework\Support\Arr;
use NinjaTables\Framework\Support\Sanitizer;

class ImportController extends Controller
{
    public function tableBuilderImport(Request $request)
    {
        return $this->importTableBuilder($request->all());
    }

    public function defaultImport(Request $request)
    {
        try {
            $input        = $request->all();
            $format       = Sanitizer::sanitizeTextField(Arr::get($input, 'format'));
            $doUnicode    = Sanitizer::sanitizeTextField(Arr::get($input, 'do_unicode'));
            $renderEngine = Sanitizer::sanitizeTextField(Arr::get($input, 'render_engine', 'footable'));

            if ($format == 'dragAndDrop') {
                return $this->importTableBuilder($input);
            }

            if (!isset($_FILES['file'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                return $this->sendError([
                    'errors'  => array(),
                    'message' => __('Please upload a file.', 'ninja-tables')
                ], 423);
            }

            // Excel goes through the chunked importer at every size, so one code path
            // covers a 200-row sheet and a 5-lakh-row one; /process drives the rest.
            if ($format === 'excel') {
                return $this->json(
                    (new CsvChunkImporter())->initFromUpload((array)$_FILES['file'], $renderEngine), // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REST nonce; validated in initFromUpload()
                    200
                );
            }

            $fileName = Sanitizer::sanitizeTextField(
                Arr::get($_FILES, 'file.name') // phpcs:ignore WordPress.Security.NonceVerification.Missing
            );

            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $importer      = new TableImporter();

            if ($fileExtension === 'csv' && $format == 'csv') {
                return $this->json($importer->fromCsv($doUnicode, $renderEngine), 200);
            } elseif ($fileExtension === 'json' && $format == 'json') {
                return $this->json($importer->fromJson($renderEngine), 200);
            } elseif ($fileExtension === 'json' && $format == 'ninjaJson') {
                return $this->json($importer->fromNinjaJson($renderEngine), 200);
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
            return $this->json((new CsvChunkImporter())->init($request->all()), 200);
        } catch (\Throwable $throwable) {
            return $this->sendImportExceptionResponse($throwable);
        }
    }

    public function processDefaultCsvImportChunk(Request $request)
    {
        try {
            return $this->json((new CsvChunkImporter())->processChunk($request->all()), 200);
        } catch (\Throwable $throwable) {
            return $this->sendImportExceptionResponse($throwable);
        }
    }

    public function getTablesFromOtherPlugin(Request $request)
    {
        $plugin  = Sanitizer::sanitizeTextField(Arr::get($request->all(), 'plugin'));
        $adapter = ThirdPartyImporter::adapter($plugin);

        if (!$adapter) {
            return false;
        }

        $this->json([
            'tables' => $adapter->getTables()
        ], 200);
    }

    public function importTableFromOtherPlugin(Request $request)
    {
        $plugin  = Sanitizer::sanitizeTextField(Arr::get($request->all(), 'plugin'));
        $tableId = intval(Arr::get($request->all(), 'tableId'));

        if ($plugin == 'TablePress' && get_post_type($tableId) !== 'tablepress_table') {
            return $this->sendError([
                'data' => ['message' => __('Invalid table.', 'ninja-tables')]
            ], 423);
        }

        $adapter = ThirdPartyImporter::adapter($plugin);

        if (!$adapter) {
            return false;
        }

        $tableId = $adapter->migrateTable($tableId);
        if (is_wp_error($tableId)) {
            return $this->sendError([
                'data' => [
                    'message' => __('Something Went Wrong When Migrating', 'ninja-tables')
                ]
            ], 423);
        }

        return $this->sendSuccess([
            'data' => [
                'message' => __(
                    'Successfully imported. Please go to all tables and review your newly imported table.',
                    'ninja-tables'
                ),
                'tableId' => $tableId
            ]
        ], 200);
    }

    public function uploadCsvInExistingTable(Request $request)
    {
        try {
            return $this->json((new TableImporter())->appendCsv($request->all()));
        } catch (\Throwable $throwable) {
            return $this->sendImportExceptionResponse($throwable);
        }
    }

    private function importTableBuilder(array $input)
    {
        $tableId = (new TableBuilderImporter())->import($input);

        return $this->sendSuccess([
            'data' => [
                'id' => $tableId
            ]
        ], 200);
    }

    private function sendImportExceptionResponse(\Throwable $throwable)
    {
        if ($throwable instanceof ImportException) {
            return $throwable->isPlainJson()
                ? $this->json($throwable->getPayload(), $throwable->getStatus())
                : $this->sendError($throwable->getPayload(), $throwable->getStatus());
        }

        return $this->sendError([
            'data' => [
                'message' => $this->getUserFriendlyImportError($throwable),
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
}
