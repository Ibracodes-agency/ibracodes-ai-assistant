<?php
/**
 * Minimal assertions for the wp eval-file test scripts. No framework: the
 * plugin has no Composer, and the tests need a real WordPress with the plugin
 * active, which WP-CLI gives for free.
 */

function wsa_assert(bool $ok, string $what): void
{
    if (! $ok) {
        fwrite(STDERR, "FAIL: {$what}\n");
        exit(1);
    }
    echo "  ok: {$what}\n";
}

function wsa_assert_same(mixed $expected, mixed $actual, string $what): void
{
    wsa_assert($expected === $actual, $what . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

/** Creates a published post the test owns; returns the id. Always pair with wsa_cleanup(). */
function wsa_make_post(string $title, string $content, string $type = 'page', string $status = 'publish'): int
{
    $id = wp_insert_post([
        'post_title' => $title,
        'post_content' => $content,
        'post_type' => $type,
        'post_status' => $status,
    ], true);
    if (is_wp_error($id)) {
        fwrite(STDERR, 'could not create post: ' . $id->get_error_message() . "\n");
        exit(1);
    }
    $GLOBALS['wsa_test_posts'][] = (int) $id;

    return (int) $id;
}

function wsa_cleanup(): void
{
    foreach ($GLOBALS['wsa_test_posts'] ?? [] as $id) {
        wp_delete_post($id, true);
    }
    $GLOBALS['wsa_test_posts'] = [];
}

function wsa_done(string $file): void
{
    wsa_cleanup();
    echo 'PASS ' . basename($file) . "\n";
}
