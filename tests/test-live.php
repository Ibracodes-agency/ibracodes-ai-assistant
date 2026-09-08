<?php
require_once __DIR__ . '/lib.php';

use Ibracodes\AI_Assistant\DB;
use Ibracodes\AI_Assistant\Live;
use Ibracodes\AI_Assistant\Settings;

$snapshot = Settings::all();
// the thread tables are shared with the owner's real conversations: every row this test adds goes, even after a failed assertion
$GLOBALS['ibraai_test_threads'] = [];
$GLOBALS['ibraai_test_user'] = 0;
register_shutdown_function(static function () use ($snapshot): void {
    foreach ($GLOBALS['ibraai_test_threads'] as $id) {
        DB::delete_thread((int) $id);
    }
    if ($GLOBALS['ibraai_test_user'] > 0) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($GLOBALS['ibraai_test_user']);
    }
    Settings::update($snapshot);
});
$start = static function (string $question, string $device = 'desktop'): int {
    $id = DB::start_thread($question, $device);
    $GLOBALS['ibraai_test_threads'][] = $id;

    return $id;
};
// the activity columns are site-local, so a backdated value has to be site-local too
$local_ago = static fn (int $seconds): string => gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - $seconds);

Settings::update(['log_threads' => true, 'live_enabled' => true, 'live_wait_minutes' => 3, 'live_email' => 'desk@example.com']);
global $wpdb;
$cols = array_column($wpdb->get_results('SHOW COLUMNS FROM ' . DB::threads_table(), ARRAY_A), 'Field');
foreach (['status', 'requested_at', 'claimed_at', 'closed_at', 'manager_id', 'last_visitor_at', 'last_manager_at'] as $c) {
    ibraai_assert(in_array($c, $cols, true), "threads column {$c}");
}
$mcols = array_column($wpdb->get_results('SHOW COLUMNS FROM ' . DB::messages_table(), ARRAY_A), 'Field');
ibraai_assert(in_array('is_read', $mcols, true), 'messages column is_read');

$sent = [];
add_filter('pre_wp_mail', static function ($pre, array $atts) use (&$sent) {
    $sent[] = $atts;

    return true;
}, 10, 2);

$thread = $start('Can I talk to someone?');
DB::log_turn($thread, 'Can I talk to someone?', 'Sure, one moment.', [], false);

$state = Live::request($thread, 12);
ibraai_assert_same('waiting', $state['status'], 'request marks the thread waiting');
ibraai_assert_same(1, count($sent), 'manager emailed once');
ibraai_assert_same('desk@example.com', $sent[0]['to'], 'to the live-chat address');
ibraai_assert(str_contains($sent[0]['message'], 'thread=' . $thread), 'email links to the conversation');
ibraai_assert(str_contains(explode("\n", $sent[0]['message'])[0], 'thread=' . $thread), 'the link is the first line of the email');
ibraai_assert_same('waiting', Live::request($thread, 12)['status'], 'a second request is idempotent');
ibraai_assert_same(1, count($sent), 'and does not email again');

$id = Live::visitor_message($thread, 'Hello? Anyone there?');
ibraai_assert($id > 0, 'visitor message stored while waiting');
$poll = Live::poll_visitor($thread, 0);
ibraai_assert_same('waiting', $poll['status'], 'visitor poll reports waiting');
ibraai_assert_same([['system', Settings::get('live_text_waiting')]], array_map(static fn (array $m): array => [$m['role'], $m['text']], $poll['messages']), 'the waiting line is delivered on the first visitor poll, and once for two requests');

$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID'])[0];
$list = Live::open_threads();
ibraai_assert_same($thread, (int) $list[0]['id'], 'waiting thread listed first');
ibraai_assert_same(1, (int) $list[0]['unread'], 'one unread visitor message');

