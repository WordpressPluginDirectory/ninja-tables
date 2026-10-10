<?php

namespace NinjaTables\App\Services\Table;

if (!defined('ABSPATH')) {
    exit;
}

use NinjaTables\App\Models\NinjaTableItem;
use NinjaTables\App\Modules\DataTables\Database\DynamicTableManager;
use NinjaTables\App\Modules\DataTables\Models\DynamicRow;

/**
 * Reads a table's stored rows regardless of engine, in a uniform shape:
 * ['id','position','owner_id','value' => array,'settings','created_at','updated_at'].
 * Iteration is keyset-paged so very large tables stream at flat memory.
 */
class RowReader
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

    public function count()
    {
        if ($this->engine === Engine::DATATABLES) {
            $dynamicRow = new DynamicRow($this->tableId);

            return $dynamicRow->tableExists() ? (int) $dynamicRow->count() : 0;
        }

        return (int) (new NinjaTableItem())->where('table_id', $this->tableId)->count();
    }

    /**
     * @param int|null $chunk rows per query (defaults to the per-chunk filter)
     * @return \Generator<object>
     */
    public function each($chunk = null)
    {
        $chunk = $chunk ?: ninjaTablePerChunk($this->tableId);

        return $this->engine === Engine::DATATABLES
            ? $this->eachDynamic($chunk)
            : $this->eachItems($chunk);
    }

    /**
     * All rows, oldest first.
     *
     * @return object[]
     */
    public function all()
    {
        return iterator_to_array($this->each(), false);
    }

    /**
     * Just the values (column key => value) of every row.
     */
    public function values()
    {
        $out = [];
        foreach ($this->each() as $row) {
            $out[] = $row->value;
        }

        return $out;
    }

    protected function eachDynamic($chunk)
    {
        $manager = new DynamicTableManager($this->tableId);
        if (!$manager->tableExists()) {
            return;
        }

        $dynamicRow = new DynamicRow($this->tableId);
        $idColumn   = DynamicTableManager::COL_ID;
        $lastId     = 0;

        do {
            $rawRows = $dynamicRow->newQuery()
                ->where($idColumn, '>', $lastId)
                ->orderBy($idColumn, 'ASC')
                ->limit($chunk)
                ->get();
            $rawRows = is_array($rawRows) ? $rawRows : $rawRows->toArray();

            $count = 0;
            foreach ($rawRows as $rawRow) {
                $mapped = $dynamicRow->mapRowToUserKeys($rawRow);
                $lastId = (int) $mapped['id'];
                $count++;

                yield (object) [
                    'id'         => $mapped['id'],
                    'position'   => $mapped['position'] ?? 0,
                    'owner_id'   => $mapped['owner_id'] ?? 0,
                    'value'      => $mapped['values'],
                    'settings'   => !empty($mapped['settings']) ? $mapped['settings'] : null,
                    'created_at' => $mapped['created_at'] ?? '',
                    'updated_at' => $mapped['updated_at'] ?? '',
                ];
            }
        } while ($count === $chunk);
    }

    protected function eachItems($chunk)
    {
        $lastId = 0;

        do {
            $rows = (new NinjaTableItem())
                ->select(['id', 'position', 'owner_id', 'attribute', 'value', 'settings', 'created_at', 'updated_at'])
                ->where('table_id', $this->tableId)
                ->where('id', '>', $lastId)
                ->orderBy('id', 'ASC')
                ->limit($chunk)
                ->get();

            $count = 0;
            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $count++;

                // toArray() applies the model's date serialisation.
                $arr          = $row->toArray();
                $arr['value'] = json_decode($row->value, true);

                yield (object) $arr;
            }
        } while ($count === $chunk);
    }
}
