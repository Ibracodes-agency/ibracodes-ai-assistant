<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen, never on a
 * plain deactivate. Removes this plugin's own settings and counters, including
 * the API key, and leaves WooCommerce and the catalog untouched.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall removes the plugin's own tables, options and transients; nothing to cache
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ibraai_messages'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ibraai_threads'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ibraai_chunks'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ibraai_leads'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ibraai_counters'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table

delete_option('ibraai_settings');
delete_option('ibraai_db_version');
delete_option('ibraai_openai_key');
delete_option('ibraai_last_failure');
delete_option('ibraai_index_queue');
delete_option('ibraai_index_backoff');
delete_option('ibraai_index_model');
wp_unschedule_hook('ibraai_purge_threads');
wp_unschedule_hook('ibraai_index_batch');
wp_unschedule_hook('ibraai_index_reconcile');

// monthly call counters were options before the counters table; an install
// deleted without ever loading 1.4.0 still has them
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ibraai\\_calls\\_month\\_%'");

// the index lock, and the rate-limit counters of an install that predates the table
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_ibraai\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_ibraai\\_%'"
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
