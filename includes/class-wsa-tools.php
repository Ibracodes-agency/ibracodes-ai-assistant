<?php
/**
 * The tools the model may call, and their dispatch.
 *
 * Tool results are DATA, never instructions. Product titles and descriptions
 * are written by whoever runs the store (and sometimes by a supplier feed), so
 * they are exactly the kind of text a prompt-injection attempt hides in. The
 * system prompt says so explicitly and nothing here is ever executed.
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

class Tools
{
    /**
     * Schemas are described in English because that is what the model reasons
     * over. The instruction that matters for a non-English site is that
     * SEARCH TERMS must be in the site's own language, since the content is.
     *
     * The content tools are always offered; the product tools only where
     * WooCommerce is, and the lead and handoff tools only when the owner
     * switched them on.
     */
    public static function definitions(): array
    {
        $language = Prompt::store_language();

        $tools = [];
        $tools[] = ['type' => 'function', 'function' => [
            'name' => 'search_content',
            'description' => sprintf('Search this website\'s own pages and posts for passages that answer the question. You MUST call this before stating anything about the site, its services, policies, prices, hours or people, unless the answer is in the store facts or on the current page given to you. Search in %s. Results are passages with the page title and URL; quote or paraphrase only what they contain.', $language),
            'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'What to look for, in the site language.']], 'required' => ['query']],
        ]];
        $tools[] = ['type' => 'function', 'function' => [
            'name' => 'get_page',
            'description' => 'The full text of one page by the id returned from search_content, for a follow-up question the passage did not cover.',
            'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']], 'required' => ['id']],
        ]];

        if (Capabilities::has_commerce()) {
            $tools[] = ['type' => 'function', 'function' => [
                'name' => 'search_products',
                'description' => sprintf(
                    'Search the real product catalog by keywords, product name or SKU. You MUST call this before recommending or mentioning any product. The catalog is written in %1$s, so always search in %1$s (model numbers and SKUs may be latin). If there are no results, try once more with a broader, more general word before concluding the store does not carry it. Set on_sale=true when the customer asks about deals, discounts or sale items; every result carries an on_sale flag.',
                    $language,
                ),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Search terms in the store language, or a model number / SKU.'],
                        'category' => ['type' => 'string', 'description' => 'Optional product category slug from get_categories, to narrow the search.'],
                        'on_sale' => ['type' => 'boolean', 'description' => 'Return only products currently on sale.'],
                    ],
                    'required' => ['query'],
                ],
            ]];
            $tools[] = ['type' => 'function', 'function' => [
                'name' => 'get_categories',
                'description' => 'List the store\'s top-level product categories with how many products each holds. Useful when a search finds nothing and you want to offer the customer a real alternative.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]];
            $tools[] = ['type' => 'function', 'function' => [
                'name' => 'get_product_details',
                'description' => 'Full details for one product by the id returned from search_products: price, stock, SKU, attributes and description. Use it when the customer asks something specific about a product you already found.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer', 'description' => 'Product id from a search_products result.']],
                    'required' => ['id'],
                ],
            ]];
        }

        if (Settings::get('leads_enabled')) {
            $tools[] = ['type' => 'function', 'function' => [
                'name' => 'capture_lead',
                'description' => 'Save the visitor\'s details so the site owner can get back to them. Only call it after the visitor agreed and gave a name and a phone number or email. If the result is an error, ask again for the missing or invalid detail; never claim it was saved.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string'],
                    'contact' => ['type' => 'string', 'description' => 'A phone number or an email address, exactly as the visitor wrote it.'],
                    'request' => ['type' => 'string', 'description' => 'What the visitor wants, in one or two sentences, in the site language.'],
                ], 'required' => ['name', 'contact', 'request']],
            ]];
        }

        // With live chat on, hand_off asks a person to join, whether or not
        // the owner also set a contact destination. Without it the button the
        // model may point to only exists when the owner set a destination;
        // without one, the prompt says there is nothing to click and the tool
        // is not offered at all.
        if (Settings::live_ready()) {
            $tools[] = ['type' => 'function', 'function' => [
                'name' => 'hand_off',
                'description' => 'Hand the conversation to a person. Call it when the visitor asks to talk to someone, or when you cannot help. A person will join this chat shortly; tell the visitor that in one short sentence and stop answering. Do not mention a button.',
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]];
        } elseif (Prompt::handoff_label() !== '') {
            $tools[] = ['type' => 'function', 'function' => [
                'name' => 'hand_off',
                'description' => sprintf(
                    'Show the customer a button that opens "%s". Call it when the customer asks to talk to a person, or when you cannot help with the tools you have. After calling it, tell the customer in one short sentence to use that button below your answer. Never mention a button without calling this.',
                    Prompt::handoff_label(),
                ),
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
            ]];
        }

        return $tools;
    }

    /**
     * Runs one tool call. Cards found along the way are collected by reference:
     * the widget renders them below the answer, so the model never has to (and
     * is told never to) paste links or prices into its text.
     *
     * @param array<int, array> $cards collected product cards, keyed by id
     * @param array{page_id?: int, thread_id?: int, live?: string} $context the verified thread and the page being read, for the lead and the live request
     */
    public static function run(string $name, array $input, array &$cards, array $context = []): array
    {
        return match ($name) {
            // page text reaches the model fenced, the way the prompt says data arrives
            'search_content' => ['results' => array_map(
                static fn (array $hit) => array_merge($hit, ['passage' => Prompt::delimit($hit['passage'])]),
                Content::search((string) ($input['query'] ?? '')),
            )],
            'get_page' => self::page((int) ($input['id'] ?? 0)),
            // the setting is checked again here: a tool the model was not offered must stay uncallable
            'capture_lead' => Settings::get('leads_enabled')
                ? Leads::capture($input, (int) ($context['thread_id'] ?? 0), (int) ($context['page_id'] ?? 0))
                : ['error' => 'unknown_tool'],
            'search_products' => self::search($input, $cards),
            'hand_off' => self::hand_off($context),
            'get_categories' => ['categories' => Catalog::categories()],
            'get_product_details' => Catalog::product_details((int) ($input['id'] ?? 0))
                ?? ['error' => 'not_found'],
            default => ['error' => 'unknown_tool'],
        };
    }

    /**
     * With live chat on, asks a person to join the verified thread. On the
     * first turn there is no thread yet, so the request is left pending for
     * the REST layer to make once the turn is recorded. Without live chat the
     * result is the contact button.
     */
    private static function hand_off(array $context): array
    {
        if (! Settings::live_ready()) {
            return ['shown' => true, 'label' => Prompt::handoff_label()];
        }
        $thread_id = (int) ($context['thread_id'] ?? 0);
        if ($thread_id === 0) {
            return ['live' => 'pending'];
        }
        Live::request($thread_id, (int) ($context['page_id'] ?? 0));

        return ['live' => 'waiting'];
    }

    /** One page's text, through the same gate as search: an id the model guessed at gets nothing. */
    private static function page(int $id): array
    {
        if (! Content::is_allowed($id)) {
            return ['error' => 'not_found'];
        }

        return [
            'id' => $id,
            'title' => wp_specialchars_decode(get_the_title($id), ENT_QUOTES),
            'url' => get_permalink($id),
            'text' => Prompt::delimit(mb_substr(Content::text_for($id), 0, 6000)),
        ];
    }

    private static function search(array $input, array &$cards): array
    {
        $found = Catalog::search(
            (string) ($input['query'] ?? ''),
            (string) ($input['category'] ?? ''),
            null,
            ! empty($input['on_sale']),
        );

        foreach ($found['results'] as $card) {
            $cards[$card['id']] = $card;
        }

        // The model gets facts, not markup: no image URLs, no price HTML, no
        // product links. Those live on the card the customer sees.
        return [
            'results' => array_map(static fn ($c) => [
                'id' => $c['id'],
                'name' => $c['name'],
                'price' => wp_strip_all_tags($c['price_html']),
                'in_stock' => $c['in_stock'],
                'on_sale' => $c['on_sale'],
                'sku' => $c['sku'],
                'needs_options' => $c['type'] === 'variable',
            ], $found['results']),
            'total' => $found['total'],
            'broadened_to' => $found['broadened'] ?? null,
        ];
    }
}
