<?php

namespace NinjaTables\App\Services\Export;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Writes a single-sheet .xlsx file, one row at a time.
 *
 * Rows are appended to the sheet XML in a temp file as they arrive and the
 * workbook is zipped only at the end, so memory stays flat for any table size.
 * Pure PHP (ZipArchive), no WordPress calls, so it is unit-testable.
 *
 * Cell rules:
 *  - Plain numbers become numeric cells (Excel can sum/sort them), unless they
 *    would change on the way: a leading zero ("00123") or more than 15
 *    significant digits (phone numbers, long IDs) stay text.
 *  - Everything else is an inline string. Inline strings are never evaluated,
 *    so a value like "=SUM(A1)" stays literal text (no formula injection).
 *  - Characters XML 1.0 forbids are removed, invalid UTF-8 is replaced, and
 *    text is capped at Excel's 32,767-character cell limit.
 *
 * Usage: $w = new XlsxWriter($dir); $w->open($header, $sheetName); $w->addRow(...)...; $path = $w->finish();
 */
class XlsxWriter
{
    const MAX_ROWS = 1048576;        // Excel's sheet limit, header included
    const MAX_CELL_CHARS = 32767;    // Excel's cell text limit
    const STYLE_HEADER = 1;          // cellXfs index of the bold header style

    protected $dir;
    protected $maxRows;
    protected $sheetPath = '';
    protected $handle = null;
    protected $sheetName = 'Sheet1';
    protected $rowCount = 0;
    protected $columns = [];
    protected $zipPath = '';
    protected $finished = false;
    protected $shutdownRegistered = false;

    /**
     * @param string $dir     writable directory for the temp files
     * @param int    $maxRows row limit, header included (tests lower it)
     */
    public function __construct($dir, $maxRows = self::MAX_ROWS)
    {
        $this->dir     = rtrim($dir, '/\\');
        $this->maxRows = max(1, (int)$maxRows);
    }

    public static function isSupported()
    {
        return class_exists('ZipArchive');
    }

    /**
     * Start the sheet and write the bold, frozen header row.
     *
     * @param string[] $header column names, left to right
     */
    public function open(array $header, $sheetName = '')
    {
        if (!static::isSupported()) {
            throw new \RuntimeException('xlsx_unsupported');
        }

        $this->columns   = [];
        $count           = max(1, count($header));
        for ($i = 0; $i < $count; $i++) {
            $this->columns[] = static::columnLetters($i);
        }
        $this->sheetName = static::safeSheetName($sheetName);
        $this->sheetPath = $this->dir . '/' . bin2hex(random_bytes(16)) . '-sheet.xml';

        // Registered before any long-running work: a fatal error (e.g. a timeout)
        // mid-build runs no catch/finally, but shutdown functions still run.
        if (!$this->shutdownRegistered) {
            register_shutdown_function([$this, 'cleanupAfterFatal']);
            $this->shutdownRegistered = true;
        }

        $this->handle    = @fopen($this->sheetPath, 'wb'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming temp file
        if (!$this->handle) {
            throw new \RuntimeException('xlsx_io');
        }

        // The header stays visible while scrolling (frozen first row).
        $this->write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>'
            . '</sheetView></sheetViews><sheetData>');

        $this->writeRow(array_values($header), self::STYLE_HEADER, false);
    }

    /**
     * @param array $cells values in header order; arrays are joined with ", "
     */
    public function addRow(array $cells)
    {
        $this->writeRow(array_values($cells), 0, true);
    }

    public function rowCount()
    {
        return $this->rowCount;
    }