ibraai_assert_same('live', Live::claim($thread, $admin)['status'], 'claim makes it live');
$mid = Live::manager_reply($thread, $admin, 'Hi, this is Dana. How can I help?');
ibraai_assert($mid > 0, 'manager reply stored');
$poll = Live::poll_visitor($thread, 0);
ibraai_assert_same('live', $poll['status'], 'visitor sees live');
$roles = array_column($poll['messages'], 'role');
ibraai_assert(in_array('manager', $roles, true) && in_array('system', $roles, true), 'manager reply and the joined line delivered');
ibraai_assert_same(get_userdata($admin)->display_name, $poll['manager'], 'manager name carried');
$poll2 = Live::poll_visitor($thread, $mid);
ibraai_assert_same(0, count($poll2['messages']), 'since-id excludes delivered messages');

$mp = Live::poll_manager($thread, 0);
ibraai_assert(count($mp['messages']) >= 4, 'manager poll returns the whole thread');
ibraai_assert_same(0, (int) Live::open_threads()[0]['unread'], 'polling as manager marks visitor messages read');

// a second manager replying takes the thread over, and the visitor is told who is talking now
$second = get_user_by('login', 'ibraai_test_manager');
if ($second) {
    $second = (int) $second->ID;
    $GLOBALS['ibraai_test_user'] = $second; // left by an interrupted run: goes with this one
} else {
    $admins = array_map('intval', get_users(['role' => 'administrator', 'number' => 2, 'fields' => 'ID', 'orderby' => 'ID']));
    $second = $admins[1] ?? 0;
    if ($second === 0) {
        $second = wp_insert_user([
            'user_login' => 'ibraai_test_manager',
            'user_email' => 'wsa-test-manager@example.com',
            'user_pass' => wp_generate_password(24),
            'display_name' => 'Noa Test',
            'role' => 'administrator',
        ]);
        ibraai_assert(! is_wp_error($second), 'a temporary second administrator could be created');
        $GLOBALS['ibraai_test_user'] = (int) $second;
    }
}
$second_name = get_userdata($second)->display_name;
$mid2 = Live::manager_reply($thread, $second, 'Noa here, taking over.');
ibraai_assert($mid2 > 0, 'a second admin can reply on a live thread');
$poll = Live::poll_visitor($thread, $mid);
ibraai_assert_same(['system', 'manager'], array_column($poll['messages'], 'role'), 'a joined line precedes the second manager\'s reply');
ibraai_assert(str_contains($poll['messages'][0]['text'], $second_name), 'the joined line names the new manager');
ibraai_assert_same($second_name, $poll['manager'], 'the reply takes over the name');
$mid3 = Live::manager_reply($thread, $second, 'Still me.');
ibraai_assert_same(['manager'], array_column(Live::poll_visitor($thread, $mid2)['messages'], 'role'), 'the same manager replying again adds no joined line');

ibraai_assert_same('closed', Live::close($thread)['status'], 'close');
$poll = Live::poll_visitor($thread, $mid3);
ibraai_assert_same('ai', $poll['status'], 'closed threads report ai to the widget');
ibraai_assert_same(['system'], array_column($poll['messages'], 'role'), 'the closed line reaches the visitor');
ibraai_assert(str_contains($poll['messages'][0]['text'], $second_name), 'and names the manager who was in the chat');
ibraai_assert(! array_key_exists('texts', $poll), 'no texts in the poll: the widget renders the stored lines');

