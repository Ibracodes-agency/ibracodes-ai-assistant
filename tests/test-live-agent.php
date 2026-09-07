<?php
/**
 * Hand-off becomes a live request: with live chat on, hand_off asks for a
 * person instead of pointing at a button, the prompt says so, the missed
 * state is told to the agent from the thread's own state, and /chat refuses
 * a thread a person owns. The model is played by the wsa_pre_complete
 * filter, so no OpenAI call is ever made.
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
// one address for the run, so the chat gates have one counter each to clear afterwards
$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
$ip = md5('wsa|203.0.113.77'); // mirrors Guards::client_ip_hash()
register_shutdown_function(static function () use ($snapshot, $ip): void {
    foreach ($GLOBALS['wsa_test_threads'] as $id) {
        DB::delete_thread((int) $id);
    }
    delete_transient('wsa_rl_' . $ip);
    delete_transient('wsa_rld_' . $ip);
    // a failed assertion inside the model filter would otherwise leave the concurrency slot taken
    delete_transient('wsa_busy');
    Settings::update($snapshot);
});
$start = static function (string $question): int {
    $id = DB::start_thread($question, 'desktop');
    $GLOBALS['wsa_test_threads'][] = $id;

    return $id;
};
$descriptions = static fn (): array => array_column(array_column(Tools::definitions(), 'function'), 'description', 'name');
$tool_call = ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'hand_off', 'arguments' => '{}']]]];

Settings::update(['log_threads' => true, 'live_enabled' => true, 'leads_enabled' => false, 'handoff_url' => 'https://wa.me/972500000000', 'handoff_label' => 'WhatsApp']);
$mails = 0;
add_filter('pre_wp_mail', static function ($pre) use (&$mails) {
    $mails++;

    return true;
});

// ---- the tool: offered as a live request, with no side effect inside the model loop
$defs = $descriptions();
wsa_assert(str_contains($defs['hand_off'], 'person will join'), 'hand_off describes a live handoff when live chat is on');
$thread = $start('Person please');
$cards = [];
wsa_assert_same(['live' => 'pending'], Tools::run('hand_off', [], $cards, ['thread_id' => $thread, 'page_id' => 0]), 'hand_off leaves the request to the REST layer, even with a thread');
wsa_assert_same('ai', Live::state($thread), 'so the model loop changes nothing on the thread');
wsa_assert_same(['live' => 'pending'], Tools::run('hand_off', [], $cards, ['thread_id' => 0]), 'and on a first turn, before a thread exists');
wsa_assert_same(0, $mails, 'and sends no email');

// ---- the prompt: the live line, and the missed paragraph built from the settings
$prompt = Prompt::system_message(['thread_id' => $thread])['content'];
wsa_assert(str_contains($prompt, 'A person will join this chat'), 'the live handoff paragraph is in the prompt');
wsa_assert(! str_contains($prompt, 'shows a button'), 'and the button wording is not');
wsa_assert(! str_contains($prompt, 'did not join'), 'no missed instruction without the missed context');
$missed = Prompt::system_message(['thread_id' => $thread, 'live' => 'missed'])['content'];
wsa_assert(str_contains($missed, 'did not join'), 'missed instruction present');
wsa_assert(! str_contains($missed, 'A person will join this chat'), 'and the live hand-off line is not');
wsa_assert(str_contains($missed, 'insists'), 'hand_off again only if the visitor insists on a person');
wsa_assert(str_contains($missed, 'unless you already have'), 'one apology per conversation');
wsa_assert(str_contains($missed, 'WhatsApp'), 'the contact option is named when there is one');
wsa_assert(! str_contains($missed, 'capture_lead'), 'no lead offer while leads are off');
Settings::update(['leads_enabled' => true, 'handoff_url' => '']);
$missed = Prompt::system_message(['thread_id' => $thread, 'live' => 'missed'])['content'];
wsa_assert(str_contains($missed, 'capture_lead'), 'the lead offer once leads are on');
wsa_assert(str_contains($missed, 'contact page') && ! str_contains($missed, 'WhatsApp'), 'the contact page when there is no contact option');
Settings::update(['leads_enabled' => false, 'handoff_url' => 'https://wa.me/972500000000']);

// ---- /chat, end to end, the model played by the filter; a key is faked where the site keeps none
if (! Settings::key_is_constant()) {
    add_filter('pre_option_wsa_openai_key', static fn () => 'sk-test');
}
Settings::update(['enabled' => true]);
wsa_assert(Settings::ready(), 'the chat route is open for the test');
$call = static function (array $params): array {
    wp_set_current_user(0);
    $req = new WP_REST_Request('POST', '/wsa/v1/chat');
    foreach ($params as $k => $v) {
        $req->set_param($k, $v);
    }
    $res = rest_do_request($req);

    return [$res->get_status(), $res->get_data()];
};

// a thread a person owns is refused before any model call
Live::request($thread, 0);
wsa_assert_same(1, $mails, 'the request emailed once');
$unexpected = static function () {
    wsa_assert(false, 'no model call expected');
};
add_filter('wsa_pre_complete', $unexpected);
[$code, $data] = $call(['messages' => [['role' => 'user', 'text' => 'Still there?']], 'thread' => Threads::token($thread)]);
wsa_assert_same(409, $code, 'a chat message on a waiting thread is refused with 409');
wsa_assert_same('waiting', $data['data']['status'] ?? '', 'and tells the widget the state');
wsa_assert_same('wsa_live_owned', $data['code'] ?? '', 'with the live-owned code');
Live::claim($thread, (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID'])[0]);
[$code] = $call(['messages' => [['role' => 'user', 'text' => 'Still there?']], 'thread' => Threads::token($thread)]);
wsa_assert_same(409, $code, 'a chat message on a live thread is refused too');
remove_filter('wsa_pre_complete', $unexpected);

// a first turn that asks for a person: one request, after the turn is recorded
$calls = 0;
$script = static function ($pre, array $messages, array $tools) use (&$calls, $tool_call) {
    $calls++;

    return $calls === 1 ? $tool_call : ['role' => 'assistant', 'content' => 'A person will be with you shortly.'];
};
add_filter('wsa_pre_complete', $script, 10, 3);
[$code, $data] = $call(['messages' => [['role' => 'user', 'text' => 'I want to talk to a person']], 'page' => 0]);
remove_filter('wsa_pre_complete', $script, 10);
wsa_assert_same(200, $code, 'the hand-off turn answers 200');
wsa_assert_same(2, $calls, 'the tool call and the answer: two model calls');
wsa_assert_same('waiting', $data['live'] ?? '', 'and reports live waiting');
$new = Threads::id_from_token((string) ($data['thread'] ?? ''));
wsa_assert($new > 0, 'with a verifying thread token');
$GLOBALS['wsa_test_threads'][] = $new;
wsa_assert_same(false, $data['handoff'], 'no contact button alongside a live request');
wsa_assert_same(2, $mails, 'exactly one email for the request');
$rows = DB::thread($new)['messages'];
wsa_assert_same(['user', 'assistant', 'system'], array_column($rows, 'role'), 'rows in order: the question, the answer, the waiting line');
wsa_assert_same(Settings::get('live_text_waiting'), end($rows)['content'], 'and the waiting line is the owner\'s text');
$unexpected = static function () {
    wsa_assert(false, 'no model call expected on an owned thread');
};
add_filter('wsa_pre_complete', $unexpected);
[$code] = $call(['messages' => [['role' => 'user', 'text' => 'Hello?']], 'thread' => $data['thread']]);
remove_filter('wsa_pre_complete', $unexpected);
wsa_assert_same(409, $code, 'the next chat on that thread is refused');

// nobody came: the AI answers again, told so by the thread's own state, and the contact button comes with each answer
wsa_assert_same('missed', Live::close($new)['status'], 'declining the request marks it missed');
$system = '';
$plain = static function ($pre, array $messages) use (&$system) {
    $system = (string) ($messages[0]['content'] ?? '');

    return ['role' => 'assistant', 'content' => 'Sorry, nobody could join. You can reach us on WhatsApp below.'];
};
add_filter('wsa_pre_complete', $plain, 10, 2);
[$code, $data] = $call(['messages' => [['role' => 'user', 'text' => 'Hello?']], 'thread' => $data['thread']]);
remove_filter('wsa_pre_complete', $plain, 10);
wsa_assert_same(200, $code, 'a missed thread is the AI\'s again');
wsa_assert(str_contains($system, 'did not join') && ! str_contains($system, 'A person will join this chat'), 'the agent was given the missed paragraph, not the live line');
wsa_assert_same(true, $data['handoff'], 'the contact button comes with the answer on a missed thread');
wsa_assert_same('', $data['live'], 'and no live request is made');

// the model asks for a person and then fails: the visitor still gets an answer and the request is still made
$calls = 0;
$broken = static function ($pre, array $messages, array $tools) use (&$calls, $tool_call) {
    $calls++;

    return $calls === 1 ? $tool_call : new WP_Error('wsa_test_down', 'down', ['status' => 503]);
};
add_filter('wsa_pre_complete', $broken, 10, 3);
[$code, $data] = $call(['messages' => [['role' => 'user', 'text' => 'Person, please']]]);
remove_filter('wsa_pre_complete', $broken, 10);
wsa_assert_same(200, $code, 'a failed second call still answers');
wsa_assert_same('A person will join this chat shortly.', $data['reply'] ?? '', 'with the live fallback reply');
wsa_assert_same('waiting', $data['live'] ?? '', 'and the request is made');
wsa_assert_same(false, $data['handoff'], 'without the contact button');
$GLOBALS['wsa_test_threads'][] = Threads::id_from_token((string) ($data['thread'] ?? ''));
wsa_assert_same(3, $mails, 'and one more email');

// ---- with live chat off and a handoff URL, the button is back
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
