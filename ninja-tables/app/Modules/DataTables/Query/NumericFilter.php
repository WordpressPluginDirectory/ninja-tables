<?php

namespace NinjaTables\App\Modules\DataTables\Query;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\Framework\Support\Arr;

/**
 * Numeric filter helpers for the DataTables dynamic-table query builder.
 *
 * Pure and side-effect free: given a filter value / column definition they
 * return a normalized float bound or the SQL CAST expression used for numeric
 * comparisons. Extracted from DynamicRow so this injection-relevant logic can
 * be unit-tested in isolation.
 */
class NumericFilter
{
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
    public static function normalizeBound($value, $column)
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
     * $colName must already be sanitized by sanitizeColumnName(); the REPLACE
     * arguments are static literals, so the expression is safe to embed.
     *
     * @param string     $colName
     * @param array|null $column
     * @return string
     */
    public static function castExpression($colName, $column)
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
}
