<?php

namespace NinjaTables\App\Modules\DataTables\Database;

use NinjaTables\Framework\Support\Arr;

class DynamicTableManager
{
    const COL_ID         = '__id';
    const COL_POSITION   = '__position';
    const COL_OWNER_ID   = '__owner_id';
    const COL_SETTINGS   = '__settings';
    const COL_CREATED_AT = '__created_at';
    const COL_UPDATED_AT = '__updated_at';

    protected $tableId;

    protected $tableName;

    protected $wpdb;

    protected $cachedTableExists = null;

    protected $cachedColumns = null;

    public function __construct($tableId)
    {
        global $wpdb;
        $this->wpdb      = $wpdb;
        $this->tableId   = intval($tableId);
        $this->tableName = $this->getTableName();
    }

    public static function getSystemColumns()
    {
        return [
            self::COL_ID,
            self::COL_POSITION,
            self::COL_OWNER_ID,
            self::COL_SETTINGS,
            self::COL_CREATED_AT,
            self::COL_UPDATED_AT,
        ];
    }

    public static function resolveColumnName($name)
    {
        $map = [
            'id'         => self::COL_ID,
            'position'   => self::COL_POSITION,
            'owner_id'   => self::COL_OWNER_ID,
            'settings'   => self::COL_SETTINGS,
            'created_at' => self::COL_CREATED_AT,
            'updated_at' => self::COL_UPDATED_AT,
        ];

        return isset($map[$name]) ? $map[$name] : $name;
    }

    public static function isReservedColumnKey($key)
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);

        return in_array($sanitized, self::getSystemColumns());
    }

    public static function getReservedKeys($columns)
    {
        $reserved = [];
        foreach ($columns as $column) {
            $key = is_array($column) ? Arr::get($column, 'key', '') : $column;
            if ($key && self::isReservedColumnKey($key)) {
                $reserved[] = $key;
            }
        }

        return $reserved;
    }
    public function getTableName()
    {
        return $this->wpdb->prefix . 'ninja_tables_dt_' . $this->tableId;
    }

    public function tableExists()
    {
        if ($this->cachedTableExists !== null) {
            return $this->cachedTableExists;
        }

        $result = $this->wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
            $this->wpdb->prepare("SHOW TABLES LIKE %s", $this->tableName) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );
        $this->cachedTableExists = ($result === $this->tableName);

        return $this->cachedTableExists;
    }

    public function createTable()
    {
        if ($this->tableExists()) {
            return true;
        }

        $charset = $this->wpdb->get_charset_collate();

        $id        = self::COL_ID;
        $position  = self::COL_POSITION;
        $ownerId   = self::COL_OWNER_ID;
        $settings  = self::COL_SETTINGS;
        $createdAt = self::COL_CREATED_AT;
        $updatedAt = self::COL_UPDATED_AT;

        $sql = "CREATE TABLE {$this->tableName} (
            `{$id}` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `{$position}` INT UNSIGNED NOT NULL DEFAULT 0,
            `{$ownerId}` BIGINT UNSIGNED DEFAULT NULL,
            `{$settings}` TEXT DEFAULT NULL,
            `{$createdAt}` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `{$updatedAt}` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`{$id}`),
            KEY position_idx (`{$position}`),
            KEY owner_id_idx (`{$ownerId}`)
        ) {$charset};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $this->cachedTableExists = null;
        $this->cachedColumns     = null;

        return $this->tableExists();
    }

    public function dropTable()
    {
        if (!$this->tableExists()) {
            return true;
        }

        $result = $this->wpdb->query("DROP TABLE IF EXISTS `{$this->tableName}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $this->cachedTableExists = null;
        $this->cachedColumns     = null;

        return $result !== false;
    }

    public function syncColumns($columns)
    {
        if (!$this->tableExists()) {
            if (!$this->createTable()) {
                return false;
            }
        }

        $existingColumns = $this->getExistingColumns();
        $systemColumns   = self::getSystemColumns();

        foreach ($columns as $column) {
            $columnKey = $this->sanitizeColumnName(Arr::get($column, 'key', ''));

            if (in_array($columnKey, $existingColumns) || in_array($columnKey, $systemColumns)) {
                continue;
            }

            $this->addColumn($columnKey);
        }

        return true;
    }

    public function addColumn($columnName, $columnType = 'TEXT')
    {
        $allowedTypes = ['TEXT', 'LONGTEXT', 'MEDIUMTEXT', 'VARCHAR(255)', 'INT', 'BIGINT', 'DECIMAL(20,6)', 'DATE', 'DATETIME'];
        $columnType   = in_array(strtoupper($columnType), $allowedTypes) ? strtoupper($columnType) : 'TEXT';
        $columnName   = $this->sanitizeColumnName($columnName);

        $sql    = "ALTER TABLE {$this->tableName} ADD COLUMN `{$columnName}` {$columnType} DEFAULT NULL";
        $result = $this->wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

        // Invalidate column cache since schema changed
        $this->cachedColumns = null;

        return $result !== false;
    }

    public function getExistingColumns()
    {
        if ($this->cachedColumns !== null) {
            return $this->cachedColumns;
        }

        $columns = [];
        $results = $this->wpdb->get_results("DESCRIBE `{$this->tableName}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($results) {
            foreach ($results as $row) {
                $columns[] = $row->Field;
            }
        }

        $this->cachedColumns = $columns;

        return $columns;
    }

    public function sanitizeColumnName($name)
    {
        $name = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);

        if (preg_match('/^[0-9]/', $name)) {
            $name = 'col_' . $name;
        }

        return substr($name, 0, 64);
    }

    public function getTableId()
    {
        return $this->tableId;
    }

    public function truncateTable()
    {
        if (!$this->tableExists()) {
            return true;
        }

        $result = $this->wpdb->query("TRUNCATE TABLE `{$this->tableName}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $result !== false;
    }
}
