<?php
/**
 * The Live chats tab: the console with its list and pane, the badge on the
 * band, the script data it needs, the settings card on the Agent tab, and
 * the manager and system rows on a stored conversation.
 *
 * Assertions check ids, names and classes, never labels: the site's admin
 * locale may be Hebrew.
 */
require_once __DIR__ . '/lib.php';

use WSA\Admin;
use WSA\DB;
use WSA\Live;
use WSA\Settings;

$snapshot = Settings::all();
$GLOBALS['wsa_test_threads'] = [];
register_shutdown_function(static function () use ($snapshot): void {
    foreach ($GLOBALS['wsa_test_threads'] as $id) {
        DB::delete_thread((int) $id);
    }
    Settings::update($snapshot);
});
$render = static function (string $tab, array $get = []): string {
    $_GET = ['tab' => $tab] + $get;
    ob_start();
    Admin::render();

    return (string) ob_get_clean();
};

Settings::update(['log_threads' => true, 'live_enabled' => true]);
add_filter('pre_wp_mail', '__return_true');
$admin = (int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID'])[0];
wp_set_current_user($admin);
$thread = DB::start_thread('Person please', 'desktop');
$GLOBALS['wsa_test_threads'][] = $thread;
Live::request($thread, 0);

$html = $render('live');
wsa_assert(str_contains($html, 'id="wsa-live"'), 'live tab renders its root');
wsa_assert(str_contains($html, 'class="wsa-live-item" data-thread="' . $thread . '"'), 'waiting thread is in the initial list');
wsa_assert(str_contains($html, 'id="wsa-live-pane"'), 'the pane is there for the script to fill');
wsa_assert(preg_match('~<a class="wsa-tab[^"]*" href="[^"]*tab=live[^"]*">\s*[^<]*<span class="wsa-tab-badge">(\d+)</span>~', $html, $m) === 1 && (int) $m[1] >= 1, 'the band shows the waiting count on the live tab');
wsa_assert(str_contains($render('live', ['thread' => $thread]), 'id="wsa-live" class="wsa-console" data-thread="' . $thread . '"'), 'the thread from the email link is handed to the console');

// the console script and its data go out on this tab only
Admin::menu();
$hook = (new ReflectionProperty(Admin::class, 'hook'))->getValue();
wsa_assert($hook !== '', 'the menu registered our screen');
$_GET = ['tab' => 'live'];
Admin::assets($hook);
wsa_assert(wp_script_is('wsa-live', 'enqueued'), 'live.js is enqueued on the live tab');
$data = (string) wp_scripts()->get_data('wsa-live', 'data');
foreach (['wsaLive', 'live/open', 'live/poll', 'live/claim', 'live/reply', 'live/close', '"nonce"', '"interval"', '"me"', '"i18n"'] as $needle) {
    wsa_assert(str_contains($data, $needle), "wsaLive localization carries {$needle}");
}
wp_dequeue_script('wsa-live');
$_GET = ['tab' => 'agent'];
Admin::assets($hook);
wsa_assert(! wp_script_is('wsa-live', 'enqueued'), 'live.js stays off the other tabs');

// the settings card on the Agent tab
$agent = $render('agent');
foreach (['live_enabled', 'live_email', 'live_wait_minutes', 'live_text_waiting', 'live_text_joined', 'live_text_missed', 'live_text_closed'] as $field) {
    wsa_assert(str_contains($agent, 'name="' . $field . '"'), "{$field} field on the agent tab");
}

// a stored conversation renders a person's lines and the system lines as their own rows
Live::claim($thread, $admin);
Live::manager_reply($thread, $admin, 'Hi from the desk');
DB::add_message($thread, 'system', 'One more system line');
$conversation = $render('conversations', ['thread' => $thread]);
wsa_assert(str_contains($conversation, 'wsa-bubble is-manager'), 'manager rows have their own class');
wsa_assert(substr_count($conversation, 'wsa-bubble is-system') === 3, 'the waiting, joined and added system rows have their own class');
wsa_assert(str_contains($conversation, 'Hi from the desk') && str_contains($conversation, 'One more system line'), 'the manager and system lines are shown');
wsa_assert(substr_count($conversation, 'wsa-bubble is-assistant') === 0, 'nothing on this thread is drawn as an assistant bubble');
wsa_assert(str_contains($conversation, esc_html(get_userdata($admin)->display_name)), 'the manager is named');

// with live chat off the tab explains itself instead of rendering the console
Settings::update(['live_enabled' => false]);
$html = $render('live');
wsa_assert(! str_contains($html, 'id="wsa-live"'), 'with live chat off the tab does not render the console');
wsa_assert(str_contains($html, 'id="wsa-live-off"') && preg_match('~id="wsa-live-off".*?href="[^"]*tab=agent~s', $html) === 1, 'and points to the Agent tab to enable it');
$_GET = ['tab' => 'live'];
wp_dequeue_script('wsa-live');
Admin::assets($hook);
wsa_assert(! wp_script_is('wsa-live', 'enqueued'), 'no console script while live chat is off');

wsa_done(__FILE__);
