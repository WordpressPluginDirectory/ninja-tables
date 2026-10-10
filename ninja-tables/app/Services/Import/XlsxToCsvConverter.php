<?php

namespace NinjaTables\App\Services\Import;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Streams the first worksheet of an .xlsx file into a CSV, in resumable slices.
 *
 * Built for 3-5 lakh row workbooks on shared hosting: nothing ever holds the
 * whole sheet or the whole shared-strings table in memory, and every slice
 * resumes from a byte offset saved in the job state, so a long conversion is
 * split across many short requests instead of one that hits max_execution_time.
 *
 * Phases, all driven by convertSlice():
 *   open()  — validate the zip, resolve the first sheet, stream the needed XML
 *             entries to the work dir, read date styles. I/O only, no row parse.
 *   'sst'   — index sharedStrings.xml into a flat strings file + offset index.
 *   'rows'  — convert <row> fragments to CSV lines; first non-empty row is the header.
 *   'done'  — CSV (data rows, no header) is complete.
 *
 * convertSlice() never deletes a phase's inputs: a caller that loses the state
 * a slice returned must be able to retry from its last saved checkpoint. After
 * durably saving a state, call releaseCompletedInputs() to free what it no longer needs.
 *
 * Pure PHP (ZipArchive + XMLReader), no WordPress or DB calls, so it is unit-testable.
 */
