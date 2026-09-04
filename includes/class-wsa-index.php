<?php
/**
 * The embeddings index: chunks of allowed posts with their vectors, kept fresh
 * by post hooks and a cron batch. Ranking is cosine similarity in PHP, which
 * is fine up to a few thousand chunks; the design says revisit only past that.
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

class Index
{
    public const HOOK = 'wsa_index_batch';

    private const QUEUE = 'wsa_index_queue';

    private const BATCH_POSTS = 20;

    public static function boot(): void
    {
        add_action(self::HOOK, [self::class, 'process_batch']);
        add_action('save_post', [self::class, 'on_save'], 20, 2);
        add_action('deleted_post', [self::class, 'remove_post']);
        add_action('trashed_post', [self::class, 'remove_post']);
        add_action('transition_post_status', [self::class, 'on_status'], 10, 3);
        add_action('update_option_wsa_settings', [self::class, 'on_settings'], 10, 2);
    }

    // ------------------------------------------------------------ state
    public static function enabled(): bool
    {
        return Settings::get('retrieval') === 'embeddings';
    }

    public static function ready(): bool
    {
        global $wpdb;

        return self::enabled() && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . DB::chunks_table()) > 0;
    }

    public static function status(): array
    {
        global $wpdb;
        $table = DB::chunks_table();

        return [
            'pending' => count((array) get_option(self::QUEUE, [])),
            'posts' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$table}"),
            'chunks' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'total' => count(get_posts(array_merge(Content::scope_args(), ['posts_per_page' => -1, 'fields' => 'ids']))),
        ];
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
        $queue = (array) get_option(self::QUEUE, []);
        $queue[] = $post_id;
        update_option(self::QUEUE, array_values(array_unique(array_map('intval', $queue))), false);
        self::schedule();
    }

    private static function schedule(): void
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + 5, self::HOOK);
        }
    }

    /** Embeds up to BATCH_POSTS queued posts; reschedules itself while work remains. */
    public static function process_batch(): void
    {
        if (! self::enabled()) {
            return;
        }
        $queue = array_map('intval', (array) get_option(self::QUEUE, []));
        $batch = array_splice($queue, 0, self::BATCH_POSTS);
        update_option(self::QUEUE, $queue, false);

        foreach ($batch as $post_id) {
            if (! Content::is_allowed($post_id)) {
                self::remove_post($post_id);

                continue;
            }
            if (self::index_post($post_id) instanceof \WP_Error) {
                // put it back and stop: the key or the cap is the problem, not the post
                update_option(self::QUEUE, array_values(array_unique(array_merge([$post_id], $queue))), false);

                break;
            }
        }
        if (get_option(self::QUEUE, [])) {
            self::schedule();
        }
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
        global $wpdb;
        $table = DB::chunks_table();
        $wpdb->delete($table, ['post_id' => $post_id]);
        $now = current_time('mysql');
        foreach ($chunks as $i => $chunk) {
            $wpdb->insert($table, [
                'post_id' => $post_id,
                'ord' => $i,
                'content' => $chunk,
                'embedding' => pack('g*', ...$vectors[$i]),
                'updated_at' => $now,
            ], ['%d', '%d', '%s', '%s', '%s']);
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
        wp_clear_scheduled_hook(self::HOOK);
    }

    // ------------------------------------------------------------ hooks
    public static function on_save(int $post_id, \WP_Post $post): void
    {
        if (! self::enabled() || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
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
        $keys = ['retrieval', 'content_scope', 'content_pages', 'content_post_types'];
        $changed = array_filter($keys, static fn ($k) => ($old[$k] ?? null) !== ($new[$k] ?? null));
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
        $rows = $wpdb->get_results('SELECT post_id, content, embedding FROM ' . DB::chunks_table(), ARRAY_A);
        $scored = [];
        foreach ($rows as $row) {
            // unpack() is 1-based; the query vector is 0-based
            $v = array_values(unpack('g*', $row['embedding']));
            $dot = 0.0;
            $vn = 0.0;
            foreach ($v as $i => $x) {
                $dot += $x * $q[$i];
                $vn += $x * $x;
            }
            $score = $dot / (($vn > 0 ? sqrt($vn) : 1.0) * $qn);
            // best chunk per post only
            $pid = (int) $row['post_id'];
            if (! isset($scored[$pid]) || $scored[$pid]['score'] < $score) {
                $scored[$pid] = ['score' => $score, 'passage' => $row['content']];
            }
        }
        uasort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);

        $hits = [];
        foreach (array_slice($scored, 0, $limit, true) as $pid => $best) {
            if (Content::is_allowed($pid)) {
                $hits[] = Content::hit($pid, $best['passage']);
            }
        }

        return $hits;
    }
}
