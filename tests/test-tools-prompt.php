<?php
require_once __DIR__ . '/lib.php';

use Ibracodes\AI_Assistant\Prompt;
use Ibracodes\AI_Assistant\Settings;
use Ibracodes\AI_Assistant\Tools;

$snapshot = Settings::all();
register_shutdown_function(static function () use ($snapshot): void {
    Settings::update($snapshot);
});

// the saved voice, facts and contact option vary by site; pin them so the wording assertions are about the prompt, not the settings
Settings::update([
    'retrieval' => 'search',
    'content_scope' => 'all',
    'content_post_types' => ['page', 'post'],
    'leads_enabled' => false,
    'leads_when' => '',
    'style_rules' => '',
    'store_facts' => 'Open Sunday to Thursday.',
    'handoff_url' => 'https://example.com/contact',
    'handoff_label' => 'Contact us',
]);
$names = static fn () => array_column(array_column(Tools::definitions(), 'function'), 'name');

$with = $names();
ibraai_assert(in_array('search_content', $with, true) && in_array('get_page', $with, true), 'content tools always present');
ibraai_assert(in_array('search_products', $with, true), 'product tools present with WooCommerce');
ibraai_assert(! in_array('capture_lead', $with, true), 'no lead tool while leads are off');

add_filter('ibraai_has_commerce', '__return_false');
$without = $names();
ibraai_assert(! in_array('search_products', $without, true) && ! in_array('get_categories', $without, true) && ! in_array('get_product_details', $without, true), 'no product tools without WooCommerce');
$prompt = Prompt::system_message()['content'];
ibraai_assert(! str_contains($prompt, 'Product cards are rendered'), 'shop paragraphs absent without WooCommerce');
ibraai_assert(str_contains($prompt, 'search_content'), 'content rule present');
ibraai_assert(str_contains($prompt, 'written by the site owner'), 'content injection rule present');
ibraai_assert(! str_contains($prompt, 'find a product') && ! str_contains($prompt, 'shop stocks') && ! str_contains($prompt, 'outside the shop'), 'no shop wording without WooCommerce');
ibraai_assert(str_contains($prompt, 'answer a question about this site or to reach a human'), 'the refusal offers the site instead of products');
ibraai_assert(str_contains($prompt, "Site facts, the only information beyond the site content you may state as fact:\nOpen Sunday to Thursday."), 'facts labelled as site facts without WooCommerce');
Settings::update(['handoff_url' => '']);
ibraai_assert(str_contains(Prompt::system_message()['content'], "suggest the site's contact page"), 'the no-button line names the site without WooCommerce');
Settings::update(['handoff_url' => 'https://example.com/contact']);
remove_filter('ibraai_has_commerce', '__return_false');

$prompt = Prompt::system_message()['content'];
ibraai_assert(str_contains($prompt, 'Product cards are rendered'), 'shop paragraphs present with WooCommerce');
ibraai_assert(str_contains($prompt, 'Store facts, the only non-catalog information'), 'facts labelled as store facts with WooCommerce');
ibraai_assert(str_contains($prompt, 'shop stocks') && str_contains($prompt, 'outside the shop'), 'shop wording kept with WooCommerce');
ibraai_assert(str_contains($prompt, 'summing up what a page on this site says is part of your job'), 'the refusal leaves the site\'s own pages alone');

Settings::update(['leads_enabled' => true, 'leads_when' => 'when someone asks for a demo']);
$prompt = Prompt::system_message()['content'];
ibraai_assert(str_contains($prompt, 'Lead capture:') && str_contains($prompt, 'when someone asks for a demo'), 'lead paragraph carries the owner\'s line');
ibraai_assert(str_contains($prompt, 'offer it before the contact option'), 'lead capture comes before the contact option when both are on');
Settings::update(['handoff_url' => '']);
ibraai_assert(! str_contains(Prompt::system_message()['content'], 'offer it before the contact option'), 'no precedence sentence without a contact option');
Settings::update(['leads_enabled' => false, 'leads_when' => '', 'handoff_url' => 'https://example.com/contact']);

$page = ibraai_make_post('Gold package', 'The gold package includes four cameras, an NVR and installation.');
$prompt = Prompt::system_message(['page_id' => $page])['content'];
ibraai_assert(str_contains($prompt, 'Gold package') && str_contains($prompt, 'four cameras'), 'current page title and text injected');
ibraai_assert(str_contains($prompt, Prompt::PAGE_OPEN . "\nThe gold package") && str_contains($prompt, "installation.\n" . Prompt::PAGE_CLOSE), 'current page text sits between the page markers');
ibraai_assert(str_contains($prompt, 'Text between a ' . Prompt::PAGE_OPEN . ' line and a ' . Prompt::PAGE_CLOSE . ' line'), 'the content rule names the markers');
$draft = ibraai_make_post('Private', 'xq-draft-secret', 'page', 'draft');
ibraai_assert(! str_contains(Prompt::system_message(['page_id' => $draft])['content'], 'xq-draft-secret'), 'a draft is not injected even when its id is sent');

$cards = [];
$out = Tools::run('search_content', ['query' => 'gold package cameras'], $cards);
ibraai_assert_same($page, $out['results'][0]['id'], 'search_content finds the page');
ibraai_assert(isset($out['results'][0]['passage'], $out['results'][0]['url']), 'result carries passage and url');
ibraai_assert(str_starts_with($out['results'][0]['passage'], Prompt::PAGE_OPEN . "\n") && str_ends_with($out['results'][0]['passage'], "\n" . Prompt::PAGE_CLOSE), 'passages are delimited');
$one = Tools::run('get_page', ['id' => $page], $cards);
ibraai_assert(str_contains($one['text'], 'installation'), 'get_page returns the text');
ibraai_assert(str_starts_with($one['text'], Prompt::PAGE_OPEN . "\n") && str_ends_with($one['text'], "\n" . Prompt::PAGE_CLOSE), 'get_page text is delimited');
$amp = ibraai_make_post('Terms & Conditions', 'The terms apply.');
ibraai_assert_same('Terms & Conditions', Tools::run('get_page', ['id' => $amp], $cards)['title'], 'get_page decodes the title');
ibraai_assert_same(['error' => 'not_found'], Tools::run('get_page', ['id' => $draft], $cards), 'get_page refuses a draft');
$exclude = static fn (array $ids): array => array_merge($ids, [$page]);
add_filter('ibraai_content_excluded_ids', $exclude);
ibraai_assert_same(['error' => 'not_found'], Tools::run('get_page', ['id' => $page], $cards), 'get_page refuses an excluded id');
remove_filter('ibraai_content_excluded_ids', $exclude);
$private = ibraai_make_post('Hidden', 'xq-private-secret', 'page', 'private');
ibraai_assert_same(['error' => 'not_found'], Tools::run('get_page', ['id' => $private], $cards), 'get_page refuses a private post');
$locked = ibraai_make_post('Locked', 'xq-locked-secret', 'page', 'publish', ['post_password' => 'pw']);
ibraai_assert_same(['error' => 'not_found'], Tools::run('get_page', ['id' => $locked], $cards), 'get_page refuses a password-protected post');

Settings::update($snapshot);
ibraai_done(__FILE__);
