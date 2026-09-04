<?php
require_once __DIR__ . '/lib.php';

use WSA\Provider;

wsa_assert_same(512, Provider::EMBED_DIMS, 'vectors are 512 wide (fast cosine in PHP, small rows)');

// no network in tests: the filter hands back vectors, the same hook the index tests use
add_filter('wsa_pre_embed', static function ($pre, array $texts) {
    return array_map(static fn ($t) => array_fill(0, Provider::EMBED_DIMS, strlen($t) / 100), $texts);
}, 10, 2);
$vectors = Provider::embed(['hello', 'hello world']);
wsa_assert(is_array($vectors) && count($vectors) === 2, 'one vector per text');
wsa_assert_same(512, count($vectors[0]), 'vector width');
wsa_assert_same([], Provider::embed([]), 'empty input short-circuits without a call');

wsa_done(__FILE__);
