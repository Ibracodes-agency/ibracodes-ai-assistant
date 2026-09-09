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

namespace Ibracodes\AI_Assistant;

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
        if (! apply_filters('ibraai_show_widget', true)) {
            return;
        }

        wp_enqueue_style('ibraai-widget', IBRAAI_URL . 'assets/widget.css', [], IBRAAI_VERSION);
        if (Capabilities::has_commerce()) {
            // WooCommerce's own add-to-cart script: with it the card button adds
            // without a page load and updates the cart fragments; without it the
            // button is still a working link
            wp_enqueue_script('wc-add-to-cart');
        }
        wp_enqueue_script('ibraai-widget', IBRAAI_URL . 'assets/widget.js', [], IBRAAI_VERSION, true);

        wp_localize_script('ibraai-widget', 'ibraaiConfig', self::config());
    }

    public static function config(): array
    {
        $config = [
            'endpoint' => esc_url_raw(rest_url('ibraai/v1/chat')),
            'accent' => (string) Settings::get('accent'),
            'position' => (string) Settings::get('position'),
            'isRtl' => is_rtl(),
            'launcherLabel' => Settings::get('show_launcher_label') ? (string) Settings::get('launcher_label') : '',
            'title' => (string) Settings::get('title'),
            'subtitle' => (string) Settings::get('subtitle'),
            'welcome' => (string) Settings::get('welcome'),
            'chips' => Settings::opening_chips(),
            'handoff' => [
                'url' => (string) Settings::get('handoff_url'),
                'label' => (string) Settings::get('handoff_label') ?: __('Contact us', 'ibracodes-ai-assistant'),
            ],
            'pageId' => is_singular() ? (int) get_queried_object_id() : 0,
            'privacyNote' => (string) Settings::get('privacy_note'),
            // opt-in: a credit link on the public site is the owner's choice
            'brand' => Settings::get('show_credit') ? [
                'url' => 'https://ibracodes.com/?utm_source=ai-assistant&utm_medium=widget',
                'label' => __('Developed by Ibracodes', 'ibracodes-ai-assistant'),
                'logo' => IBRAAI_URL . 'assets/ibracodes.svg',
            ] : null,
            'i18n' => [
                'open' => __('Open chat', 'ibracodes-ai-assistant'),
                'close' => __('Close chat', 'ibracodes-ai-assistant'),
                'placeholder' => __('Type your question', 'ibracodes-ai-assistant'),
                'send' => __('Send', 'ibracodes-ai-assistant'),
                'thinking' => __('Typing', 'ibracodes-ai-assistant'),
                'error' => __('Something went wrong. Please try again.', 'ibracodes-ai-assistant'),
                'conversation' => __('Chat conversation', 'ibracodes-ai-assistant'),
                /* translators: %s: the name of the person who joined the chat */
                'writeTo' => __('Write to %s', 'ibracodes-ai-assistant'),
            ],
        ];

        // product cards are the only thing that adds to a cart or carries a
        // stock line, so without commerce none of this would ever be read
        if (Capabilities::has_commerce()) {
            $config['cartEndpoint'] = Settings::get('log_threads') ? esc_url_raw(rest_url('ibraai/v1/cart-event')) : '';
            $config['i18n'] += [
                'addToCart' => __('Add to cart', 'ibracodes-ai-assistant'),
                'viewProduct' => __('View product', 'ibracodes-ai-assistant'),
                'outOfStock' => __('Out of stock', 'ibracodes-ai-assistant'),
                'onSale' => __('Sale', 'ibracodes-ai-assistant'),
            ];
        }

        // the live routes only exist for the widget when a person can actually be asked for
        if (Settings::live_ready()) {
            $config['liveEndpoint'] = esc_url_raw(rest_url('ibraai/v1/live/thread'));
            $config['liveMessageEndpoint'] = esc_url_raw(rest_url('ibraai/v1/live/thread/message'));
            $config['livePoll'] = 4000;
        }

        return $config;
    }
}
