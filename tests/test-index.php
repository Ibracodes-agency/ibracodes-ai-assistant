<?php
require_once __DIR__ . '/lib.php';

use WSA\Index;
use WSA\Provider;
use WSA\Settings;

$snapshot = Settings::all();
// a mid-test failure must never leave the site in embeddings mode with a populated index
register_shutdown_function(static function () use ($snapshot): void {
    Settings::update($snapshot);
    Index::drop();
});

// deterministic fake embeddings: a bag of words, so similar wording lands close
add_filter('wsa_pre_embed', static function ($pre, array $texts) {
    return array_map(static function ($t) {
        $v = array_fill(0, Provider::EMBED_DIMS, 0.0);
        foreach (preg_split('/\W+/u', mb_strtolower($t)) as $w) {
            if ($w !== '') {
                $v[crc32($w) % Provider::EMBED_DIMS] += 1.0;
            }
        }

        return $v;
    }, $texts);
}, 10, 2);

Settings::update(['retrieval' => 'embeddings', 'content_scope' => 'all', 'content_post_types' => ['page', 'post']]);
global $wpdb;
wsa_assert_same($wpdb->prefix . 'wsa_chunks', $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wsa_chunks'"), 'chunks table exists after upgrade');

$a = wsa_make_post('Opening hours', 'We are open Sunday to Thursday from nine to six and Friday until one.');
$b = wsa_make_post('Warranty', 'Every product carries a three year warranty with free replacement.');

Index::queue_all();
$status = Index::status();
wsa_assert($status['pending'] >= 2, 'both pages queued');
// the site holds more pages than the two fixtures; a batch is twenty posts
for ($i = 0; $i < 10 && Index::status()['pending'] > 0; $i++) {
    Index::process_batch();
}
$status = Index::status();
wsa_assert_same(0, $status['pending'], 'batch drained the queue');
wsa_assert($status['chunks'] >= 2, 'chunks stored');
wsa_assert_same(true, Index::ready(), 'index reports ready once it holds chunks');

$hits = Index::search('what are your opening hours', 3);
wsa_assert_same($a, $hits[0]['id'], 'opening hours page ranks first by cosine');
wsa_assert(str_contains($hits[0]['passage'], 'Sunday to Thursday'), 'passage is the chunk text');

// editing re-indexes just that post
wp_update_post(['ID' => $b, 'post_content' => 'Every product carries a five year warranty.']);
Index::process_batch();
$hits = Index::search('five year warranty', 1);
wsa_assert(str_contains($hits[0]['passage'], 'five year'), 'updated content replaced the old chunks');

// unpublishing removes it
wp_update_post(['ID' => $b, 'post_status' => 'draft']);
$hits = Index::search('warranty', 3);
foreach ($hits as $h) {
    wsa_assert($h['id'] !== $b, 'draft removed from the index');
}

Index::drop();
wsa_assert_same(false, Index::ready(), 'drop empties the index');
Settings::update(['retrieval' => 'search']);

// the settings hook fires inside update_option(); a scope change must rebuild by the new scope, not the cached one
$seen = null;
$probe = static function ($old, $new) use (&$seen): void {
    $seen = ['cached' => Settings::get('content_post_types'), 'saved' => $new['content_post_types']];
};
add_action('update_option_wsa_settings', $probe, 5, 2);
Settings::update(['content_post_types' => ['page']]);
remove_action('update_option_wsa_settings', $probe, 5);
wsa_assert_same(['page'], $seen['saved'], 'the probe saw the save');
wsa_assert_same($seen['saved'], $seen['cached'], 'readers inside the settings hook see the values being saved');

Settings::update($snapshot);
Index::drop();
wsa_done(__FILE__);
