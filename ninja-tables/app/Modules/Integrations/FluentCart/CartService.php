<?php

namespace NinjaTables\App\Modules\Integrations\FluentCart;

if (!defined('ABSPATH')) {
    exit;
}

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CartHelper;
use NinjaTables\Framework\Support\Arr;

/**
 * FluentCart cart fragment and add-to-cart.
 */
class CartService
{
    /**
     * Mini-cart HTML printed before/after a FluentCart table.
     */
    public function fragmentHtml($tableId)
    {
        if (!defined('FLUENTCART_PLUGIN_PATH')) {
            return;
        }

        $appearanceSettings = get_post_meta($tableId, '_ninja_table_fct_appearance_settings', true);
        $showCheckoutBtn    = Arr::get($appearanceSettings, 'show_checkout_button') === 'yes';
        $showCartBtn        = Arr::get($appearanceSettings, 'show_cart_button') === 'yes';

        $cartInfo = CartHelper::getCart();

        $cartItems = Arr::get($cartInfo, 'cart_data', []);

        $itemCount   = count($cartItems);
        $totalAmount = $this->totalAmount($cartItems);

        $style = '';
        $class = 'nt-cart-visible';
        if (!$itemCount) {
            $style = 'display: none;';
            $class = '';
        }

        if ($itemCount > 1) {
            $itemText = __('Items', 'ninja-tables');
        } else {
            $itemText = __('Item', 'ninja-tables');
        }

        $storeSettings = new StoreSettings();
        $checkOutUrl   = $storeSettings->getCheckoutPage();
        $cartUrl       = $storeSettings->getCartPage();
        $cartUrl       = apply_filters('ninja_table_fct_cart_url', $cartUrl);

        $defaultCheckoutText = __('Checkout', 'ninja-tables');
        $checkOutText = Arr::get($appearanceSettings, 'checkoutBtnText', $defaultCheckoutText);
        $checkOutText = apply_filters('ninja_table_fct_checkout_text', $checkOutText);

        $defaultCartText = __('View cart', 'ninja-tables');
        $cartText = Arr::get($appearanceSettings, 'cartBtnText', $defaultCartText);
        $cartText = apply_filters('ninja_table_fct_cart_text', $cartText);

        ob_start();
        ?>
        <div style="<?php echo esc_attr($style); ?>" class="<?php echo esc_attr($class);?> ninjatable_cart_wrapper fluent-cart woocommerce widget_shopping_cart">
            <div class="cart_details fct-cart-details">
                <div class="nt_woo_items">
                    <span class="nt_fct_item_count"><?php echo esc_html($itemCount) . ' ' . esc_html($itemText); ?> </span>
                    <span class="nt_fct_separator">|</span>
                    <span class="nt_fct_amount"><?php echo esc_html($totalAmount); ?></span>
                </div>

                <div class="nt_fct_cart_checkout_buttons">
                    <?php
                    if ($showCartBtn): ?>
                        <a class="button fct-forward" href="<?php echo esc_url($cartUrl); ?>">
                            <span class="nt_fct_view_cart">
                                <i class="fooicon fooicon-bag"></i>
                                <?php echo esc_html($cartText); ?>
                            </span>
                        </a>
                    <?php endif; ?>

                    <?php if ($showCheckoutBtn): ?>
                        <a class="button checkout fct-forward" href="<?php echo esc_url($checkOutUrl); ?>">
                            <span class="nt_fct_view_cart">
                                <i class="fooicon fooicon-basket"></i>
                                <?php echo esc_html($checkOutText); ?>
                            </span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        $content = ob_get_contents();
        ob_end_clean();

        return $content;
    }

    /**
     * @param array $products [['variation_id' => int, 'quantity' => int], ...]
     */
    public function addItems(array $products)
    {
        foreach ($products as $product) {
            CartResource::update([
                'quantity' => intval(Arr::get($product, 'quantity')),
                'item_id'  => intval(Arr::get($product, 'variation_id')),
            ], '');
        }
    }

    public function totalAmount($cartItems)
    {
        $totalAmount = 0;
        foreach ($cartItems as $item) {
            $totalAmount += Arr::get($item, 'line_total', 0);
        }

        return CurrencySettings::getFormattedPrice($totalAmount);
    }
}
