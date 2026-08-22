<?php

namespace NinjaTables\App\Modules\DataTables\Models;

use NinjaTables\App\Models\Model;
use NinjaTables\App\Modules\DataTables\Database\DynamicTableManager;
use NinjaTables\Framework\Support\Arr;

class DynamicRow
{
    protected $tableId;

    protected $tableManager;

    protected $columns;

    public function __construct($tableId)
    {
        $this->tableId      = intval($tableId);
        $this->tableManager = new DynamicTableManager($tableId);
        $this->columns      = get_post_meta($tableId, '_ninja_table_columns', true) ?: [];
    }

    public function getTableManager()
    {
        return $this->tableManager;
    }

    public function newQuery()
    {
        return Model::resolveConnection()->table('ninja_tables_dt_' . $this->tableId);
    }

    public function tableExists()
    {
        return $this->tableManager->tableExists();
    }

    public function insert($values, $settings = [], $position = null, $ownerId = null)
    {
        if (!$this->tableExists()) {
            return false;
        }

        if ($position === null) {
            $position = $this->getNextPosition();
        }

        $data = [
            DynamicTableManager::COL_POSITION => $position,
            DynamicTableManager::COL_OWNER_ID => $ownerId ?: get_current_user_id(),
            DynamicTableManager::COL_SETTINGS => !empty($settings) ? maybe_serialize($settings) : null,
        ];

        $existingColumns = $this->tableManager->getExistingColumns();
        $systemColumns   = DynamicTableManager::getSystemColumns();
        foreach ($this->columns as $column) {
            $key          = Arr::get($column, 'key', '');
            $sanitizedKey = $this->tableManager->sanitizeColumnName($key);

            if (in_array($sanitizedKey, $systemColumns)) {
                continue;
            }

            if (in_array($sanitizedKey, $existingColumns) && isset($values[$key])) {
                $data[$sanitizedKey] = is_array($values[$key]) ? json_encode($values[$key]) : $values[$key];
            }
        }

        return $this->newQuery()->insertGetId($data);
    }

    public function update($rowId, $values, $settings = null)
    {
        if (!$this->tableExists()) {
            return false;
        }

        $data = [];

        if ($settings !== null) {
            $data[DynamicTableManager::COL_SETTINGS] = !empty($settings) ? maybe_serialize($settings) : null;
        }

        $existingColumns = $this->tableManager->getExistingColumns();
        foreach ($this->columns as $column) {
            $key          = Arr::get($column, 'key', '');
            $sanitizedKey = $this->tableManager->sanitizeColumnName($key);

            if (in_array($sanitizedKey, $existingColumns) && array_key_exists($key, $values)) {
                $data[$sanitizedKey] = is_array($values[$key]) ? json_encode($values[$key]) : $values[$key];
            }
        }

        if (empty($data)) {
            return true;
        }

        return $this->newQuery()->where(DynamicTableManager::COL_ID, $rowId)->update($data) !== false;
    }

    public function delete($rowId)
    {
        if (!$this->tableExists()) {
            return false;
        }

        return $this->newQuery()->where(DynamicTableManager::COL_ID, $rowId)->delete() !== false;
    }

    public function deleteMany($rowIds)
    {
        if (!$this->tableExists() || empty($rowIds)) {
            return false;
        }

        $ids = array_map('intval', $rowIds);

        return $this->newQuery()->whereIn(DynamicTableManager::COL_ID, $ids)->delete() !== false;
    }

    public function find($rowId)
    {
        if (!$this->tableExists()) {
            return null;
        }

        return $this->newQuery()->where(DynamicTableManager::COL_ID, $rowId)->first();
    }

