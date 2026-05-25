<?php

namespace NinjaTables\App\Http\Controllers;

use NinjaTables\App\Models\Post;
use NinjaTables\App\Modules\DataProviders\NinjaFooTable;
use NinjaTables\Database\Migrations\NinjaTableItemsMigrator;
use NinjaTables\Framework\Http\Request\Request;
use NinjaTables\Framework\Support\Arr;
use NinjaTables\Framework\Support\Sanitizer;
use NinjaTables\App\Models\NinjaTableItem;

class TablesController extends Controller
{
    private $cptName = 'ninja-table';

    private function assertNinjaTable($id)
    {
        if (get_post_type($id) !== $this->cptName) {
            return $this->sendError([
                'message' => __('Invalid table.', 'ninja-tables'),
            ], 423);
        }
        return null;
    }

    public function index(Request $request)
    {
        $perPage = intval(Arr::get($request->all(), 'per_page')) ?: 10;

        $currentPage = intval(Arr::get($request->all(), 'page')) ?: 1;

        $skip = $perPage * ($currentPage - 1);

        $postStatus = Sanitizer::sanitizeTextField(Arr::get($request->all(), 'post_status'));

        $args = array(
            'posts_per_page' => $perPage,
            'offset'         => $skip,
            'orderby'        => Sanitizer::sanitizeTextField(Arr::get($request->all(), 'orderBy')),
            'order'          => Sanitizer::sanitizeTextField(Arr::get($request->all(), 'order')),
            'post_type'      => $this->cptName,
            'post_status'    => $postStatus,
        );

        if (Arr::get($request->all(), 'search') && $request->search) {
            $args['s'] = Sanitizer::sanitizeTextField($request->search);
        }

        try {
            $tables    = Post::getPosts($args);
            $total = $tables['total'];
            $tables    = $this->app->applyFilters('ninja_tables_get_all_tables', $tables['data']);
            $tablesRes = Post::getTables($perPage, $currentPage, $tables, $total);
            $this->json($tablesRes, 200);
        } catch (\Exception $e) {
            $this->json(array(
                'message' => __('An error occurred while loading tables.', 'ninja-tables')
            ), 300);
        }
    }

    public function store(Request $request)
    {
        if (empty($request->get('post_title'))) {
            wp_send_json([
                'message' => __('Title is required', 'ninja-tables')
            ], 422);
        }

        $postId = intval(Arr::get($request->all(), 'tableId'));

        if ($postId && get_post_type($postId) !== $this->cptName) {
            return $this->sendError([
                'message' => __('Invalid table.', 'ninja-tables')
            ], 423);
        }

        $caption = Arr::get($request->all(), 'table_caption');
        update_post_meta($postId, '_ninja_table_caption', Sanitizer::sanitizeTextField($caption));

        $attributes = array(
            'post_title'   => Sanitizer::sanitizeTextField(Arr::get($request->all(), 'post_title')),
            'post_content' => wp_kses_post(Arr::get($request->all(), 'post_content')),
            'post_type'    => $this->cptName,
            'post_status'  => 'publish'
        );

        $tableId = Post::saveTable($attributes, $postId);

        if (!$postId) {
            do_action('ninja_tables/update_table_settings', $tableId, [
                'request_data' => $request->all()
            ]);
            do_action('ninja_tables/after_table_created', $tableId, [
                'request_data' => $request->all()
            ]);
        }

        $this->json(array(
            'table_id' => $tableId,
            'message'  => $postId ? __('Table updated successfully', 'ninja-tables') : __('Table saved successfully.', 'ninja-tables')
        ), 200);
    }

    public function delete(Request $request, $id)
    {
        $tableId = intval($id);

        $tableExist = get_post($tableId);

        if (!$tableExist || get_post_type($tableId) !== 'ninja-table') {
             return $this->sendError([
                'message' => __('Invalid Table to Delete', 'ninja-tables')
            ], 423);
        }

        try {
            $action = Arr::get($request->all(), 'action', 'delete');

            if ($action === 'trash') {
                wp_trash_post($tableId);

                $this->json([
                    'message' => __('Table trashed successfully.', 'ninja-tables')
                ], 200);
            }

            Post::destroyTable($tableId);

            $this->json(array(
                'message' => __('Table deleted successfully.', 'ninja-tables')
            ), 200);
        } catch (\Exception $e) {
            $this->json(array(
                'message' => __('An error occurred while deleting the table.', 'ninja-tables')
            ), 300);
        }
    }

