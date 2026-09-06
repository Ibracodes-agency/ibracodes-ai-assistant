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

// deterministic fake embeddings: a bag of words, so similar wording lands close;
// a title named in $GLOBALS['wsa_fail_title'] makes the call fail like an outage
add_filter('wsa_pre_embed', static function ($pre, array $texts) {
    $fail = $GLOBALS['wsa_fail_title'] ?? '';
    foreach ($texts as $t) {
        if ($fail !== '' && str_starts_with($t, $fail . "\n")) {
            return new WP_Error('wsa_upstream', 'simulated outage');
        }
    }

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

$drain = static function (): void {
    for ($i = 0; $i < 50 && Index::status()['pending'] > 0; $i++) {
        Index::process_batch();
    }
};

Settings::update(['retrieval' => 'embeddings', 'content_scope' => 'all', 'content_post_types' => ['page', 'post']]);
global $wpdb;
$table = $wpdb->prefix . 'wsa_chunks';
wsa_assert_same($table, $wpdb->get_var("SHOW TABLES LIKE '{$table}'"), 'chunks table exists after upgrade');
wsa_assert(has_action('add_option_wsa_settings', [Index::class, 'on_first_save']) !== false, 'the first save of the settings reaches the index too');

$a = wsa_make_post('Opening hours', 'We are open Sunday to Thursday from nine to six and Friday until one.');
$b = wsa_make_post('Warranty', 'Every product carries a three year warranty with free replacement.');

Index::queue_all();
$status = Index::status();
wsa_assert($status['pending'] >= 2, 'both pages queued');
// the site holds more pages than the two fixtures; a batch is twenty posts
$drain();
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

// a failed call keeps the failed post and everything after it, in order
Index::drop();
$p1 = wsa_make_post('First', 'alpha content');
$p2 = wsa_make_post('Second', 'beta content');
$p3 = wsa_make_post('Third', 'gamma content');
$p4 = wsa_make_post('Fourth', 'delta content');
wsa_assert_same([$p1, $p2, $p3, $p4], get_option('wsa_index_queue'), 'saving queues the four posts in order');
$GLOBALS['wsa_fail_title'] = 'Second';
Index::process_batch();
wsa_assert_same([$p2, $p3, $p4], get_option('wsa_index_queue'), 'the failed post and the unprocessed remainder are back in the queue, in order');
wsa_assert_same([(string) $p1], $wpdb->get_col("SELECT DISTINCT post_id FROM {$table}"), 'only the first post was indexed');
wsa_assert(wp_next_scheduled(Index::HOOK) >= time() + 55, 'a failed batch backs off a minute before retrying');
$GLOBALS['wsa_fail_title'] = '';
$drain();
wsa_assert_same(0, Index::status()['pending'], 'the queue drains once the failure clears');
wsa_assert_same(4, Index::status()['posts'], 'all four posts indexed after the retry');
wsa_assert_same(false, get_option('wsa_index_backoff'), 'a successful batch resets the backoff');

// saving a post of a type outside the scope leaves the queue alone
register_post_type('wsa_probe', ['public' => false]);
$queue_before = get_option('wsa_index_queue');
wsa_make_post('Probe', 'out of scope', 'wsa_probe');
wsa_assert_same($queue_before, get_option('wsa_index_queue'), 'an out-of-scope post type never touches the queue');

// the daily reconcile catches an edit the hooks missed
wp_update_post(['ID' => $p1, 'post_content' => 'alpha content, edited']);
$drain();
$wpdb->query($wpdb->prepare("UPDATE {$table} SET updated_at = %s WHERE post_id = %d", gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS), $p1));
wsa_assert_same([], (array) get_option('wsa_index_queue', []), 'nothing queued before the reconcile');
Index::reconcile();
$queued = (array) get_option('wsa_index_queue', []);
wsa_assert(in_array($p1, $queued, true), 'reconcile queues the post edited after its chunks were made');
wsa_assert(! in_array($p3, $queued, true), 'reconcile leaves a post indexed after its last edit alone');
wsa_assert(in_array($a, $queued, true), 'reconcile queues an allowed post that has no chunks (the drop above removed them)');
$exclude = static fn (array $ids): array => array_merge($ids, [$p2]);
add_filter('wsa_content_excluded_ids', $exclude);
Index::reconcile();
remove_filter('wsa_content_excluded_ids', $exclude);
wsa_assert_same('0', $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE post_id = %d", $p2)), 'reconcile removes the chunks of a post that is no longer allowed');

// a running batch holds a lock; a second runner leaves
$queue_before = get_option('wsa_index_queue');
set_transient('wsa_index_lock', 1, 120);
Index::process_batch();
wsa_assert_same($queue_before, get_option('wsa_index_queue'), 'a locked batch leaves the queue untouched');
delete_transient('wsa_index_lock');
$drain();
wsa_assert_same(0, Index::status()['pending'], 'the batch runs once the lock is gone');

// drop() clears the daily reconcile; init re-arms it on the next request while embeddings are on
wsa_assert(has_action('init', [Index::class, 'schedule_reconcile']) !== false, 'the daily reconcile is armed from init');
Index::drop();
wsa_assert_same(false, wp_next_scheduled(Index::RECONCILE_HOOK), 'drop() clears the reconcile event');
Index::schedule_reconcile();
wsa_assert(wp_next_scheduled(Index::RECONCILE_HOOK) !== false, 'the init hook re-arms the reconcile while embeddings are on');

// switching embeddings off drops the index through the settings hook alone
Settings::update(['retrieval' => 'search']);
wsa_assert_same(false, Index::ready(), 'the index is not ready in search mode');
wsa_assert_same('0', $wpdb->get_var("SELECT COUNT(*) FROM {$table}"), 'switching to search dropped every chunk');

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
