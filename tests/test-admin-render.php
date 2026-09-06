<?php
/**
 * The admin tabs render for an administrator: the Content and Leads groups
 * on the Agent tab, the Leads tab with search, list and delete, the leads
 * tile on the Overview, and the Catalogue tab only with WooCommerce.
 *
 * Assertions check field names, ids and classes, never labels: the site's
 * admin locale may be Hebrew, and labels come out translated.
 */
require_once __DIR__ . '/lib.php';

use WSA\Admin;
use WSA\Index;
use WSA\Leads;
use WSA\Rest;
use WSA\Settings;
use WSA\Widget;

wp_set_current_user((int) get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0]);
$snapshot = Settings::all();
$GLOBALS['wsa_test_leads'] = [];
// flipping retrieval makes the index drop and re-queue the whole site; this test only reads the setting, so the hook stays out of it
remove_action('update_option_wsa_settings', [Index::class, 'on_settings'], 10);
register_shutdown_function(static function () use ($snapshot): void {
    foreach ($GLOBALS['wsa_test_leads'] as $id) {
        Leads::delete((int) $id);
    }
    if (isset($GLOBALS['wsa_test_key'])) {
        Settings::save_api_key($GLOBALS['wsa_test_key']);
    }
    Settings::update($snapshot);
});
add_filter('pre_wp_mail', '__return_true');

$render = static function (string $tab, array $get = []): string {
    $_GET = ['tab' => $tab] + $get;
    ob_start();
    Admin::render();

    return (string) ob_get_clean();
};

// ---- agent tab: content and leads groups
Settings::update(['retrieval' => 'search']);
$agent = $render('agent');
foreach (['content_post_types[]', 'content_scope', 'content_pages', 'retrieval', 'leads_enabled', 'leads_when', 'leads_email', 'leads_retention_days', 'privacy_note'] as $field) {
    wsa_assert(str_contains($agent, 'name="' . $field . '"'), "{$field} field on the agent tab");
}
wsa_assert(! str_contains($agent, 'id="wsa-rebuild"'), 'no rebuild button in search mode');
wsa_assert(! str_contains($agent, 'id="wsa-index-status"'), 'no index status line in search mode');

Settings::update(['retrieval' => 'embeddings']);
$agent = $render('agent');
wsa_assert(str_contains($agent, 'id="wsa-rebuild"'), 'rebuild button in embeddings mode');
wsa_assert(str_contains($agent, 'id="wsa-index-status"'), 'index status line in embeddings mode');
Settings::update(['retrieval' => 'search']);
wsa_assert(str_contains($agent, 'name="privacy_note" maxlength="240"'), 'the privacy note field is capped at 240 characters');
Settings::update(['privacy_note' => str_repeat('א', 300)]);
wsa_assert_same(240, mb_strlen((string) Settings::get('privacy_note')), 'the sanitiser trims the privacy note to 240 characters');
Settings::update(['privacy_note' => $snapshot['privacy_note']]);

// ---- appearance tab: the credit line is opt-in
wsa_assert(str_contains($render('appearance'), 'name="show_credit"'), 'show_credit toggle on the appearance tab');
Settings::update(['show_credit' => false]);
wsa_assert_same(null, Widget::config()['brand'], 'no brand in the widget config while the credit is off');
Settings::update(['show_credit' => true]);
$brand = Widget::config()['brand'];
wsa_assert(is_array($brand) && str_starts_with($brand['url'], 'https://ibracodes.com/') && str_ends_with($brand['logo'], 'assets/ibracodes.svg'), 'brand url and logo in the widget config once the credit is on');
Settings::update(['show_credit' => (bool) $snapshot['show_credit']]);

// ---- leads tab
$leads = $render('leads');
wsa_assert(str_contains($leads, 'wsa-card-title'), 'leads tab renders');
wsa_assert(str_contains($leads, 'name="s"'), 'leads tab has a search form');
wsa_assert(str_contains($leads, 'name="export_leads"'), 'leads tab has an export button');

$lead = Leads::capture(['name' => 'Render & Test', 'contact' => '0501234567', 'request' => 'a quote'], 0, 0);
wsa_assert(! empty($lead['saved']), 'fixture lead saved');
$GLOBALS['wsa_test_leads'][] = (int) $lead['id'];
$leads = $render('leads');
wsa_assert(str_contains($leads, 'Render &amp; Test'), 'the lead name is listed, escaped');
wsa_assert(! str_contains($leads, 'Render & Test'), 'the raw name never reaches the page');
wsa_assert(str_contains($leads, 'name="delete_lead" value="' . (int) $lead['id'] . '"'), 'a delete form carries the lead id');
wsa_assert(str_contains($render('leads', ['s' => 'Render']), 'Render &amp; Test'), 'search finds the lead');
wsa_assert(! str_contains($render('leads', ['s' => 'zzz-no-such-lead']), 'Render &amp; Test'), 'a search that does not match hides the lead');
wsa_assert_same(true, Leads::delete((int) $lead['id']), 'deleting a lead reports a removed row');
wsa_assert_same(false, Leads::delete((int) $lead['id']), 'deleting it again reports nothing removed');
$GLOBALS['wsa_test_leads'] = [];
wsa_assert(! str_contains($render('leads'), 'Render &amp; Test'), 'a deleted lead is gone from the list');

