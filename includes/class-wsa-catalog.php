<?php
/**
 * Catalog access. Everything the agent knows about products comes through
 * here, which is what makes its answers grounded: it can only talk about rows
 * this class actually returned.
 *
 * Search has to work on a store nobody tuned, in a language nobody planned
 * for, so it degrades in stages rather than returning nothing:
 *   1. the phrase as typed (WordPress requires ALL words to match)
 *   2. the most distinctive single word, when the phrase found nothing
 *   3. that word truncated, which absorbs plural and inflected forms in most
 *      languages ("cameras" finds "camera", "מצלמות" finds "מצלמת")
 * SKU matching runs alongside, because WordPress search never looks at it.
 */

namespace WSA;

use WC_Product;
use WP_Query;

if (! defined('ABSPATH')) {
    exit;
}

class Catalog
{
    private const MAX_LIMIT = 10;

    public static function search(string $query, string $category = '', ?int $limit = null, bool $on_sale = false): array
    {
        $query = trim($query);
        // the owner caps how many products one reply may show
        $limit = max(1, min(self::MAX_LIMIT, $limit ?? (int) Settings::get('max_products')));
        if (mb_strlen($query) < 2) {
            return ['results' => [], 'total' => 0];
        }

        foreach (self::query_ladder($query) as $attempt) {
            $found = self::run_search($attempt, $category, $limit, $on_sale);
            if ($found['ids']) {
                return [
                    'results' => self::cards($found['ids']),
                    'total' => $found['total'],
                    // tells the model it is looking at a broadened match, so it
                    // can say "nothing for X, but here is Y" instead of
                    // presenting a loose result as an exact hit
                    'broadened' => $attempt !== $query ? $attempt : null,
                ];
            }
        }

        return ['results' => [], 'total' => 0];
    }

    /** Progressively looser queries: exact phrase, most distinctive word, then that word stemmed. */
    private static function query_ladder(string $query): array
    {
        $ladder = [$query];

        $words = array_values(array_filter(
            preg_split('/[\s,]+/u', $query) ?: [],
            static fn ($w) => mb_strlen($w) > 2,
        ));
        if (count($words) > 1) {
            // Longest first: articles and filler are short in every language
            // this is likely to meet, so length is a decent proxy for how
            // distinctive a word is. Two of them, not one, because a shopper on
            // a Hebrew store may well type "cameras Reolink", where the winning
            // term is the second word, not the first.
            usort($words, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            $ladder[] = $words[0];
            $ladder[] = $words[1];
        }

        $head = $words[0] ?? $query;
        if (mb_strlen($head) >= 5) {
            // WordPress search is a LIKE %term%, so a truncated word still
            // matches the full one: this is stemming without a stemmer
            $ladder[] = mb_substr($head, 0, mb_strlen($head) - 2);
        }

        // Capped at four rungs on purpose. Each rung is an unindexed LIKE over
        // wp_posts, so an unmatchable query on a large catalog must not turn
        // into an open-ended scan loop.
        return array_slice(array_values(array_unique($ladder)), 0, 4);
    }

    private static function run_search(string $query, string $category, int $limit, bool $on_sale): array
    {
        $args = self::base_args($category, $on_sale);

        $by_text = new WP_Query($args + ['s' => $query, 'posts_per_page' => $limit]);
        $ids = wp_list_pluck($by_text->posts, 'ID');
        $total = (int) $by_text->found_posts;

        // WordPress search covers title, excerpt and content, never the SKU
        if (count($ids) < $limit) {
            $by_sku = new WP_Query($args + [
                'posts_per_page' => $limit - count($ids),
                'post__not_in' => $ids ?: [0],
                'meta_query' => [['key' => '_sku', 'value' => $query, 'compare' => 'LIKE']],
            ]);
            $sku_ids = wp_list_pluck($by_sku->posts, 'ID');
            $ids = array_merge($ids, $sku_ids);
            $total += (int) $by_sku->found_posts;
        }

        return ['ids' => $ids, 'total' => max($total, count($ids))];
    }

    private static function base_args(string $category, bool $on_sale): array
    {
        $tax_query = [
            [
                'taxonomy' => 'product_visibility',
                'field' => 'name',
                'terms' => ['exclude-from-search'],
                'operator' => 'NOT IN',
            ],
        ];

        // Categories the owner does not want a bot selling unattended.
        $excluded = (array) Settings::get('excluded_cats');
        if ($excluded) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => array_map('absint', $excluded),
                'operator' => 'NOT IN',
                'include_children' => true,
            ];
        }

