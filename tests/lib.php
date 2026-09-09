<?php
/**
 * Minimal assertions for the wp eval-file test scripts. No framework: the
 * plugin has no Composer, and the tests need a real WordPress with the plugin
 * active, which WP-CLI gives for free.
 */

function ibraai_assert(bool $ok, string $what): void
{
    if (! $ok) {
        fwrite(STDERR, "FAIL: {$what}\n");
        exit(1);
    }
    echo "  ok: {$what}\n";
}

function ibraai_assert_same(mixed $expected, mixed $actual, string $what): void
{
    ibraai_assert($expected === $actual, $what . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

/** Creates a post the test owns (published by default); returns the id. $extra adds or overrides insert arguments. */
function ibraai_make_post(string $title, string $content, string $type = 'page', string $status = 'publish', array $extra = []): int
{
    $id = wp_insert_post($extra + [
        'post_title' => $title,
        'post_content' => $content,
        'post_type' => $type,
        'post_status' => $status,
    ], true);
    if (is_wp_error($id)) {
        fwrite(STDERR, 'could not create post: ' . $id->get_error_message() . "\n");
        exit(1);
    }
    // wp eval-file includes the script inside a method scope, so the registry has to live in $GLOBALS for ibraai_cleanup() to see it.
    $GLOBALS['ibraai_test_posts'][] = (int) $id;

    return (int) $id;
}

function ibraai_cleanup(): void
{
    foreach ($GLOBALS['ibraai_test_posts'] ?? [] as $id) {
        wp_delete_post($id, true);
    }
    $GLOBALS['ibraai_test_posts'] = [];
}

/**
 * The rate limits and usage counters live in the plugin's counters table (see
 * Guards), one row per name. Tests seed a row so they can stand a window in
 * the past, read the raw value past its expiry, and remove what they created.
 */
function ibraai_counter_set(string $name, int $value, string $expires_at): void
{
    global $wpdb;
    $wpdb->replace(\Ibracodes\AI_Assistant\DB::counters_table(), [
        'name' => $name,
        'value' => $value,
        'expires_at' => $expires_at,
    ], ['%s', '%d', '%s']);
}

/** The row as stored, expired or not, or null when there is none. */
function ibraai_counter_row(string $name): ?array
{
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT value, expires_at FROM %i WHERE name = %s',
        \Ibracodes\AI_Assistant\DB::counters_table(),
        $name,
    ), ARRAY_A);

    return $row ?: null;
}

function ibraai_counter_forget(string $name): void
{
    global $wpdb;
    $wpdb->delete(\Ibracodes\AI_Assistant\DB::counters_table(), ['name' => $name], ['%s']);
}

function ibraai_done(string $file): void
{
    ibraai_cleanup();
    echo 'PASS ' . basename($file) . "\n";
}

register_shutdown_function('ibraai_cleanup');
