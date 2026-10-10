<?php

namespace NinjaTables\App\Modules\Integrations\FluentCart\Handlers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use NinjaTables\App\Services\Vite;
use NinjaTables\App\App;
use NinjaTables\App\Modules\Integrations\FluentCart\CartService;
use NinjaTables\App\Modules\Integrations\FluentCart\ProductFormatter;
use NinjaTables\App\Modules\Integrations\FluentCart\ProductQuery;
use NinjaTables\Framework\Support\Arr;
use FluentCart\Api\StoreSettings;

/**
 * FluentCart data provider: admin ajax endpoints, provider hooks and frontend assets.
 */
class FluentCartHandler
{
    /** @var ProductQuery */
    protected $query;
    /** @var ProductFormatter */
    protected $formatter;
    /** @var CartService */
    protected $cart;

    public function __construct()
    {
        $this->query     = new ProductQuery();
        $this->formatter = new ProductFormatter();
        $this->cart      = new CartService();
    }

    private function assertNinjaTable($tableId)
    {
        if (get_post_type($tableId) !== 'ninja-table') {
            wp_send_json_error([
                'message' => __('No Table Found', 'ninja-tables')
            ], 423);
        }
    }

    public function getFluentCartOptions()
    {
        if (!current_user_can(ninja_table_admin_role())) {
            wp_send_json_error(['message' => __('You do not have permission to get options.', 'ninja-tables')], 403);
        }

        ninjaTablesValidateNonce();

        $settings = [
            'product-categories' => [
                'title'       => __('Select Products By Category', 'ninja-tables'),
                'description' => __('Select the categories from where you want to show the products. Leave empty if you want to show from all categories', 'ninja-tables'),
                'terms'       => get_terms([
                    'taxonomy'   => 'product-categories',
                    'hide_empty' => false,
                ])
            ],
            'product-types'      => [
                'title'       => __('Select Products By Product Brands', 'ninja-tables'),
                'description' => __('Select the product brands from where you want to show the products. Leave empty if you want to show from all types', 'ninja-tables'),
                'terms'       => get_terms([
                    'taxonomy'   => 'product-brands',
                    'hide_empty' => false,
                ])
            ]
        ];

        wp_send_json_success([
            'query_terms' => $settings
        ], 200);
    }

    public function createFluentCartTable()
    {
        if (!current_user_can(ninja_table_admin_role())) {
            wp_send_json_error(['message' => __('You do not have permission to create tables.', 'ninja-tables')], 403);
        }

        ninjaTablesValidateNonce();

        $messages   = array();
        $inputs     = App::getInstance('request')->all();
        $post_title = sanitize_text_field(Arr::get($inputs, 'post_title'));
        if ($post_title === '') {
            $messages['title'] = __('The title field is required.', 'ninja-tables');
        }

        // If Validation failed
        if (array_filter($messages)) {
            wp_send_json_error(array('message' => $messages), 422);
            wp_die();
        }

        $initialHeaders = App::getInstance('config')->get('fluent-cart-headers');

        $tableId = $this->saveTable();

        update_post_meta($tableId, '_ninja_wp_posts_query_extra', $this->getQueryExtra($tableId));

        $message = 'Table created successfully.';

        $query_selections = ninja_tables_sanitize_array(Arr::get($inputs, 'query_selections', []));
        $query_conditions = ninja_tables_sanitize_array(Arr::get($inputs, 'query_conditions', []));

        update_post_meta($tableId, '_ninja_table_fct_query_selections', $query_selections);
        update_post_meta($tableId, '_ninja_table_fct_query_conditions', $query_conditions);

        $appearanceSettings = [
            'show_cart_before_table' => 'yes',
            'show_cart_after_table'  => 'yes',
            'show_cart_button'       => 'yes',
            'show_checkout_button'   => 'yes'
        ];

        update_post_meta($tableId, '_ninja_table_fct_appearance_settings', $appearanceSettings);
        update_post_meta($tableId, '_ninja_table_columns', $initialHeaders);
        update_post_meta($tableId, '_ninja_tables_data_provider', 'wp_fct');

        wp_send_json_success(array('table_id' => $tableId, 'message' => $message), 200);
    }

