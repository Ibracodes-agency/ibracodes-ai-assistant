<?php
require_once __DIR__ . '/lib.php';

$plugin = get_plugin_data(WSA_FILE, false, false);
wsa_assert_same('IbraCodes AI Assistant', $plugin['Name'], 'plugin name renamed');
wsa_assert_same('', $plugin['WC requires at least'] ?? '', 'WooCommerce is no longer a declared hard requirement');
wsa_assert(! function_exists('WSA\\has_required_woocommerce'), 'the hard dependency check is gone');
wsa_assert(class_exists('WSA\\Capabilities'), 'Capabilities loaded by the bootstrap');

wsa_done(__FILE__);
