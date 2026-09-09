<?php
/**
 * The one-time carry-over that 0.2.0 runs when it finds data written under the
 * prefix the plugin used before the WordPress.org review.
 *
 * Options only: the table rename is asserted against the tables that are
 * already there, never by creating or renaming any.
 */
require_once __DIR__ . '/lib.php';

use Ibracodes\AI_Assistant\DB;
use Ibracodes\AI_Assistant\Guards;

global $wpdb;

$old_prefix = 'wsa_';
// a month of its own, never the current one: the live month counter is the store's
$counter = 'calls_month_2019-01';
$seeded = ['enabled' => false, 'model' => 'gpt-5-mini', 'title' => 'carried over'];
$names = ['settings', 'db_version', $counter];

// the site's own values for every option the migration writes, so a failure
// halfway through still hands the store back exactly what it had
$snapshot = [];
foreach ($names as $name) {
    $snapshot[$name] = get_option('ibraai_' . $name);
}
register_shutdown_function(static function () use ($snapshot, $old_prefix, $counter): void {
    foreach ($snapshot as $name => $value) {
        $value === false ? delete_option('ibraai_' . $name) : update_option('ibraai_' . $name, $value);
        delete_option($old_prefix . $name);
    }
    ibraai_counter_forget('ibraai_' . $counter);
});

foreach ($names as $name) {
    delete_option('ibraai_' . $name);
}
update_option($old_prefix . 'settings', $seeded);
update_option($old_prefix . 'db_version', '1.3.0', false);
update_option($old_prefix . $counter, 7, false);

DB::maybe_upgrade();

ibraai_assert_same($seeded, get_option('ibraai_settings'), 'the settings arrive under the new name');
// the carried-over stamp is what tells the schema step which upgrades still owe work, and it ends on the current version
ibraai_assert(version_compare((string) get_option('ibraai_db_version'), '1.4.0', '>='), 'the schema version arrives under the new name and is brought up to date');
// the counters left options for a table in 1.4.0, so a carried-over month lands as a row
$row = ibraai_counter_row('ibraai_' . $counter);
ibraai_assert_same(7, (int) ($row['value'] ?? 0), 'a monthly counter is found by query and carried over into the counters table');
ibraai_assert_same(Guards::month_expiry('2019-01'), (string) ($row['expires_at'] ?? ''), 'with the absolute expiry of the month it counts');
ibraai_assert_same(false, get_option('ibraai_' . $counter), 'and the option it came from is gone');

foreach ($names as $name) {
    ibraai_assert_same(false, get_option($old_prefix . $name), "the old {$name} option is gone");
}

// a second bootstrap must not undo what the first one wrote
update_option($old_prefix . 'settings', ['title' => 'must not win']);
DB::maybe_upgrade();
ibraai_assert_same($seeded, get_option('ibraai_settings'), 'a later run leaves the carried-over settings alone');
delete_option($old_prefix . 'settings');

$exists = static function (string $table) use ($wpdb): bool {
    return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . $table)));
};
foreach (['threads', 'messages', 'chunks', 'leads'] as $table) {
    ibraai_assert($exists('ibraai_' . $table), "the {$table} table is on the new prefix");
    ibraai_assert(! $exists($old_prefix . $table), "the old {$table} table is gone");
}
ibraai_assert_same(false, wp_next_scheduled($old_prefix . 'purge_threads'), 'the old purge job is unscheduled');

ibraai_done(__FILE__);
