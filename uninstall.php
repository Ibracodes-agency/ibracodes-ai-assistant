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
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wsa_messages'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wsa_threads'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wsa_chunks'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wsa_leads'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table

delete_option('wsa_settings');
delete_option('wsa_db_version');
delete_option('wsa_openai_key');
delete_option('wsa_last_failure');
delete_option('wsa_index_queue');
delete_option('wsa_index_backoff');
delete_option('wsa_index_model');
wp_unschedule_hook('wsa_purge_threads');
wp_unschedule_hook('wsa_index_batch');
wp_unschedule_hook('wsa_index_reconcile');

// monthly call counters are options (they must survive a cache flush)
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wsa\\_calls\\_month\\_%'");

// rate-limit counters are transients
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_wsa\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_wsa\\_%'"
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
