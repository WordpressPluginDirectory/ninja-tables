<?php

namespace NinjaTables\App\Services\Table;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Models\NinjaTableItem;
use NinjaTables\App\Modules\DataTables\Database\DynamicTableManager;
use NinjaTables\App\Modules\DataTables\Models\DynamicRow;

/**
 * Writes rows into a table regardless of its engine (shared items table or DataTables dynamic table).
 * Rows are associative arrays keyed by column key; values are sanitised here.
 */
class RowWriter
{
    protected $tableId;
    protected $engine;

    public function __construct($tableId, $engine = null)
    {
        $this->tableId = (int) $tableId;
        $this->engine  = $engine ?: Engine::of($this->tableId);
    }

    public function engine()
    {
        return $this->engine;
    }

    /**
     * Turn an indexed CSV-style row into an associative row, padding/trimming to the header.
     */
    public static function combine(array $headerKeys, array $row)
    {
        if (count($headerKeys) === count($row)) {
            return array_combine($headerKeys, array_values($row));
        }

        // Keep entries the header knows about, blank the missing ones.
        $known = array_intersect_key($row, array_fill_keys($headerKeys, null));
        $blank = array_fill_keys(array_diff($headerKeys, array_keys($row)), null);

        return array_combine($headerKeys, array_values(array_merge($known, $blank) + array_fill_keys($headerKeys, null)));
    }

    /**
     * Append rows.
     *
     * @param array $rows     Associative rows (column key => value)
     * @param array $options  'filter' => hook name applied to the DB rows before insert (items table only)
     * @return int Number of rows written
     */
    public function insert(array $rows, array $options = [])
    {
        $rows = array_values(array_filter($rows, 'is_array'));

        if (!$rows) {
            return 0;
        }

        $rows = array_map('ninja_tables_sanitize_array', $rows);

        return $this->engine === Engine::DATATABLES
            ? $this->insertDynamic($rows)
            : $this->insertItems($rows, $options);
    }

    /**
     * Replace every row of the table with the given rows.
     */
    public function replace(array $rows, array $options = [])
    {
        $this->truncate();

        return $this->insert($rows, $options);
    }

    public function truncate()
    {
        if ($this->engine === Engine::DATATABLES) {
            $manager = new DynamicTableManager($this->tableId);
            if ($manager->tableExists()) {
                $manager->truncateTable();
            }

            return;
        }

        NinjaTableItem::where('table_id', $this->tableId)->delete();
    }

    protected function insertItems(array $rows, array $options)
    {
        global $wpdb;

        $userId    = get_current_user_id();
        $now       = gmdate('Y-m-d H:i:s');
        $timestamp = time() - count($rows);
        $data      = [];

        foreach ($rows as $index => $row) {
            $item = [
                'table_id'   => $this->tableId,
                'attribute'  => 'value',
                'owner_id'   => $userId,
                'value'      => wp_json_encode($row, JSON_UNESCAPED_UNICODE),
                'created_at' => gmdate('Y-m-d H:i:s', $timestamp + $index),
                'updated_at' => $now,
            ];

            if (isset($row['position']) && defined('NINJAPROPLUGIN_VERSION')) {
                $item['position'] = $row['position'];
            }

            $data[] = $item;
        }

        if (!empty($options['filter'])) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- only value passed is the prefixed ninja_tables_import_table_data hook
            $data = apply_filters($options['filter'], $data, $this->tableId);
        }

        $table = $wpdb->prefix . ninja_tables_db_table_name();
        $count = 0;

        foreach (array_chunk($data, ninjaTablePerChunk($this->tableId)) as $chunk) {
            $count += (int) ninjaTablesBatchInsert($table, $chunk);
        }

        return $count;
    }

    protected function insertDynamic(array $rows)
    {
        $manager = new DynamicTableManager($this->tableId);

        if (!$manager->tableExists()) {
            $manager->createTable();
        }

        $columns = get_post_meta($this->tableId, '_ninja_table_columns', true) ?: [];
        $manager->syncColumns($columns);

        $dynamicRow = new DynamicRow($this->tableId);

        return (int) $dynamicRow->batchInsert($rows, $dynamicRow->getNextPosition());
    }
}