        // respect the owner's setting, and the store's own "hide out of stock items"
        if (Settings::get('only_in_stock') || get_option('woocommerce_hide_out_of_stock_items') === 'yes') {
            $tax_query[] = [
                'taxonomy' => 'product_visibility',
                'field' => 'name',
                'terms' => ['outofstock'],
                'operator' => 'NOT IN',
            ];
        }

        if ($category !== '') {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field' => 'slug',
                'terms' => sanitize_title($category),
            ];
        }

        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'ignore_sticky_posts' => true,
            'tax_query' => $tax_query,
        ];

        if ($on_sale) {
            // constrain to WooCommerce's own on-sale set; [0] when nothing is on
            // sale forces an empty result, so the agent reports "none on sale"
            // instead of presenting full-price products as deals
            $args['post__in'] = wc_get_product_ids_on_sale() ?: [0];
        }

        return $args;
    }

    /** @return array<int, array> card payloads, in the order given */
    public static function cards(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        // one priming pass instead of a query storm per product
        _prime_post_caches(array_map('intval', $ids), false, true);

        $cards = [];
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if ($product instanceof WC_Product) {
                $cards[] = self::card($product);
            }
        }

        return $cards;
    }

    public static function card(WC_Product $product): array
    {
        $image_id = $product->get_image_id();

        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'url' => (string) get_permalink($product->get_id()),
            'image' => $image_id
                ? (string) wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail')
                : (string) wc_placeholder_img_src(),
            // known-safe WooCommerce markup (handles sale strikethrough, ranges,
            // tax suffixes); the widget inserts it as HTML deliberately
            'price_html' => $product->get_price_html(),
            'sku' => (string) $product->get_sku(),
            'in_stock' => $product->is_in_stock(),
            'on_sale' => $product->is_on_sale(),
            'type' => $product->get_type(),
            // only a simple, purchasable, in-stock product can go straight to
            // the cart; anything with options must be configured on its page
            'can_add' => $product->is_purchasable() && $product->is_in_stock() && ! $product->is_type('variable'),
            'add_url' => (string) $product->add_to_cart_url(),
        ];
    }

    /** Top-level product categories, for orientation when a search comes up empty. */
    public static function categories(): array
    {
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => true,
            'parent' => 0,
            'number' => 40,
        ]);

        if (is_wp_error($terms)) {
            return [];
        }

        return array_map(static fn ($t) => [
            'name' => $t->name,
            'slug' => $t->slug,
            'count' => (int) $t->count,
        ], $terms);
    }

    public static function product_details(int $id): ?array
    {
        $product = wc_get_product($id);
        if (! $product instanceof WC_Product || $product->get_status() !== 'publish') {
            return null;
        }

        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'price' => wp_strip_all_tags($product->get_price_html()),
            'in_stock' => $product->is_in_stock(),
            'stock_status' => $product->get_stock_status(),
            'sku' => (string) $product->get_sku(),
            'on_sale' => $product->is_on_sale(),
            'categories' => wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']) ?: [],
            'attributes' => self::attributes($product),
            'short_description' => mb_substr(wp_strip_all_tags($product->get_short_description()), 0, 400),
            'description' => mb_substr(wp_strip_all_tags($product->get_description()), 0, 800),
        ];
    }

    /**
     * Product attributes as "Label: value" lines. This is the portable
     * equivalent of a theme's custom spec fields: every WooCommerce store has
     * attributes, no store has the same custom fields.
     */
    private static function attributes(WC_Product $product): array
    {
        $out = [];
        foreach ($product->get_attributes() as $attribute) {
            if (! $attribute->get_visible()) {
                continue;
            }
            $name = wc_attribute_label($attribute->get_name());
            $values = $attribute->is_taxonomy()
                ? wp_get_post_terms($product->get_id(), $attribute->get_name(), ['fields' => 'names'])
                : $attribute->get_options();
            if (is_wp_error($values) || ! $values) {
                continue;
            }
            $out[] = $name . ': ' . implode(', ', array_map('wp_strip_all_tags', (array) $values));
        }

        return array_slice($out, 0, 15);
    }
}
