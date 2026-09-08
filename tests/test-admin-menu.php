<?php
/**
 * The admin page registers where the site allows (under WooCommerce, or as
 * its own top-level entry) and assets() recognises whichever hook that gave.
 */
require_once __DIR__ . '/lib.php';
if (! function_exists('set_current_screen')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
    require_once ABSPATH . 'wp-admin/includes/screen.php';
}

use Ibracodes\AI_Assistant\Admin;

$administrator = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;
ibraai_assert($administrator !== null, 'an administrator exists to register the menu as');
wp_set_current_user($administrator->ID);
set_current_screen('dashboard');

$hook = new ReflectionProperty(Admin::class, 'hook');

$reset_menu = static function (): void {
    foreach (['menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages'] as $global) {
        $GLOBALS[$global] = [];
    }
    wp_dequeue_script('ibraai-admin');
};

add_filter('ibraai_has_commerce', '__return_false');
$reset_menu();
Admin::menu();
$captured = $hook->getValue();
ibraai_assert_same('toplevel_page_ibracodes-ai-assistant', $captured, 'without WooCommerce the page is a top-level entry');
ibraai_assert(isset($GLOBALS['admin_page_hooks']['ibracodes-ai-assistant']), 'the top-level entry is registered with WordPress');
Admin::assets('edit.php');
ibraai_assert(! wp_script_is('ibraai-admin', 'enqueued'), 'assets() leaves other screens alone (no commerce)');
Admin::assets($captured);
ibraai_assert(wp_script_is('ibraai-admin', 'enqueued'), 'assets() enqueues admin.js on the captured hook (no commerce)');
remove_filter('ibraai_has_commerce', '__return_false');

// WooCommerce registers its parent menu before ours runs
$reset_menu();
add_menu_page('WooCommerce', 'WooCommerce', 'manage_woocommerce', 'woocommerce');
Admin::menu();
$captured = $hook->getValue();
ibraai_assert_same('woocommerce_page_ibracodes-ai-assistant', $captured, 'with WooCommerce the page sits under its menu');
Admin::assets('edit.php');
ibraai_assert(! wp_script_is('ibraai-admin', 'enqueued'), 'assets() leaves other screens alone (commerce)');
Admin::assets($captured);
ibraai_assert(wp_script_is('ibraai-admin', 'enqueued'), 'assets() enqueues admin.js on the captured hook (commerce)');

ibraai_done(__FILE__);