// ---- CSV export: byte order mark, header, guarded cell, the lead itself
$lead = Leads::capture(['name' => '=SUM(1)', 'contact' => '0507654321', 'request' => 'csv'], 0, 0);
wsa_assert(! empty($lead['saved']), 'csv fixture lead saved');
$GLOBALS['wsa_test_leads'][] = (int) $lead['id'];
$stream = fopen('php://memory', 'w+');
(new ReflectionMethod(Admin::class, 'csv_write'))->invoke(null, $stream, (new ReflectionMethod(Admin::class, 'csv_rows'))->invoke(null));
rewind($stream);
$csv = (string) stream_get_contents($stream);
fclose($stream);
wsa_assert(str_starts_with($csv, "\xEF\xBB\xBF" . 'id,created_at,name,contact,request,page'), 'the csv starts with the byte order mark and the header row');
wsa_assert(str_contains($csv, ",'=SUM(1),0507654321,csv,"), 'a name starting with = is guarded and the lead contact is in the csv');
Leads::delete((int) $lead['id']);
$GLOBALS['wsa_test_leads'] = [];

// ---- overview and the band
$overview = $render('overview');
wsa_assert(str_contains($overview, 'tab=leads'), 'the band links to the leads tab');
wsa_assert_same(5, substr_count($overview, 'class="wsa-kpi"'), 'five tiles with WooCommerce: conversations, replies, products, carts, leads');

add_filter('wsa_has_commerce', '__return_false');
$band = $render('overview');
wsa_assert(! str_contains($band, 'tab=catalogue'), 'no catalogue tab without WooCommerce');
wsa_assert(str_contains($band, 'tab=leads'), 'the leads tab stays without WooCommerce');
wsa_assert_same(3, substr_count($band, 'class="wsa-kpi"'), 'without WooCommerce the product and cart tiles go');
remove_filter('wsa_has_commerce', '__return_false');

// ---- CSV injection guard
$cell = new ReflectionMethod(Admin::class, 'csv_cell');
foreach ([['=1+1', "'=1+1"], ['+972501234567', "'+972501234567"], ['-5', "'-5"], ['@cmd', "'@cmd"], ['Dana Levi', 'Dana Levi'], ['', '']] as [$in, $out]) {
    wsa_assert_same($out, $cell->invoke(null, $in), 'csv guard for ' . json_encode($in));
}

// ---- rebuild route: registered, admin-only, refuses in search mode
$routes = rest_get_server()->get_routes('wsa/v1');
wsa_assert(isset($routes['/wsa/v1/rebuild-index']), 'rebuild-index route registered');
$permission = $routes['/wsa/v1/rebuild-index'][0]['permission_callback'];
wsa_assert_same(true, (bool) $permission(new WP_REST_Request('POST')), 'an administrator may rebuild');
$admin_id = get_current_user_id();
wp_set_current_user(0);
wsa_assert_same(false, (bool) $permission(new WP_REST_Request('POST')), 'a visitor may not rebuild');
wp_set_current_user($admin_id);
$refused = Rest::rebuild_index(new WP_REST_Request('POST'));
wsa_assert($refused instanceof WP_Error && $refused->get_error_code() === 'wsa_index_off' && $refused->get_error_data()['status'] === 400, 'rebuild refuses while retrieval is search');
// a key in wp-config.php cannot be blanked, so the no-key path is only checked on sites that keep the key in the database
if (! Settings::key_is_constant()) {
    $GLOBALS['wsa_test_key'] = Settings::api_key();
    Settings::save_api_key('');
    Settings::update(['retrieval' => 'embeddings']);
    $refused = Rest::rebuild_index(new WP_REST_Request('POST'));
    wsa_assert($refused instanceof WP_Error && $refused->get_error_code() === 'wsa_no_key' && $refused->get_error_data()['status'] === 400, 'rebuild refuses without a key');
    Settings::update(['retrieval' => 'search']);
    Settings::save_api_key($GLOBALS['wsa_test_key']);
    unset($GLOBALS['wsa_test_key']);
}

Settings::update($snapshot);
wsa_done(__FILE__);
