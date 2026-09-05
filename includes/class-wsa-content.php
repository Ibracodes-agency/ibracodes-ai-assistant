<?php
/**
 * Site content as the assistant sees it: plain text, chunked, scoped.
 *
 * Content is DATA. Pages are written by the owner and sometimes by page
 * builders and plugins; the prompt tells the model to ignore instructions
 * found inside them, and nothing here is ever executed.
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

class Content
{
    /** Longest text taken from one post; page builders can produce megabytes of markup. */
    private const MAX_CHARS = 40000;

    public const CHUNK_WORDS = 300;

    public const CHUNK_OVERLAP = 40;

    /** Pages one search returns; five passages is what fits a reply without drowning the model. */
    public const MAX_HITS = 5;

    /**
     * Plain text of one post: blocks and shortcodes rendered, markup and
     * scripts stripped. Does not gate access; callers must check is_allowed().
     */
    public static function text_for(int $post_id): string
    {
        $post = get_post($post_id);
        if (! $post) {
            return '';
        }
        // the_content filters from other plugins may echo or enqueue; render
        // only what WordPress core does for blocks and shortcodes, inside a
        // buffer so a shortcode that echoes cannot corrupt the REST JSON
        ob_start();
        try {
            $html = do_shortcode(do_blocks((string) $post->post_content));
        } finally {
            ob_end_clean();
        }
        $text = self::plain($html);
        // a hand-written excerpt is often the clearest summary the page has
        $excerpt = self::plain((string) $post->post_excerpt);
        if ($excerpt !== '') {
            $text = $excerpt . "\n" . $text;
        }
        // page builders and ACF sites store content outside post_content and
        // can supply text here
        $text = (string) apply_filters('wsa_content_text', $text, $post);

        return self::fence_safe(mb_substr(trim($text), 0, self::MAX_CHARS));
    }

    /**
     * A line that is exactly a fence marker would end the fence the prompt
     * puts around page text early; a space inside it (">> >") keeps it plain
     * text. Runs last, so text added through the wsa_content_text filter is
     * covered as well as the rendered content.
     */
    private static function fence_safe(string $text): string
    {
        $markers = preg_quote(Prompt::PAGE_OPEN, '/') . '|' . preg_quote(Prompt::PAGE_CLOSE, '/');

        return preg_replace_callback(
            '/^[ \t]*(' . $markers . ')[ \t\r]*$/mu',
            static fn (array $m) => str_replace($m[1], mb_substr($m[1], 0, -1) . ' ' . mb_substr($m[1], -1), $m[0]),
            $text,
        ) ?? $text;
    }

    /** Markup and script bodies stripped, entities decoded, whitespace collapsed to single spaces and newlines. */
    private static function plain(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // a chips line written into a page would otherwise become buttons under the answer
        $text = preg_replace(Prompt::CHIPS_PATTERN, ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;

        return trim($text);
    }

    /** Word-window chunks with overlap, so an answer spanning a boundary is still found. */
    public static function chunk(string $text, int $words = self::CHUNK_WORDS, int $overlap = self::CHUNK_OVERLAP): array
    {
        $all = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (! $all) {
            return [];
        }
        $chunks = [];
        $step = max(1, $words - $overlap);
        for ($i = 0; $i < count($all); $i += $step) {
            $chunks[] = implode(' ', array_slice($all, $i, $words));
            if ($i + $words >= count($all)) {
                break;
            }
        }

        return $chunks;
    }

    /** Post types the owner allowed, intersected with what exists on this site. */
    public static function allowed_types(): array
    {
        return array_values(array_intersect((array) Settings::get('content_post_types'), Settings::indexable_post_types()));
    }

    /**
     * Ids kept out of the assistant whatever the scope says: WooCommerce adds
     * its cart, checkout and account pages, a site can add its own.
     */
    public static function excluded_ids(): array
    {
        return array_values(array_unique(array_map('intval', (array) apply_filters('wsa_content_excluded_ids', []))));
    }

    /** Published, public, unprotected, not excluded, and inside the owner's scope. */
    public static function is_allowed(int $post_id): bool
    {
        $post = get_post($post_id);
        if (! $post || $post->post_status !== 'publish' || $post->post_password !== '') {
            return false;
        }
        if (! in_array($post->post_type, self::allowed_types(), true)) {
            return false;
        }
        if (in_array($post_id, self::excluded_ids(), true)) {
            return false;
        }
        if (Settings::get('content_scope') === 'selected') {
            return in_array($post_id, array_map('intval', (array) Settings::get('content_pages')), true);
        }

        return true;
    }

    /** WP_Query arguments that express the scope; shared by search and the indexer. */
    public static function scope_args(): array
    {
        $types = self::allowed_types();
        $excluded = self::excluded_ids();
        $args = [
            'post_type' => $types,
            'post_status' => 'publish',
            'has_password' => false,
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        ];
        if (! $types) {
            // an empty post_type makes WP_Query fall back to posts; close the scope instead
            $args['post__in'] = [0];
        } elseif (Settings::get('content_scope') === 'selected') {
            // WP_Query ignores post__not_in once post__in is set, so subtract here
            $pages = array_map('intval', (array) Settings::get('content_pages'));
            $args['post__in'] = array_values(array_diff($pages, $excluded)) ?: [0];
        } elseif ($excluded) {
            $args['post__not_in'] = $excluded;
        }

        return $args;
    }

    /**
     * One entry point for the model's search_content tool. The backend is the
     * owner's choice; the shape of the result never changes.
     */
    public static function search(string $query, int $limit = self::MAX_HITS): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        if (Settings::get('retrieval') === 'embeddings' && Index::ready()) {
            return Index::search($query, $limit);
        }

        return self::search_keyword_fallback($query, $limit);
    }

    /** WordPress search; public because the index falls back here when the embeddings call fails. */
    public static function search_keyword_fallback(string $query, int $limit): array
    {
        // get_posts() suppresses the posts_* SQL filters, so search plugins and
        // language filters are bypassed on purpose: results stay predictable on
        // any site. A site that wants them can opt in through wsa_search_args.
        $args = apply_filters('wsa_search_args', array_merge(self::scope_args(), [
            's' => $query,
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'orderby' => 'relevance',
        ]), $query);
        $ids = get_posts($args);

        $hits = [];
        foreach ($ids as $id) {
            $hits[] = self::hit((int) $id, self::passage(self::text_for((int) $id), $query));
        }

        return $hits;
    }

    /** The chunk holding the most query terms; the first chunk when none does. */
    public static function passage(string $text, string $query): string
    {
        $chunks = self::chunk($text);
        if (! $chunks) {
            return '';
        }
        // split on anything that is not a letter or digit, so "shipping?" still matches "shipping"
        $terms = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [], static fn ($t) => mb_strlen($t) > 1);
        $best = 0;
        $best_score = -1;
        foreach ($chunks as $i => $chunk) {
            $hay = mb_strtolower($chunk);
            $score = 0;
            foreach ($terms as $term) {
                $score += substr_count($hay, $term);
            }
            if ($score > $best_score) {
                [$best, $best_score] = [$i, $score];
            }
        }

        return $chunks[$best];
    }

    /** One search result; public because the embeddings index builds the same shape. */
    public static function hit(int $id, string $passage): array
    {
        return [
            'id' => $id,
            'title' => wp_specialchars_decode(get_the_title($id), ENT_QUOTES),
            'url' => get_permalink($id),
            'passage' => $passage,
        ];
    }
}
