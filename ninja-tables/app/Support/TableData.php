<?php

namespace NinjaTables\App\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reading and writing table rows.
 */
class TableData
{
    public static function getTablesDataByID($tableId,
    $tableColumns = [],
    $defaultSorting = false,
    $disableCache = false,
    $limit = false,
    $skip = false,
    $ownOnly = false)
    {
        $providerName = ninja_table_get_data_provider($tableId);
        $providerName = in_array($providerName, array('csv', 'google-csv')) ? 'csv' : $providerName;

        return apply_filters(
            'ninja_tables_fetching_table_rows_' . $providerName,
            array(),
            $tableId,
            $defaultSorting,
            $limit,
            $skip,
            $ownOnly
        );
    }

    public static function insertDataToTable($tableId, $values, $header)
    {
        $headerKeys = array_keys($header);
        $rows       = [];

        foreach ($values as $item) {
            if (is_array($item)) {
                $rows[] = \NinjaTables\App\Services\Table\RowWriter::combine($headerKeys, $item);
            }
        }

        (new \NinjaTables\App\Services\Table\RowWriter($tableId, \NinjaTables\App\Services\Table\Engine::FOOTABLE))->insert($rows);
    }

    /**
     * Batch insert data using raw SQL query.
     *
     * @param string $table
     * @param array $rows
     *
     * @return bool|int
     */
    public static function batchInsert($table, $rows)
    {
        global $wpdb;

        // Extract column list from first row of data
        $columns = array_keys($rows[0]);
        asort($columns);
        $columnList = '`' . implode('`, `', $columns) . '`';
        // Start building SQL, initialise data and placeholder arrays
        $sql          = "INSERT INTO `$table` ($columnList) VALUES\n";
        $placeholders = array();
        $data         = array();
        // Build placeholders for each row, and add values to data array
        foreach ($rows as $row) {
            ksort($row);
            $rowPlaceholders = array();
            foreach ($row as $key => $value) {
                $data[]            = $value;
                $rowPlaceholders[] = is_numeric($value) ? '%d' : '%s';
            }
            $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
        }
        // Stitch all rows together
        $sql .= implode(",\n", $placeholders);

        // Run the query.  Returns number of affected rows.
        return $wpdb->query($wpdb->prepare($sql, $data)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    public static function getRemoteContent($url)
    {
        $remoteContent = wp_safe_remote_get($url);

        if (is_wp_error($remoteContent)) {
            return false;
        }

        return wp_remote_retrieve_body($remoteContent);
    }
}
