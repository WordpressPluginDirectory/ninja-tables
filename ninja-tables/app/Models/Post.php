<?php

namespace NinjaTables\App\Models;

use NinjaTables\Framework\Foundation\App;
use NinjaTables\Framework\Support\Arr;
use NinjaTables\App\Modules\DataTables\Database\DynamicTableManager;
use NinjaTables\App\Services\Table\Engine;

class Post extends Model
{
    private static $cptName = 'ninja-table';
    protected $table = 'posts';

    public static function getPosts($args)
    {
        $orderByes = ['ID', 'post_title'];
        $orderBy   = Arr::get($args, 'orderby', 'ID');
        $postStatus = Arr::get($args, 'post_status', 'publish');
        $orderBy   = in_array($orderBy, $orderByes) ? $orderBy : 'ID';

        $posts = Post::where('post_type', self::$cptName)
                     ->where('post_status', $postStatus)
                     ->where(function ($query) use ($args) {
                         if (isset($args['s'])) {
                             $query->where('post_title', 'like', '%' . $args['s'] . '%')
                                   ->orWhere('ID', 'like', '%' . $args['s'] . '%')
                                   ->orWhere('post_content', 'like', '%' . $args['s'] . '%');
                         }
                     });
        $total = $posts->count();
        $orderByType = Arr::get($args, 'order');
        $orderByType = in_array(strtoupper($orderByType), ['ASC', 'DESC']) ? $orderByType : 'DESC';

        $posts = $posts->orderBy($orderBy, $orderByType)
                       ->skip($args['offset'])
                       ->take($args['posts_per_page'])
                       ->get();

        return [
            'total' => $total,
            'data'  => $posts,
        ];
    }

    public static function getTables($perPage, $currentPage, $tables, $total = 0)
    {
        foreach ($tables as $table) {
            $provider = get_post_meta($table->ID, '_ninja_tables_data_provider', true);
            if ($provider === 'drag_and_drop') {
                $table->preview_url = site_url('?ninjatable_builder_preview=' . $table->ID);
            } else {
                $table->preview_url = site_url('?ninjatable_preview=' . $table->ID);
            }
            $dataSourceType        = ninja_table_get_data_provider($table->ID);
            $table->dataSourceType = $dataSourceType;
            $tableSettings         = ninja_table_get_table_settings($table->ID, 'admin');
            $table->tableType      = Arr::get($tableSettings, 'library', 'footable');
            if ($dataSourceType == 'fluent-form') {
                $fluentFormFormId = get_post_meta($table->ID, '_ninja_tables_data_provider_ff_form_id', true);
                if ($fluentFormFormId) {
                    $table->fluentfrom_url = admin_url(
                        'admin.php?page=fluent_forms&route=entries&form_id=' . $fluentFormFormId
                    );
                }
            } elseif ($dataSourceType == 'csv' || $dataSourceType == 'google-csv') {
                $table->remoteURL = get_post_meta($table->ID, '_ninja_tables_data_provider_url', true);
            }
        }

        $lastPage = ceil($total / $perPage);

        return [
            'total'        => intval($total),
            'per_page'     => $perPage,
            'current_page' => $currentPage,
            'last_page'    => ($lastPage) ? $lastPage : 1,
            'data'         => $tables,
        ];
    }

    public static function saveTable($attributes, $postId = null)
    {
        if ( ! $postId) {
            $postId = wp_insert_post($attributes);
        } else {
            $attributes['ID'] = $postId;
            wp_update_post($attributes);
        }
        update_post_meta($postId, '_last_edited_by', get_current_user_id());
        update_post_meta($postId, '_last_edited_time', gmdate('Y-m-d H:i:s'));

        return $postId;
    }

    public static function destroyTable($tableId)
    {
        wp_delete_post($tableId, true);

        NinjaTableItem::where('table_id', $tableId)->delete();
    }

