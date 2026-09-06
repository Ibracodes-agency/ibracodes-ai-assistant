<?php
/**
 * Hand-off becomes a live request: with live chat on, hand_off asks for a
 * person instead of pointing at a button, the prompt says so, and /chat
 * refuses to answer a thread a person owns. No OpenAI call is made: the
 * refusal happens before Agent::answer().
 */
require_once __DIR__ . '/lib.php';

use WSA\DB;
use WSA\Live;
use WSA\Prompt;
use WSA\Settings;
use WSA\Threads;
use WSA\Tools;

$snapshot = Settings::all();
$GLOBALS['wsa_test_threads'] = [];
register_shutdown_function(static function () use ($snapshot): void {
    foreach ($GLOBALS['wsa_test_threads'] as $id) {
        DB::delete_thread((int) $id);
    }
    Settings::update($snapshot);
});
$start = static function (string $question): int {
    $id = DB::start_thread($question, 'desktop');
    $GLOBALS['wsa_test_threads'][] = $id;

    return $id;
};
$descriptions = static fn (): array => array_column(array_column(Tools::definitions(), 'function'), 'description', 'name');

Settings::update(['log_threads' => true, 'live_enabled' => true, 'handoff_url' => 'https://wa.me/972500000000', 'handoff_label' => 'WhatsApp']);
add_filter('pre_wp_mail', '__return_true');

$defs = $descriptions();
wsa_assert(str_contains($defs['hand_off'], 'person will join'), 'hand_off describes a live handoff when live chat is on');

$thread = $start('Person please');
$cards = [];
$out = Tools::run('hand_off', [], $cards, ['thread_id' => $thread, 'page_id' => 0]);
wsa_assert_same('waiting', $out['live'], 'hand_off requests a person');
wsa_assert_same('waiting', Live::state($thread), 'thread is waiting');

$out = Tools::run('hand_off', [], $cards, ['thread_id' => 0]);
wsa_assert_same('pending', $out['live'], 'hand_off on a first turn, before a thread exists, reports pending');

$prompt = Prompt::system_message(['thread_id' => $thread])['content'];
wsa_assert(str_contains($prompt, 'A person will join this chat'), 'the live handoff paragraph is in the prompt');
wsa_assert(! str_contains($prompt, 'shows a button'), 'and the button wording is not');
wsa_assert(! str_contains($prompt, 'did not join'), 'no missed instruction without the missed context');
$prompt = Prompt::system_message(['thread_id' => $thread, 'live' => 'missed'])['content'];
wsa_assert(str_contains($prompt, 'did not join'), 'missed instruction present');

// /chat refuses a thread a person owns, before any upstream call
$call = static function (array $params): array {
    wp_set_current_user(0);
    $req = new WP_REST_Request('POST', '/wsa/v1/chat');
    foreach ($params as $k => $v) {
        $req->set_param($k, $v);
    }
    $res = rest_do_request($req);

    return [$res->get_status(), $res->get_data()];
};
$GLOBALS['wsa_upstream_calls'] = 0;
add_filter('pre_http_request', static function ($pre) {
    $GLOBALS['wsa_upstream_calls']++;

    return new WP_Error('wsa_test', 'no upstream call expected');
});
Settings::update(['enabled' => true]);
if (Settings::api_key() === '') {
    echo "  note: no API key on this site, the /chat refusal check was skipped\n";
} else {
    [$code, $data] = $call(['messages' => [['role' => 'user', 'text' => 'Still there?']], 'thread' => Threads::token($thread)]);
    wsa_assert_same(409, $code, 'a chat message on a waiting thread is refused with 409');
    wsa_assert_same('waiting', $data['data']['status'] ?? '', 'and tells the widget the state');
    wsa_assert_same('wsa_live_owned', $data['code'] ?? '', 'with the live-owned code');
    wsa_assert_same(0, $GLOBALS['wsa_upstream_calls'], 'without calling OpenAI');
    Live::claim($thread, (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID'])[0]);
    [$code] = $call(['messages' => [['role' => 'user', 'text' => 'Still there?']], 'thread' => Threads::token($thread)]);
    wsa_assert_same(409, $code, 'a chat message on a live thread is refused too');
}

// with live chat off and a handoff URL, the button is back
Settings::update(['live_enabled' => false]);
$defs = $descriptions();
wsa_assert(! str_contains($defs['hand_off'], 'person will join'), 'without live chat hand_off is the contact button again');
$prompt = Prompt::system_message()['content'];
wsa_assert(! str_contains($prompt, 'A person will join this chat'), 'no live wording in the prompt with live chat off');
wsa_assert(str_contains($prompt, 'shows a button that opens WhatsApp'), 'the button line is back');
$cards = [];
wsa_assert_same(['shown' => true, 'label' => 'WhatsApp'], Tools::run('hand_off', [], $cards, ['thread_id' => $thread, 'page_id' => 0]), 'hand_off shows the button with live chat off');

// with live chat on and no handoff URL, the tool is still offered
Settings::update(['live_enabled' => true, 'handoff_url' => '']);
wsa_assert(isset($descriptions()['hand_off']), 'hand_off is offered for live chat even without a contact destination');
Settings::update(['live_enabled' => false]);
wsa_assert(! isset($descriptions()['hand_off']), 'and not at all with neither');

wsa_done(__FILE__);
