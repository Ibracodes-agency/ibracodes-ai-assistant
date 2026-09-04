<?php
require_once __DIR__ . '/lib.php';

use WSA\Content;
use WSA\Settings;

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

Settings::update(['content_scope' => 'selected', 'content_pages' => [$b]]);
$scoped = Content::search('shipping');
foreach ($scoped as $h) {
    wsa_assert($h['id'] === $b, 'selected scope excludes every other page');
}
Settings::update(['content_scope' => 'all', 'content_pages' => []]);

wsa_done(__FILE__);
