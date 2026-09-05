<?php
require_once __DIR__ . '/lib.php';

use WSA\Prompt;
use WSA\Settings;
use WSA\Tools;

$snapshot = Settings::all();
register_shutdown_function(static function () use ($snapshot): void {
    Settings::update($snapshot);
});

Settings::update(['retrieval' => 'search', 'content_scope' => 'all', 'content_post_types' => ['page', 'post'], 'leads_enabled' => false]);
$names = static fn () => array_column(array_column(Tools::definitions(), 'function'), 'name');

$with = $names();
wsa_assert(in_array('search_content', $with, true) && in_array('get_page', $with, true), 'content tools always present');
wsa_assert(in_array('search_products', $with, true), 'product tools present with WooCommerce');
wsa_assert(! in_array('capture_lead', $with, true), 'no lead tool while leads are off');

add_filter('wsa_has_commerce', '__return_false');
$without = $names();
wsa_assert(! in_array('search_products', $without, true) && ! in_array('get_categories', $without, true) && ! in_array('get_product_details', $without, true), 'no product tools without WooCommerce');
$prompt = Prompt::system_message()['content'];
wsa_assert(! str_contains($prompt, 'Product cards are rendered'), 'shop paragraphs absent without WooCommerce');
wsa_assert(str_contains($prompt, 'search_content'), 'content rule present');
wsa_assert(str_contains($prompt, 'written by the site owner'), 'content injection rule present');
remove_filter('wsa_has_commerce', '__return_false');

$prompt = Prompt::system_message()['content'];
wsa_assert(str_contains($prompt, 'Product cards are rendered'), 'shop paragraphs present with WooCommerce');

$page = wsa_make_post('Gold package', 'The gold package includes four cameras, an NVR and installation.');
$prompt = Prompt::system_message(['page_id' => $page])['content'];
wsa_assert(str_contains($prompt, 'Gold package') && str_contains($prompt, 'four cameras'), 'current page title and text injected');
$draft = wsa_make_post('Private', 'xq-draft-secret', 'page', 'draft');
wsa_assert(! str_contains(Prompt::system_message(['page_id' => $draft])['content'], 'xq-draft-secret'), 'a draft is not injected even when its id is sent');

$cards = [];
$out = Tools::run('search_content', ['query' => 'gold package cameras'], $cards);
wsa_assert_same($page, $out['results'][0]['id'], 'search_content finds the page');
wsa_assert(isset($out['results'][0]['passage'], $out['results'][0]['url']), 'result carries passage and url');
$one = Tools::run('get_page', ['id' => $page], $cards);
wsa_assert(str_contains($one['text'], 'installation'), 'get_page returns the text');
wsa_assert_same(['error' => 'not_found'], Tools::run('get_page', ['id' => $draft], $cards), 'get_page refuses a draft');

Settings::update($snapshot);
wsa_done(__FILE__);
