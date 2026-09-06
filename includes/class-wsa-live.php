<?php
/**
 * Live chat: a person takes over a conversation from the AI.
 *
 * A thread is in one of five states. `ai` is the default. When the visitor
 * asks for a person the thread becomes `waiting` and the live-chat address is
 * emailed once; a manager who claims it makes it `live`; a wait longer than
 * the configured minutes, or a request a manager declines, becomes `missed`,
 * which the widget treats as "back to the AI, offer the contact option";
 * `closed` is a live chat a manager ended, or one nobody wrote in for
 * IDLE_MINUTES. A missed or closed thread can be requested again, and a
 * missed one can still be claimed late, which brings the widget back on its
 * next poll.
 *
 * Both sides poll: there is no socket, and none is needed at the pace of a
 * shop desk. The visitor is identified by the signed thread token the widget
 * already holds, the manager by the admin capability. Timeouts are applied on
 * the way in, by the visitor's poll and by the manager list, so a thread
 * nobody looks at is judged the moment someone does.
 *
 * Every transition is a conditional UPDATE on the current status, so two
 * managers claiming at once, or two requests racing, resolve to one winner
 * and one email. And every transition the visitor can see writes one system
 * line (waiting, joined, missed, closed) on the thread, so the widget only
 * ever renders what the server stored and never has to synthesise a text.
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

class Live
{
    /** Rows the admin list shows; anything else belongs to the AI. */
    private const OPEN_STATUSES = ['waiting', 'live', 'missed'];

    /** A live chat nobody has written in for this long is over. */
    private const IDLE_MINUTES = 30;

    /** Bounds on one message, either side. */
    private const VISITOR_MAX = 1200;

    private const MANAGER_MAX = 2000;

    /** Rows the manager list, one poll, and one timeout pass return at most. */
    private const LIST_LIMIT = 200;

    /** The question in the email is one line of a notification, not the transcript. */
    private const QUESTION_MAX = 200;

    // -----------------------------------------------------------------------
    // Visitor side
    // -----------------------------------------------------------------------
    /**
     * Asks for a person. Idempotent: only the transition into `waiting` writes
     * the waiting line and emails the desk, so the model calling hand_off
     * twice costs one line and one email.
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
            DB::add_message($thread_id, 'system', self::text('live_text_waiting', ''));
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
        $text = mb_substr(trim(sanitize_textarea_field($text)), 0, self::VISITOR_MAX);
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
        self::apply_timeouts($thread_id);
        $row = self::row($thread_id);
        $status = (string) ($row['status'] ?? 'ai');
        $manager_id = (int) ($row['manager_id'] ?? 0);
        $manager = self::manager_name($manager_id);

        return [
            'status' => $status === 'closed' || $status === '' ? 'ai' : $status,
            'manager' => $manager,
            'messages' => $row ? self::messages_since($thread_id, $since_id, ['manager', 'system']) : [],
            // sent on every poll rather than only when the state changes: four
            // short strings, and the widget can never miss the one it needs
            'texts' => self::texts($manager, $manager_id > 0),
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
     * Threads that need a person, after the timeouts have run over all of
     * them: waiting ones first, longest wait on top, then live ones by the
     * visitor's latest line, then missed ones. `waiting_seconds` is how long
     * the visitor has been waiting for a waiting or missed thread and 0 once a
     * person is in.
     */
    public static function open_threads(): array
    {
        global $wpdb;
        self::apply_timeouts();
        $threads = DB::threads_table();
        $messages = DB::messages_table();
        $slots = implode(', ', array_fill(0, count(self::OPEN_STATUSES), '%s'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.status, t.first_question, t.requested_at, t.manager_id,
                    (SELECT COUNT(*) FROM {$messages} m WHERE m.thread_id = t.id AND m.is_read = 0) AS unread
             FROM {$threads} t
             WHERE t.status IN ({$slots})
             ORDER BY FIELD(t.status, {$slots}),
                      CASE WHEN t.status = 'waiting' THEN t.requested_at END ASC,
                      t.last_visitor_at DESC,
                      t.requested_at DESC
             LIMIT %d",
            ...[...self::OPEN_STATUSES, ...self::OPEN_STATUSES, self::LIST_LIMIT],
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

    /**
     * The manager's line. A different manager than the one who claimed takes
     * the thread over, and the visitor is told who is talking now before the
     * line arrives. The takeover is conditional on the current owner, so two
     * replies from the same newcomer racing produce one joined line. Returns
     * the message id, 0 unless the thread is live and the text is not empty.
     */
    public static function manager_reply(int $thread_id, int $user_id, string $text): int
    {
        global $wpdb;
        $row = self::row($thread_id);
        if (($row['status'] ?? '') !== 'live') {
            return 0;
        }
        $text = mb_substr(trim(sanitize_textarea_field($text)), 0, self::MANAGER_MAX);
        if ($text === '') {
            return 0;
        }
        if ((int) $row['manager_id'] !== $user_id) {
            $threads = DB::threads_table();
            $changed = (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$threads} SET manager_id = %d WHERE id = %d AND status = 'live' AND manager_id <> %d",
                $user_id,
                $thread_id,
                $user_id,
            )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            if ($changed > 0) {
                DB::add_message($thread_id, 'system', self::text('live_text_joined', self::manager_name($user_id)));
            } elseif (self::state($thread_id) !== 'live') {
                return 0; // the chat ended between the read and the write; no change means it was already mine
            }
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
        $row = self::row($thread_id);
        $rows = $row ? self::messages_since($thread_id, $since_id, []) : [];

        if ($row) {
            $wpdb->update(DB::messages_table(), ['is_read' => 1], ['thread_id' => $thread_id, 'is_read' => 0], ['%d'], ['%d', '%d']);
        }

        return [
            'status' => (string) ($row['status'] ?? ''),
            'manager' => self::manager_name((int) ($row['manager_id'] ?? 0)),
            'messages' => $rows,
        ];
    }

    /**
     * The manager ends the chat and the AI has the thread again. A request
     * nobody joined has no chat to end: closing a waiting one marks it missed,
     * so the widget falls back to the contact option and lead capture instead
     * of announcing a chat that never happened; a missed one is left as it is.
     *
     * @return array{status: string}
     */
    public static function close(int $thread_id): array
    {
        $row = self::row($thread_id);

        if (($row['status'] ?? '') === 'live') {
            self::end($thread_id, (int) $row['manager_id']);
        } else {
            self::miss($thread_id);
        }

        return ['status' => self::state($thread_id)];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------
    /**
     * Judges one thread, or with 0 every thread. A wait past the window is
     * missed, with the missed line. A live chat nobody has written in for
     * IDLE_MINUTES is closed with the closed line. requested_at is GMT and the
     * activity columns are site-local, see DB::install(), so each rule has
     * its own cutoff.
     */
    private static function apply_timeouts(int $thread_id = 0): void
    {
        global $wpdb;
        $threads = DB::threads_table();
        $wait = max(1, (int) Settings::get('live_wait_minutes'));

        $stale = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$threads}
             WHERE status = 'waiting' AND requested_at < %s AND (%d = 0 OR id = %d)
             LIMIT %d",
            gmdate('Y-m-d H:i:s', time() - $wait * MINUTE_IN_SECONDS),
            $thread_id,
            $thread_id,
            self::LIST_LIMIT,
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        foreach ($stale ?: [] as $id) {
            self::miss((int) $id);
        }

        $idle = $wpdb->get_results($wpdb->prepare(
            "SELECT id, manager_id FROM {$threads}
             WHERE status = 'live'
               AND GREATEST(COALESCE(last_manager_at, claimed_at), COALESCE(last_visitor_at, claimed_at)) < %s
               AND (%d = 0 OR id = %d)
             LIMIT %d",
            gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - self::IDLE_MINUTES * MINUTE_IN_SECONDS),
            $thread_id,
            $thread_id,
            self::LIST_LIMIT,
        ), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        foreach ($idle ?: [] as $row) {
            self::end((int) $row['id'], (int) $row['manager_id']);
        }
    }

    /** A request nobody joined, with the missed line. The wait timeout and a manager declining both land here; a claim racing in wins. */
    private static function miss(int $thread_id): void
    {
        global $wpdb;
        $threads = DB::threads_table();

        $changed = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$threads} SET status = 'missed' WHERE id = %d AND status = 'waiting'",
            $thread_id,
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($changed > 0) {
            DB::add_message($thread_id, 'system', self::text('live_text_missed', ''));
        }
    }

    /** Ends a chat a person was in, with the line that names them. The manual close and the idle timeout both land here. */
    private static function end(int $thread_id, int $manager_id): void
    {
        global $wpdb;
        $threads = DB::threads_table();

        $changed = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$threads} SET status = 'closed', closed_at = %s WHERE id = %d AND status = 'live'",
            current_time('mysql'),
            $thread_id,
        )); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        if ($changed > 0) {
            DB::add_message($thread_id, 'system', self::text('live_text_closed', self::manager_name($manager_id)));
        }
    }

    /** One email per request, to the live-chat address, with the link into the conversation on the first line. */
    private static function notify(int $thread_id, int $page_id): void
    {
        $row = self::row($thread_id);
        if (! $row) {
            return;
        }
        // one plain line: the visitor typed it, so tags and line breaks go
        $question = mb_substr(sanitize_text_field((string) ($row['first_question'] ?? '')), 0, self::QUESTION_MAX);
        $permalink = $page_id > 0 ? get_permalink($page_id) : false;
        $lines = [
            sprintf(__('Answer here: %s', 'woocommerce-shop-agent'), admin_url('admin.php?page=' . Admin::SLUG . '&tab=live&thread=' . $thread_id)),
            '',
            sprintf(__('Question: %s', 'woocommerce-shop-agent'), $question !== '' ? $question : '-'),
            sprintf(__('Page: %s', 'woocommerce-shop-agent'), $permalink ?: '-'),
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

    /** Lines after an id, oldest first, restricted to the given roles (none means every role), in the shape both polls hand to the browser. */
    private static function messages_since(int $thread_id, int $since_id, array $roles): array
    {
        global $wpdb;
        $messages = DB::messages_table();
        $filter = $roles ? ' AND role IN (' . implode(', ', array_fill(0, count($roles), '%s')) . ')' : '';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, role, content, created_at FROM {$messages}
             WHERE thread_id = %d AND id > %d{$filter}
             ORDER BY id ASC LIMIT %d",
            ...[$thread_id, $since_id, ...$roles, self::LIST_LIMIT],
        ), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'role' => (string) $row['role'],
            'text' => (string) $row['content'],
            'at' => (string) $row['created_at'],
        ], $rows ?: []);
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

    /** The closed text only exists once a person was in the chat; a declined request has nothing to have ended. */
    private static function texts(string $manager, bool $had_manager): array
    {
        return [
            'waiting' => self::text('live_text_waiting', $manager),
            'joined' => self::text('live_text_joined', $manager),
            'missed' => self::text('live_text_missed', $manager),
            'closed' => $had_manager ? self::text('live_text_closed', $manager) : '',
        ];
    }
}