    public function getTableData($response = array(array(), 0), $tableId = 0)
    {
        if (!current_user_can(ninja_table_admin_role())) {
            return;
        }

        $inputs  = ninjaTablesRequest();
        $tableId = intval($tableId) ?: intval(Arr::get($inputs, 'table_id'));

        $this->assertNinjaTable($tableId);

        $perPage     = intval(Arr::get($inputs, 'per_page', 20));
        $currentPage = intval(Arr::get($inputs, 'page', 1));
        $skip        = ($currentPage - 1) * $perPage;

        $columns = get_post_meta($tableId, '_ninja_table_columns', true);

        $total         = $this->query->getProductCount($tableId);
        $products      = $this->query->getProducts($tableId, $perPage, $skip);
        $formattedData = $this->formatter->adminRows($columns, $products);

        $data = [
            'data'        => $formattedData,
            'data_source' => 'wp_fct',
            'total'       => $total
        ];

        return wp_send_json($data, 200);
    }

    protected function saveTable($postId = null)
    {
        $inputs = App::getInstance('request')->all();

        $attributes = array(
            'post_title'  => sanitize_text_field(Arr::get($inputs, 'post_title')),
            'post_type'   => 'ninja-table',
            'post_status' => 'publish'
        );

        if (!$postId) {
            $postId = wp_insert_post($attributes);
        } else {
            $attributes['ID'] = $postId;
            wp_update_post($attributes);
        }

        return $postId;
    }

    public function getTableSettings($table)
    {
        $table->dataSourceType = get_post_meta($table->ID, '_ninja_tables_data_provider', true);

        $table->isEditable        = false;
        $table->isExportable      = true;
        $table->isImportable      = false;
        $table->isSortable        = false;
        $table->isCreatedSortable = false;
        $table->hasCacheFeature   = false;

        $metaKeys = [
            'query_selections'    => '_ninja_table_fct_query_selections',
            'query_conditions'    => '_ninja_table_fct_query_conditions',
            'appearance_settings' => '_ninja_table_fct_appearance_settings',
        ];

        foreach ($metaKeys as $property => $metaKey) {
            $value            = get_post_meta($table->ID, $metaKey, true);
            $table->$property = $value ?: (object)[];
        }

        return $table;
    }

    protected function getQueryExtra($tableId)
    {
        $queryExtra = get_post_meta($tableId, '_ninja_wp_posts_query_extra', true);

        if (!$queryExtra || $queryExtra == 'false') {
            $queryExtra = [
                'query_limit'     => 6000,
                'order_by_column' => 'ID',
                'order_by'        => 'DESC'
            ];
        }

        if (empty($queryExtra['query_limit'])) {
            $queryExtra['query_limit'] = 7000;
        }

        return apply_filters('ninja_table_wp_posts_query_extra', $queryExtra, $tableId);
    }

    public function saveQuerySettings(): void
    {
        if (!current_user_can(ninja_table_admin_role())) {
            return;
        }
        ninjaTablesValidateNonce();

        $inputs  = App::getInstance('request')->all();
        $tableId = intval(Arr::get($inputs, 'table_id'));

        if (!$tableId) {
            wp_send_json_error(array('message' => __('Table not found', 'ninja-tables')), 400);
        }

        $this->assertNinjaTable($tableId);

        $data = [
            'query_selections'    => ninja_tables_sanitize_array(Arr::get($inputs, 'query_selections', [])),
            'query_conditions'    => ninja_tables_sanitize_array(Arr::get($inputs, 'query_conditions', [])),
            'appearance_settings' => ninja_tables_sanitize_array(Arr::get($inputs, 'appearance_settings', []))
        ];

        update_post_meta($tableId, '_ninja_table_fct_query_selections', $data['query_selections']);
        update_post_meta($tableId, '_ninja_table_fct_query_conditions', $data['query_conditions']);
        update_post_meta($tableId, '_ninja_table_fct_appearance_settings', $data['appearance_settings']);

        wp_send_json_success(array('message' => __('Settings successfully updated', 'ninja-tables')), 200);
    }

