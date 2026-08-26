<?php
/**
 * The storefront widget: asset loading and the configuration handed to it.
 *
 * Two deliberate choices here. There is no REST nonce: the chat route is
 * public by design (shop visitors are not logged in), and baking a nonce into
 * the page would break on any full-page cache while buying nothing, since the
 * route's real protection is the guard stack. And every UI string is passed
 * from PHP already translated, so the widget needs no JS translation files,
 * which are the fiddliest part of shipping a translated plugin.
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

class Widget
{
    public static function boot(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function enqueue(): void
    {
        if (! Settings::ready() || is_admin()) {
            return;
        }

        /** Lets a store hide the chat on specific pages (checkout, for instance). */
        if (! apply_filters('wsa_show_widget', true)) {
            return;
        }

        wp_enqueue_style('wsa-widget', WSA_URL . 'assets/widget.css', [], WSA_VERSION);
        // WooCommerce's own add-to-cart script: with it the card button adds
        // without a page load and updates the cart fragments; without it the
        // button is still a working link
        wp_enqueue_script('wc-add-to-cart');
        wp_enqueue_script('wsa-widget', WSA_URL . 'assets/widget.js', [], WSA_VERSION, true);

        wp_localize_script('wsa-widget', 'wsaConfig', self::config());
    }

    private static function config(): array
    {
        return [
            'endpoint' => esc_url_raw(rest_url('wsa/v1/chat')),
            'accent' => (string) Settings::get('accent'),
            'position' => (string) Settings::get('position'),
            'isRtl' => is_rtl(),
            'title' => (string) Settings::get('title'),
            'subtitle' => (string) Settings::get('subtitle'),
            'welcome' => (string) Settings::get('welcome'),
            'chips' => Settings::opening_chips(),
            'handoff' => [
                'url' => (string) Settings::get('handoff_url'),
                'label' => (string) Settings::get('handoff_label') ?: __('Contact us', 'woocommerce-shop-agent'),
            ],
            'i18n' => [
                'open' => __('Open chat', 'woocommerce-shop-agent'),
                'close' => __('Close chat', 'woocommerce-shop-agent'),
                'placeholder' => __('Type your question', 'woocommerce-shop-agent'),
                'send' => __('Send', 'woocommerce-shop-agent'),
                'thinking' => __('Typing', 'woocommerce-shop-agent'),
                'addToCart' => __('Add to cart', 'woocommerce-shop-agent'),
                'viewProduct' => __('View product', 'woocommerce-shop-agent'),
                'outOfStock' => __('Out of stock', 'woocommerce-shop-agent'),
                'onSale' => __('Sale', 'woocommerce-shop-agent'),
                'error' => __('Something went wrong. Please try again.', 'woocommerce-shop-agent'),
                'conversation' => __('Chat conversation', 'woocommerce-shop-agent'),
            ],
        ];
    }
}
