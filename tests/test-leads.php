<?php
require_once __DIR__ . '/lib.php';

use WSA\Leads;
use WSA\Settings;
use WSA\Tools;

$snapshot = Settings::all();
// the table is shared with the owner's real leads: every row this test adds goes, even after a failed assertion
$GLOBALS['wsa_test_leads'] = [];
register_shutdown_function(static function () use ($snapshot): void {
    foreach ($GLOBALS['wsa_test_leads'] as $id) {
        Leads::delete((int) $id);
    }
    Settings::update($snapshot);
});
$own = static function (array $result): array {
    if (! empty($result['id'])) {
        $GLOBALS['wsa_test_leads'][] = (int) $result['id'];
    }

    return $result;
};

Settings::update(['leads_enabled' => true, 'leads_when' => 'when someone wants a quote', 'leads_email' => 'owner@example.com', 'leads_retention_days' => 180]);
global $wpdb;
$table = $wpdb->prefix . 'wsa_leads';
wsa_assert_same($table, $wpdb->get_var("SHOW TABLES LIKE '{$table}'"), 'leads table exists');
$rows_before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

$names = array_column(array_column(Tools::definitions(), 'function'), 'name');
wsa_assert(in_array('capture_lead', $names, true), 'lead tool offered when enabled');

$sent = [];
$GLOBALS['wsa_mail_ok'] = true;
add_filter('pre_wp_mail', static function ($pre, array $atts) use (&$sent) {
    $sent[] = $atts;

    return $GLOBALS['wsa_mail_ok'];
}, 10, 2);

$bad = Leads::capture(['name' => 'Dana', 'contact' => 'call me', 'request' => 'quote'], 0, 0);
wsa_assert_same('invalid_contact', $bad['error'] ?? '', 'a contact that is neither phone nor email is rejected');
$short = Leads::capture(['name' => 'D', 'contact' => '0501234567', 'request' => 'quote'], 0, 0);
wsa_assert_same('invalid_name', $short['error'] ?? '', 'one-letter names are rejected');

$ok = $own(Leads::capture(['name' => 'Dana Levi', 'contact' => '050-123-4567', 'request' => str_repeat('x', 900)], 77, 0));
wsa_assert_same(true, $ok['saved'], 'phone lead saved');
$row = Leads::find((int) $ok['id']);
wsa_assert_same('050-123-4567', $row['contact'], 'contact stored as typed');
wsa_assert_same(500, mb_strlen($row['request']), 'request trimmed to 500');
wsa_assert_same(1, (int) $row['email_sent'], 'email_sent set once the owner was reached');
wsa_assert_same(1, count($sent), 'owner emailed once');
wsa_assert_same('owner@example.com', $sent[0]['to'], 'email goes to the configured address');
wsa_assert(str_contains($sent[0]['message'], 'Dana Levi'), 'email carries the name');
wsa_assert(str_starts_with($sent[0]['subject'], 'New lead'), 'a first capture is announced as new');

$again = $own(Leads::capture(['name' => 'Dana Levi', 'contact' => 'dana@example.com', 'request' => 'updated'], 77, 0));
wsa_assert_same((int) $ok['id'], (int) $again['id'], 'second capture on the same thread updates the first');
wsa_assert_same('dana@example.com', Leads::find((int) $ok['id'])['contact'], 'update applied');
wsa_assert_same(2, count($sent), 'owner emailed again on update');
wsa_assert(str_starts_with($sent[1]['subject'], 'Updated lead'), 'a corrected lead is announced as updated');

$rows = Leads::list(1, 20);
wsa_assert($rows['total'] >= 1, 'listing works');
foreach (['%', '_'] as $needle) {
    $found = Leads::list(1, 20, $needle);
    wsa_assert_same('', $wpdb->last_error, "list() search with {$needle} runs without an SQL error");
    wsa_assert(is_int($found['total']) && is_array($found['rows']), "list() search with {$needle} returns the usual shape");
}
Leads::delete((int) $ok['id']);
wsa_assert_same(null, Leads::find((int) $ok['id']), 'delete removes the row');

// a number pasted from a phone app on an RTL site carries direction marks; they must neither reject it nor be stored
$rtl = $own(Leads::capture(['name' => 'Rtl Lead', 'contact' => "\u{200E}050-123-4567\u{200F}", 'request' => 'x'], 0, 0));
wsa_assert_same(true, $rtl['saved'] ?? false, 'direction marks around a phone number do not reject it');
wsa_assert_same('050-123-4567', Leads::find((int) $rtl['id'])['contact'], 'direction marks are not stored');
Leads::delete((int) $rtl['id']);

foreach ([
    ['123456', false],
    ['1234567', true],
    [str_repeat('5', 15), true],
    [str_repeat('5', 16), false],
    ['abcdefgh', false],
    ['+1 (555) 123-4567', true],
    ["\u{200E}0501234567", true],
] as [$value, $expected]) {
    wsa_assert_same($expected, Leads::is_phone($value), 'is_phone(' . json_encode($value) . ')');
}

// a failed email keeps the lead and leaves the flag off for the admin to show
$GLOBALS['wsa_mail_ok'] = false;
$unsent = $own(Leads::capture(['name' => 'Quiet Lead', 'contact' => 'quiet@example.com', 'request' => 'x'], 0, 0));
wsa_assert_same(true, $unsent['saved'] ?? false, 'a failed email keeps the lead');
wsa_assert_same(0, (int) Leads::find((int) $unsent['id'])['email_sent'], 'a failed email leaves email_sent 0');
$GLOBALS['wsa_mail_ok'] = true;
Leads::delete((int) $unsent['id']);

// retention has its own window: old rows go, fresh ones stay
$old = $own(Leads::capture(['name' => 'Old Lead', 'contact' => '0501111111', 'request' => 'old'], 0, 0));
$fresh = $own(Leads::capture(['name' => 'Fresh Lead', 'contact' => '0502222222', 'request' => 'fresh'], 0, 0));
$wpdb->update($table, ['created_at' => gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - 200 * DAY_IN_SECONDS)], ['id' => (int) $old['id']]);
Leads::purge();
wsa_assert_same(null, Leads::find((int) $old['id']), 'purge deletes a lead past the retention window');
wsa_assert(Leads::find((int) $fresh['id']) !== null, 'purge keeps a fresh lead');
Leads::delete((int) $fresh['id']);

$cards = [];
$via_tool = $own(Tools::run('capture_lead', ['name' => 'Noa', 'contact' => 'noa@example.com', 'request' => 'call me'], $cards, ['thread_id' => 78, 'page_id' => 0]));
wsa_assert_same(true, $via_tool['saved'] ?? false, 'the tool path saves a lead');
wsa_assert_same(78, (int) Leads::find((int) $via_tool['id'])['thread_id'], 'the tool path records the thread from the context');
Leads::delete((int) $via_tool['id']);

Settings::update(['leads_enabled' => false, 'leads_when' => '']);
wsa_assert_same(['error' => 'unknown_tool'], Tools::run('capture_lead', ['name' => 'Noa', 'contact' => 'noa@example.com', 'request' => 'call me'], $cards, ['thread_id' => 78, 'page_id' => 0]), 'the tool refuses while leads are off');
wsa_assert_same($rows_before, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"), 'no lead rows left behind');

Settings::update($snapshot);
wsa_done(__FILE__);
