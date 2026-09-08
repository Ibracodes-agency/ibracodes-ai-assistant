<?php
/**
 * Conversation storage.
 *
 * Two tables, deliberately narrow: what customers asked, what the agent
 * showed them, and whether it worked. No IP address, no email, no name, no
 * user id. A shop owner reading these learns about their catalogue, not about
 * a person, and there is nothing here worth stealing.
 *
 * A thread also carries its live-chat state (see Live): who is answering it,
 * a person or the AI, and since when. The only user id stored is the
 * manager's own.
 *
 * Rows are deleted by age on a daily job. Retention is a setting, and the
 * default is short.
 */

namespace Ibracodes\AI_Assistant;

if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables owned by this plugin; results are small and per-request

class DB
{
    public const PURGE_HOOK = 'ibraai_purge_threads';

    private const DB_VERSION = '1.3.0';

    /** The prefix every option, table and cron hook used before 0.2.0. */
    private const LEGACY_PREFIX = 'wsa_';

    /** Fixed option names under that prefix; the monthly counters are found by query. */
    private const LEGACY_OPTIONS = [
        'settings',
        'openai_key',
        'db_version',
        'last_failure',
        'index_queue',
        'index_backoff',
        'index_model',
    ];

    private const LEGACY_TABLES = ['threads', 'messages', 'chunks', 'leads'];

    private const LEGACY_HOOKS = ['purge_threads', 'index_batch', 'index_reconcile'];

