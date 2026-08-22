<?php

namespace NinjaTables\App\Http\Controllers;

use NinjaTables\App\Models\NinjaTableItem;
use NinjaTables\App\Modules\DataTables\Handlers\ItemsHandler;
use NinjaTables\Framework\Http\Request\Request;
use NinjaTables\Framework\Support\Arr;
use NinjaTables\Framework\Support\Sanitizer;

class TableItemsController extends Controller
{
    protected function isDataTablesTable($tableId)
    {
        $provider = ninja_table_get_data_provider($tableId);

        if ($provider !== 'default') {
            return false;
        }

        $settings = ninja_table_get_table_settings($tableId, 'public');
        return isset($settings['library']) && $settings['library'] === 'datatables';
    }

    public function index(Request $request, $id)
    {
        $tableId = intval($id);

        if ($this->isDataTablesTable($tableId)) {
            $result = ItemsHandler::getItems($tableId, $request->all());

            if (!$result['success']) {
                $this->json(['message' => $result['message']], 400);
                return;
            }

            unset($result['success']);
            $this->json($result, 200);
            return;
        }

        $perPage     = intval(Arr::get($request->all(), 'per_page', 10));
        $currentPage = intval(Arr::get($request->all(), 'page', 1));
        $skip        = $perPage * ($currentPage - 1);
        $tableId     = intval($id);
        $search      = Sanitizer::sanitizeTextField(Arr::get($request->all(), 'search'));

        $dataSourceType = ninja_table_get_data_provider($tableId);

        $data = NinjaTableItem::getItems($tableId, $perPage, $currentPage, $skip, $search, $dataSourceType);

        $this->json($data, 200);
    }

    public function delete(Request $request, $id)
    {
        $tableId = intval($id);

        if ($this->isDataTablesTable($tableId)) {
            $ids = Arr::get($request->all(), 'id');
            $result = ItemsHandler::delete($tableId, $ids);

            if (!$result['success']) {
                $this->json(['message' => $result['message']], 400);
                return;
            }

            $this->json(['message' => $result['message']], 200);
            return;
        }

        $data = ninja_tables_sanitize_array($request->all());

        $id = Arr::get($data, 'id');

        $ids = is_array($id) ? $id : array($id);

        $ids = array_map(function ($item) {
            return intval($item);
        }, $ids);

        NinjaTableItem::deleteTableItem($tableId, $ids);

        $this->json(array(
            'message' => __('Successfully deleted data.', 'ninja-tables')
        ), 200);
    }

    public function store(Request $request, $id)
    {
        $tableId = intval($id);

        if ($this->isDataTablesTable($tableId)) {
            $result = ItemsHandler::store($tableId, $request->all());

            if (!$result['success']) {
                $this->json(['message' => $result['message']], $result['message'] === __('Row not found.', 'ninja-tables') ? 404 : 400);
                return;
            }

            $this->json([
                'message' => $result['message'],
                'item' => $result['item'],
            ], 200);
            return;
        }

        if (user_can_richedit()) {
            $row = ninja_tables_sanitize_table_content_array(Arr::get($request->all(), 'row', []), $tableId);
        } else {
            ninja_tables_allowed_css_properties();
            $row = ninja_tables_sanitize_array(Arr::get($request->all(), 'row', []));
        }

        $formattedRow = array();

        foreach ($row as $key => $item) {
            $formattedRow[$key] = wp_unslash($item);
        }

        $created_at    = Arr::get($request->all(), 'created_at');
        $insertAfterId = Arr::get($request->all(), 'insert_after_id');
        $settings      = Arr::get($request->all(), 'settings');
        // Read the row id from the BODY only. The router merges the route's
        // {id} — the TABLE id — into the general inputs as 'id', so reading it
        // from all() makes an id-less insert look like an update of a
        // nonexistent row and 404s. post() never carries the route parameter.
        $rowId         = intval($request->post('id'));

        if ($rowId) {
            $row = NinjaTableItem::where('id', $rowId)->where('table_id', $tableId)->first();

            if (!$row) {
                return $this->sendError([
                    'message' => __('Row not found.', 'ninja-tables')
                ], 404);
            }
        }

        $data = NinjaTableItem::insertTableItem($rowId, $tableId, $formattedRow, $created_at, $insertAfterId, $settings);

        $this->json(array(
            'message' => __('Successfully saved the data.', 'ninja-tables'),
            'item'    => $data
        ), 200);
    }

    public function update(Request $request, $id)
    {
        $tableId = intval($id);

        if ($this->isDataTablesTable($tableId)) {
            $result = ItemsHandler::updateCell($tableId, $request->all());

            if (!$result['success']) {
                $this->json(['message' => $result['message']], $result['message'] === __('Row not found.', 'ninja-tables') ? 404 : 400);
                return;
            }

            return $this->sendSuccess([
                'data' => [
                    'message' => $result['message']
                ]
            ], 200);
        }

        $rowId = intval(Arr::get($request->all(), 'row_id'));

        $row = NinjaTableItem::where('id', $rowId)->where('table_id', $tableId)->first();

        if (!$row) {
            return $this->sendError([
                'message' => __('Row not found.', 'ninja-tables')
            ], 404);
        }

        if (user_can_richedit()) {
            $data = ninja_tables_sanitize_table_content_array($request->all(), $tableId);
        } else {
            ninja_tables_allowed_css_properties();
            $data = ninja_tables_sanitize_array($request->all());
        }

        $columnKey   = Sanitizer::sanitizeTextField(Arr::get($data, 'column_key'));
        $columnValue = Arr::get($data, 'column_value', '');

        if (is_string($columnValue)) {
            $columnValue = Sanitizer::sanitizeTextField($columnValue);
        }

        NinjaTableItem::editSingleCell($rowId, $row, $columnKey, $columnValue);

        return $this->sendSuccess([
            'data' => [
                'message' => __('Cell successfully updated', 'ninja-tables')
            ]
        ], 200);
    }
}
