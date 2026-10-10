<?php

namespace NinjaTables\App\Support;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Library\Csv\Reader;

/**
 * The one CSV dialect the plugin reads and writes: RFC 4180, as Excel, Google
 * Sheets and LibreOffice use it. Quotes inside a cell are doubled ("") and a
 * backslash is an ordinary character.
 *
 * PHP's default escape is a backslash, which mis-parses any quoted cell that
 * ends in one ("C:\path\" swallows the rest of the row). ESCAPE = '' turns that
 * off (PHP 7.4+, the plugin's minimum).
 */
class Csv
{
    const ESCAPE = '';

    /** @return Reader */
    public static function reader($path)
    {
        return Reader::createFromPath($path, 'r')->setEscape(self::ESCAPE);
    }

    /** @return Reader */
    public static function readerFromString($content)
    {
        return Reader::createFromString($content)->setEscape(self::ESCAPE);
    }
}