    public function duplicate(Request $request, $id)
    {
        $oldPostId = intval($id);

        if ( ! $oldPostId) {
            $this->json(array(
                'message' => __('Table not found.', 'ninja-tables')
            ), 404);
        }

        NinjaTableItemsMigrator::checkDBMigrations();

        $post = get_post($oldPostId);

        // Duplicate table itself.
        $attributes = array(
            'post_title'   => $post->post_title . '( Duplicate )',
            'post_content' => $post->post_content,
            'post_type'    => $post->post_type,
            'post_status'  => 'publish'
        );

        $newPostId = wp_insert_post($attributes);

        try {
            Post::makeDuplicate($oldPostId, $newPostId);

            $this->json(array(
                'message'  => __('Table duplicated successfully.', 'ninja-tables'),
                'table_id' => $newPostId
            ), 200);
        } catch (\Exception $e) {
            $this->json(array(
                'message' => __('An error occurred while duplicating the table.', 'ninja-tables')
            ), 300);
        }
    }

    public function dismissFluentSuggest(Request $request)
    {
        update_option('_ninja_tables_plugin_suggest_dismiss', time());
    }

    public function tableInnerHtml($id)
    {
        $tableId       = intval($id);
        $tableColumns  = ninja_table_get_table_columns($tableId, 'public');
        $tableSettings = ninja_table_get_table_settings($tableId, 'public');

        $formattedColumns = [];
        foreach ($tableColumns as $index => $column) {
            $formattedColumn             = NinjaFooTable::getFormattedColumn($column, $index, $tableSettings, true,
                'by_created_at');
            $formattedColumn['original'] = $column;
            $formattedColumns[]          = $formattedColumn;
        }

        $formatted_data = ninjaTablesGetTablesDataByID($tableId, $tableColumns, $tableSettings['default_sorting'], true,
            25);

        if (count($formatted_data) > 25) {
            $formatted_data = array_slice($formatted_data, 0, 25);
        }

        return (string)$this->app->view->make('public/table-inner-html', array(
            'table_columns' => $formattedColumns,
            'table_rows'    => $formatted_data
        ));
    }

    public function dragAndDropHtml($id)
    {
        $tableId       = intval($id);

        return [
            'html' => do_shortcode('[ninja_table_builder id="' . $tableId . '"]')
        ];
    }

    public function bulkDeleteTables(Request $request)
    {
        $tableIds = (array) Arr::get($request->all(), 'ids', []);
        $action   = Arr::get($request->all(), 'action');

        if (empty($tableIds)) {
            $this->sendError([
                'message' => __('No table selected.', 'ninja-tables')
            ], 422);
        }

        $validTableIds = array_filter($tableIds, function ($tableId) {
            return get_post_type($tableId) === 'ninja-table';
        });

        if (!$validTableIds) {
            return $this->sendError([
                'message' => __('No valid tables found.', 'ninja-tables')
            ], 422);
        }

        if ($action === 'trash') {
            foreach ($validTableIds as $tableId) {
                wp_trash_post($tableId);
            }

            return $this->sendSuccess([
                'data' => [
                    'message' => __('Tables trashed successfully.', 'ninja-tables')
                ]
            ], 200);
        } elseif ($action === 'delete') {
            foreach ($validTableIds as $tableId) {
                wp_delete_post($tableId, true);
            }

            NinjaTableItem::whereIn('table_id', $validTableIds)->delete();

            return $this->sendSuccess([
                'data' => [
                    'message' => __('Tables deleted successfully.', 'ninja-tables')
                ]
            ], 200);
        }
    }

    public function bulkRestoreTables(Request $request)
    {
        $tableIds = (array) Arr::get($request->all(), 'ids', []);

        if (empty($tableIds)) {
            return $this->sendError([
                'message' => __('No table selected.', 'ninja-tables')
            ], 422);
        }

        $validTableIds = array_filter($tableIds, function ($tableId) {
            $post = get_post($tableId);
            return $post && get_post_type($tableId) === 'ninja-table' && $post->post_status === 'trash';
        });

        if (!$validTableIds) {
            return $this->sendError([
                'message' => __('No valid trashed tables found.', 'ninja-tables')
            ], 422);
        }

        foreach ($validTableIds as $tableId) {
            wp_update_post(array(
                'ID'          => $tableId,
                'post_status' => 'publish'
            ));
        }

        return $this->sendSuccess([
            'data' => [
                'message' => __('Tables restored successfully.', 'ninja-tables')
            ]
        ], 200);
    }

    public function bulkDeleteColumns(Request $request, $id)
    {
        if ($response = $this->assertNinjaTable($id)) {
            return $response;
        }

        $deletableKeys = Arr::get($request->all(), 'deletable_keys', []);

        $getAllColumns = get_post_meta($id, '_ninja_table_columns', true);

        $filteredColumns = array_values(array_filter($getAllColumns, function ($column) use ($deletableKeys) {
            return ! in_array($column['key'], $deletableKeys);
        }));

        update_post_meta($id, '_ninja_table_columns', $filteredColumns);

        if (empty($filteredColumns)) {
            NinjaTableItem::where('table_id', $id)->delete();
        }

        return $this->sendSuccess([
            'data' => [
                'message' => __('Columns deleted successfully.', 'ninja-tables'),
                'columns' => $filteredColumns
            ]
        ], 200);
    }
}
