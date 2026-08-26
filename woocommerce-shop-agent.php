<?php
/**
 * Plugin Name:       Shop Agent for WooCommerce
 * Plugin URI:        https://ibracodes.com
 * Description:       An AI shop assistant for the storefront. It searches the real catalog, recommends products the customer can add to cart, and answers store questions from facts you write. Uses your own OpenAI key.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * WC requires at least: 8.0
 * Author:            Ibracodes
 * Author URI:        https://ibracodes.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woocommerce-shop-agent
 * Domain Path:       /languages
 *
 * The agent is grounded: every product it mentions comes back from a tool call
 * against live WooCommerce data, so it cannot invent a product, a price or
 * stock. It never mutates the cart (the customer clicks the button on the
 * card), never looks up orders, and never sends email.
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

define('WSA_VERSION', '0.1.0');
define('WSA_FILE', __FILE__);
define('WSA_PATH', plugin_dir_path(__FILE__));
define('WSA_URL', plugin_dir_url(__FILE__));

/**
 * WooCommerce is a hard dependency, checked on every load rather than only on
 * activation: a site can deactivate WooCommerce later without ever touching
 * this plugin.
 */
function has_required_woocommerce(): bool
{
    if (! class_exists('WooCommerce') || ! defined('WC_VERSION')) {
        return false;
    }

    return version_compare(WC_VERSION, '8.0', '>=');
}

function woocommerce_missing_notice(): void
{
    ?>
    <div class="notice notice-error">
        <p><?php esc_html_e('Shop Agent requires WooCommerce 8.0 or later to be installed and active. The plugin has been deactivated.', 'woocommerce-shop-agent'); ?></p>
    </div>
    <?php
}

register_activation_hook(__FILE__, function (): void {
    if (version_compare(PHP_VERSION, '8.1', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Shop Agent requires PHP 8.1 or later.', 'woocommerce-shop-agent'),
            esc_html__('Plugin activation error', 'woocommerce-shop-agent'),
            ['back_link' => true],
        );
    }
    if (! has_required_woocommerce()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Shop Agent requires WooCommerce 8.0 or later. Install and activate WooCommerce first, then reactivate this plugin.', 'woocommerce-shop-agent'),
            esc_html__('Plugin activation error', 'woocommerce-shop-agent'),
            ['back_link' => true],
        );
    }
    require_once WSA_PATH . 'includes/class-wsa-settings.php';
    require_once WSA_PATH . 'includes/class-wsa-db.php';
    DB::install();
    DB::schedule_purge();
});

register_deactivation_hook(__FILE__, function (): void {
    require_once WSA_PATH . 'includes/class-wsa-db.php';
    wp_clear_scheduled_hook(DB::PURGE_HOOK);
});

add_action('plugins_loaded', function (): void {
    if (! has_required_woocommerce()) {
        add_action('admin_notices', __NAMESPACE__ . '\\woocommerce_missing_notice');
        add_action('admin_init', function (): void {
            deactivate_plugins(plugin_basename(WSA_FILE));
            unset($_GET['activate']);
        });

        return;
    }

    load_plugin_textdomain('woocommerce-shop-agent', false, dirname(plugin_basename(__FILE__)) . '/languages');

    require_once WSA_PATH . 'includes/class-wsa-settings.php';
    require_once WSA_PATH . 'includes/class-wsa-db.php';
    require_once WSA_PATH . 'includes/class-wsa-threads.php';
    require_once WSA_PATH . 'includes/class-wsa-guards.php';
    require_once WSA_PATH . 'includes/class-wsa-catalog.php';
    require_once WSA_PATH . 'includes/class-wsa-tools.php';
    require_once WSA_PATH . 'includes/class-wsa-prompt.php';
    require_once WSA_PATH . 'includes/class-wsa-scrubber.php';
    require_once WSA_PATH . 'includes/class-wsa-provider.php';
    require_once WSA_PATH . 'includes/class-wsa-agent.php';
    require_once WSA_PATH . 'includes/class-wsa-rest.php';
    require_once WSA_PATH . 'includes/class-wsa-widget.php';
    require_once WSA_PATH . 'includes/class-wsa-admin.php';

    DB::maybe_upgrade();
    DB::schedule_purge();
    add_action(DB::PURGE_HOOK, [DB::class, 'purge']);

    Rest::boot();
    Widget::boot();
    Admin::boot();
});