// missed: a waiting request older than the wait window
$t2 = $start('Person please', 'mobile');
Live::request($t2, 0);
$counted = Live::waiting_count();
ibraai_assert($counted >= 1, 'the waiting count sees a fresh request');
$wpdb->update(DB::threads_table(), ['requested_at' => gmdate('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS)], ['id' => $t2]);
ibraai_assert_same($counted - 1, Live::waiting_count(), 'and leaves out a wait past the window');
ibraai_assert_same('waiting', Live::state($t2), 'without writing anything: the count is a number, not the timeout pass');
$poll = Live::poll_visitor($t2, 0);
ibraai_assert_same('missed', $poll['status'], 'a stale wait becomes missed');
ibraai_assert_same('missed', Live::state($t2), 'and is persisted');
ibraai_assert_same(['system', Settings::get('live_text_missed')], [end($poll['messages'])['role'], end($poll['messages'])['text']], 'with the missed line, after the waiting one');
ibraai_assert_same(2, count($poll['messages']), 'the waiting line and the missed line, nothing else');
ibraai_assert_same('live', Live::claim($t2, $admin)['status'], 'a late claim revives a missed thread');

// closing a request nobody joined: the visitor gets the missed fallback, not a chat that "ended"
$t3 = $start(str_repeat('q', 300) . " <b>tag</b>\nsecond line", 'mobile');
Live::request($t3, 0);
$mail = end($sent);
ibraai_assert(str_contains($mail['message'], 'thread=' . $t3), 'the request emailed');
ibraai_assert(! str_contains($mail['message'], '<b>') && ! str_contains($mail['message'], "tag\nsecond"), 'the question is one plain line in the email');
ibraai_assert(str_contains($mail['message'], str_repeat('q', 200)) && ! str_contains($mail['message'], str_repeat('q', 201)), 'the question is capped at 200 characters in the email');
ibraai_assert_same(0, Live::manager_reply($t3, $admin, 'Hello?'), 'a reply outside a live chat is refused');
ibraai_assert_same('missed', Live::close($t3)['status'], 'closing a waiting request marks it missed');
$poll = Live::poll_visitor($t3, 0);
ibraai_assert_same('missed', $poll['status'], 'the widget sees missed');
$last = end($poll['messages']);
ibraai_assert_same(['system', Settings::get('live_text_missed')], [$last['role'], $last['text']], 'with the missed line, not a closed one');
ibraai_assert_same('missed', Live::close($t3)['status'], 'closing a missed request leaves it missed');

// timeouts from the manager list: a stale wait is missed and an idle live chat is closed, with no visitor poll involved
$t4 = $start('Anyone?');
Live::request($t4, 0);
$wpdb->update(DB::threads_table(), ['requested_at' => gmdate('Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS)], ['id' => $t4]);
$t5 = $start('Still there?');
Live::request($t5, 0);
Live::claim($t5, $admin);
Live::manager_reply($t5, $admin, 'Hi');
Live::visitor_message($t5, 'Hi back');
$idle = $local_ago(31 * MINUTE_IN_SECONDS);
$wpdb->update(DB::threads_table(), ['claimed_at' => $idle, 'last_manager_at' => $idle, 'last_visitor_at' => $idle], ['id' => $t5]);
$by_id = array_column(Live::open_threads(), null, 'id');
ibraai_assert_same('missed', $by_id[$t4]['status'] ?? '', 'the list marks a stale wait missed');
$last = end(DB::thread($t4)['messages']);
ibraai_assert_same(['system', Settings::get('live_text_missed')], [$last['role'], $last['content']], 'and writes the missed line on it');
ibraai_assert_same('live', $by_id[$t2]['status'] ?? '', 'a live chat with recent activity is left alone');
ibraai_assert(! isset($by_id[$t5]), 'an idle live chat leaves the list');
ibraai_assert_same('closed', Live::state($t5), 'and is closed');
$last = end(DB::thread($t5)['messages']);
ibraai_assert_same('system', $last['role'], 'with a closed line');
ibraai_assert(str_contains($last['content'], get_userdata($admin)->display_name), 'that names the manager');

// the same idle rule on the visitor's poll, on a chat where the visitor never wrote after the claim
$t6 = $start('Hello again');
Live::request($t6, 0);
Live::claim($t6, $admin);
$wpdb->update(DB::threads_table(), ['claimed_at' => $idle, 'last_manager_at' => $idle], ['id' => $t6]);
$poll = Live::poll_visitor($t6, 0);
ibraai_assert_same('ai', $poll['status'], 'an idle live chat reports ai to the widget');
ibraai_assert_same('closed', Live::state($t6), 'and is closed');
ibraai_assert_same('system', end($poll['messages'])['role'], 'with the closed line delivered');

ibraai_done(__FILE__);
