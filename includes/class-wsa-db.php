<?php
/**
 * Conversation storage.
 *
 * Two tables, deliberately narrow: what customers asked, what the agent
 * showed them, and whether it worked. No IP address, no email, no name, no
 * user id. A shop owner reading these learns about their catalogue, not about
 * a person, and there is nothing here worth stealing.
 *
 * Rows are deleted by age on a daily job. Retention is a setting, and the
 * default is short.
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

class DB
{
    public const PURGE_HOOK = 'wsa_purge_threads';

    private const DB_VERSION = '1.0.1';

    public static function threads_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wsa_threads';
    }

    public static function messages_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'wsa_messages';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $threads = self::threads_table();
        $messages = self::messages_table();

        dbDelta("CREATE TABLE {$threads} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            locale VARCHAR(20) NOT NULL DEFAULT '',
            device VARCHAR(10) NOT NULL DEFAULT '',
            turns SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            products_shown SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            no_match TINYINT(1) NOT NULL DEFAULT 0,
            added_to_cart TINYINT(1) NOT NULL DEFAULT 0,
            first_question TEXT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY no_match (no_match, created_at)
        ) {$charset};

        CREATE TABLE {$messages} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(10) NOT NULL,
            content TEXT NOT NULL,
            product_ids VARCHAR(255) NOT NULL DEFAULT '',
            no_match TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY thread_id (thread_id, id),
            KEY no_match (no_match, created_at)
        ) {$charset};");

        update_option('wsa_db_version', self::DB_VERSION, false);
    }

    /** Runs the schema once per version bump, under a lock so a busy site cannot stampede it. */
    public static function maybe_upgrade(): void
    {
        if (get_option('wsa_db_version') === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        $lock = 'wsa_install_' . substr(md5(DB_NAME . $wpdb->prefix), 0, 8);
        if (! (bool) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
            return;
        }
        try {
            if (get_option('wsa_db_version') !== self::DB_VERSION) {
                self::install();
            }
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------
    public static function start_thread(string $question, string $device): int
    {
        global $wpdb;
        $now = current_time('mysql');

        $wpdb->insert(self::threads_table(), [
            'created_at' => $now,
            'updated_at' => $now,
            'locale' => get_locale(),
            'device' => $device === 'mobile' ? 'mobile' : 'desktop',
            'turns' => 0,
            'first_question' => mb_substr($question, 0, 500),
        ]);

        return (int) $wpdb->insert_id;
    }

    public static function log_turn(int $thread_id, string $question, string $reply, array $product_ids, bool $no_match): void
    {
        global $wpdb;
        $now = current_time('mysql');
        $messages = self::messages_table();

        $wpdb->insert($messages, [
            'thread_id' => $thread_id,
            'role' => 'user',
            'content' => mb_substr($question, 0, 2000),
            // recorded on the question itself: a thread can contain one
            // answerable question and one that found nothing, and the report
            // has to name the one that failed
            'no_match' => $no_match ? 1 : 0,
            'created_at' => $now,
        ]);
        $wpdb->insert($messages, [
            'thread_id' => $thread_id,
            'role' => 'assistant',
            'content' => mb_substr($reply, 0, 4000),
            'product_ids' => implode(',', array_map('intval', array_slice($product_ids, 0, 10))),
            'created_at' => $now,
        ]);

        $threads = self::threads_table();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$threads}
             SET turns = turns + 1,
                 products_shown = products_shown + %d,
                 no_match = GREATEST(no_match, %d),
                 updated_at = %s
             WHERE id = %d",
            count($product_ids),
            $no_match ? 1 : 0,
            $now,
            $thread_id,
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public static function mark_added_to_cart(int $thread_id): void
    {
        global $wpdb;
        $wpdb->update(self::threads_table(), ['added_to_cart' => 1], ['id' => $thread_id]);
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------
    /** @return array{rows: array, total: int} */
    public static function threads(int $page = 1, int $per_page = 20): array
    {
        global $wpdb;
        $table = self::threads_table();
        $offset = max(0, ($page - 1) * $per_page);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset,
        ), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return [
            'rows' => $rows ?: [],
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
        ];
    }

    public static function thread(int $id): ?array
    {
        global $wpdb;
        $threads = self::threads_table();
        $messages = self::messages_table();

        $thread = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$threads} WHERE id = %d", $id), ARRAY_A);
        if (! $thread) {
            return null;
        }
        $thread['messages'] = $wpdb->get_results($wpdb->prepare(
            "SELECT role, content, product_ids, created_at FROM {$messages} WHERE thread_id = %d ORDER BY id ASC LIMIT 60",
            $id,
        ), ARRAY_A) ?: [];

        return $thread;
    }

    /**
     * Questions where the agent searched and the catalogue had nothing. Each
     * one is either a product the shop should stock or a word its titles do
     * not use, which is the single most useful thing this plugin can tell an
     * owner.
     */
    public static function unanswered(int $limit = 50): array
    {
        global $wpdb;
        $messages = self::messages_table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT thread_id, content AS question, created_at
             FROM {$messages}
             WHERE no_match = 1 AND role = 'user' AND content <> ''
             ORDER BY created_at DESC
             LIMIT %d",
            $limit,
        ), ARRAY_A) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** Most-recommended products over the window, for the overview panel. */
    public static function top_products(int $days = 30, int $limit = 5): array
    {
        global $wpdb;
        $messages = self::messages_table();
        $since = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - $days * DAY_IN_SECONDS);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_ids FROM {$messages}
             WHERE product_ids <> '' AND created_at >= %s
             LIMIT 2000",
            $since,
        ), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $counts = [];
        foreach ($rows ?: [] as $row) {
            foreach (explode(',', $row['product_ids']) as $id) {
                $id = (int) $id;
                if ($id) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        return array_slice($counts, 0, $limit, true);
    }

    public static function stats(int $days = 30): array
    {
        global $wpdb;
        $table = self::threads_table();
        $since = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - $days * DAY_IN_SECONDS);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS threads,
                    COALESCE(SUM(turns), 0) AS turns,
                    COALESCE(SUM(products_shown), 0) AS products,
                    COALESCE(SUM(no_match), 0) AS no_match,
                    COALESCE(SUM(added_to_cart), 0) AS carts
             FROM {$table} WHERE created_at >= %s",
            $since,
        ), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return [
            'threads' => (int) ($row['threads'] ?? 0),
            'turns' => (int) ($row['turns'] ?? 0),
            'products' => (int) ($row['products'] ?? 0),
            'no_match' => (int) ($row['no_match'] ?? 0),
            'carts' => (int) ($row['carts'] ?? 0),
        ];
    }

    // -----------------------------------------------------------------------
    // Retention
    // -----------------------------------------------------------------------
    public static function schedule_purge(): void
    {
        if (! wp_next_scheduled(self::PURGE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK);
        }
    }

    /** Deletes threads past the retention window, in bounded chunks. */
    public static function purge(): void
    {
        global $wpdb;
        $days = max(1, (int) Settings::get('retention_days'));
        $cutoff = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - $days * DAY_IN_SECONDS);
        $threads = self::threads_table();
        $messages = self::messages_table();

        // chunked so a long-neglected site cannot lock the table for a minute
        for ($i = 0; $i < 20; $i++) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$threads} WHERE created_at < %s LIMIT 500",
                $cutoff,
            )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if (! $ids) {
                return;
            }
            $in = implode(',', array_map('intval', $ids));
            $wpdb->query("DELETE FROM {$messages} WHERE thread_id IN ({$in})");
            $wpdb->query("DELETE FROM {$threads} WHERE id IN ({$in})");
        }
    }

    public static function delete_all_threads(): void
    {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . self::messages_table());
        $wpdb->query('TRUNCATE TABLE ' . self::threads_table());
    }
}
