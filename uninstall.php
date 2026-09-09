<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen, never on a
 * plain deactivate. Removes this plugin's own settings and counters, including
 * the API key, and leaves WooCommerce and the catalog untouched.
 *
 * Every table and option this plugin writes belongs to one site, so on a
 * network install the removal runs once per site: deleting a network-activated
 * copy without that would leave an API key behind on every subsite.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Removes everything this plugin stores on the current site. Reads
 * $wpdb->prefix on each call, so it follows switch_to_blog().
 */
function ibraai_uninstall_site(): void
{
    global $wpdb;

    // the wsa_ names are the pre-0.2.0 slug. An install deleted before it ever
    // loaded 0.2.0 never ran the rename, so its tables, options, transients and
    // cron hooks are still under the old prefix, API key included.
    $tables = ['messages', 'threads', 'chunks', 'leads', 'counters'];
    $legacy_tables = ['messages', 'threads', 'chunks', 'leads'];
    $options = ['settings', 'db_version', 'openai_key', 'last_failure', 'index_queue', 'index_backoff', 'index_model'];
    $hooks = ['purge_threads', 'index_batch', 'index_reconcile'];

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall removes the plugin's own tables, options and transients; nothing to cache
    foreach ($tables as $table) {
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'ibraai_' . $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table
    }
    foreach ($legacy_tables as $table) {
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wsa_' . $table)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own table under its pre-0.2.0 prefix
    }

    foreach ($options as $option) {
        delete_option('ibraai_' . $option);
        delete_option('wsa_' . $option);
    }
    foreach ($hooks as $hook) {
        wp_unschedule_hook('ibraai_' . $hook);
        wp_unschedule_hook('wsa_' . $hook);
    }

    // monthly call counters were options before the counters table; an install
    // deleted without ever loading 1.4.0 still has them
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ibraai\\_calls\\_month\\_%' OR option_name LIKE 'wsa\\_calls\\_month\\_%'");

    // the index lock, and the rate-limit counters of an install that predates the table
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\\_transient\\_ibraai\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_ibraai\\_%'
            OR option_name LIKE '\\_transient\\_wsa\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_wsa\\_%'"
    );
    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

// The plugin stores nothing network-wide: no site option, no network cron, so
// there is nothing left to remove once every site has been cleaned.
if (is_multisite()) {
    // a file included at global scope, so even the loop variable is a global
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $ibraai_site_id) {
        switch_to_blog((int) $ibraai_site_id);
        ibraai_uninstall_site();
        restore_current_blog();
    }
    unset($ibraai_site_id);
} else {
    ibraai_uninstall_site();
}
