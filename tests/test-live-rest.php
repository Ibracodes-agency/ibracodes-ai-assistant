<?php
require_once __DIR__ . '/lib.php';

use WSA\DB;
use WSA\Guards;
use WSA\Live;
use WSA\Settings;
use WSA\Threads;

$snapshot = Settings::all();
// one address for the whole run, so the per-address backstop has a single counter to clear afterwards
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$ip_key = 'wsa_pollip_' . md5('wsa|203.0.113.9'); // mirrors Guards::remote_addr_hash()
add_filter('pre_wp_mail', '__return_true');
$thread = DB::start_thread('Person please', 'desktop');
$poll_key = 'wsa_poll_' . $thread;
delete_transient($poll_key);
delete_transient($ip_key);
$GLOBALS['wsa_test_threads'] = [$thread];
register_shutdown_function(static function () use ($snapshot, $poll_key, $ip_key): void {
    foreach ($GLOBALS['wsa_test_threads'] as $id) {
        DB::delete_thread((int) $id);
    }
    delete_transient($poll_key);
    delete_transient($ip_key);
    Settings::update($snapshot);
});

$token = Threads::token($thread);
$call = static function (string $method, string $route, array $params = [], bool $as_admin = false): array {
    if ($as_admin) {
        wp_set_current_user((int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID'])[0]);
    } else {
        wp_set_current_user(0);
    }
    $req = new WP_REST_Request($method, '/wsa/v1' . $route);
    foreach ($params as $k => $v) {
        $req->set_param($k, $v);
    }
    $res = rest_do_request($req);

    return [$res->get_status(), $res->get_data()];
};

Settings::update(['log_threads' => true, 'live_enabled' => false]);
[$code] = $call('GET', '/live/thread', ['thread' => $token, 'since' => 0]);
wsa_assert_same(503, $code, 'visitor poll is 503 while live chat is off');

Settings::update(['log_threads' => true, 'live_enabled' => true]);
[$code, $data] = $call('GET', '/live/thread', ['thread' => 'forged.token', 'since' => 0]);
wsa_assert_same(403, $code, 'forged token rejected');
[$code] = $call('POST', '/live/thread/message', ['thread' => 'forged.token', 'text' => 'hello']);
wsa_assert_same(403, $code, 'forged token rejected on the message route too');
[$code, $data] = $call('GET', '/live/thread', ['thread' => $token, 'since' => 0]);
wsa_assert_same(200, $code, 'valid token polls');
wsa_assert_same('ai', $data['status'], 'no request yet');

[$code] = $call('POST', '/live/thread/message', ['thread' => $token, 'text' => 'hello']);
wsa_assert_same(409, $code, 'visitor messages refused while the AI owns the thread');

Live::request($thread, 0);
[$code, $data] = $call('POST', '/live/thread/message', ['thread' => $token, 'text' => str_repeat('a', 3000)]);
wsa_assert_same(200, $code, 'visitor message accepted while waiting');
$messages = DB::thread($thread)['messages'];
wsa_assert_same(1200, mb_strlen(end($messages)['content']), 'visitor message bounded to 1200 chars');

[$code] = $call('GET', '/live/open');
wsa_assert_same(401, $code, 'manager list needs a login');
[$code, $data] = $call('GET', '/live/open', [], true);
wsa_assert_same(200, $code, 'admin lists');
wsa_assert_same($thread, (int) $data['threads'][0]['id'], 'waiting thread listed');
[$code] = $call('POST', '/live/reply', ['id' => $thread, 'text' => 'too early'], true);
wsa_assert_same(409, $code, 'a reply before claiming is refused');
[$code, $data] = $call('POST', '/live/claim', ['id' => $thread], true);
wsa_assert_same('live', $data['status'], 'claimed');
[$code, $data] = $call('POST', '/live/reply', ['id' => $thread, 'text' => 'Hi there'], true);
wsa_assert_same(200, $code, 'reply');
[$code, $data] = $call('GET', '/live/thread', ['thread' => $token, 'since' => 0]);
$texts = array_column($data['messages'], 'text');
wsa_assert(in_array('Hi there', $texts, true), 'visitor receives the reply');
[$code, $data] = $call('POST', '/live/close', ['id' => $thread], true);
wsa_assert_same('closed', $data['status'], 'closed');

// a logged-in user without the capability is refused too, not only an anonymous one
$subscriber = get_users(['role' => 'subscriber', 'number' => 1, 'fields' => 'ID']);
if ($subscriber) {
    wp_set_current_user((int) $subscriber[0]);
    wsa_assert_same(403, rest_do_request(new WP_REST_Request('GET', '/wsa/v1/live/open'))->get_status(), 'a subscriber gets 403 on the manager list');
} else {
    echo "  note: no subscriber user on this site, the 403 check was skipped\n";
}

// the poll gate is keyed on the thread: forty a minute, whatever address the caller claims
delete_transient($poll_key);
$allowed = 0;
for ($i = 0; $i < 41; $i++) {
    if (Guards::poll_allowed($thread)) {
        $allowed++;
    }
}
wsa_assert_same(40, $allowed, 'forty polls a minute per thread, the forty-first refused');
wsa_assert_same(false, Guards::poll_allowed($thread), 'and it stays refused within the window');
foreach ([1, 2, 3] as $i) {
    $_SERVER['HTTP_CF_CONNECTING_IP'] = "198.51.100.{$i}";
    wsa_assert_same(false, Guards::poll_allowed($thread), "a rotating proxy header does not mint a fresh counter ({$i})");
}
unset($_SERVER['HTTP_CF_CONNECTING_IP']);
[$code] = $call('GET', '/live/thread', ['thread' => $token, 'since' => 0]);
wsa_assert_same(429, $code, 'the visitor route relays a shut gate as 429');
set_transient($poll_key, ['count' => 40, 'until' => time() - 1], MINUTE_IN_SECONDS);
wsa_assert_same(true, Guards::poll_allowed($thread), 'a counter past its minute starts over');
// the per-address backstop, on REMOTE_ADDR alone, bounds one machine polling many threads
set_transient($ip_key, ['count' => 300, 'until' => time() + MINUTE_IN_SECONDS], MINUTE_IN_SECONDS);
wsa_assert_same(false, Guards::poll_allowed($thread), 'the per-address backstop refuses at its limit');
delete_transient($ip_key);
wsa_assert_same(true, Guards::poll_allowed($thread), 'and allows again once it clears');

wsa_done(__FILE__);