class XlsxToCsvConverter
{
    const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    const REL_NS = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'http://purl.oclc.org/ooxml/officeDocument/relationships',
    ];

    /**
     * The temp CSV is plain RFC 4180: quotes doubled, no backslash escape. PHP's
     * default backslash escape corrupts any cell ending in one (C:\path\),
     * so whoever reads this CSV must pass this same escape to fgetcsv().
     */
    const CSV_ESCAPE = '';

    // Temp files share this suffix so sweepStale() never touches anything else in the dir.
    const TEMP_SUFFIX = '.nttmp';

    // Excel's last column is XFD. Anything beyond is malformed and, unchecked,
    // would make buildHeader() allocate an array sized by an attacker's cell ref.
    const MAX_COLUMNS = 16384;

    // Offsets in the shared-strings index are 32-bit, so the strings file must stay under 4 GB.
    const SST_MAX_BYTES = 4294967295;

    const BUILTIN_DATE_FORMATS = [
        14 => 'date', 15 => 'date', 16 => 'date', 17 => 'date',
        18 => 'time', 19 => 'time', 20 => 'time', 21 => 'time',
        22 => 'datetime',
        27 => 'date', 28 => 'date', 29 => 'date', 30 => 'date', 31 => 'date',
        32 => 'time', 33 => 'time', 34 => 'time', 35 => 'time', 36 => 'date',
        45 => 'time', 46 => 'time', 47 => 'time',
        50 => 'date', 51 => 'date', 52 => 'date', 53 => 'date', 54 => 'date',
        55 => 'date', 56 => 'date', 57 => 'date', 58 => 'date',
    ];

    protected $limits;

    // Per-slice shared-string lookup state; every cache is bounded so memory
    // stays flat however many unique strings the workbook has.
    protected $sstIndexHandle = null;
    protected $sstHandle = null;
    protected $sstCount = 0;
    protected $sstBlocks = [];
    protected $sstCache = [];
    protected $sstCacheBytes = 0;

    public function __construct(array $limits = [])
    {
        $this->limits = array_merge([
            'max_entries'            => 10000,
            'max_uncompressed_bytes' => 2147483648, // 2 GB of sheet + shared strings + styles
            'max_meta_bytes'         => 20971520,   // 20 MB cap for workbook.xml / rels read into memory
            'read_buffer_bytes'      => 1048576,
            // One <row>/<si> fragment is copied ~4x while parsed; 8 MB keeps that well under 128 MB.
            'max_fragment_bytes'     => 8388608,
            // Resolved text of one row: shared-string refs are a few bytes of XML each
            // but can expand to 32 KB+, so the fragment cap alone doesn't bound a row.
            'max_row_bytes'          => 8388608,
            'sst_cache_entries'      => 20000,
            'sst_cache_bytes'        => 4194304,
            'sst_index_block_bytes'  => 8192, // multiple of 4: 2048 offsets per block
            'sst_index_blocks'       => 64,   // => at most 512 KB of index in memory
        ], $limits);
    }

    public static function isSupported()
    {
        return class_exists('ZipArchive') && class_exists('XMLReader');
    }

    /**
     * Create the temp dir with web-access guards. Safe to call on every request.
     */
    public static function ensureWorkDir($dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- race-safe mkdir; pure-PHP service
            static::fail('xlsx_workdir', __('Could not create the temporary import folder.', 'ninja-tables'));
        }

        $guards = [
            'index.php' => "<?php\n// Silence is golden\n",
            '.htaccess' => "Require all denied\nDeny from all\n",
        ];
        foreach ($guards as $name => $content) {
            if (!file_exists($dir . '/' . $name)) {
                file_put_contents($dir . '/' . $name, $content); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- pure-PHP service, no WP_Filesystem in unit context
            }
        }
    }

    /**
     * Delete this converter's temp files older than $ttlSeconds. A transient
     * expiring runs no code and a fatal timeout never reaches a catch block,
     * so this sweep is what actually bounds leftover temp files.
     */
    public static function sweepStale($dir, $ttlSeconds)
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $deleted = 0;
        $cutoff  = time() - max(0, (int)$ttlSeconds);
        foreach ((array)glob(rtrim($dir, '/') . '/*' . self::TEMP_SUFFIX) as $file) {
            if (is_file($file) && filemtime($file) < $cutoff && @unlink($file)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- best-effort cleanup
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Delete every temp file the job created. Pass $keepCsv while the CSV is still being imported.
     */
    public static function cleanup(array $state, $keepCsv = false)
    {
        foreach ((array)($state['files'] ?? []) as $key => $path) {
            if ($keepCsv && $key === 'csv') {
                continue;
            }
            if ($path && is_file($path)) {
                @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- best-effort cleanup
            }
        }
    }

    /**
     * Delete inputs that phases before $state['phase'] needed. Call only after
     * $state is durably saved: until then a retry from the previous checkpoint
     * still reads them. On 'done' only the CSV is left.
     */
    public static function releaseCompletedInputs(array $state)
    {
        $phase = $state['phase'] ?? '';
        $drop  = [];
        if ($phase === 'rows' || $phase === 'done') {
            $drop[] = 'sst_xml';
        }
        if ($phase === 'done') {
            array_push($drop, 'sheet', 'sst_data', 'sst_index');
        }

        foreach ($drop as $key) {
            $path = $state['files'][$key] ?? '';
            if ($path && is_file($path)) {
                @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- best-effort cleanup
            }
        }
    }

    /**
     * Validate the workbook and stage its XML in $workDir. Returns the job state
     * that every later convertSlice() call takes and returns.
     */
    public function open($xlsxPath, $workDir)
    {
        $prevErrors = libxml_use_internal_errors(true);
        try {
            return $this->doOpen($xlsxPath, $workDir);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevErrors);
        }
    }

    protected function doOpen($xlsxPath, $workDir)
    {
        if (!static::isSupported()) {
            static::fail('xlsx_unsupported', __('Excel import needs the PHP Zip and XMLReader extensions. Please export the sheet as CSV and import that instead.', 'ninja-tables'));
        }

        $zip = new \ZipArchive();
        if (!is_file($xlsxPath) || $zip->open($xlsxPath) !== true) {
            static::fail('xlsx_invalid', __('This is not a valid .xlsx file. Password-protected and old .xls files are not supported; re-save it as .xlsx or export CSV.', 'ninja-tables'));
        }

        $zipOpen = true;
        $token   = bin2hex(random_bytes(16));
        $base  = rtrim($workDir, '/') . '/' . $token;
        $files = [
            'sheet'      => $base . '-sheet' . self::TEMP_SUFFIX,
            'sst_xml'    => $base . '-sst-xml' . self::TEMP_SUFFIX,
            'sst_data'   => $base . '-sst-data' . self::TEMP_SUFFIX,
            'sst_index'  => $base . '-sst-index' . self::TEMP_SUFFIX,
            'csv'        => $base . '-csv' . self::TEMP_SUFFIX,
        ];

        try {
            $this->assertZipShape($zip);

            $workbookXml = $this->readSmallEntry($zip, 'xl/workbook.xml');
            $workbook    = $this->parseWorkbook($workbookXml);
            $rels        = $this->parseRels($zip, 'xl/_rels/workbook.xml.rels');

            $sheetPath = $this->resolveFirstSheet($workbook['sheet_rids'], $rels);
            if (!$sheetPath || $zip->locateName($sheetPath) === false) {
                static::fail('xlsx_no_sheet', __('The workbook has no worksheet to import.', 'ninja-tables'));
            }

            $sstPath    = $this->resolveRelTarget($rels, 'sharedStrings', 'xl/sharedStrings.xml', $zip);
            $stylesPath = $this->resolveRelTarget($rels, 'styles', 'xl/styles.xml', $zip);

            $this->assertUncompressedBudget($zip, array_filter([$sheetPath, $sstPath, $stylesPath]), $workDir);

            $this->extractEntry($zip, $sheetPath, $files['sheet']);
            if ($sstPath) {
                $this->extractEntry($zip, $sstPath, $files['sst_xml']);
            } else {
                unset($files['sst_xml']);
            }

            $dateStyles = [];
            if ($stylesPath) {
                $dateStyles = $this->parseDateStyles($this->readSmallEntry($zip, $stylesPath));
            }

            $zip->close();
            $zipOpen = false;

            $sheetHead = $this->readHead($files['sheet']);

            touch($files['csv']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- temp file in our own work dir
            if ($sstPath) {
                touch($files['sst_data']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
                touch($files['sst_index']); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
            } else {
                unset($files['sst_data'], $files['sst_index']);
            }

            return [
                'phase'          => $sstPath ? 'sst' : 'rows',
                'files'          => $files,
                'date_styles'    => $dateStyles,
                'date1904'       => $workbook['date1904'],
                'sheet_ns'       => $this->rootNamespaces($sheetHead, 'worksheet'),
                'sheet_size'     => (int)filesize($files['sheet']),
                'sheet_offset'   => 0,
                'estimated_rows' => $this->dimensionRows($sheetHead),
                'sst_ns'         => $sstPath ? $this->rootNamespaces($this->readHead($files['sst_xml']), 'sst') : '',
                'sst_size'       => $sstPath ? (int)filesize($files['sst_xml']) : 0,
                'sst_offset'     => 0,
                'sst_count'      => 0,
                'sst_data_size'  => 0,
                'header'         => [],
                'rows_written'   => 0,
                'csv_size'       => 0,
            ];
        } catch (\Throwable $e) {
            if ($zipOpen) {
                $zip->close();
            }
            static::cleanup(['files' => $files]);
            throw $e;
        }
    }

    /**
     * Do one bounded unit of work and return the new state. Call until
     * $state['phase'] === 'done'. Stops after $maxRows rows (or shared strings)
     * or $maxSeconds wall time, whichever comes first.
     */
    public function convertSlice(array $state, $maxRows = 20000, $maxSeconds = 0)
    {
        $deadline = $maxSeconds > 0 ? microtime(true) + $maxSeconds : 0;
        $maxRows  = max(1, (int)$maxRows);

        // Malformed XML must become a clean import error, not PHP warnings.
        $prevErrors = libxml_use_internal_errors(true);
        try {
            if ($state['phase'] === 'sst') {
                return $this->sstSlice($state, $maxRows, $deadline);
            }

            if ($state['phase'] === 'rows') {
                return $this->rowsSlice($state, $maxRows, $deadline);
            }

            return $state;
        } catch (\Throwable $e) {
            // A failed job can't be resumed, so drop its temp files now.
            static::cleanup($state);
            throw $e;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prevErrors);
        }
    }

    /**
     * 0..1 progress across both phases, weighted by bytes scanned.
     */
    public static function progress(array $state)
    {
        if ($state['phase'] === 'done') {
            return 1.0;
        }

        $sheetSize = max(1, (int)$state['sheet_size']);
        $sstSize   = (int)$state['sst_size'];

        $done = ($state['phase'] === 'rows' ? $sstSize : (int)$state['sst_offset']) + (int)$state['sheet_offset'];

        return min(1.0, $done / ($sheetSize + $sstSize));
    }

    /* ------------------------------------------------------------------ */
    /* Shared strings phase                                                */
    /* ------------------------------------------------------------------ */

    protected function sstSlice(array $state, $maxRows, $deadline)
    {
        $data  = $this->openFile($state['files']['sst_data'], 'c+b');
        $index = $this->openFile($state['files']['sst_index'], 'c+b');

        $dataSize = (int)$state['sst_data_size'];
        $count    = (int)$state['sst_count'];
        $indexBuf = '';

        try {
            // Drop anything a crashed earlier slice wrote past the last saved state.
            $this->resetTo($data, $dataSize);
            $this->resetTo($index, $count * 4);

            $scan = $this->scanFragments($state['files']['sst_xml'], (int)$state['sst_offset'], 'si', $maxRows, $deadline,
                function ($fragment) use (&$dataSize, &$count, &$indexBuf, $data, $state) {
                    $text = $this->fragmentText($fragment, $state['sst_ns']);
                    if ($dataSize + strlen($text) > self::SST_MAX_BYTES) {
                        static::fail('xlsx_too_large', __('The workbook text is too large to import.', 'ninja-tables'));
                    }
                    $indexBuf .= pack('N', $dataSize);
                    $this->writeAll($data, $text);
                    $dataSize += strlen($text);
                    $count++;
                }
            );

            if ($scan['eof']) {
                // Sentinel end offset so string i is always [index[i], index[i+1]).
                $indexBuf .= pack('N', $dataSize);
            }
            $this->writeAll($index, $indexBuf);

            // Never checkpoint offsets the files don't actually hold.
            $this->assertSize($data, $dataSize);
            $this->assertSize($index, ($count + ($scan['eof'] ? 1 : 0)) * 4);
        } finally {
            fclose($data); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($index); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }

        $state['sst_offset']    = $scan['offset'];
        $state['sst_count']     = $count;
        $state['sst_data_size'] = $dataSize;

        if ($scan['eof']) {
            // sst_xml stays until releaseCompletedInputs(): a retry from the saved 'sst' state re-reads it.
            $state['phase'] = 'rows';
        }

        return $state;
    }

    protected function sharedString($i)
    {
        if (isset($this->sstCache[$i])) {
            return $this->sstCache[$i];
        }

        if (!$this->sstIndexHandle || $i < 0 || $i >= $this->sstCount) {
            return '';
        }

        // String i spans [offset(i), offset(i+1)); the index ends with a sentinel offset.
        $start = $this->sstIndexEntry($i);
        $end   = $this->sstIndexEntry($i + 1);
        if ($start === null || $end === null || $end <= $start) {
            return '';
        }

        fseek($this->sstHandle, $start);
        $value  = (string)fread($this->sstHandle, $end - $start); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $length = strlen($value);

        if (count($this->sstCache) >= $this->limits['sst_cache_entries']
            || $this->sstCacheBytes + $length > $this->limits['sst_cache_bytes']) {
            $this->sstCache      = [];
            $this->sstCacheBytes = 0;
        }

        if ($length <= $this->limits['sst_cache_bytes']) {
            $this->sstCache[$i]   = $value;
            $this->sstCacheBytes += $length;
        }

        return $value;
    }

    /**
     * Read one 4-byte offset from the on-disk index through a small block cache.
     */
    protected function sstIndexEntry($k)
    {
        $blockBytes = max(4, (int)$this->limits['sst_index_block_bytes']);
        $byte       = $k * 4;
        $block      = intdiv($byte, $blockBytes);

        if (!isset($this->sstBlocks[$block])) {
            if (count($this->sstBlocks) >= max(1, (int)$this->limits['sst_index_blocks'])) {
                $this->sstBlocks = [];
            }
            fseek($this->sstIndexHandle, $block * $blockBytes);
            $this->sstBlocks[$block] = (string)fread($this->sstIndexHandle, $blockBytes); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        }

        $raw = substr($this->sstBlocks[$block], $byte - $block * $blockBytes, 4);

        return strlen($raw) === 4 ? unpack('N', $raw)[1] : null;
    }

    /* ------------------------------------------------------------------ */
    /* Rows phase                                                          */
    /* ------------------------------------------------------------------ */

    protected function rowsSlice(array $state, $maxRows, $deadline)
    {
        $files = $state['files'];

        $this->sstCache      = [];
        $this->sstCacheBytes = 0;
        $this->sstBlocks     = [];
        $this->sstCount      = (int)$state['sst_count'];
        if (!empty($files['sst_index'])) {
            $this->sstIndexHandle = $this->openFile($files['sst_index'], 'rb');
            $this->sstHandle      = $this->openFile($files['sst_data'], 'rb');
        }

        $csv     = $this->openFile($files['csv'], 'c+b');
        $line    = $this->openFile('php://memory', 'w+b');
        $csvSize = (int)$state['csv_size'];
        $header  = $state['header'];
        $written = (int)$state['rows_written'];

        try {
            $this->resetTo($csv, $csvSize);

            $scan = $this->scanFragments($files['sheet'], (int)$state['sheet_offset'], 'row', $maxRows, $deadline,
                function ($fragment) use (&$header, &$written, &$csvSize, $csv, $line, $state) {
                    $cells = $this->parseRow($fragment, $state);
                    if (!$cells) {
                        return;
                    }

                    if (!$header) {
                        $header = $this->buildHeader($cells);
                        return;
                    }

                    $row = array_fill(0, count($header), '');
                    foreach ($cells as $col => $value) {
                        if ($col < count($header)) {
                            $row[$col] = $value;
                        }
                    }

                    if (implode('', $row) === '') {
                        return;
                    }

                    // Format in memory first: fputcsv() can't report a short write to disk,
                    // writeAll() can. Explicit escape also avoids PHP 8.4's deprecation.
                    ftruncate($line, 0);
                    rewind($line);
                    if (fputcsv($line, $row, ',', '"', self::CSV_ESCAPE) === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
                        static::failWrite();
                    }
                    rewind($line);
                    $csvLine = (string)stream_get_contents($line);

                    $this->writeAll($csv, $csvLine);
                    $csvSize += strlen($csvLine);
                    $written++;
                }
            );

            $this->assertSize($csv, $csvSize);
        } finally {
            fclose($line); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($csv); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            foreach ([$this->sstHandle, $this->sstIndexHandle] as $handle) {
                if ($handle) {
                    fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                }
            }
            $this->sstHandle      = null;
            $this->sstIndexHandle = null;
            $this->sstBlocks      = [];
            $this->sstCache       = [];
        }

        $state['header']       = $header;
        $state['rows_written'] = $written;
        $state['csv_size']     = $csvSize;
        $state['sheet_offset'] = $scan['offset'];

        if ($scan['eof']) {
            if (!$header) {
                static::cleanup($state);
                static::fail('xlsx_empty', __('The first sheet of this workbook is empty.', 'ninja-tables'));
            }
            // Sheet and string files stay until releaseCompletedInputs(): a retry from the
            // saved 'rows' state must still be able to re-convert the tail.
            $state['phase'] = 'done';
        }

        return $state;
    }

    /**
     * Header width = last non-empty header cell, so trailing styled-but-empty
     * cells don't become blank columns.
     */
    protected function buildHeader(array $cells)
    {
        $last = 0;
        foreach ($cells as $col => $value) {
            if ($value !== '') {
                $last = max($last, $col);
            }
        }

        $header = array_fill(0, $last + 1, '');
        foreach ($cells as $col => $value) {
            if ($col <= $last) {
                $header[$col] = $value;
            }
        }

        return $header;
    }

    /**
     * @return array<int,string> column index => display value; empty if the row has no values
     */
    protected function parseRow($fragment, array $state)
    {
        $reader = $this->fragmentReader($fragment, $state['sheet_ns']);

        $cells    = [];
        $nextCol  = 0;
        $hasData  = false;
        $rowBytes = 0;

        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'c') {
                continue;
            }

            $ref   = $reader->getAttribute('r');
            $type  = $reader->getAttribute('t') ?: 'n';
            $style = (int)$reader->getAttribute('s');
            $col   = $ref ? $this->columnIndex($ref) : $nextCol;
            if ($col >= self::MAX_COLUMNS) {
                $reader->close();
                static::fail('xlsx_corrupt', __('The workbook is damaged: a cell is outside Excel\'s column range.', 'ninja-tables'));
            }
            $nextCol = $col + 1;

            [$raw, $inline] = $reader->isEmptyElement ? [null, null] : $this->readCellContent($reader);

            $value     = $this->cellValue($type, $raw, $inline, $style, $state);
            $rowBytes += strlen($value);
            if ($rowBytes > $this->limits['max_row_bytes']) {
                $reader->close();
                static::failFragmentTooLarge();
            }
            if ($value !== '') {
                $hasData = true;
            }
            $cells[$col] = $value;
        }

        $reader->close();
        $this->assertNoXmlErrors();

        return $hasData ? $cells : [];
    }

    /**
     * Read the current <c> element's cached <v> value and any <is> inline string.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function readCellContent(\XMLReader $reader)
    {
        $depth  = $reader->depth;
        $raw    = null;
        $inline = null;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === $depth) {
                break;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'v') {
                $raw = $reader->readString();
            } elseif ($reader->localName === 'is') {
                $inline = $this->readRichText($reader);
            }
        }

        return [$raw, $inline];
    }

    protected function cellValue($type, $raw, $inline, $style, array $state)
    {
        switch ($type) {
            case 's':
                return $raw === null ? '' : $this->sharedString((int)$raw);
            case 'inlineStr':
                return (string)$inline;
            case 'str':
                return $this->decodeEscapes((string)$raw);
            case 'b':
                if ($raw === null || $raw === '') {
                    return '';
                }
                return $raw === '1' || strtolower($raw) === 'true' ? 'TRUE' : 'FALSE';
            case 'e':
                return '';
            case 'd':
                return $this->isoDate((string)$raw);
        }

        // 'n' (or missing type): number, unless the cell's style is a date format.
        if ($raw === null || $raw === '') {
            return '';
        }

        if (isset($state['date_styles'][$style]) && is_numeric($raw)) {
            return $this->serialToDate((float)$raw, $state['date_styles'][$style], !empty($state['date1904']));
        }

        return $this->formatNumber($raw);
    }

    /**
     * Excel stores binary floats (0.1+0.2 => 0.30000000000000004) but shows 15
     * significant digits; match what the user sees. Integers pass through untouched.
     */
    protected function formatNumber($raw)
    {
        if (!is_numeric($raw) || strpbrk($raw, '.eE') === false) {
            return $raw;
        }

        // %G is locale-aware on PHP 7.4; force the decimal point back.
        $out = str_replace(',', '.', sprintf('%.15G', (float)$raw));
        if (strpos($out, 'E') === false && strpos($out, '.') !== false) {
            $out = rtrim(rtrim($out, '0'), '.');
        }

        return $out;
    }

    protected function serialToDate($serial, $kind, $date1904)
    {
        if ($serial < 0) {
            return $this->formatNumber((string)$serial);
        }

        $days    = (int)floor($serial);
        $seconds = (int)round(($serial - $days) * 86400);
        if ($seconds >= 86400) {
            $days++;
            $seconds -= 86400;
        }

        if ($kind === 'time' && $days === 0) {
            return gmdate('H:i:s', $seconds);
        }

        if ($date1904) {
            $epoch = -2082844800; // 1904-01-01
        } else {
            // 1900 system counts a fake 1900-02-29 (serial 60); serials before it are off by one day.
            if ($days < 60) {
                $days++;
            }
            $epoch = -2209161600; // 1899-12-30
        }

        $timestamp = $epoch + $days * 86400 + $seconds;

        if ($kind === 'time') {
            return gmdate('Y-m-d H:i:s', $timestamp);
        }

        return gmdate($kind === 'datetime' ? 'Y-m-d H:i:s' : 'Y-m-d', $timestamp);
    }

    protected function isoDate($raw)
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:T(\d{2}:\d{2}(?::\d{2})?))?/', $raw, $m)) {
            return $raw;
        }

        if (empty($m[2]) || preg_match('/^00:00(:00)?$/', $m[2])) {
            return $m[1];
        }

        return $m[1] . ' ' . (strlen($m[2]) === 5 ? $m[2] . ':00' : $m[2]);
    }

    protected function columnIndex($ref)
    {
        $index = 0;
        $len   = strlen($ref);
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($ref[$i]) & 0xDF; // upper-case
            if ($ord < 65 || $ord > 90) {
                break;
            }
            $index = $index * 26 + ($ord - 64);
        }

        return max(0, $index - 1);
    }

    /* ------------------------------------------------------------------ */
    /* Fragment scanning                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Walk <$element>...</$element> fragments in an XML file starting at a byte
     * offset. XMLReader can't seek, so we find element boundaries by bytes and
     * parse each small fragment on its own; the returned offset is resumable.
     *
     * @return array{offset:int, eof:bool}
     */
    protected function scanFragments($path, $offset, $element, $maxItems, $deadline, callable $onFragment)
    {
        $handle = $this->openFile($path, 'rb');
        fseek($handle, $offset);

        $name     = preg_quote($element, '/');
        $startRe  = '/<(?:[A-Za-z_][\w.-]*:)?' . $name . '[\s\/>]/';
        $endRe    = '/<\/(?:[A-Za-z_][\w.-]*:)?' . $name . '\s*>/';
        $bufSize  = (int)$this->limits['read_buffer_bytes'];
        $maxFrag  = max(64, (int)$this->limits['max_fragment_bytes']);

        $buffer   = '';
        $pending  = false; // buffer starts with an unfinished fragment
        $bufStart = $offset;
        $pos      = 0;
        $eof      = false;
        $items    = 0;

        try {
            while ($items < $maxItems) {
                if ($deadline && $items > 0 && microtime(true) >= $deadline) {
                    break;
                }

                $fragment = null;
                $pending  = false;
                if (preg_match($startRe, $buffer, $m, PREG_OFFSET_CAPTURE, $pos)) {
                    $start = $m[0][1];
                    $gt    = strpos($buffer, '>', $start);
                    if ($gt !== false && $buffer[$gt - 1] === '/') {
                        $end = $gt + 1;
                    } elseif ($gt !== false && preg_match($endRe, $buffer, $e, PREG_OFFSET_CAPTURE, $gt)) {
                        $end = $e[0][1] + strlen($e[0][0]);
                    } else {
                        $end = null;
                    }

                    if ($end !== null) {
                        if ($end - $start > $maxFrag) {
                            static::failFragmentTooLarge();
                        }
                        $fragment = substr($buffer, $start, $end - $start);
                        $pos      = $end;
                    } else {
                        // Fragment straddles the buffer edge: keep it and read more.
                        $buffer   = substr($buffer, $start);
                        $bufStart += $start;
                        $pos      = 0;
                        $pending  = true;
                    }
                } else {
                    // Keep a short tail in case "<row" is split across reads.
                    $keep     = max($pos, strlen($buffer) - 64);
                    $buffer   = substr($buffer, $keep);
                    $bufStart += $keep;
                    $pos      = 0;
                }

                if ($fragment !== null) {
                    $onFragment($fragment);
                    $items++;
                    continue;
                }

                if ($eof) {
                    if ($buffer !== '' && preg_match($startRe, $buffer)) {
                        static::fail('xlsx_corrupt', __('The workbook is damaged and could not be read completely.', 'ninja-tables'));
                    }
                    $bufStart += strlen($buffer);
                    $buffer = '';
                    break;
                }

                // While a fragment is unfinished, never let it grow past the cap: read
                // at most one byte beyond it so an oversized fragment is detected
                // before it is copied, wrapped, or parsed.
                $readSize = $bufSize;
                if ($pending) {
                    $room = $maxFrag + 1 - strlen($buffer);
                    if ($room <= 0) {
                        static::failFragmentTooLarge();
                    }
                    $readSize = min($bufSize, $room);
                }

                $chunk = fread($handle, $readSize); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                if ($chunk === false || $chunk === '') {
                    $eof = true;
                } else {
                    $buffer .= $chunk;
                }
            }
        } finally {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }

        return [
            'offset' => $bufStart + $pos,
            'eof'    => $eof && $buffer === '',
        ];
    }

    /**
     * Parse a standalone fragment, re-declaring the root element's namespaces
     * so prefixed elements/attributes (x:row, x14ac:dyDescent) stay valid XML.
     */
    protected function fragmentReader($fragment, $nsDecls)
    {
        $reader = new \XMLReader();
        $xml    = '<nt-root ' . $nsDecls . '>' . $fragment . '</nt-root>';
        if (!$reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            static::fail('xlsx_corrupt', __('The workbook is damaged and could not be read completely.', 'ninja-tables'));
        }

        return $reader;
    }

    protected function fragmentText($fragment, $nsDecls)
    {
        $reader = $this->fragmentReader($fragment, $nsDecls);
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                $text = $reader->isEmptyElement ? '' : $this->readRichText($reader);
                $reader->close();
                $this->assertNoXmlErrors();
                return $text;
            }
        }
        $reader->close();
        $this->assertNoXmlErrors();

        return '';
    }

    /**
     * Concatenate <t> text inside the current element (<si> or <is>), skipping
     * <rPh> phonetic runs, which Excel does not display.
     */
    protected function readRichText(\XMLReader $reader)
    {
        $depth    = $reader->depth;
        $text     = '';
        $phonetic = 0;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT) {
                if ($reader->depth === $depth) {
                    break;
                }
                if ($reader->localName === 'rPh') {
                    $phonetic--;
                }
                continue;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'rPh' && !$reader->isEmptyElement) {
                $phonetic++;
            } elseif ($reader->localName === 't' && !$phonetic) {
                $text .= $reader->readString();
            }
        }

        return $this->decodeEscapes($text);
    }

    /**
     * Excel writes control characters as _xHHHH_ (e.g. _x000D_ for \r).
     */
    protected function decodeEscapes($text)
    {
        if (strpos($text, '_x') === false) {
            return $text;
        }

        return preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', function ($m) {
            return mb_convert_encoding(pack('n', hexdec($m[1])), 'UTF-8', 'UTF-16BE');
        }, $text);
    }

    /* ------------------------------------------------------------------ */
    /* Zip + workbook metadata                                             */
    /* ------------------------------------------------------------------ */

    protected function assertZipShape(\ZipArchive $zip)
    {
        if ($zip->numFiles < 1 || $zip->numFiles > $this->limits['max_entries']) {
            static::fail('xlsx_invalid', __('This is not a valid .xlsx file.', 'ninja-tables'));
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if ($name === '' || $name[0] === '/' || $name[0] === '\\' || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $name)) {
                static::fail('xlsx_invalid', __('This is not a valid .xlsx file.', 'ninja-tables'));
            }
        }

        if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
            static::fail('xlsx_invalid', __('This is not a valid .xlsx file.', 'ninja-tables'));
        }
    }

    protected function assertUncompressedBudget(\ZipArchive $zip, array $entries, $workDir)
    {
        $total = 0;
        foreach ($entries as $entry) {
            $stat   = $zip->statName($entry);
            $total += $stat ? (int)$stat['size'] : 0;
        }

        if ($total > $this->limits['max_uncompressed_bytes']) {
            static::fail('xlsx_too_large', __('This workbook is too large to import. Split it into smaller files or export CSV.', 'ninja-tables'));
        }

        // Sheet XML + strings + the CSV we write: budget roughly twice the XML size.
        $free = function_exists('disk_free_space') ? @disk_free_space($workDir) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled on the host
        if ($free !== false && $free < $total * 2) {
            static::fail('xlsx_disk_full', __('Not enough free disk space on the server to import this workbook.', 'ninja-tables'));
        }
    }

    /**
     * Stream one zip entry to disk, enforcing the size cap on actual bytes
     * (the central directory's declared size is attacker-controlled).
     */
    protected function extractEntry(\ZipArchive $zip, $entry, $target)
    {
        $in = $zip->getStream($entry);
        if (!$in) {
            static::fail('xlsx_corrupt', __('The workbook is damaged and could not be read completely.', 'ninja-tables'));
        }

        $out     = $this->openFile($target, 'wb');
        $written = 0;
        $cap     = (int)$this->limits['max_uncompressed_bytes'];

        try {
            while (!feof($in)) {
                $chunk = fread($in, 1048576); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
                if ($chunk === false) {
                    // A short read would silently drop the sheet's tail rows.
                    static::fail('xlsx_corrupt', __('The workbook is damaged and could not be read completely.', 'ninja-tables'));
                }
                $written += strlen($chunk);
                if ($written > $cap) {
                    static::fail('xlsx_too_large', __('This workbook is too large to import. Split it into smaller files or export CSV.', 'ninja-tables'));
                }
                $this->writeAll($out, $chunk);
            }
            $this->assertSize($out, $written);
        } finally {
            fclose($in); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }
    }

    protected function readSmallEntry(\ZipArchive $zip, $entry)
    {
        $stat = $zip->statName($entry);
        if (!$stat) {
            return '';
        }
        if ((int)$stat['size'] > $this->limits['max_meta_bytes']) {
            static::fail('xlsx_too_large', __('This workbook is too large to import. Split it into smaller files or export CSV.', 'ninja-tables'));
        }

        $xml = $zip->getFromName($entry);
        if (!is_string($xml) || strlen($xml) > $this->limits['max_meta_bytes']) {
            static::fail('xlsx_corrupt', __('The workbook is damaged and could not be read completely.', 'ninja-tables'));
        }

        return $xml;
    }

    protected function xmlReader($xml)
    {
        $reader = new \XMLReader();
        if ($xml === '' || !$reader->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
            static::fail('xlsx_corrupt', __('The workbook is damaged and could not be read completely.', 'ninja-tables'));
        }

        return $reader;
    }

    /**
     * @return array{sheet_rids: string[], date1904: bool}
     */
    protected function parseWorkbook($xml)
    {
        $reader = $this->xmlReader($xml);
        $rids   = [];
        $is1904 = false;

        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'workbookPr') {
                $flag   = strtolower((string)$reader->getAttribute('date1904'));
                $is1904 = $flag === '1' || $flag === 'true';
            } elseif ($reader->localName === 'sheet') {
                $rid = null;
                foreach (self::REL_NS as $ns) {
                    $rid = $reader->getAttributeNs('id', $ns);
                    if ($rid) {
                        break;
                    }
                }
                if ($rid) {
                    $rids[] = $rid;
                }
            }
        }
        $reader->close();
        $this->assertNoXmlErrors();

        return ['sheet_rids' => $rids, 'date1904' => $is1904];
    }

    /**
     * @return array<string, array{type:string, target:string}> keyed by relationship Id
     */
    protected function parseRels(\ZipArchive $zip, $entry)
    {
        $xml = $this->readSmallEntry($zip, $entry);
        if ($xml === '') {
            return [];
        }

        $reader = $this->xmlReader($xml);
        $rels   = [];
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'Relationship') {
                $type   = (string)$reader->getAttribute('Type');
                $target = (string)$reader->getAttribute('Target');
                if ($reader->getAttribute('TargetMode') === 'External') {
                    continue;
                }
                $rels[(string)$reader->getAttribute('Id')] = [
                    'type'   => substr($type, strrpos($type, '/') + 1),
                    'target' => $this->normalizeTarget($target),
                ];
            }
        }
        $reader->close();
        $this->assertNoXmlErrors();

        return $rels;
    }

    protected function normalizeTarget($target)
    {
        $path  = $target !== '' && $target[0] === '/' ? ltrim($target, '/') : 'xl/' . $target;
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    /**
     * First sheet in workbook order that is a worksheet (chartsheets have no cells).
     */
    protected function resolveFirstSheet(array $rids, array $rels)
    {
        foreach ($rids as $rid) {
            if (isset($rels[$rid]) && $rels[$rid]['type'] === 'worksheet') {
                return $rels[$rid]['target'];
            }
        }

        // No usable rels: fall back to the conventional location.
        return $rels ? null : 'xl/worksheets/sheet1.xml';
    }

    protected function resolveRelTarget(array $rels, $type, $fallback, \ZipArchive $zip)
    {
        foreach ($rels as $rel) {
            if ($rel['type'] === $type && $zip->locateName($rel['target']) !== false) {
                return $rel['target'];
            }
        }

        return $zip->locateName($fallback) !== false ? $fallback : null;
    }

    /**
     * Map cellXfs index => 'date'|'datetime'|'time' for date-formatted styles.
     */
    protected function parseDateStyles($xml)
    {
        if ($xml === '') {
            return [];
        }

        $reader  = $this->xmlReader($xml);
        $custom  = [];
        $styles  = [];
        $inXfs   = false;
        $xfIndex = 0;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'cellXfs') {
                $inXfs = false;
                continue;
            }
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->localName === 'numFmt') {
                $kind = $this->classifyFormatCode((string)$reader->getAttribute('formatCode'));
                if ($kind) {
                    $custom[(int)$reader->getAttribute('numFmtId')] = $kind;
                }
            } elseif ($reader->localName === 'cellXfs') {
                $inXfs = !$reader->isEmptyElement;
            } elseif ($inXfs && $reader->localName === 'xf') {
                $fmtId = (int)$reader->getAttribute('numFmtId');
                $kind  = $custom[$fmtId] ?? (self::BUILTIN_DATE_FORMATS[$fmtId] ?? null);
                if ($kind) {
                    $styles[$xfIndex] = $kind;
                }
                $xfIndex++;
            }
        }
        $reader->close();
        $this->assertNoXmlErrors();

        return $styles;
    }

    /**
     * Decide whether a custom number format displays a date, a time, or both.
     */
    protected function classifyFormatCode($code)
    {
        // Only the first section (positive numbers) matters.
        $code = preg_replace('/"[^"]*"|\\\\.|_.|\*./', '', $code);
        $code = explode(';', $code)[0];

        $elapsed = (bool)preg_match('/\[(h+|m+|s+)\]/i', $code);
        $code    = strtolower(preg_replace('/\[[^\]]*\]/', '', $code));

        if ($code === '' || strpos($code, 'general') !== false) {
            return $elapsed ? 'time' : null;
        }

        $hasDate = (bool)preg_match('/[yd]/', $code);
        $hasTime = $elapsed || (bool)preg_match('/[hs]/', $code);
        if (!$hasDate && !$hasTime && strpos($code, 'm') !== false) {
            $hasDate = true; // bare "mmm" / "mm-yy" style month formats
        }

        if ($hasDate && $hasTime) {
            return 'datetime';
        }
        if ($hasDate) {
            return 'date';
        }

        return $hasTime ? 'time' : null;
    }

    protected function readHead($path)
    {
        $handle = $this->openFile($path, 'rb');
        $head   = (string)fread($handle, 16384); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        return $head;
    }

    /**
     * Copy the xmlns declarations from the root start tag so fragments parse standalone.
     */
    protected function rootNamespaces($head, $rootName)
    {
        if (!preg_match('/<(?:[A-Za-z_][\w.-]*:)?' . $rootName . '\b([^>]*)>/', $head, $m)) {
            return 'xmlns="' . self::MAIN_NS . '"';
        }

        preg_match_all('/\sxmlns(?::[A-Za-z_][\w.-]*)?\s*=\s*("[^"]*"|\'[^\']*\')/', $m[1], $decls);

        return $decls[0] ? trim(implode(' ', $decls[0])) : 'xmlns="' . self::MAIN_NS . '"';
    }

    /**
     * Row count from <dimension ref="A1:J500000"/>. An estimate only: writers may omit or misstate it.
     */
    protected function dimensionRows($head)
    {
        if (preg_match('/<(?:[A-Za-z_][\w.-]*:)?dimension\s+ref="[A-Z]*\d*:?[A-Z]*(\d+)"/i', $head, $m)) {
            return (int)$m[1];
        }

        return 0;
    }

    protected function assertNoXmlErrors()
    {
        $error = libxml_get_last_error();
        if ($error && $error->level >= LIBXML_ERR_ERROR) {
            libxml_clear_errors();
            static::fail('xlsx_corrupt', __('The workbook is damaged and could not be read completely.', 'ninja-tables'));
        }
        libxml_clear_errors();
    }

    /**
     * Write every byte or throw. fwrite() returns false or a short count when
     * the disk or quota fills, and an unchecked write would let a checkpoint
     * advance past data that was never saved.
     */
    protected function writeAll($handle, $data)
    {
        $length = strlen($data);
        $done   = 0;
        while ($done < $length) {
            $n = fwrite($handle, $done ? substr($data, $done) : $data); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming temp file
            if ($n === false || $n === 0) {
                static::failWrite();
            }
            $done += $n;
        }
    }

    /**
     * Resume point: cut the file back to the saved size and seek to its end.
     * A file shorter than the checkpoint has lost data; ftruncate() would
     * silently pad it with zero bytes, so fail instead.
     */
    protected function resetTo($handle, $size)
    {
        $stat = fstat($handle);
        if (!$stat || $stat['size'] < $size || !ftruncate($handle, $size) || fseek($handle, 0, SEEK_END) !== 0) {
            static::failWrite();
        }
        $this->assertSize($handle, $size);
    }

    protected function assertSize($handle, $size)
    {
        if (ftell($handle) !== $size) {
            static::failWrite();
        }
    }

    protected static function failWrite()
    {
        static::fail('xlsx_io', __('Could not write a temporary import file. The server may be out of disk space.', 'ninja-tables'));
    }

    protected function openFile($path, $mode)
    {
        $handle = @fopen($path, $mode); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming temp files need native handles
        if (!$handle) {
            static::fail('xlsx_io', __('Could not read or write a temporary import file.', 'ninja-tables'));
        }

        return $handle;
    }

    protected static function failFragmentTooLarge()
    {
        static::fail('xlsx_row_too_large', __('A row in this workbook is too large to import. Split very wide rows or long cell text, or export the sheet as CSV.', 'ninja-tables'));
    }

    protected static function fail($code, $message)
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message is JSON payload for the Vue app, not HTML output
        throw new ImportException(['data' => ['message' => $message, 'code' => $code]], 422);
    }
}
