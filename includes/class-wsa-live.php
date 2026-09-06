<?php
/**
 * Live chat: a person takes over a conversation from the AI.
 *
 * A thread is in one of five states. `ai` is the default. When the visitor
 * asks for a person the thread becomes `waiting` and the live-chat address is
 * emailed once; a manager who claims it makes it `live`; a wait longer than
 * the configured minutes becomes `missed`, which the widget treats as "back to
 * the AI, offer the contact option"; `closed` is a live chat the manager
 * ended. A missed or closed thread can be requested again, and a missed one
 * can still be claimed late, which brings the widget back on its next poll.
 *
 * Both sides poll: there is no socket, and none is needed at the pace of a
 * shop desk. The visitor is identified by the signed thread token the widget
 * already holds, the manager by the admin capability.
 *
 * Every transition is a conditional UPDATE on the current status, so two
 * managers claiming at once, or two requests racing, resolve to one winner
 * and one email.
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

class Live
{
    /** Rows the admin list shows; anything else belongs to the AI. */
    private const OPEN_STATUSES = ['waiting', 'live', 'missed'];

    /** Bounds on one message, either side. */
    private const VISITOR_MAX = 1200;

    private const MANAGER_MAX = 2000;

    // -----------------------------------------------------------------------
    // Visitor side
    // -----------------------------------------------------------------------
    /**
     * Asks for a person. Idempotent: only the transition into `waiting` emails
     * the desk, so the model calling hand_off twice costs one email.
     *
     * @return array{status: string}
     */
    public static function request(int $thread_id, int $page_id): array
    {
        global $wpdb;
        $threads = DB::threads_table();

        $changed = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$threads}
             SET status = 'waiting', requested_at = %s, claimed_at = NULL, closed_at = NULL, manager_id = 0
             WHERE id = %d AND status IN ('ai', 'missed', 'closed')",
            current_time('mysql', true),
            $thread_id,
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($changed > 0) {
            self::notify($thread_id, $page_id);
        }

        return ['status' => self::state($thread_id)];
    }

    /** A line the visitor typed while a person is expected or present. Returns the message id, 0 when the AI owns the thread or the text is empty. */
    public static function visitor_message(int $thread_id, string $text): int
    {
        if (! in_array(self::state($thread_id), ['waiting', 'live'], true)) {
            return 0;
        }
        $text = mb_substr(trim(wp_strip_all_tags($text)), 0, self::VISITOR_MAX);
        if ($text === '') {
            return 0;
        }
        $id = DB::add_message($thread_id, 'user', $text, false);
        if ($id > 0) {
            self::touch($thread_id, 'last_visitor_at');
        }

        return $id;
    }

    /**
     * What the widget needs on each poll: the state as the widget understands
     * it (a closed chat is the AI's again), the manager's name, the lines a
     * person or the system added since the last poll, and the four texts with
     * the name filled in.
     *
     * @return array{status: string, manager: string, messages: array, texts: array}
     */
    public static function poll_visitor(int $thread_id, int $since_id): array
    {
        global $wpdb;
        self::apply_timeout($thread_id);
        $row = self::row($thread_id);
        $status = (string) ($row['status'] ?? 'ai');
        $manager = self::manager_name((int) ($row['manager_id'] ?? 0));
        $messages = DB::messages_table();

        $rows = $row ? $wpdb->get_results($wpdb->prepare(
            "SELECT id, role, content, created_at FROM {$messages}
             WHERE thread_id = %d AND id > %d AND role IN ('manager', 'system')
             ORDER BY id ASC LIMIT 100",
            $thread_id,
            $since_id,
        ), ARRAY_A) : []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return [
            'status' => $status === 'closed' || $status === '' ? 'ai' : $status,
            'manager' => $manager,
            'messages' => array_map([self::class, 'message'], $rows ?: []),
            'texts' => self::texts($manager),
        ];
    }

    /** The stored status, or '' when the thread does not exist. */
    public static function state(int $thread_id): string
    {
        global $wpdb;
        $threads = DB::threads_table();

        return (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$threads} WHERE id = %d", $thread_id)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    // -----------------------------------------------------------------------
    // Manager side
    // -----------------------------------------------------------------------
    /**
     * Threads that need a person: waiting ones first, longest wait on top, then
     * live ones by the visitor's latest line, then missed ones. `waiting_seconds`
     * is how long the visitor has been waiting for a waiting or missed thread
     * and 0 once a person is in.
     */
    public static function open_threads(): array
    {
        global $wpdb;
        $threads = DB::threads_table();
        $messages = DB::messages_table();
        $in = "'" . implode("', '", self::OPEN_STATUSES) . "'";

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.status, t.first_question, t.requested_at, t.manager_id,
                    (SELECT COUNT(*) FROM {$messages} m WHERE m.thread_id = t.id AND m.is_read = 0) AS unread
             FROM {$threads} t
             WHERE t.status IN ({$in})
             ORDER BY FIELD(t.status, {$in}),
                      CASE WHEN t.status = 'waiting' THEN t.requested_at END ASC,
                      t.last_visitor_at DESC,
                      t.requested_at DESC
             LIMIT %d",
            200,
        ), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $now = time();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'first_question' => (string) ($row['first_question'] ?? ''),
            'unread' => (int) $row['unread'],
            'waiting_seconds' => $row['status'] === 'live' || ! $row['requested_at']
                ? 0
                : max(0, $now - (int) strtotime($row['requested_at'] . ' UTC')),
            'manager' => self::manager_name((int) $row['manager_id']),
        ], $rows ?: []);
    }

    /**
     * A manager takes the thread. Only a waiting or missed thread can be
     * claimed; a second manager arriving a moment later gets the current state
     * back, with the first one's name, instead of silently taking over.
     *
     * @return array{status: string, manager: string}
     */
    public static function claim(int $thread_id, int $user_id): array
    {
        global $wpdb;
        $threads = DB::threads_table();
        $now = current_time('mysql');

        $changed = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$threads}
             SET status = 'live', claimed_at = %s, manager_id = %d, last_manager_at = %s
             WHERE id = %d AND status IN ('waiting', 'missed')",
            $now,
            $user_id,
            $now,
            $thread_id,
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($changed > 0) {
            DB::add_message($thread_id, 'system', self::text('live_text_joined', self::manager_name($user_id)));
        }
        $row = self::row($thread_id);

        return [
            'status' => (string) ($row['status'] ?? ''),
            'manager' => self::manager_name((int) ($row['manager_id'] ?? 0)),
        ];
    }

    /** The manager's line. Returns the message id, 0 unless the thread is live and the text is not empty. */
    public static function manager_reply(int $thread_id, int $user_id, string $text): int
    {
        if (self::state($thread_id) !== 'live') {
            return 0;
        }
        $text = mb_substr(trim(sanitize_textarea_field($text)), 0, self::MANAGER_MAX);
        if ($text === '') {
            return 0;
        }
        $id = DB::add_message($thread_id, 'manager', $text);
        if ($id > 0) {
            self::touch($thread_id, 'last_manager_at');
        }

        return $id;
    }

    /**
     * The whole conversation for the admin pane, every role, since an id.
     * Reading it is what marks the visitor's lines read, which is how the
     * unread badge clears.
     *
     * @return array{status: string, manager: string, messages: array}
     */
    public static function poll_manager(int $thread_id, int $since_id): array
    {
        global $wpdb;
        $messages = DB::messages_table();
        $row = self::row($thread_id);

        $rows = $row ? $wpdb->get_results($wpdb->prepare(
            "SELECT id, role, content, created_at FROM {$messages}
             WHERE thread_id = %d AND id > %d
             ORDER BY id ASC LIMIT 200",
            $thread_id,
            $since_id,
        ), ARRAY_A) : []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($row) {
            $wpdb->update($messages, ['is_read' => 1], ['thread_id' => $thread_id, 'is_read' => 0], ['%d'], ['%d', '%d']);
        }

        return [
            'status' => (string) ($row['status'] ?? ''),
            'manager' => self::manager_name((int) ($row['manager_id'] ?? 0)),
            'messages' => array_map([self::class, 'message'], $rows ?: []),
        ];
    }

    /**
     * The manager ends the chat and the AI has the thread again. A waiting or
     * missed request can be closed too, which is how a desk declines one.
     *
     * @return array{status: string}
     */
    public static function close(int $thread_id, int $user_id): array
    {
        global $wpdb;
        $threads = DB::threads_table();
        $in = "'" . implode("', '", self::OPEN_STATUSES) . "'";

        $changed = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$threads} SET status = 'closed', closed_at = %s WHERE id = %d AND status IN ({$in})",
            current_time('mysql'),
            $thread_id,
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($changed > 0) {
            DB::add_message($thread_id, 'system', self::text('live_text_closed', self::manager_name($user_id)));
        }

        return ['status' => self::state($thread_id)];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------
    /** A wait past the configured minutes becomes missed. Compares GMT with GMT, see DB::install(). */
    private static function apply_timeout(int $thread_id): void
    {
        global $wpdb;
        $threads = DB::threads_table();
        $minutes = max(1, (int) Settings::get('live_wait_minutes'));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$threads} SET status = 'missed' WHERE id = %d AND status = 'waiting' AND requested_at < %s",
            $thread_id,
            gmdate('Y-m-d H:i:s', time() - $minutes * MINUTE_IN_SECONDS),
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    /** One email per request, to the live-chat address, with a link straight into the conversation. */
    private static function notify(int $thread_id, int $page_id): void
    {
        $row = self::row($thread_id);
        $permalink = $page_id > 0 ? get_permalink($page_id) : false;
        $lines = [
            sprintf(__('Question: %s', 'woocommerce-shop-agent'), $row['first_question'] ?: '-'),
            sprintf(__('Page: %s', 'woocommerce-shop-agent'), $permalink ?: '-'),
            '',
            sprintf(__('Answer here: %s', 'woocommerce-shop-agent'), admin_url('admin.php?page=' . Admin::SLUG . '&tab=live&thread=' . $thread_id)),
        ];

        wp_mail(
            (string) Settings::get('live_email'),
            __('A visitor is waiting for a person', 'woocommerce-shop-agent'),
            implode("\n", $lines),
        );
    }

    private static function row(int $thread_id): ?array
    {
        global $wpdb;
        $threads = DB::threads_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$threads} WHERE id = %d", $thread_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return $row ?: null;
    }

    private static function touch(int $thread_id, string $column): void
    {
        global $wpdb;
        $wpdb->update(DB::threads_table(), [$column => current_time('mysql')], ['id' => $thread_id], ['%s'], ['%d']);
    }

    /** The shape both polls hand to the browser. */
    private static function message(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'role' => (string) $row['role'],
            'text' => (string) $row['content'],
            'at' => (string) $row['created_at'],
        ];
    }

    /** Display name, or '' for no manager or a deleted account. Cached: the list asks once per row. */
    private static function manager_name(int $user_id): string
    {
        static $names = [];
        if ($user_id <= 0) {
            return '';
        }
        if (! array_key_exists($user_id, $names)) {
            $user = get_userdata($user_id);
            $names[$user_id] = $user ? (string) $user->display_name : '';
        }

        return $names[$user_id];
    }

    /** An owner-editable text with the manager's name filled in; str_replace, not sprintf, so an edited text without %s cannot throw. */
    private static function text(string $key, string $manager): string
    {
        return str_replace('%s', $manager, (string) Settings::get($key));
    }

    private static function texts(string $manager): array
    {
        return [
            'waiting' => self::text('live_text_waiting', $manager),
            'joined' => self::text('live_text_joined', $manager),
            'missed' => self::text('live_text_missed', $manager),
            'closed' => self::text('live_text_closed', $manager),
        ];
    }
}
