<?php

namespace NinjaTables\App\Modules\DataTables\Handlers;

use NinjaTables\App\Modules\DataTables\Models\DynamicRow;
use NinjaTables\App\Modules\DataTables\Database\DynamicTableManager;
use NinjaTables\Framework\Support\Arr;

class ItemsHandler
{
    public static function getItems($tableId, $params = [])
    {
        $dynamicRow = new DynamicRow($tableId);

        if (!$dynamicRow->tableExists()) {
            return [
                'success' => false,
                'message' => __('Dynamic table does not exist. Please save columns first.', 'ninja-tables'),
            ];
        }

        $perPage = max(1, min(intval(Arr::get($params, 'per_page', 20)), 1000));
        $page    = intval(Arr::get($params, 'page', 1));
        $orderBy = DynamicTableManager::resolveColumnName(
            sanitize_text_field(Arr::get($params, 'orderBy', 'position'))
        );
        $order   = sanitize_text_field(Arr::get($params, 'order', 'ASC'));
        $search  = sanitize_text_field(Arr::get($params, 'search', ''));

        $rows = $dynamicRow->getAll($perPage, $page, $orderBy, $order, $search ?: null);
        $total = $dynamicRow->count($search ?: null);

        $formattedRows = [];
        foreach ($rows as $row) {
            $formattedRows[] = self::formatRow($dynamicRow->mapRowToUserKeys($row));
        }

        return [
            'success' => true,
            'data' => $formattedRows,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => ceil($total / $perPage),
        ];
    }

    public static function store($tableId, $params = [])
    {
        $dynamicRow = new DynamicRow($tableId);

        if (!$dynamicRow->tableExists()) {
            return [
                'success' => false,
                'message' => __('Dynamic table does not exist. Please save columns first.', 'ninja-tables'),
            ];
        }

        $row = Arr::get($params, 'row', []);
        if (is_array($row)) {
            $row = ninja_tables_sanitize_array($row);
        }

        $settings = Arr::get($params, 'settings', []);
        $rowId    = Arr::get($params, 'id');

        if ($rowId) {
            return self::updateRow($dynamicRow, $tableId, intval($rowId), $row, $settings);
        }

        return self::insertRow($dynamicRow, $tableId, $row, $settings, $params);
    }

    protected static function updateRow($dynamicRow, $tableId, $rowId, $row, $settings)
    {
        $existingRow = $dynamicRow->find($rowId);

        if (!$existingRow) {
            return [
                'success' => false,
                'message' => __('Row not found.', 'ninja-tables'),
            ];
        }

        $success = $dynamicRow->update($rowId, $row, $settings);

        if (!$success) {
            return [
                'success' => false,
                'message' => __('Failed to update row.', 'ninja-tables'),
            ];
        }

        ninjaTablesClearTableDataCache($tableId);

        $updatedRow = $dynamicRow->find($rowId);

        return [
            'success' => true,
            'message' => __('Successfully updated the data.', 'ninja-tables'),
            'item' => self::formatRow($dynamicRow->mapRowToUserKeys($updatedRow)),
        ];
    }

    protected static function insertRow($dynamicRow, $tableId, $row, $settings, $params)
    {
        $insertAfterId = Arr::get($params, 'insert_after_id');
        $position = null;

        if ($insertAfterId) {
            $afterRow = $dynamicRow->find(intval($insertAfterId));
            if ($afterRow) {
                $colPosition = DynamicTableManager::COL_POSITION;
                $position = $afterRow->{$colPosition} + 1;
                self::shiftRowPositions($tableId, $position);
            }
        }

        $insertId = $dynamicRow->insert($row, $settings, $position);

        if ($insertId === false) {
            return [
                'success' => false,
                'message' => __('Failed to insert row.', 'ninja-tables'),
            ];
        }

        ninjaTablesClearTableDataCache($tableId);

        $insertedRow = $dynamicRow->find($insertId);

        return [
            'success' => true,
            'message' => __('Successfully saved the data.', 'ninja-tables'),
            'item' => self::formatRow($dynamicRow->mapRowToUserKeys($insertedRow)),
        ];
    }

    public static function updateCell($tableId, $params = [])
    {
        $dynamicRow = new DynamicRow($tableId);

        if (!$dynamicRow->tableExists()) {
            return [
                'success' => false,
                'message' => __('Dynamic table does not exist.', 'ninja-tables'),
            ];
        }

        $rowId       = intval(Arr::get($params, 'row_id', 0));
        $columnKey   = sanitize_text_field(Arr::get($params, 'column_key', ''));
        $columnValue = Arr::get($params, 'column_value', '');

        if (is_string($columnValue)) {
            $columnValue = sanitize_text_field($columnValue);
        }

        $existingRow = $dynamicRow->find($rowId);
        if (!$existingRow) {
            return [
                'success' => false,
                'message' => __('Row not found.', 'ninja-tables'),
            ];
        }

        $mapped = $dynamicRow->mapRowToUserKeys($existingRow);
        $values = $mapped['values'];
        $values[$columnKey] = $columnValue;

        $success = $dynamicRow->update($rowId, $values);

        if (!$success) {
            return [
                'success' => false,
                'message' => __('Failed to update row.', 'ninja-tables'),
            ];
        }

        ninjaTablesClearTableDataCache($tableId);

        return [
            'success' => true,
            'message' => __('Cell successfully updated', 'ninja-tables'),
        ];
    }

    public static function delete($tableId, $ids)
    {
        $dynamicRow = new DynamicRow($tableId);

        if (!$dynamicRow->tableExists()) {
            return [
                'success' => false,
                'message' => __('Dynamic table does not exist.', 'ninja-tables'),
            ];
        }

        $ids = array_map('intval', (array) $ids);
        $success = $dynamicRow->deleteMany($ids);

        if (!$success) {
            return [
                'success' => false,
                'message' => __('Failed to delete row(s).', 'ninja-tables'),
            ];
        }

        ninjaTablesClearTableDataCache($tableId);

        return [
            'success' => true,
            'message' => __('Successfully deleted data.', 'ninja-tables'),
        ];
    }

    protected static function shiftRowPositions($tableId, $fromPosition)
    {
        global $wpdb;
        $tableManager = new DynamicTableManager($tableId);
        $tableName    = $tableManager->getTableName();
        $colPosition  = DynamicTableManager::COL_POSITION;

        $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->prepare(
                "UPDATE {$tableName} SET `{$colPosition}` = `{$colPosition}` + 1 WHERE `{$colPosition}` >= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $fromPosition
            )
        );
    }

    protected static function formatRow($row)
    {
        return [
            'id'         => Arr::get($row, 'id'),
            'position'   => Arr::get($row, 'position'),
            'values'     => Arr::get($row, 'values', []),
            'settings'   => Arr::get($row, 'settings') ?: new \stdClass(),
            'created_by' => Arr::get($row, 'owner_id'),
            'created_at' => Arr::get($row, 'created_at'),
            'updated_at' => Arr::get($row, 'updated_at'),
        ];
    }
}
