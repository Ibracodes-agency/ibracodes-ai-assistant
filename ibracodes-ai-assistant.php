<?php
/**
 * Plugin Name:       IbraCodes AI Assistant
 * Plugin URI:        https://ibracodes.com/resources/ibracodes-ai-assistant/
 * Description:       An AI assistant for any WordPress site. It answers from your pages and posts and the facts you write, captures leads, and on WooCommerce stores recommends products the customer can add to cart. Uses your own OpenAI key.
 * Version:           0.2.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Ibracodes
 * Author URI:        https://ibracodes.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ibracodes-ai-assistant
 * Domain Path:       /languages
 *
 * The agent is grounded: on a WooCommerce store every product it mentions
 * comes back from a tool call against live catalog data, so it cannot invent
 * a product, a price or stock. It never mutates the cart (the customer clicks
 * the button on the card), never looks up orders, and the only email it sends
 * is the owner's own notification of a lead the visitor agreed to leave.
 */

namespace Ibracodes\AI_Assistant;

if (! defined('ABSPATH')) {
    exit;
}

// A second copy of the plugin (a manual upload next to the store install) must
// not redefine the constants and redeclare the classes, which would take the
// site down. The copy that loaded first wins; this one only leaves a notice.
if (defined('IBRAAI_VERSION')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__('Another copy of IbraCodes AI Assistant is already active. Deactivate one of them.', 'ibracodes-ai-assistant') . '</p></div>';
    });

    return;
}

define('IBRAAI_VERSION', '0.2.0');
define('IBRAAI_FILE', __FILE__);
define('IBRAAI_PATH', plugin_dir_path(__FILE__));
define('IBRAAI_URL', plugin_dir_url(__FILE__));

register_activation_hook(__FILE__, function (): void {
    if (version_compare(PHP_VERSION, '8.1', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('IbraCodes AI Assistant requires PHP 8.1 or later.', 'ibracodes-ai-assistant'),
            esc_html__('Plugin activation error', 'ibracodes-ai-assistant'),
            ['back_link' => true],
        );
    }
    require_once IBRAAI_PATH . 'includes/class-ibraai-settings.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-db.php';
    DB::install();
    DB::schedule_purge();
});

register_deactivation_hook(__FILE__, function (): void {
    require_once IBRAAI_PATH . 'includes/class-ibraai-db.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-index.php';
    wp_clear_scheduled_hook(DB::PURGE_HOOK);
    wp_clear_scheduled_hook(Index::HOOK);
    wp_clear_scheduled_hook(Index::RECONCILE_HOOK);
});

add_action('plugins_loaded', function (): void {
    require_once IBRAAI_PATH . 'includes/class-ibraai-settings.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-capabilities.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-db.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-threads.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-guards.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-catalog.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-content.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-leads.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-live.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-index.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-tools.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-prompt.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-scrubber.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-provider.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-agent.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-rest.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-widget.php';
    require_once IBRAAI_PATH . 'includes/class-ibraai-admin.php';

    DB::maybe_upgrade();
    DB::schedule_purge();
    // on init, not here: it reads the settings, whose defaults are translated strings
    add_action('init', [Index::class, 'schedule_reconcile']);
    add_action(DB::PURGE_HOOK, [DB::class, 'purge']);

    Catalog::boot();
    Index::boot();
    Rest::boot();
    Widget::boot();
    Admin::boot();
});