    public function getCustomFieldOptions(): void
    {
        if (!current_user_can(ninja_table_admin_role())) {
            return;
        }

        ninjaTablesValidateNonce();

        $fields = App::getInstance('config')->get('fluent-cart-dynamic-column-option');

        if (post_type_exists('fluent-products')) {
            $fields[] = $this->getProductTaxonomyFields('fluent-products');
        }

        wp_send_json_success([
            'custom_fields' => $fields
        ]);
    }

    public function getProductTaxonomyFields($postType): array
    {
        $taxonomies = get_object_taxonomies($postType);

        $attributes = [];
        foreach ($taxonomies as $taxonomy) {
            $attributes[$taxonomy] = ucwords(str_replace('-', ' ', $taxonomy));
        }

        return [
            "key"         => 'tax_data',
            'source_type' => 'tax_data',
            "label"       => 'Product Taxonomy',
            "instruction" => 'Show Product Categories & Type From Here',
            "value_type"  => 'options',
            "placeholder" => 'Select Data Attribute',
            "options"     => $attributes
        ];
    }


    public function fetchData($data, $tableId, $defaultSorting, $limitEntries = false, $skip = false)
    {
        $perPage = -1;

        if ($limitEntries) {
            $perPage = $limitEntries;
        }

        return $this->getPosts($tableId, $perPage, $skip);
    }

    public function getPosts($tableId, $per_page = -1, $offset = 0)
    {
        $frontendColumns = $this->formatter->getFrontendColumns($tableId);

        $limit    = ($per_page > 0) ? $per_page : 0;
        $offset   = intval($offset);
        $products = $this->query->getProducts($tableId, $limit, $offset);

        return $this->formatter->frontendRows($frontendColumns, $products);
    }

    public function addFrontendAsset($tableArray)
    {
        $assets  = App::getInstance('url.assets');
        $tableId = intval(Arr::get($tableArray, 'table_id'));

        Vite::enqueueScript('ninja_table_fluent_cart_frontend_js', 'js/fluent-cart/fct_table_frontend.js', ['jquery']);

        $storeSettings = new StoreSettings();
        $cartUrl       = $storeSettings->getCartPage();
        $checkOutUrl   = $storeSettings->getCheckoutPage();

        wp_localize_script(
            'ninja_table_fluent_cart_frontend_js',
            'NinjaTablesFCTProducts',
            array(
                'cartUrl'     => $cartUrl,
                'checkOutUrl' => $checkOutUrl,
            )
        );

        $appearanceSettings  = get_post_meta($tableId, '_ninja_table_fct_appearance_settings', true);
        $showCartBeforeTable = Arr::get($appearanceSettings, 'show_cart_before_table', 'yes');
        $showCartAfterTable  = Arr::get($appearanceSettings, 'show_cart_after_table', 'yes');

        if ($showCartBeforeTable === 'yes') {
            add_action('ninja_tables_before_table_print', array($this, 'maybeAddCartDom'), 10, 2);
        }
        if ($showCartAfterTable === 'yes') {
            add_action('ninja_tables_after_table_print', array($this, 'maybeAddCartDom'), 10, 2);
        }
    }

    public function maybeAddCartDom($table, $tableArray)
    {
        if (Arr::get($tableArray, 'provider') !== 'wp_fct') {
            return '';
        }
        $tableId = intval(Arr::get($tableArray, 'table_id'));
        echo $this->cart->fragmentHtml($tableId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function addToCart()
    {
        $inputs = App::getInstance('request')->all();
        $nonce = sanitize_text_field(Arr::get($inputs, 'nonce', ''));
        if (!wp_verify_nonce($nonce, 'ninja_table_public_nonce')) {
            wp_send_json([
                'message' => __('Invalid nonce', 'ninja-tables')
            ], 403);
        }

        $products = ninja_tables_sanitize_array(Arr::get($inputs, 'products', []));
        $tableId  = intval(Arr::get($inputs, 'table_id'));

        $this->cart->addItems($products);

        wp_send_json([
            'success'   => true,
            'cart_html' => $this->cart->fragmentHtml($tableId)
        ]);
    }

}