    public function getAll($perPage = 20, $page = 1, $orderBy = null, $order = 'ASC', $search = null, $customFilters = [])
    {
        if (!$this->tableExists()) {
            return [];
        }

        if ($orderBy === null) {
            $orderBy = DynamicTableManager::COL_POSITION;
        }

        $offset = ($page - 1) * $perPage;

        $allowedOrderBy = array_merge(
            [DynamicTableManager::COL_ID, DynamicTableManager::COL_POSITION, DynamicTableManager::COL_CREATED_AT, DynamicTableManager::COL_UPDATED_AT],
            $this->getColumnKeys()
        );
        $orderBy = in_array($orderBy, $allowedOrderBy) ? $orderBy : DynamicTableManager::COL_POSITION;
        $order          = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        $query = $this->newQuery();

        $this->applySearchConditions($query, $search);
        $this->applyCustomFilters($query, $customFilters);

        $query->orderBy($orderBy, $order);

        // Stable tie-breaker so offset paging can't drop/duplicate rows that
        // share the ordering value (e.g. duplicate __position).
        if ($orderBy !== DynamicTableManager::COL_ID) {
            $query->orderBy(DynamicTableManager::COL_ID, 'ASC');
        }

        $results = $query->offset($offset)
                        ->limit($perPage)
                        ->get();

        return is_array($results) ? $results : $results->toArray();
    }

    public function count($search = null, $customFilters = [])
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $query = $this->newQuery();

        $this->applySearchConditions($query, $search);
        $this->applyCustomFilters($query, $customFilters);

