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

    /** Plain text of one post: blocks and shortcodes rendered, markup and scripts stripped. */
    public static function text_for(int $post_id): string
    {
        $post = get_post($post_id);
        if (! $post) {
            return '';
        }
        // the_content filters from other plugins may echo or enqueue; render
        // only what WordPress core does for blocks and shortcodes
        $html = do_shortcode(do_blocks((string) $post->post_content));
        $html = preg_replace('#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;

        return mb_substr(trim($text), 0, self::MAX_CHARS);
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

    /** Published, public, unprotected, and inside the owner's scope. */
    public static function is_allowed(int $post_id): bool
    {
        $post = get_post($post_id);
        if (! $post || $post->post_status !== 'publish' || $post->post_password !== '') {
            return false;
        }
        if (! in_array($post->post_type, self::allowed_types(), true)) {
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
        $args = [
            'post_type' => self::allowed_types(),
            'post_status' => 'publish',
            'has_password' => false,
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        ];
        if (Settings::get('content_scope') === 'selected') {
            $args['post__in'] = array_map('intval', (array) Settings::get('content_pages')) ?: [0];
        }

        return $args;
    }
}
