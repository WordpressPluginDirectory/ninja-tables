<?php

namespace NinjaTables\App\Modules\Integrations\FluentCart;

if (!defined('ABSPATH')) {
    exit;
}

use FluentCart\App\Models\Product;
use NinjaTables\Framework\Support\Arr;

/**
 * Builds the FluentCart product query for a table from its saved selections/conditions.
 */
class ProductQuery
{
    public function buildProductQuery($tableId, $withOrdering = true)
    {
        $querySelection  = get_post_meta($tableId, '_ninja_table_fct_query_selections', true);
        $queryConditions = get_post_meta($tableId, '_ninja_table_fct_query_conditions', true);

        $categories      = Arr::get($querySelection, 'product-categories');
        //TODO: rename product-types to product-brands
        $brands          = Arr::get($querySelection, 'product-types');
        $hideOutOfStock  = Arr::get($queryConditions, 'hide_out_of_stock');

        $query = Product::where('post_type', 'fluent-products')
                        ->where('post_status', 'publish');

        if ($hideOutOfStock === 'yes') {
            $query->join('fct_product_details', 'fct_product_details.post_id', '=', 'posts.ID')
                  ->where('fct_product_details.stock_availability', 'in-stock');
        }

        if (!empty($categories) || !empty($brands)) {
            if (!empty($categories)) {
                $query->join('term_relationships', 'term_relationships.object_id', '=', 'posts.ID')
                      ->leftJoin('term_taxonomy as category_taxonomy', function ($join) {
                          $join->on('category_taxonomy.term_taxonomy_id', '=', 'term_relationships.term_taxonomy_id')
                               ->where('category_taxonomy.taxonomy', 'product-categories');
                      })
                      ->leftJoin('terms as category_terms', 'category_terms.term_id', '=', 'category_taxonomy.term_id')
                      ->whereIn('category_terms.slug', $categories);
            }

            if (!empty($brands)) {
                $query->join('term_relationships as tr_brands', 'tr_brands.object_id', '=', 'posts.ID')
                      ->leftJoin('term_taxonomy as brand_taxonomy', function ($join) {
                          $join->on('brand_taxonomy.term_taxonomy_id', '=', 'tr_brands.term_taxonomy_id')
                               ->where('brand_taxonomy.taxonomy', 'product-brands');
                      })
                      ->leftJoin('terms as brand_terms', 'brand_terms.term_id', '=', 'brand_taxonomy.term_id')
                      ->whereIn('brand_terms.slug', $brands);
            }
        }

        if ($withOrdering) {
            $orderBy     = Arr::get($queryConditions, 'order_by');
            $orderByType = strtoupper((string) Arr::get($queryConditions, 'order_by_type'));
            $orderByType = in_array($orderByType, ['ASC', 'DESC'], true) ? $orderByType : 'DESC';

            if ($orderBy === 'post_title' || $orderBy === 'post_date') {
                $query->orderBy($orderBy, $orderByType);
            } elseif ($orderBy === 'price') {
                $query->select('posts.*')
                      ->join('fct_product_variations as fpv', 'posts.ID', '=', 'fpv.post_id')
                      ->orderByRaw('MIN(fpv.item_price) ' . $orderByType);
            } elseif ($orderBy === 'stock') {
                $query->select('posts.*')
                      ->join('fct_product_variations as fpv', 'posts.ID', '=', 'fpv.post_id')
                      ->orderByRaw('CASE WHEN SUM(fpv.available) > 0 THEN 0 ELSE 1 END ASC')
                      ->orderByRaw('SUM(fpv.available) ' . $orderByType);
            }
        }

        return $query;
    }

    public function getProducts($tableId, $limit = 0, $offset = 0)
    {
        $query = $this->buildProductQuery($tableId);

        $query->with('variants')->groupBy('posts.ID');

        $offset = intval($offset);
        if ($offset > 0) {
            $query->offset($offset);
        }

        $limit = intval($limit);
        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get();

        if (!$products->count()) {
            return [];
        }

        return $products;
    }

    public function getProductCount($tableId)
    {
        return (int) $this->buildProductQuery($tableId, false)
                          ->distinct()
                          ->count('posts.ID');
    }
}
