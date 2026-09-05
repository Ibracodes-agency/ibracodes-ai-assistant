<?php
require_once __DIR__ . '/lib.php';

use WSA\Leads;
use WSA\Settings;
use WSA\Tools;

$snapshot = Settings::all();
register_shutdown_function(static function () use ($snapshot): void {
    Settings::update($snapshot);
});

Settings::update(['leads_enabled' => true, 'leads_when' => 'when someone wants a quote', 'leads_email' => 'owner@example.com']);
global $wpdb;
wsa_assert_same($wpdb->prefix . 'wsa_leads', $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wsa_leads'"), 'leads table exists');
// the table is shared with the owner's real leads: every row this test adds is deleted below
$rows_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_leads");

$names = array_column(array_column(Tools::definitions(), 'function'), 'name');
wsa_assert(in_array('capture_lead', $names, true), 'lead tool offered when enabled');

$sent = [];
add_filter('pre_wp_mail', static function ($pre, array $atts) use (&$sent) {
    $sent[] = $atts;

    return true;
}, 10, 2);

$bad = Leads::capture(['name' => 'Dana', 'contact' => 'call me', 'request' => 'quote'], 0, 0);
wsa_assert_same('invalid_contact', $bad['error'] ?? '', 'a contact that is neither phone nor email is rejected');
$short = Leads::capture(['name' => 'D', 'contact' => '0501234567', 'request' => 'quote'], 0, 0);
wsa_assert_same('invalid_name', $short['error'] ?? '', 'one-letter names are rejected');

$ok = Leads::capture(['name' => 'Dana Levi', 'contact' => '050-123-4567', 'request' => str_repeat('x', 900)], 77, 0);
wsa_assert_same(true, $ok['saved'], 'phone lead saved');
$row = Leads::find((int) $ok['id']);
wsa_assert_same('050-123-4567', $row['contact'], 'contact stored as typed');
wsa_assert_same(500, mb_strlen($row['request']), 'request trimmed to 500');
wsa_assert_same(1, count($sent), 'owner emailed once');
wsa_assert_same('owner@example.com', $sent[0]['to'], 'email goes to the configured address');
wsa_assert(str_contains($sent[0]['message'], 'Dana Levi'), 'email carries the name');

$again = Leads::capture(['name' => 'Dana Levi', 'contact' => 'dana@example.com', 'request' => 'updated'], 77, 0);
wsa_assert_same((int) $ok['id'], (int) $again['id'], 'second capture on the same thread updates the first');
wsa_assert_same('dana@example.com', Leads::find((int) $ok['id'])['contact'], 'update applied');
wsa_assert_same(2, count($sent), 'owner emailed again on update');

$rows = Leads::list(1, 20);
wsa_assert($rows['total'] >= 1, 'listing works');
Leads::delete((int) $ok['id']);
wsa_assert_same(null, Leads::find((int) $ok['id']), 'delete removes the row');

$cards = [];
$via_tool = Tools::run('capture_lead', ['name' => 'Noa', 'contact' => 'noa@example.com', 'request' => 'call me'], $cards, ['thread_id' => 78, 'page_id' => 0]);
wsa_assert_same(true, $via_tool['saved'] ?? false, 'the tool path saves a lead');
wsa_assert_same(78, (int) Leads::find((int) $via_tool['id'])['thread_id'], 'the tool path records the thread from the context');
Leads::delete((int) $via_tool['id']);

Settings::update(['leads_enabled' => false, 'leads_when' => '']);
wsa_assert_same(['error' => 'unknown_tool'], Tools::run('capture_lead', ['name' => 'Noa', 'contact' => 'noa@example.com', 'request' => 'call me'], $cards, ['thread_id' => 78, 'page_id' => 0]), 'the tool refuses while leads are off');
wsa_assert_same($rows_before, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wsa_leads"), 'no lead rows left behind');

Settings::update($snapshot);
wsa_done(__FILE__);
