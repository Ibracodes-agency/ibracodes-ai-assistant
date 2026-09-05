<?php
/**
 * The embeddings index: chunks of allowed posts with their vectors, kept fresh
 * by post hooks, a cron batch and a daily reconcile. Ranking is cosine
 * similarity in PHP, which is fine up to a few thousand chunks; the design
 * says revisit only past that.
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

class Index
{
    public const HOOK = 'wsa_index_batch';

    public const RECONCILE_HOOK = 'wsa_index_reconcile';

    private const QUEUE = 'wsa_index_queue';

    /** Failed batches in a row; drives the exponential backoff. */
    private const BACKOFF = 'wsa_index_backoff';

    /** model:dims the stored vectors were made with; a change means a rebuild, never a mix. */
    private const MODEL = 'wsa_index_model';

    private const LOCK = 'wsa_index_lock';

    private const BATCH_POSTS = 20;

    private const BATCH_DELAY = 5;

    private const MAX_BACKOFF = 6 * HOUR_IN_SECONDS;

    public static function boot(): void
    {
        add_action(self::HOOK, [self::class, 'process_batch']);
        add_action(self::RECONCILE_HOOK, [self::class, 'reconcile']);
        add_action('save_post', [self::class, 'on_save'], 20, 2);
        add_action('deleted_post', [self::class, 'remove_post']);
        add_action('trashed_post', [self::class, 'remove_post']);
        add_action('transition_post_status', [self::class, 'on_status'], 10, 3);
        add_action('update_option_wsa_settings', [self::class, 'on_settings'], 10, 2);
        // a never-saved option goes through add_option, not update_option
        add_action('add_option_wsa_settings', [self::class, 'on_first_save'], 10, 2);
    }

    // ------------------------------------------------------------ state
    public static function enabled(): bool
    {
        return Settings::get('retrieval') === 'embeddings';
    }

    public static function ready(): bool
    {
        if (! self::enabled() || get_option(self::MODEL) !== self::model_stamp()) {
            return false;
        }
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . DB::chunks_table()) > 0;
    }

    public static function status(): array
    {
        global $wpdb;
        $table = DB::chunks_table();
        // a count, not the ids: scope_args() switches found_posts off, so switch it back on
        $scope = new \WP_Query(array_merge(Content::scope_args(), [
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]));

        return [
            'pending' => count((array) get_option(self::QUEUE, [])),
            'posts' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$table}"),
            'chunks' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'total' => (int) $scope->found_posts,
        ];
    }

    private static function model_stamp(): string
    {
        return Provider::EMBED_MODEL . ':' . Provider::EMBED_DIMS;
    }

    /**
     * Vectors made by another model or at another size are worthless next to
     * new ones, so a stamp that disagrees with the current constants means a
     * rebuild from scratch. drop() removes the stamp, so this runs once.
     */
    private static function rebuild_on_model_change(): bool
    {
        $stamp = get_option(self::MODEL);
        if ($stamp === false || $stamp === self::model_stamp()) {
            return false;
        }
        self::drop();
        self::queue_all();

        return true;
    }

    // ------------------------------------------------------------ queue
    public static function queue_all(): void
    {
        $ids = get_posts(array_merge(Content::scope_args(), ['posts_per_page' => -1, 'fields' => 'ids']));
        update_option(self::QUEUE, array_values(array_unique(array_map('intval', $ids))), false);
        self::schedule();
    }

    public static function queue_post(int $post_id): void
    {
        self::push([$post_id]);
    }

    /** Appends to the queue without duplicates and makes sure a batch is coming. */
    private static function push(array $ids): void
    {
        $queue = array_merge((array) get_option(self::QUEUE, []), $ids);
        update_option(self::QUEUE, array_values(array_unique(array_map('intval', $queue))), false);
        self::schedule();
    }

    private static function schedule(int $delay = self::BATCH_DELAY): void
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + $delay, self::HOOK);
        }
    }

    public static function schedule_reconcile(): void
    {
        if (! self::enabled()) {
            return;
        }
        if (! wp_next_scheduled(self::RECONCILE_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::RECONCILE_HOOK);
        }
    }

    /**
     * Embeds up to BATCH_POSTS queued posts; reschedules itself while work
     * remains. One batch at a time: a second cron runner finds the lock and
     * leaves.
     */
    public static function process_batch(): void
    {
        if (! self::enabled() || get_transient(self::LOCK)) {
            return;
        }
        // long enough for every post in the batch to hit the upstream timeout, so a live batch is never mistaken for a dead one
        set_transient(self::LOCK, 1, self::BATCH_POSTS * Provider::TIMEOUT + MINUTE_IN_SECONDS);
        try {
            self::rebuild_on_model_change();
            $queue = array_map('intval', (array) get_option(self::QUEUE, []));
            $batch = array_splice($queue, 0, self::BATCH_POSTS);
            update_option(self::QUEUE, $queue, false);

            foreach ($batch as $k => $post_id) {
                if (! Content::is_allowed($post_id)) {
                    self::remove_post($post_id);

                    continue;
                }
                $result = self::index_post($post_id);
                if ($result instanceof \WP_Error) {
                    // put back this post and everything after it, and stop: the key or the cap is the problem, not the post
                    update_option(self::QUEUE, array_values(array_unique(array_merge(array_slice($batch, $k), $queue))), false);
                    self::back_off($result->get_error_code());

                    return;
                }
            }
            if (get_option(self::QUEUE, [])) {
                self::schedule();
            }
        } finally {
            delete_transient(self::LOCK);
        }
    }

    /**
     * No key: nothing to retry until the owner saves one; on_save, on_settings
     * and a rebuild re-arm the batch. Rate limited: the guard resets at the
     * UTC day boundary. Anything else: exponential backoff, so an outage or a
     * rejected key cannot burn the daily cap and lock customers out of chat.
     */
    private static function back_off(string $code): void
    {
        wp_clear_scheduled_hook(self::HOOK);
        if ($code === 'wsa_no_key') {
            return;
        }
        // the code Guards::busy() puts on every rate-limit error
        if ($code === 'wsa_rate_limited') {
            $midnight = ((int) floor(time() / DAY_IN_SECONDS) + 1) * DAY_IN_SECONDS;
            self::schedule($midnight + MINUTE_IN_SECONDS - time());

            return;
        }
        $attempts = (int) get_option(self::BACKOFF, 0);
        update_option(self::BACKOFF, $attempts + 1, false);
        self::schedule(min(self::MAX_BACKOFF, MINUTE_IN_SECONDS * 2 ** $attempts));
    }

    public static function index_post(int $post_id): bool|\WP_Error
    {
        $chunks = Content::chunk(Content::text_for($post_id));
        // the title is embedded with each chunk because it is often the most descriptive text a page has; the stored passage stays the bare chunk since hit() carries the title separately
        $title = wp_specialchars_decode(get_the_title($post_id), ENT_QUOTES);
        $vectors = $chunks ? Provider::embed(array_map(static fn ($chunk) => $title . "\n" . $chunk, $chunks)) : [];
        if ($vectors instanceof \WP_Error) {
            return $vectors;
        }
        if (count($vectors) !== count($chunks)) {
            return new \WP_Error('wsa_embed_shape', __('The embeddings service returned the wrong number of vectors.', 'woocommerce-shop-agent'));
        }
        global $wpdb;
        $table = DB::chunks_table();
        $wpdb->delete($table, ['post_id' => $post_id]);
        // GMT, so the reconcile can compare it with post_modified_gmt
        $now = current_time('mysql', true);
        foreach ($chunks as $i => $chunk) {
            $wpdb->insert($table, [
                'post_id' => $post_id,
                'ord' => $i,
                'content' => $chunk,
                'embedding' => pack('g*', ...$vectors[$i]),
                'updated_at' => $now,
            ], ['%d', '%d', '%s', '%s', '%s']);
        }
        // stamped once, by the first vectors stored; a later mismatch is a rebuild, never an overwrite
        if ($chunks && get_option(self::MODEL) === false) {
            update_option(self::MODEL, self::model_stamp(), false);
        }
        if (get_option(self::BACKOFF)) {
            delete_option(self::BACKOFF);
        }

        return true;
    }

    public static function remove_post(int $post_id): void
    {
        global $wpdb;
        $wpdb->delete(DB::chunks_table(), ['post_id' => $post_id]);
    }

    public static function drop(): void
    {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE ' . DB::chunks_table());
        delete_option(self::QUEUE);
        delete_option(self::BACKOFF);
        delete_option(self::MODEL);
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::RECONCILE_HOOK);
    }

    /**
     * The daily catch-up for anything the hooks missed: allowed posts with no
     * chunks or edited since they were indexed are queued, chunks of posts
     * that are no longer allowed are removed.
     */
    public static function reconcile(): void
    {
        if (! self::enabled() || self::rebuild_on_model_change()) {
            return;
        }
        global $wpdb;
        $table = DB::chunks_table();
        $ids = array_map('intval', get_posts(array_merge(Content::scope_args(), ['posts_per_page' => -1, 'fields' => 'ids'])));
        $indexed = array_column(
            $wpdb->get_results("SELECT post_id, MAX(updated_at) AS updated_at FROM {$table} GROUP BY post_id", ARRAY_A) ?: [],
            'updated_at',
            'post_id',
        );
        // ids first, then the one column the comparison needs: hydrating every post in scope is what a big site cannot afford daily
        $modified = [];
        foreach (array_chunk($ids, 500) as $slice) {
            $placeholders = implode(',', array_fill(0, count($slice), '%d'));
            $modified += array_column(
                $wpdb->get_results($wpdb->prepare("SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE ID IN ({$placeholders})", ...$slice), ARRAY_A) ?: [], // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                'post_modified_gmt',
                'ID',
            );
        }

        $missing = [];
        foreach ($ids as $id) {
            if (! isset($indexed[$id]) || ($modified[$id] ?? '') > $indexed[$id]) {
                $missing[] = $id;
            }
        }
        $stale = array_values(array_diff(array_map('intval', array_keys($indexed)), $ids));
        if ($stale) {
            $placeholders = implode(',', array_fill(0, count($stale), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE post_id IN ({$placeholders})", ...$stale)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        if ($missing) {
            self::push($missing);
        }
    }

    // ------------------------------------------------------------ hooks
    public static function on_save(int $post_id, \WP_Post $post): void
    {
        if (! self::enabled() || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if ($post->post_status === 'auto-draft' || ! in_array($post->post_type, Content::allowed_types(), true)) {
            return;
        }
        Content::is_allowed($post_id) ? self::queue_post($post_id) : self::remove_post($post_id);
    }

    public static function on_status(string $new, string $old, \WP_Post $post): void
    {
        if (self::enabled() && $new !== 'publish' && $old === 'publish') {
            self::remove_post((int) $post->ID);
        }
    }

    /** Scope or mode changed: rebuild, or drop when embeddings were switched off. */
    public static function on_settings(mixed $old, mixed $new): void
    {
        $old = (array) $old;
        $new = (array) $new;
        $scopes = [$old['content_scope'] ?? null, $new['content_scope'] ?? null];
        $changed = ($old['retrieval'] ?? null) !== ($new['retrieval'] ?? null)
            || $scopes[0] !== $scopes[1]
            || self::differ((array) ($old['content_post_types'] ?? []), (array) ($new['content_post_types'] ?? []))
            // the page list only matters while one side of the save is the selected scope
            || (in_array('selected', $scopes, true) && self::differ((array) ($old['content_pages'] ?? []), (array) ($new['content_pages'] ?? [])));
        if (! $changed) {
            return;
        }
        if (($new['retrieval'] ?? '') !== 'embeddings') {
            self::drop();

            return;
        }
        self::drop();
        self::queue_all();
    }

    public static function on_first_save(string $option, mixed $value): void
    {
        self::on_settings(false, $value);
    }

    /** Set comparison: the admin form can reorder a list without changing it. */
    private static function differ(array $a, array $b): bool
    {
        $a = array_map('strval', $a);
        $b = array_map('strval', $b);
        sort($a, SORT_STRING);
        sort($b, SORT_STRING);

        return $a !== $b;
    }

    // ------------------------------------------------------------ search
    public static function search(string $query, int $limit): array
    {
        $vectors = Provider::embed([$query]);
        if ($vectors instanceof \WP_Error || ! $vectors) {
            return Content::search_keyword_fallback($query, $limit);
        }
        $q = $vectors[0];
        $qn = sqrt(array_sum(array_map(static fn ($x) => $x * $x, $q))) ?: 1.0;

        global $wpdb;
        $table = DB::chunks_table();
        $rows = $wpdb->get_results("SELECT id, post_id, embedding FROM {$table}", ARRAY_A);
        $best = [];
        foreach ($rows as $row) {
            // unpack() is 1-based; the query vector is 0-based
            $v = array_values(unpack('g*', $row['embedding']));
            if (count($v) !== count($q)) {
                continue;
            }
            $dot = 0.0;
            $vn = 0.0;
            foreach ($v as $i => $x) {
                $dot += $x * $q[$i];
                $vn += $x * $x;
            }
            $score = $dot / (($vn > 0 ? sqrt($vn) : 1.0) * $qn);
            // best chunk per post only
            $pid = (int) $row['post_id'];
            if (! isset($best[$pid]) || $best[$pid]['score'] < $score) {
                $best[$pid] = ['score' => $score, 'chunk' => (int) $row['id']];
            }
        }
        uasort($best, static fn ($a, $b) => $b['score'] <=> $a['score']);

        // allowed posts only, until the limit is met; then one query for the winning passages
        $winners = [];
        foreach ($best as $pid => $top) {
            if (Content::is_allowed($pid)) {
                $winners[$pid] = $top['chunk'];
            }
            if (count($winners) >= $limit) {
                break;
            }
        }
        if (! $winners) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($winners), '%d'));
        $passages = array_column(
            $wpdb->get_results($wpdb->prepare("SELECT id, content FROM {$table} WHERE id IN ({$placeholders})", ...array_values($winners)), ARRAY_A) ?: [], // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            'content',
            'id',
        );

        $hits = [];
        foreach ($winners as $pid => $chunk_id) {
            $hits[] = Content::hit($pid, (string) ($passages[$chunk_id] ?? ''));
        }

        return $hits;
    }
}
