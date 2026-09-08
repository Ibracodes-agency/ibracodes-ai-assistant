<?php
/**
 * Leads hold personal data on purpose, unlike transcripts, so they live in
 * their own table with their own retention. Validation happens here, not in
 * the model: a bad phone number comes back as an error the model must relay
 * by asking again, never by claiming success.
 */

namespace Ibracodes\AI_Assistant;

if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables owned by this plugin; results are small and per-request

class Leads
{
    public static function capture(array $input, int $thread_id, int $page_id): array
    {
        $name = trim(sanitize_text_field((string) ($input['name'] ?? '')));
        $contact = trim(self::without_format_chars(sanitize_text_field((string) ($input['contact'] ?? ''))));
        $request = mb_substr(trim(sanitize_textarea_field((string) ($input['request'] ?? ''))), 0, 500);

        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            return ['error' => 'invalid_name', 'message' => 'Ask for the visitor\'s name again; it must be 2 to 80 characters.'];
        }
        if (! self::is_phone($contact) && ! is_email($contact)) {
            return ['error' => 'invalid_contact', 'message' => 'That is not a phone number or an email address. Ask again for one of the two.'];
        }

        global $wpdb;
        $table = DB::leads_table();
        // one lead per conversation: a second call corrects the first rather than duplicating it
        $existing = $thread_id > 0 ? (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE thread_id = %d ORDER BY id DESC LIMIT 1', $table, $thread_id)) : 0;
        $data = ['name' => $name, 'contact' => $contact, 'request' => $request, 'page_id' => $page_id];

        if ($existing) {
            if ($wpdb->update($table, $data, ['id' => $existing]) === false) {
                return self::storage_failed();
            }
            $id = $existing;
        } else {
            $ok = $wpdb->insert($table, $data + ['thread_id' => $thread_id, 'created_at' => current_time('mysql')]);
            if (! $ok) {
                return self::storage_failed();
            }
            $id = (int) $wpdb->insert_id;
        }

        // a failed email keeps the lead; the flag lets the admin show which ones the owner never heard about
        $sent = self::notify($id, $existing > 0);
        $wpdb->update($table, ['email_sent' => $sent ? 1 : 0], ['id' => $id]);

        return ['saved' => true, 'id' => $id];
    }

    private static function storage_failed(): array
    {
        return ['error' => 'storage_failed', 'message' => 'The lead could not be saved. Apologise and offer the contact option instead.'];
    }

    /** Digits with the usual separators, 7 to 15 digits in total. */
    public static function is_phone(string $value): bool
    {
        $value = self::without_format_chars($value);
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return (bool) preg_match('/^\+?[\d\s().-]{7,20}$/', $value) && strlen($digits) >= 7 && strlen($digits) <= 15;
    }

    /** Invisible direction and joining marks, which a phone app pastes along with the number on an RTL site. */
    private static function without_format_chars(string $value): string
    {
        return preg_replace('/\p{Cf}/u', '', $value) ?? $value;
    }

    private static function notify(int $id, bool $updated): bool
    {
        $lead = self::find($id);
        if (! $lead) {
            return false;
        }
        $to = (string) Settings::get('leads_email');
        $subject = sprintf(
            $updated
                /* translators: %s: the visitor's name */
                ? __('Updated lead from the AI Assistant: %s', 'ibracodes-ai-assistant')
                /* translators: %s: the visitor's name */
                : __('New lead from the AI Assistant: %s', 'ibracodes-ai-assistant'),
            $lead['name'],
        );
        $lines = [
            /* translators: %s: the visitor's name */
            sprintf(__('Name: %s', 'ibracodes-ai-assistant'), $lead['name']),
            /* translators: %s: the visitor's phone number or email address */
            sprintf(__('Contact: %s', 'ibracodes-ai-assistant'), $lead['contact']),
            /* translators: %s: what the visitor asked for */
            sprintf(__('Request: %s', 'ibracodes-ai-assistant'), $lead['request'] ?: '-'),
            /* translators: %s: the URL of the page the visitor was on */
            sprintf(__('Page: %s', 'ibracodes-ai-assistant'), $lead['page_id'] ? get_permalink((int) $lead['page_id']) : '-'),
            '',
            /* translators: %s: link to the conversation in the WordPress admin */
            sprintf(__('Conversation: %s', 'ibracodes-ai-assistant'), $lead['thread_id'] ? admin_url('admin.php?page=' . Admin::SLUG . '&tab=conversations&thread=' . (int) $lead['thread_id']) : '-'),
            /* translators: %s: link to the leads screen in the WordPress admin */
            sprintf(__('All leads: %s', 'ibracodes-ai-assistant'), admin_url('admin.php?page=' . Admin::SLUG . '&tab=leads')),
        ];

        return (bool) wp_mail($to, $subject, implode("\n", $lines));
    }

    public static function find(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', DB::leads_table(), $id), ARRAY_A);

        return $row ?: null;
    }

    /** @return array{rows: array, total: int} */
    public static function list(int $page, int $per_page, string $search = ''): array
    {
        global $wpdb;
        $table = DB::leads_table();
        // one prepare() per query: a prepared clause fed back into prepare() would have its own '%' read as placeholders
        $where = '';
        $args = [];
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where = ' WHERE name LIKE %s OR contact LIKE %s OR request LIKE %s';
            $args = [$like, $like, $like];
        }
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i{$where}", $table, ...$args)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the search clause is a literal with one %s per LIKE, bound from $args
        $offset = max(0, ($page - 1) * $per_page);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i{$where} ORDER BY id DESC LIMIT %d OFFSET %d", ...[$table, ...$args, $per_page, $offset]), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the search clause is a literal with one %s per LIKE, bound from $args

        return ['rows' => $rows ?: [], 'total' => $total];
    }

    /** The conversation the lead came from goes with it: that is the owner's promise to the visitor. Returns whether a row was removed. */
    public static function delete(int $id): bool
    {
        $lead = self::find($id);
        if (! $lead) {
            return false;
        }
        if ((int) $lead['thread_id'] > 0) {
            DB::delete_thread((int) $lead['thread_id']);
        }
        global $wpdb;

        return (bool) $wpdb->delete(DB::leads_table(), ['id' => $id], ['%d']);
    }

    public static function purge(): void
    {
        global $wpdb;
        $table = DB::leads_table();
        $days = max(1, (int) Settings::get('leads_retention_days'));
        $cutoff = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - $days * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, thread_id FROM %i WHERE created_at < %s LIMIT 1000', $table, $cutoff), ARRAY_A) ?: [];
        if (! $rows) {
            return;
        }
        // transcripts first, so a purged lead never leaves its conversation behind
        foreach (array_unique(array_map('intval', array_column($rows, 'thread_id'))) as $thread_id) {
            if ($thread_id > 0) {
                DB::delete_thread($thread_id);
            }
        }
        $ids = array_map('intval', array_column($rows, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE id IN ({$placeholders})", $table, ...$ids)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one %d per id, built from the id list at runtime
    }

    public static function count_since(int $days): int
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - $days * DAY_IN_SECONDS);

        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE created_at >= %s', DB::leads_table(), $cutoff));
    }
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
