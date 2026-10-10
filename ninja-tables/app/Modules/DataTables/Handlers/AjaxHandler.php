<?php

namespace NinjaTables\App\Modules\DataTables\Handlers;

defined('ABSPATH') || exit;

use NinjaTables\App\Modules\DataTables\Database\DynamicTableManager;
use NinjaTables\App\Modules\DataTables\Models\DynamicRow;
use NinjaTables\App\Services\Render\DataTablesRowFormatter;
use NinjaTables\Framework\Support\Arr;

/**
 * Public (logged-in and anonymous) AJAX endpoints backing server-side DataTables.
 */
class AjaxHandler
{
    public function register()
    {
        add_action('wp_ajax_ninja_tables_dt_public', [$this, 'handleAjax']);
        add_action('wp_ajax_nopriv_ninja_tables_dt_public', [$this, 'handleAjax']);
        add_action('wp_ajax_ninja_tables_dt_filter_options', [$this, 'handleFilterOptions']);
        add_action('wp_ajax_nopriv_ninja_tables_dt_filter_options', [$this, 'handleFilterOptions']);
    }

    public function handleAjax()
    {
        $request = ninjaTablesRequest();

        $tableId = intval(Arr::get($request, 'table_id', 0));
        $draw    = intval(Arr::get($request, 'draw', 1));

        $emptyResponse = [
            'draw'            => $draw,
            'recordsTotal'    => 0,
            'recordsFiltered' => 0,
            'data'            => [],
        ];

        if (!$tableId) {
            wp_send_json($emptyResponse);
        }

        $post = get_post($tableId);
        if (!$post || $post->post_type !== 'ninja-table' || $post->post_status !== 'publish') {
            wp_send_json($emptyResponse);
        }

        try {
            $start  = intval(Arr::get($request, 'start', 0));
            $length = intval(Arr::get($request, 'length', 20));

            // DataTables sends length=-1 when paging is disabled (meaning "all rows")
            if ($length < 1) {
                $length = apply_filters('ninja_tables_dt_max_length_all', 10000);
            }

            $search = substr(sanitize_text_field(Arr::get($request, 'search.value', '')), 0, 200);

            $customFilters = [];
            $ninjaFilters  = Arr::get($request, 'ninja_filters', '');
            if (!empty($ninjaFilters)) {
                $rawFilters = json_decode(wp_unslash($ninjaFilters), true);
                if (is_array($rawFilters)) {
                    $customFilters = $rawFilters;
                }
            }

            // Shortcode-locked sf_* filter: verified from its signature, never trusted
            // raw from the client. Restricts the whole dataset, so it also drives
            // recordsTotal (the "unfiltered" universe for this shortcode instance).
            $lockedFilters = DataTablesRowFormatter::getVerifiedLockedFilters($request);
            $allFilters    = array_merge($lockedFilters, $customFilters);

            $orderColIdx = intval(Arr::get($request, 'order.0.column', 0));
            $orderDirRaw = strtoupper(sanitize_text_field(Arr::get($request, 'order.0.dir', 'asc')));
            $orderDir    = ($orderDirRaw === 'DESC') ? 'DESC' : 'ASC';

            $tableColumns = ninja_table_get_table_columns($tableId, 'public');
            if (!$tableColumns) {
                wp_send_json($emptyResponse);
            }

            $visibleColumns = array_values(array_filter($tableColumns, function ($col) {
                return Arr::get($col, 'breakpoints') !== 'hidden';
            }));

            $orderByColumn = DynamicTableManager::COL_POSITION;
            $requestedColName = sanitize_text_field(Arr::get($request, "columns.{$orderColIdx}.name", ''));
            if ($requestedColName && $requestedColName !== '____editing____' && $requestedColName !== '____responsive_toggle____') {
                foreach ($visibleColumns as $col) {
                    if (Arr::get($col, 'key') === $requestedColName) {
                        $isSortable = Arr::get($col, 'unsortable') !== 'yes';
                        if ($isSortable) {
                            $orderByColumn = $requestedColName;
                        }
                        break;
                    }
                }
            } elseif (isset($visibleColumns[$orderColIdx])) {
                $col = $visibleColumns[$orderColIdx];
                $isSortable = Arr::get($col, 'unsortable') !== 'yes';
                if ($isSortable) {
                    $orderByColumn = Arr::get($col, 'key');
                }
            }

            $dynamicRow = new DynamicRow($tableId);

            if (!$dynamicRow->tableExists()) {
                wp_send_json($emptyResponse);
            }

            // A table set to "own data only" must scope the public feed to the
            // viewer's rows; the DataTables client never sends own_only, so the
            // decision is made entirely server-side (Pro answers the filter).
            // Scopes the counts as well so the pager stays consistent.
            if ((bool) apply_filters('ninja_tables_public_force_own_only', false, $tableId)) {
                $viewerId = get_current_user_id();
                $dynamicRow->restrictToOwner($viewerId > 0 ? $viewerId : -1);
            }

            $recordsTotal    = !empty($lockedFilters)
                ? $dynamicRow->count(null, $lockedFilters)
                : $dynamicRow->count();
            // recordsTotal already reflects the locked filters, so only run a second
            // count when the user narrowed further via search or a custom filter.
            $recordsFiltered = ($search || !empty($customFilters))
                ? $dynamicRow->count($search, $allFilters)
                : $recordsTotal;

            $page    = ($start / max($length, 1)) + 1;
            $orderBy = $dynamicRow->getTableManager()->sanitizeColumnName($orderByColumn);
            $rows    = $dynamicRow->getAll($length, $page, $orderBy, $orderDir, $search ?: null, $allFilters);

            $columnTypeMap = DataTablesRowFormatter::buildColumnTypeMap($tableColumns);

            $data            = [];
            $counter         = $start;
            $executeShortcodes = ninja_tables_provider_executes_shortcodes($tableId);
            foreach ($rows as $row) {
                $mapped  = $dynamicRow->mapRowToUserKeys($row);
                $rowData = Arr::get($mapped, 'values', []);

                $shortcodesRendered = false;
                if ($executeShortcodes) {
                    $rowData = DataTablesRowFormatter::renderShortcodesInRowValues($rowData, $columnTypeMap);
                    $shortcodesRendered = true;
                }

                $rowData = DataTablesRowFormatter::escapeRowValues($rowData, $columnTypeMap, $shortcodesRendered);

                $colId = DynamicTableManager::COL_ID;
                $rowData['DT_RowClass'] = 'ninja_table_row_' . $counter . ' nt_row_id_' . $row->{$colId};

                if (!empty(Arr::get($mapped, 'settings', []))) {
                    $rowData['___row_settings___'] = Arr::get($mapped, 'settings', []);
                }

                $data[] = $rowData;
                $counter++;
            }

            wp_send_json([
                'draw'            => $draw,
                'recordsTotal'    => $recordsTotal,
                'recordsFiltered' => $recordsFiltered,
                'data'            => $data,
            ]);
        } catch (\Throwable $e) {
            wp_send_json($emptyResponse);
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * AJAX: distinct values for the requested dynamic-data filter columns, narrowed by
     * the active custom filters. Lets server-side (ajax_table) tables build full
     * dynamic-select option lists that the client cannot derive from a single page.
     */
    public function handleFilterOptions()
    {
        $request = ninjaTablesRequest();
        $tableId = intval(Arr::get($request, 'table_id', 0));

        if (!$tableId) {
            wp_send_json(['options' => []]);
        }

        $post = get_post($tableId);
        if (!$post || $post->post_type !== 'ninja-table' || $post->post_status !== 'publish') {
            wp_send_json(['options' => []]);
        }

        try {
            $columns = Arr::get($request, 'columns', []);
            if (is_string($columns)) {
                $columns = json_decode(wp_unslash($columns), true);
            }
            $columns = is_array($columns) ? array_slice(array_values(array_filter($columns)), 0, 20) : [];

            // Only serve columns actually configured as dynamic-data select filters for this table.
            $allowedColumns = [];
            $savedFilters   = get_post_meta($tableId, '_ninja_table_custom_filters', true);
            if (is_array($savedFilters)) {
                foreach ($savedFilters as $savedFilter) {
                    if (
                        is_array($savedFilter)
                        && Arr::get($savedFilter, 'type') === 'select'
                        && Arr::get($savedFilter, 'select_value_type') === 'dynamic_data'
                        && Arr::get($savedFilter, 'dynamic_select_column')
                    ) {
                        $allowedColumns[] = Arr::get($savedFilter, 'dynamic_select_column');
                    }
                }
            }
            // Deduplicate so a request repeating one column can't force repeated
            // identical DISTINCT scans (and can't vary the cache key by ordering).
            $columns = array_values(array_unique(array_intersect($columns, $allowedColumns)));

            $search = substr(sanitize_text_field(Arr::get($request, 'search', '')), 0, 200);

            $customFilters = [];
            $ninjaFilters  = Arr::get($request, 'ninja_filters', '');
            if (!empty($ninjaFilters)) {
                $decoded = json_decode(wp_unslash($ninjaFilters), true);
                if (is_array($decoded)) {
                    $customFilters = $decoded;
                }
            }

            // Whether the caller supplied filters of their own (before we add the
            // server-derived locked filter) decides cacheability below.
            $hasUserFilters = !empty($customFilters);

            // Keep option lists inside the shortcode-locked (sf_*) subset.
            $lockedFilters = DataTablesRowFormatter::getVerifiedLockedFilters($request);
            if (!empty($lockedFilters)) {
                $customFilters = array_merge($lockedFilters, $customFilters);
            }

            // Cache ONLY the canonical, high-reuse state — empty search and no
            // user-supplied filters (the initial/reset set every visitor requests) —
            // so an unauthenticated caller can't force unbounded transient writes by
            // varying search/ninja_filters. Columns are sorted so equivalent sets share
            // one key. Short, filterable TTL; set the filter to 0 to disable caching.
            sort($columns);
            $cacheTtl    = (int) apply_filters('ninja_tables_dt_filter_options_cache_ttl', 5 * MINUTE_IN_SECONDS, $tableId);
            $isCanonical = ($search === '' && !$hasUserFilters);
            // The version is bumped by ninjaTablesClearTableDataCache() on every
            // mutation, so a row edit changes the key and the stale options list
            // is never re-read (FIX-PLAN #6).
            $cacheVersion = (int) get_post_meta($tableId, '_nt_dt_fopts_version', true);
            $cacheKey    = ($cacheTtl > 0 && $isCanonical)
                ? 'nt_dt_fopts_' . md5($tableId . '|' . $cacheVersion . '|' . wp_json_encode($lockedFilters) . '|' . implode(',', $columns))
                : '';
            if ($cacheKey) {
                $cached = get_transient($cacheKey);
                if (is_array($cached)) {
                    wp_send_json(['options' => $cached]);
                }
            }

            $dynamicRow = new DynamicRow($tableId);
            if (!$dynamicRow->tableExists()) {
                wp_send_json(['options' => []]);
            }

            $options = [];
            foreach ($columns as $col) {
                $col = sanitize_text_field($col);
                $options[$col] = $dynamicRow->getDistinctValues($col, $search ?: null, $customFilters);
            }

            if ($cacheKey) {
                set_transient($cacheKey, $options, $cacheTtl);
            }

            wp_send_json(['options' => $options]);
        } catch (\Throwable $e) {
            wp_send_json(['options' => []]);
        }
    }
}