    public static function makeDuplicate($oldPostId, $newPostId)
    {
        global $wpdb;
        $oldPostId = (int)$oldPostId;
        $newPostId = (int)$newPostId;

        // Duplicate table settings. Caches belong to the source table (same list the
        // exporter skips); the copy builds its own on first render.
        $cacheMetaKeys = [
            '_ninja_table_cache_object',
            '_ninja_table_cache_html',
            '_external_cached_data',
            '_last_external_cached_time',
            '__ninja_cached_table_html',
            '__ninja_cached_table_html_policy',
            '__last_ninja_table_last_cached_time',
        ];
        $postMetas = get_post_meta($oldPostId);

        foreach ($postMetas as $metaKey => $metaValue) {
            if (in_array($metaKey, $cacheMetaKeys, true)) {
                continue;
            }
            update_post_meta($newPostId, $metaKey, maybe_unserialize($metaValue[0]));
        }

        // DataTables keeps its rows in a per-table table, not in ninja_table_items.
        if (Engine::isDataTables($oldPostId)) {
            static::duplicateDynamicRows($oldPostId, $newPostId);
        }

        // Duplicate table rows.
        $itemsTable = $wpdb->prefix . esc_sql(ninja_tables_db_table_name());

        // Only the table name is interpolated (prefix + fixed name, esc_sql'd); %i needs WP 6.2.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $copied = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "INSERT INTO {$itemsTable} (`position`, `table_id`, `owner_id`, `settings`, `attribute`, `value`, `created_at`, `updated_at`)
                 SELECT `position`, %d, `owner_id`, `settings`, `attribute`, `value`, `created_at`, `updated_at` FROM {$itemsTable}
                 WHERE `table_id` = %d",
                $newPostId,
                $oldPostId
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ($copied === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal; the controller replies with its own message
            throw new \RuntimeException('Could not copy the table rows: ' . $wpdb->last_error);
        }
    }

    /**
     * Copy a DataTables table's own row table (same columns and indexes) with all rows.
     * Nothing to copy when the source never got one (columns not saved yet).
     */
    protected static function duplicateDynamicRows($oldPostId, $newPostId)
    {
        global $wpdb;

        $source = new DynamicTableManager($oldPostId);
        if (!$source->tableExists()) {
            return;
        }
        $target = new DynamicTableManager($newPostId);
        $from   = $source->getTableName();
        $to     = $target->getTableName();

        // Both names are the prefix + a fixed name + an int id; %i needs WP 6.2.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
        // Each step is checked: DDL can't be rolled back, so the caller removes the copy on failure.
        if (!$target->tableExists() && $wpdb->query("CREATE TABLE `{$to}` LIKE `{$from}`") === false) {
            $failed = 'Could not create the row table for the copy: ' . $wpdb->last_error;
        } elseif ($wpdb->query("INSERT INTO `{$to}` SELECT * FROM `{$from}`") === false) {
            $failed = 'Could not copy the table rows: ' . $wpdb->last_error;
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if (isset($failed)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal; the controller replies with its own message
            throw new \RuntimeException($failed);
        }
    }

    public static function updatedSettings($tableId, $rawColumns, $tablePreference)
    {
        $tableColumns             = array();
        $formattedTablePreference = array();
        $provider                 = ninja_table_get_data_provider($tableId);

        if (is_array($rawColumns) && empty($rawColumns) && ! $tablePreference) {
                update_post_meta($tableId, '_ninja_table_columns', $tableColumns);
                if ($provider === 'default') {
                    NinjaTableItem::where('table_id', $tableId)->delete();
                }
        } elseif (is_array($rawColumns) && ! empty($rawColumns)) {
            foreach ($rawColumns as $column) {
                foreach ($column as $column_index => $column_value) {
                    if ($provider === 'google-csv' && gettype($column_value) === 'string') {
                        $column_value = htmlspecialchars_decode($column_value);
                    }
                    if (is_int($column_value)) {
                        $column[$column_index] = intval($column_value);
                    } else {
                        $column[$column_index] = $column_value;
                    }
                }
                $tableColumns[] = $column;
            }
            $tableColumns = apply_filters(
                'ninja_table_update_columns_' . ninja_table_get_data_provider($tableId),
                $tableColumns,
                $rawColumns,
                $tableId
            );
            do_action(
                'ninja_table_before_update_columns_' . ninja_table_get_data_provider($tableId),
                $tableColumns,
                $rawColumns,
                $tableId
            );
            do_action('ninja_table_before_update_columns', $tableColumns, $rawColumns, $tableId);
            update_post_meta($tableId, '_ninja_table_columns', $tableColumns);
        }

        if ($tablePreference && is_array($tablePreference)) {
            $formattedTablePreference = ninjaTableNormalize($tablePreference);
            update_post_meta($tableId, '_ninja_table_settings', $formattedTablePreference);
        }

        ninjaTablesClearTableDataCache($tableId);

        update_post_meta($tableId, '_last_edited_by', get_current_user_id());
        update_post_meta($tableId, '_last_edited_time', gmdate('Y-m-d H:i:s'));

        return [
            'message'  => __('Successfully updated configuration.', 'ninja-tables'),
            'columns'  => $tableColumns,
            'settings' => $formattedTablePreference,
        ];
    }
}