    public static function threads_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ibraai_threads';
    }

    public static function messages_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ibraai_messages';
    }

    public static function chunks_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ibraai_chunks';
    }

    public static function leads_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ibraai_leads';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $threads = self::threads_table();
        $messages = self::messages_table();
        $chunks = self::chunks_table();
        $leads = self::leads_table();

        // Live chat columns on threads: status is one of ai, waiting, live,
        // missed, closed. requested_at alone is stored in GMT
        // (current_time('mysql', true)) because the wait timeout compares it
        // with time(); the other datetimes are site-local like created_at.
        // A comment inside the CREATE TABLE would be read by dbDelta as a
        // column, which is why this note sits here.
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
            status VARCHAR(10) NOT NULL DEFAULT 'ai',
            requested_at DATETIME NULL,
            claimed_at DATETIME NULL,
            closed_at DATETIME NULL,
            manager_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_visitor_at DATETIME NULL,
            last_manager_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY no_match (no_match, created_at),
            KEY status (status, requested_at)
        ) {$charset};

        CREATE TABLE {$messages} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(10) NOT NULL,
            content TEXT NOT NULL,
            product_ids VARCHAR(255) NOT NULL DEFAULT '',
            no_match TINYINT(1) NOT NULL DEFAULT 0,
            is_read TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY thread_id (thread_id, id),
            KEY no_match (no_match, created_at)
        ) {$charset};

        CREATE TABLE {$chunks} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            ord SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            content TEXT NOT NULL,
            embedding BLOB NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id, ord)
        ) {$charset};

        CREATE TABLE {$leads} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(80) NOT NULL,
            contact VARCHAR(120) NOT NULL,
            request TEXT NULL,
            page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY thread_id (thread_id),
            KEY created_at (created_at)
        ) {$charset};");

        update_option('ibraai_db_version', self::DB_VERSION, false);
    }

    /** Runs the schema once per version bump, under a lock so a busy site cannot stampede it. */
    public static function maybe_upgrade(): void
    {
        if (get_option('ibraai_db_version') === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        $lock = 'ibraai_install_' . substr(md5(DB_NAME . $wpdb->prefix), 0, 8);
        if (! (bool) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
            return;
        }
        try {
            self::adopt_legacy_names();
            if (get_option('ibraai_db_version') !== self::DB_VERSION) {
                self::install();
            }
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /**
     * 0.2.0 moved every option, table and cron hook onto the ibraai_ prefix,
     * which WordPress.org requires. An install that predates it keeps its data:
     * the options are copied across, the tables renamed and the old cron hooks
     * cleared, once, before the schema step fills in whatever is still missing.
     *
     * Runs inside maybe_upgrade()'s lock, so two requests cannot both start it,
     * and only while the new version stamp is absent and the old one is not, so
     * it cannot run twice or undo later writes.
     */
    private static function adopt_legacy_names(): void
    {
        global $wpdb;
        $old_prefix = self::LEGACY_PREFIX;

        if (get_option('ibraai_db_version') !== false || get_option($old_prefix . 'db_version') === false) {
            return;
        }

        $names = self::LEGACY_OPTIONS;
        // one option per month of API usage, so the set is only known at runtime
        $counters = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($old_prefix . 'calls_month_') . '%',
        ));
        foreach ($counters as $counter) {
            $names[] = substr($counter, strlen($old_prefix));
        }

        foreach ($names as $name) {
            $old = $old_prefix . $name;
            $value = get_option($old);
            if ($value !== false) {
                // the stored autoload flag comes along, so the settings stay on
                // the autoloaded set and the counters stay off it
                $autoload = $wpdb->get_var($wpdb->prepare(
                    "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
                    $old,
                ));
                update_option('ibraai_' . $name, $value, $autoload);
            }
            delete_option($old);
        }

        foreach (self::LEGACY_TABLES as $table) {
            $old = $wpdb->prefix . $old_prefix . $table;
            $new = $wpdb->prefix . 'ibraai_' . $table;
            $have_old = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($old)));
            $have_new = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($new)));
            if ($have_old && ! $have_new) {
                $wpdb->query($wpdb->prepare('RENAME TABLE %i TO %i', $old, $new)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- renaming the plugin's own tables onto the new prefix
            }
        }

        foreach (self::LEGACY_HOOKS as $hook) {
            wp_unschedule_hook($old_prefix . $hook);
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

        $wpdb->query($wpdb->prepare(
            'UPDATE %i
             SET turns = turns + 1,
                 products_shown = products_shown + %d,
                 no_match = GREATEST(no_match, %d),
                 updated_at = %s
             WHERE id = %d',
            self::threads_table(),
            count($product_ids),
            $no_match ? 1 : 0,
            $now,
            $thread_id,
        ));
    }

    /**
     * One message outside the question-and-answer turn: a visitor line while
     * a person is being fetched, the manager's reply, or a system line such
     * as "X joined". Does not count as a turn. Returns the id, 0 on failure.
     */
    public static function add_message(int $thread_id, string $role, string $content, bool $is_read = true): int
    {
        global $wpdb;
        $ok = $wpdb->insert(self::messages_table(), [
            'thread_id' => $thread_id,
            'role' => $role,
            'content' => $content,
            'is_read' => $is_read ? 1 : 0,
            'created_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%d', '%s']);

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function mark_added_to_cart(int $thread_id): void
    {
        global $wpdb;
        $wpdb->update(self::threads_table(), ['added_to_cart' => 1], ['id' => $thread_id]);
    }

    /** Removes one conversation outright: a deleted lead takes the transcript it came from with it. */
    public static function delete_thread(int $thread_id): void
    {
        global $wpdb;
        $wpdb->delete(self::messages_table(), ['thread_id' => $thread_id], ['%d']);
        $wpdb->delete(self::threads_table(), ['id' => $thread_id], ['%d']);
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
            'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d',
            $table,
            $per_page,
            $offset,
        ), ARRAY_A);

        return [
            'rows' => $rows ?: [],
            'total' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table)),
        ];
    }

    public static function thread(int $id): ?array
    {
        global $wpdb;
        $threads = self::threads_table();
        $messages = self::messages_table();

        $thread = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $threads, $id), ARRAY_A);
        if (! $thread) {
            return null;
        }
        $thread['messages'] = $wpdb->get_results($wpdb->prepare(
            'SELECT id, role, content, product_ids, is_read, created_at FROM %i WHERE thread_id = %d ORDER BY id ASC LIMIT 60',
            $messages,
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
             FROM %i
             WHERE no_match = 1 AND role = 'user' AND content <> ''
             ORDER BY created_at DESC
             LIMIT %d",
            $messages,
            $limit,
        ), ARRAY_A) ?: [];
    }

    /** Most-recommended products over the window, for the overview panel. */
    public static function top_products(int $days = 30, int $limit = 5): array
    {
        global $wpdb;
        $messages = self::messages_table();
        $since = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - $days * DAY_IN_SECONDS);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_ids FROM %i
             WHERE product_ids <> '' AND created_at >= %s
             LIMIT 2000",
            $messages,
            $since,
        ), ARRAY_A);

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
            'SELECT COUNT(*) AS threads,
                    COALESCE(SUM(turns), 0) AS turns,
                    COALESCE(SUM(products_shown), 0) AS products,
                    COALESCE(SUM(no_match), 0) AS no_match,
                    COALESCE(SUM(added_to_cart), 0) AS carts
             FROM %i WHERE created_at >= %s',
            $table,
            $since,
        ), ARRAY_A);

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

    /** Deletes threads past the retention window, in bounded chunks, then leads past their own. */
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
                'SELECT id FROM %i WHERE created_at < %s LIMIT 500',
                $threads,
                $cutoff,
            ));
            if (! $ids) {
                break;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE thread_id IN ({$placeholders})", $messages, ...$ids)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %d per id, built from the id list at runtime
            $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE id IN ({$placeholders})", $threads, ...$ids)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %d per id, built from the id list at runtime
        }

        Leads::purge();
    }

    public static function delete_all_threads(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('TRUNCATE TABLE %i', self::messages_table()));
        $wpdb->query($wpdb->prepare('TRUNCATE TABLE %i', self::threads_table()));
    }
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
