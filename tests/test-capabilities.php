<?php
require_once __DIR__ . '/lib.php';

use WSA\Capabilities;

wsa_assert(class_exists(Capabilities::class), 'Capabilities class loads');
wsa_assert_same(true, Capabilities::has_commerce(), 'commerce detected on shop.test (WooCommerce active)');

add_filter('wsa_has_commerce', '__return_false');
wsa_assert_same(false, Capabilities::has_commerce(), 'the wsa_has_commerce filter can switch commerce off for tests');
remove_filter('wsa_has_commerce', '__return_false');

wsa_assert_same('manage_woocommerce', Capabilities::admin_cap(), 'admin capability follows WooCommerce when present');
add_filter('wsa_has_commerce', '__return_false');
wsa_assert_same('manage_options', Capabilities::admin_cap(), 'admin capability falls back to manage_options');
remove_filter('wsa_has_commerce', '__return_false');

wsa_done(__FILE__);
