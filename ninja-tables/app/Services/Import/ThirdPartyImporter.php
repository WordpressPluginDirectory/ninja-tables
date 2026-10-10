<?php

namespace NinjaTables\App\Services\Import;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Services\Import\Adapters\BaseAdapter;
use NinjaTables\App\Services\Import\Adapters\SupsysticAdapter;
use NinjaTables\App\Services\Import\Adapters\TablePressAdapter;

/**
 * Imports tables from other table plugins (TablePress, Supsystic).
 */
class ThirdPartyImporter
{
    /**
     * @return BaseAdapter|null
     */
    public static function adapter($plugin)
    {
        switch ($plugin) {
            case 'TablePress':
                return new TablePressAdapter();
            case 'supsystic':
                return new SupsysticAdapter();
        }

        return null;
    }
}
