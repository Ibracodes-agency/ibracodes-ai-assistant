<?php
require_once __DIR__ . '/lib.php';

use WSA\Content;
use WSA\Settings;

$snapshot = Settings::all();
Settings::update(['retrieval' => 'search', 'content_scope' => 'all', 'content_post_types' => ['page', 'post']]);

$a = wsa_make_post('Shipping policy', 'Orders ship within two business days. Free shipping over 399 shekels. ' . str_repeat('Filler sentence about packaging. ', 120) . ' Returns are accepted within fourteen days.');
$b = wsa_make_post('About us', 'We install security systems in homes and businesses since 2009.');
$c = wsa_make_post('Hidden draft', 'Free shipping secret', 'page', 'draft');

$hits = Content::search('free shipping');
wsa_assert(count($hits) >= 1, 'search finds something');
wsa_assert_same($a, $hits[0]['id'], 'the shipping page ranks first');
wsa_assert_same('Shipping policy', $hits[0]['title'], 'title carried');
wsa_assert_same(get_permalink($a), $hits[0]['url'], 'url carried');
wsa_assert(str_contains($hits[0]['passage'], 'Free shipping over 399'), 'passage is the chunk that mentions the terms');
wsa_assert(mb_strlen($hits[0]['passage']) < 2500, 'passage bounded');
foreach ($hits as $h) {
    wsa_assert($h['id'] !== $c, 'drafts never returned');
}

$returns = Content::search('returns fourteen days');
wsa_assert(str_contains($returns[0]['passage'], 'Returns are accepted'), 'a later chunk wins when it holds the terms');

wsa_assert_same([], Content::search('x'), 'one-character queries return nothing');

// password protection hides a page even when its words match
$locked = wsa_make_post('Locked', 'The quokka manifesto is for members only.');
wp_update_post(['ID' => $locked, 'post_password' => 'pw']);
wsa_assert_same([], array_column(Content::search('quokka manifesto'), 'id'), 'password protected pages are absent from search');

// post types outside the owner's list are invisible
$post = wsa_make_post('Blog note', 'The axolotl bulletin, as a post.', 'post');
$page = wsa_make_post('Page note', 'The axolotl bulletin, as a page.');
$both = array_column(Content::search('axolotl bulletin'), 'id');
sort($both);
wsa_assert_same([min($post, $page), max($post, $page)], $both, 'pages and posts both found when both types are allowed');
Settings::update(['content_post_types' => ['page']]);
wsa_assert_same([$page], array_column(Content::search('axolotl bulletin'), 'id'), 'a post is absent when only pages are allowed');
Settings::update(['content_post_types' => []]);
wsa_assert_same([], Content::search('axolotl bulletin'), 'no allowed types means nothing is searchable');
Settings::update(['content_post_types' => ['page', 'post']]);

// an excluded id is invisible to search and to is_allowed()
$excluded = wsa_make_post('Excluded', 'The capybara charter applies here.');
wsa_assert_same(true, Content::is_allowed($excluded), 'the page is allowed before exclusion');
$exclude = static fn (array $ids): array => array_merge($ids, [$excluded]);
add_filter('wsa_content_excluded_ids', $exclude);
wsa_assert_same([], array_column(Content::search('capybara charter'), 'id'), 'an excluded page is absent from search');
wsa_assert_same(false, Content::is_allowed($excluded), 'an excluded page is not allowed');
remove_filter('wsa_content_excluded_ids', $exclude);
wsa_assert_same([$excluded], array_column(Content::search('capybara charter'), 'id'), 'the page is back once the exclusion is lifted');

// a hand-picked page list is the whole world
Settings::update(['content_scope' => 'selected', 'content_pages' => [$b]]);
wsa_assert_same([], array_column(Content::search('shipping'), 'id'), 'selected scope hides the shipping page');
wsa_assert_same([$b], array_column(Content::search('security systems'), 'id'), 'selected scope still finds the picked page');

Settings::update($snapshot);
wsa_done(__FILE__);
