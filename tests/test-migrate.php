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

global $wpdb;

$old_prefix = 'wsa_';
$counter = 'calls_month_2026-09';
$seeded = ['enabled' => false, 'model' => 'gpt-5-mini', 'title' => 'carried over'];
$names = ['settings', 'db_version', $counter];

// the site's own values for every option the migration writes, so a failure
// halfway through still hands the store back exactly what it had
$snapshot = [];
foreach ($names as $name) {
    $snapshot[$name] = get_option('ibraai_' . $name);
}
register_shutdown_function(static function () use ($snapshot, $old_prefix): void {
    foreach ($snapshot as $name => $value) {
        $value === false ? delete_option('ibraai_' . $name) : update_option('ibraai_' . $name, $value);
        delete_option($old_prefix . $name);
    }
});

foreach ($names as $name) {
    delete_option('ibraai_' . $name);
}
update_option($old_prefix . 'settings', $seeded);
update_option($old_prefix . 'db_version', '1.3.0', false);
update_option($old_prefix . $counter, 7, false);

DB::maybe_upgrade();

ibraai_assert_same($seeded, get_option('ibraai_settings'), 'the settings arrive under the new name');
ibraai_assert_same('1.3.0', get_option('ibraai_db_version'), 'the schema version arrives under the new name');
ibraai_assert_same('7', (string) get_option('ibraai_' . $counter), 'a monthly counter is found by query and carried over');

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
