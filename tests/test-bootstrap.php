<?php
require_once __DIR__ . '/lib.php';

$plugin = get_plugin_data(IBRAAI_FILE, false, false);
ibraai_assert_same('IbraCodes AI Assistant', $plugin['Name'], 'plugin name renamed');
ibraai_assert(! str_contains(file_get_contents(IBRAAI_FILE), 'WC requires at least'), 'WooCommerce is no longer a declared hard requirement');
ibraai_assert(! function_exists('Ibracodes\AI_Assistant\\has_required_woocommerce'), 'the hard dependency check is gone');
ibraai_assert(class_exists('Ibracodes\AI_Assistant\\Capabilities'), 'Capabilities loaded by the bootstrap');

// a second copy of the plugin (a manual upload next to the store install) must
// neither redefine the constants nor redeclare the classes: it backs off with a notice
$version_before = IBRAAI_VERSION;
$notices_before = count($GLOBALS['wp_filter']['admin_notices']->callbacks[10] ?? []);
$warnings = [];
set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
ob_start();
include IBRAAI_FILE;
$output = ob_get_clean();
restore_error_handler();
ibraai_assert_same([], $warnings, 'including the main file a second time raises no warning');
ibraai_assert_same('', $output, 'and prints nothing');
ibraai_assert_same($version_before, IBRAAI_VERSION, 'IBRAAI_VERSION is unchanged');
ibraai_assert_same($notices_before + 1, count($GLOBALS['wp_filter']['admin_notices']->callbacks[10] ?? []), 'the second copy registers an admin notice instead');

ibraai_done(__FILE__);
