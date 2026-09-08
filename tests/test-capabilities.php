<?php
require_once __DIR__ . '/lib.php';

use Ibracodes\AI_Assistant\Capabilities;

ibraai_assert(class_exists(Capabilities::class), 'Capabilities class loads');
ibraai_assert_same(true, Capabilities::has_commerce(), 'commerce detected on shop.test (WooCommerce active)');

add_filter('ibraai_has_commerce', '__return_false');
ibraai_assert_same(false, Capabilities::has_commerce(), 'the ibraai_has_commerce filter can switch commerce off for tests');
remove_filter('ibraai_has_commerce', '__return_false');

ibraai_assert_same('manage_woocommerce', Capabilities::admin_cap(), 'admin capability follows WooCommerce when present');
add_filter('ibraai_has_commerce', '__return_false');
ibraai_assert_same('manage_options', Capabilities::admin_cap(), 'admin capability falls back to manage_options');
remove_filter('ibraai_has_commerce', '__return_false');

ibraai_done(__FILE__);