    /**
     * Close the sheet and zip the workbook. Returns the .xlsx path; the caller
     * owns (and must delete) it. The sheet temp file is removed.
     */
    public function finish()
    {
        $this->write('</sheetData></worksheet>');
        $this->closeHandle();

        $xlsxPath      = $this->dir . '/' . bin2hex(random_bytes(16)) . '.xlsx';
        $this->zipPath = $xlsxPath;
        $zip           = new \ZipArchive();

        try {
            if ($zip->open($xlsxPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('xlsx_io');
            }

            $zip->addFromString('[Content_Types].xml', static::contentTypesXml());
            $zip->addFromString('_rels/.rels', static::rootRelsXml());
            $zip->addFromString('xl/workbook.xml', static::workbookXml($this->sheetName));
            $zip->addFromString('xl/_rels/workbook.xml.rels', static::workbookRelsXml());
            $zip->addFromString('xl/styles.xml', static::stylesXml());
            // Added by path: libzip reads and compresses it at close(), not into memory now.
            $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');

            if (!$zip->close()) {
                throw new \RuntimeException('xlsx_io');
            }
        } catch (\Throwable $e) {
            @unlink($xlsxPath); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
            $this->abort();
            throw $e;
        }

        $this->abort(); // removes the sheet temp file
        $this->finished = true; // the caller owns the .xlsx from here on

        return $xlsxPath;
    }

    /**
     * Shutdown hook: if the build never finished, remove the sheet temp file and
     * any partial workbook (including libzip's in-progress "<path>.XXXXXX" copy).
     * Public only because register_shutdown_function needs a callable.
     */
    public function cleanupAfterFatal()
    {
        if ($this->finished) {
            return;
        }

        $this->abort();
        if ($this->zipPath) {
            foreach ((array)glob($this->zipPath . '*') as $file) {
                if (is_file($file)) {
                    @unlink($file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
                }
            }
        }
    }

    /** Remove the temp sheet (on failure, or once the zip is built). */
    public function abort()
    {
        $this->closeHandle();
        if ($this->sheetPath && is_file($this->sheetPath)) {
            @unlink($this->sheetPath); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
        }
    }

    /* ------------------------------------------------------------------ */

    protected function writeRow(array $cells, $style, $typed)
    {
        if ($this->rowCount >= $this->maxRows) {
            throw new \RuntimeException('xlsx_too_many_rows');
        }
        $this->rowCount++;
        $r = $this->rowCount;

        $xml = '<row r="' . $r . '">';
        foreach ($this->columns as $i => $letters) {
            $value = isset($cells[$i]) ? $cells[$i] : '';
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $value = (string)$value;
            if ($value === '') {
                continue;
            }

            $ref   = $letters . $r;
            $sAttr = $style ? ' s="' . $style . '"' : '';
            if ($typed && static::isPlainNumber($value)) {
                $xml .= '<c r="' . $ref . '"' . $sAttr . '><v>' . $value . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '"' . $sAttr . ' t="inlineStr"><is><t xml:space="preserve">'
                    . static::xmlText($value) . '</t></is></c>';
            }
        }
        $this->write($xml . '</row>');
    }

    /**
     * A number Excel will store exactly as written: optional minus, no leading
     * zero (except "0" / "0.x"), no exponent or separators, <= 15 significant digits.
     */
    public static function isPlainNumber($value)
    {
        if (!preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $value)) {
            return false;
        }

        $digits = ltrim(str_replace(['-', '.'], '', $value), '0');

        return strlen($digits) <= 15;
    }

    /** Escape for XML text, dropping characters XML 1.0 forbids and capping length. */
    public static function xmlText($value)
    {
        // Replace invalid UTF-8 first so the /u regex below can't fail.
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $value = (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
        if (mb_strlen($value, 'UTF-8') > self::MAX_CELL_CHARS) {
            $value = mb_substr($value, 0, self::MAX_CELL_CHARS, 'UTF-8');
        }

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** 0 => A, 25 => Z, 26 => AA ... */
    public static function columnLetters($index)
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26) . $letters;
        }

        return $letters;
    }

    /** Excel sheet names: <= 31 chars, none of []:*?/\ , not empty, no leading/trailing apostrophe. */
    public static function safeSheetName($name)
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', (string)$name));
        $name = trim(mb_substr($name, 0, 31, 'UTF-8'), " '");

        return $name === '' ? 'Sheet1' : $name;
    }

    protected function write($xml)
    {
        $length = strlen($xml);
        for ($done = 0; $done < $length; $done += $n) {
            $n = fwrite($this->handle, $done ? substr($xml, $done) : $xml); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming temp file
            if ($n === false || $n === 0) {
                $this->abort();
                throw new \RuntimeException('xlsx_io'); // disk full or quota reached
            }
        }
    }

    protected function closeHandle()
    {
        if ($this->handle) {
            fclose($this->handle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            $this->handle = null;
        }
    }

    protected static function contentTypesXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    protected static function rootRelsXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    protected static function workbookXml($sheetName)
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars($sheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    protected static function workbookRelsXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /** Minimal stylesheet: xf 0 = default, xf 1 = bold (header). */
    protected static function stylesXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
