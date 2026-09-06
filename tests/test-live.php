<?php
require_once __DIR__ . '/lib.php';

use WSA\DB;
use WSA\Live;
use WSA\Settings;
use WSA\Threads;

$snapshot = Settings::all();
// the thread tables are shared with the owner's real conversations: every row this test adds goes, even after a failed assertion
$GLOBALS['wsa_test_threads'] = [];
register_shutdown_function(static function () use ($snapshot): void {
    foreach ($GLOBALS['wsa_test_threads'] as $id) {
        DB::delete_thread((int) $id);
    }
    Settings::update($snapshot);
});

Settings::update(['log_threads' => true, 'live_enabled' => true, 'live_wait_minutes' => 3, 'live_email' => 'desk@example.com']);
global $wpdb;
$cols = array_column($wpdb->get_results('SHOW COLUMNS FROM ' . DB::threads_table(), ARRAY_A), 'Field');
foreach (['status', 'requested_at', 'claimed_at', 'closed_at', 'manager_id', 'last_visitor_at', 'last_manager_at'] as $c) {
    wsa_assert(in_array($c, $cols, true), "threads column {$c}");
}
$mcols = array_column($wpdb->get_results('SHOW COLUMNS FROM ' . DB::messages_table(), ARRAY_A), 'Field');
wsa_assert(in_array('is_read', $mcols, true), 'messages column is_read');

$sent = [];
add_filter('pre_wp_mail', static function ($pre, array $atts) use (&$sent) {
    $sent[] = $atts;

    return true;
}, 10, 2);

$thread = DB::start_thread('Can I talk to someone?', 'desktop');
$GLOBALS['wsa_test_threads'][] = $thread;
DB::log_turn($thread, 'Can I talk to someone?', 'Sure, one moment.', [], false);
$token = Threads::token($thread);

$state = Live::request($thread, 12);
wsa_assert_same('waiting', $state['status'], 'request marks the thread waiting');
wsa_assert_same(1, count($sent), 'manager emailed once');
wsa_assert_same('desk@example.com', $sent[0]['to'], 'to the live-chat address');
wsa_assert(str_contains($sent[0]['message'], 'thread=' . $thread), 'email links to the conversation');
wsa_assert_same('waiting', Live::request($thread, 12)['status'], 'a second request is idempotent');
wsa_assert_same(1, count($sent), 'and does not email again');

$id = Live::visitor_message($thread, 'Hello? Anyone there?');
wsa_assert($id > 0, 'visitor message stored while waiting');
$poll = Live::poll_visitor($thread, 0);
wsa_assert_same('waiting', $poll['status'], 'visitor poll reports waiting');
wsa_assert_same(0, count($poll['messages']), 'no manager messages yet');

$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0];
$list = Live::open_threads();
wsa_assert_same($thread, (int) $list[0]['id'], 'waiting thread listed first');
wsa_assert_same(1, (int) $list[0]['unread'], 'one unread visitor message');

wsa_assert_same('live', Live::claim($thread, $admin)['status'], 'claim makes it live');
$mid = Live::manager_reply($thread, $admin, 'Hi, this is Dana. How can I help?');
wsa_assert($mid > 0, 'manager reply stored');
$poll = Live::poll_visitor($thread, 0);
wsa_assert_same('live', $poll['status'], 'visitor sees live');
$roles = array_column($poll['messages'], 'role');
wsa_assert(in_array('manager', $roles, true) && in_array('system', $roles, true), 'manager reply and the joined line delivered');
wsa_assert_same(get_userdata($admin)->display_name, $poll['manager'], 'manager name carried');
$poll2 = Live::poll_visitor($thread, $mid);
wsa_assert_same(0, count($poll2['messages']), 'since-id excludes delivered messages');

$mp = Live::poll_manager($thread, 0);
wsa_assert(count($mp['messages']) >= 4, 'manager poll returns the whole thread');
wsa_assert_same(0, (int) Live::open_threads()[0]['unread'], 'polling as manager marks visitor messages read');

wsa_assert_same('closed', Live::close($thread, $admin)['status'], 'close');
wsa_assert_same('ai', Live::poll_visitor($thread, 0)['status'], 'closed threads report ai to the widget');

// missed: a waiting request older than the wait window
$t2 = DB::start_thread('Person please', 'mobile');
$GLOBALS['wsa_test_threads'][] = $t2;
Live::request($t2, 0);
$wpdb->update(DB::threads_table(), ['requested_at' => gmdate('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS)], ['id' => $t2]);
wsa_assert_same('missed', Live::poll_visitor($t2, 0)['status'], 'a stale wait becomes missed');
wsa_assert_same('missed', Live::state($t2), 'and is persisted');
wsa_assert_same('live', Live::claim($t2, $admin)['status'], 'a late claim revives a missed thread');

wsa_done(__FILE__);