        return (int) $query->count();
    }

    public function getDistinctValues($columnKey, $search = null, $customFilters = [])
    {
        if (!$this->tableExists()) {
            return [];
        }

        $colName = $this->tableManager->sanitizeColumnName($columnKey);
        if (!in_array($colName, $this->tableManager->getExistingColumns(), true)) {
            return [];
        }

        $query = $this->newQuery();

        $this->applySearchConditions($query, $search);
        $this->applyCustomFilters($query, $customFilters);

        $cap     = apply_filters('ninja_tables_dt_max_filter_options', 2000);
        $results = $query->select($colName)->distinct()->limit($cap)->get();

        $values = [];
        foreach ($results as $row) {
            $val = is_object($row)
                ? (isset($row->{$colName}) ? $row->{$colName} : null)
                : Arr::get((array) $row, $colName);
            if ($val !== null && $val !== '') {
                $values[] = $val;
            }
        }

        return array_values(array_unique($values));
    }

    public function getNextPosition()
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $maxPosition = $this->newQuery()->max(DynamicTableManager::COL_POSITION);

        return ($maxPosition !== null) ? $maxPosition + 1 : 0;
    }

    public function updatePosition($rowId, $newPosition)
    {
        if (!$this->tableExists()) {
            return false;
        }

        return $this->newQuery()->where(DynamicTableManager::COL_ID, $rowId)->update([DynamicTableManager::COL_POSITION => $newPosition]) !== false;
    }

    public function mapRowToUserKeys($row)
    {
        $values = [];

        $colId        = DynamicTableManager::COL_ID;
        $colPosition  = DynamicTableManager::COL_POSITION;
        $colOwnerId   = DynamicTableManager::COL_OWNER_ID;
        $colSettings  = DynamicTableManager::COL_SETTINGS;
        $colCreatedAt = DynamicTableManager::COL_CREATED_AT;
        $colUpdatedAt = DynamicTableManager::COL_UPDATED_AT;

        foreach ($this->columns as $column) {
            $key          = Arr::get($column, 'key', '');
            $sanitizedKey = $this->tableManager->sanitizeColumnName($key);

            if (isset($row->{$sanitizedKey})) {
                $raw = $row->{$sanitizedKey};

                if (is_string($raw) && strlen($raw) > 1) {
                    $firstChar = $raw[0];
                    if ($firstChar === '[' || $firstChar === '{') {
                        $decoded = json_decode($raw, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $raw = $decoded;
                        }
                    }
                }

                $values[$key] = $raw;
            } else {
                $values[$key] = '';
            }
        }

        $settings = [];
        if (!empty($row->{$colSettings})) {
            if (is_serialized($row->{$colSettings})) {
                $settings = unserialize($row->{$colSettings}, ['allowed_classes' => false]);
            } else {
                $settings = $row->{$colSettings};
            }
            if (!is_array($settings)) {
                $settings = [];
            }
        }

        return [
            'id'         => $row->{$colId},
            'position'   => $row->{$colPosition},
            'values'     => $values,
            'settings'   => $settings,
            'owner_id'   => $row->{$colOwnerId},
            'created_at' => $row->{$colCreatedAt},
            'updated_at' => $row->{$colUpdatedAt},
        ];
    }

    protected function getColumnKeys()
    {
        $keys = [];
        foreach ($this->columns as $column) {
            $keys[] = $this->tableManager->sanitizeColumnName(Arr::get($column, 'key', ''));
        }

        return $keys;
    }

    protected function applySearchConditions($query, $search)
    {
        if (!$search) {
            return;
        }

        $existingColumns = $this->tableManager->getExistingColumns();
        $searchColumns   = [];

        foreach ($this->columns as $column) {
            if (Arr::get($column, 'unfilterable') === 'yes') {
                continue;
            }

            $sanitizedKey = $this->tableManager->sanitizeColumnName(Arr::get($column, 'key', ''));
            if (in_array($sanitizedKey, $existingColumns)) {
                $searchColumns[] = $sanitizedKey;
            }
        }

        if (empty($searchColumns)) {
            return;
        }

        global $wpdb;
        $escapedSearch = $wpdb->esc_like($search);
        $query->where(function ($q) use ($searchColumns, $escapedSearch) {
            foreach ($searchColumns as $col) {
                $q->orWhere($col, 'LIKE', '%' . $escapedSearch . '%');
            }
        });
    }

    /**
     * Each filter may target multiple columns (OR'd within the filter).
     * Different filters are AND'd together.
     */
    protected function applyCustomFilters($query, $filters)
    {
        if (empty($filters) || !is_array($filters)) {
            return;
        }

        // Limit the number of filters to prevent abuse
        $filters = array_slice($filters, 0, 20);

        $existingColumns = $this->tableManager->getExistingColumns();
        $allowedOperators = ['like', 'exact', 'starts_with', 'not_equal', 'gte', 'lte', 'gt', 'lt', 'range', 'multi_or'];

        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }

            $targetColumns = [];
            $filterColumns = Arr::get($filter, 'columns', []);
            if (!empty($filterColumns) && is_array($filterColumns)) {
                foreach ($filterColumns as $col) {
                    $sanitized = $this->tableManager->sanitizeColumnName($col);
                    if (in_array($sanitized, $existingColumns)) {
                        $targetColumns[] = $sanitized;
                    }
                }
            } elseif (Arr::get($filter, 'column')) {
                $sanitized = $this->tableManager->sanitizeColumnName(Arr::get($filter, 'column'));
                if (in_array($sanitized, $existingColumns)) {
                    $targetColumns[] = $sanitized;
                }
            }

            if (empty($targetColumns)) {
                continue;
            }

            $operator = Arr::get($filter, 'operator', 'like');
            if (!in_array($operator, $allowedOperators, true)) {
                continue;
            }

            $query->where(function ($q) use ($targetColumns, $operator, $filter, $query) {
                foreach ($targetColumns as $colName) {
                    $q->orWhere(function ($inner) use ($colName, $operator, $filter, $query) {
                        $this->applyOperatorCondition($inner, $colName, $operator, $filter, $query);
                    });
                }
            });
        }
    }

    protected function applyOperatorCondition($query, $colName, $operator, $filter, $parentQuery)
    {
        switch ($operator) {
            case 'like':
                global $wpdb;
                $val = Arr::get($filter, 'value', '');
                $query->where($colName, 'LIKE', '%' . $wpdb->esc_like($val) . '%');
                break;

            case 'exact':
                $val = Arr::get($filter, 'value', '');
                $query->where($colName, '=', $val);
                break;

            case 'starts_with':
                global $wpdb;
                $val = Arr::get($filter, 'value', '');
                $query->where($colName, 'LIKE', $wpdb->esc_like($val) . '%');
                break;

            case 'not_equal':
                $val = Arr::get($filter, 'value', '');
                $query->where($colName, '!=', $val);
                break;

            case 'gte':
                $column   = $this->getColumnBySanitizedName($colName);
                $val      = $this->normalizeNumericBound(Arr::get($filter, 'value', 0), $column);
                $castExpr = $parentQuery->raw($this->numericCastExpression($colName, $column));
                $query->where($castExpr, '>=', $val);
                break;

            case 'lte':
                $column   = $this->getColumnBySanitizedName($colName);
                $val      = $this->normalizeNumericBound(Arr::get($filter, 'value', 0), $column);
                $castExpr = $parentQuery->raw($this->numericCastExpression($colName, $column));
                $query->where($castExpr, '<=', $val);
                break;

            // Strict comparisons (used by the sf_match=gt|lt static shortcode filter).
            case 'gt':
                $column   = $this->getColumnBySanitizedName($colName);
                $val      = $this->normalizeNumericBound(Arr::get($filter, 'value', 0), $column);
                $castExpr = $parentQuery->raw($this->numericCastExpression($colName, $column));
                $query->where($castExpr, '>', $val);
                break;

            case 'lt':
                $column   = $this->getColumnBySanitizedName($colName);
                $val      = $this->normalizeNumericBound(Arr::get($filter, 'value', 0), $column);
                $castExpr = $parentQuery->raw($this->numericCastExpression($colName, $column));
                $query->where($castExpr, '<', $val);
                break;

            case 'range':
                $column   = $this->getColumnBySanitizedName($colName);
                $from     = $this->normalizeNumericBound(Arr::get($filter, 'value_from', 0), $column);
                $to       = $this->normalizeNumericBound(Arr::get($filter, 'value_to', 0), $column);
                $castExpr = $parentQuery->raw($this->numericCastExpression($colName, $column));
                $query->where($castExpr, '>=', $from);
                $query->where($castExpr, '<=', $to);
                break;

            case 'multi_or':
                $values      = Arr::get($filter, 'values', []);
                $values      = is_array($values) ? $values : [];
                $values      = array_map('sanitize_text_field', array_slice($values, 0, 100));
                $subOperator = Arr::get($filter, 'sub_operator', 'exact');

                if (empty($values)) {
                    break;
                }

                if ($subOperator === 'like') {
                    global $wpdb;
                    $query->where(function ($q) use ($colName, $values, $wpdb) {
                        foreach ($values as $v) {
                            $q->orWhere($colName, 'LIKE', '%' . $wpdb->esc_like($v) . '%');
                        }
                    });
                } else {
                    $query->whereIn($colName, $values);
                }
                break;

            default:
                break;
        }
    }

    /**
     * Resolve a column definition from its sanitized (DB) column name.
     *
     * @param string $colName
     * @return array|null
     */
    protected function getColumnBySanitizedName($colName)
    {
        foreach ($this->columns as $column) {
            $key = $this->tableManager->sanitizeColumnName(Arr::get($column, 'key', ''));
            if ($key === $colName) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Normalize a numeric filter bound to a float.
     *
     * The client sends canonical dot-decimal bounds (see canonicalNumber in
     * _dtCustomFilters.js). As a defensive fallback for any caller that still
     * passes a European display value, a lone decimal comma is honoured when
     * the column is configured that way before parsing.
     *
     * @param mixed      $value
     * @param array|null $column
     * @return float
     */
    protected function normalizeNumericBound($value, $column)
    {
        $value = preg_replace('/[^0-9.,\-]/', '', (string) $value);

        // Canonical dot-decimal (no comma) is the expected form.
        if (strpos($value, ',') === false) {
            return floatval($value);
        }

        // Fallback: a comma-decimal display value slipped through.
        if ($column && Arr::get($column, 'decimalSeparator') === ',') {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        return floatval($value);
    }

    /**
     * Build a CAST expression that normalizes the stored cell value before the
     * numeric comparison, honouring the column's decimalSeparator so a stored
     * "1.234,56" casts to 1234.56 rather than 1.234.
     *
     * $colName is already sanitized by sanitizeColumnName(); the REPLACE
     * arguments are static literals, so the expression is safe to embed.
     *
     * @param string     $colName
     * @param array|null $column
     * @return string
     */
    protected function numericCastExpression($colName, $column)
    {
        if ($column && Arr::get($column, 'decimalSeparator') === ',') {
            // Strip the '.' thousands separator, turn the ',' decimal into '.'.
            $expr = "REPLACE(REPLACE(`{$colName}`, '.', ''), ',', '.')";
        } else {
            // Strip the ',' thousands separator.
            $expr = "REPLACE(`{$colName}`, ',', '')";
        }

        return "CAST({$expr} AS DECIMAL(20,6))";
    }

    /**
     * Batch insert multiple rows into the dynamic table.
     *
     * @param array $rows Array of associative arrays [key => value]
     * @param int   $startPosition Starting position index
     * @param int   $chunkSize Number of rows per INSERT query
     *
     * @return int Number of rows inserted
     */
    public function batchInsert($rows, $startPosition = 0, $chunkSize = 1000)
    {
        if (!$this->tableExists() || empty($rows)) {
            return 0;
        }

        global $wpdb;

        $tableName      = $this->tableManager->getTableName();
        $existingColumns = $this->tableManager->getExistingColumns();
        $systemColumns   = DynamicTableManager::getSystemColumns();
        $ownerId         = get_current_user_id();

        // Build column key mapping: user key => sanitized DB column name
        $columnMap = [];
        foreach ($this->columns as $column) {
            $key          = Arr::get($column, 'key', '');
            $sanitizedKey = $this->tableManager->sanitizeColumnName($key);

            if (in_array($sanitizedKey, $systemColumns)) {
                continue;
            }

            if (in_array($sanitizedKey, $existingColumns)) {
                $columnMap[$key] = $sanitizedKey;
            }
        }

        // Build all prepared row data
        $preparedRows = [];
        foreach ($rows as $index => $rowValues) {
            $data = [
                DynamicTableManager::COL_POSITION => $startPosition + $index,
                DynamicTableManager::COL_OWNER_ID => $ownerId,
                DynamicTableManager::COL_SETTINGS => null,
            ];

            foreach ($columnMap as $userKey => $dbKey) {
                if (isset($rowValues[$userKey])) {
                    $data[$dbKey] = is_array($rowValues[$userKey])
                        ? json_encode($rowValues[$userKey])
                        : $rowValues[$userKey];
                } else {
                    $data[$dbKey] = null;
                }
            }

            $preparedRows[] = $data;
        }

        if (empty($preparedRows)) {
            return 0;
        }

        $inserted = 0;
        $chunks   = array_chunk($preparedRows, $chunkSize);

        foreach ($chunks as $chunk) {
            $columns    = array_keys($chunk[0]);
            sort($columns);
            $columnList = '`' . implode('`, `', $columns) . '`';

            $sql          = "INSERT INTO `{$tableName}` ({$columnList}) VALUES\n";
            $placeholders = [];
            $values       = [];

            foreach ($chunk as $row) {
                ksort($row);
                $rowPlaceholders = [];
                foreach ($row as $value) {
                    if ($value === null) {
                        $rowPlaceholders[] = 'NULL';
                    } else {
                        $values[]          = $value;
                        $rowPlaceholders[] = '%s';
                    }
                }
                $placeholders[] = '(' . implode(', ', $rowPlaceholders) . ')';
            }

            $sql .= implode(",\n", $placeholders);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $result = $wpdb->query($wpdb->prepare($sql, $values));

            if ($result !== false) {
                $inserted += $result;
            }
        }

        return $inserted;
    }

    public function duplicate($rowId)
    {
        $row = $this->find($rowId);
        if (!$row) {
            return false;
        }

        $mapped = $this->mapRowToUserKeys($row);

        return $this->insert(
            $mapped['values'],
            $mapped['settings'],
            null,
            get_current_user_id()
        );
    }
}
