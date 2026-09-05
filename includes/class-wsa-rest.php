<?php
/**
 * The one public endpoint.
 *
 * Public by necessity: shop visitors are not logged in. That is exactly why
 * every guard in Guards runs before a single upstream call, and why the API
 * key never leaves the server.
 */

namespace WSA;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

class Rest
{
    private const NS = 'wsa/v1';

    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NS, '/chat', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'chat'],
            'args' => [
                'messages' => [
                    'required' => true,
                    'type' => 'array',
                    'validate_callback' => static fn ($value) => is_array($value),
                ],
                // the post the visitor is reading; the prompt only uses it when the page is public and in scope
                'page' => ['type' => 'integer', 'required' => false],
                'thread' => ['type' => 'string', 'required' => false],
            ],
        ]);

        // The single conversion signal: the customer actually added something the
        // agent recommended. Public because the shopper is not logged in; it can
        // only ever set one boolean on a thread whose token the caller already
        // holds, so there is nothing to gain by forging it.
        register_rest_route(self::NS, '/cart-event', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'cart_event'],
        ]);

        // admin-only: verifies a key before it is trusted with customer traffic
        register_rest_route(self::NS, '/test-key', [
            'methods' => 'POST',
            'permission_callback' => static fn () => current_user_can(Capabilities::admin_cap()),
            'callback' => [self::class, 'test_key'],
        ]);

        // admin-only: throws the embeddings index away and queues every page in scope again
        register_rest_route(self::NS, '/rebuild-index', [
            'methods' => 'POST',
            'permission_callback' => static fn () => current_user_can(Capabilities::admin_cap()),
            'callback' => [self::class, 'rebuild_index'],
        ]);
    }

    public static function chat(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! Settings::ready()) {
            return new WP_Error('wsa_off', __('The chat is not available right now.', 'woocommerce-shop-agent'), ['status' => 503]);
        }

        $gate = Guards::check_and_acquire();
        if ($gate instanceof WP_Error) {
            return $gate;
        }

        try {
            $messages = (array) $request['messages'];
            $context = [
                'page_id' => absint($request->get_param('page')),
                'thread_id' => Threads::id_from_token(sanitize_text_field((string) $request->get_param('thread'))),
            ];
            $answer = Agent::answer($messages, $context);
            if ($answer instanceof WP_Error) {
                return $answer;
            }

            $last = end($messages);
            $answer['thread'] = Threads::record(
                sanitize_text_field((string) $request->get_param('thread')),
                (string) ($last['text'] ?? ''),
                $answer,
                sanitize_key((string) $request->get_param('device')),
            );
            unset($answer['no_match']); // server-side signal, not the customer's business

            return rest_ensure_response($answer);
        } finally {
            // runs even when the agent throws, so a fatal cannot leak a
            // concurrency slot for the length of its TTL
            Guards::release();
        }
    }

    public static function cart_event(WP_REST_Request $request): WP_REST_Response
    {
        if (Settings::get('log_threads')) {
            $thread_id = Threads::id_from_token(sanitize_text_field((string) $request->get_param('thread')));
            if ($thread_id > 0) {
                DB::mark_added_to_cart($thread_id);
            }
        }

        return rest_ensure_response(['ok' => true]);
    }

    public static function rebuild_index(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! Index::enabled()) {
            return new WP_Error('wsa_index_off', __('Switch retrieval to the embeddings index first.', 'woocommerce-shop-agent'), ['status' => 400]);
        }
        Index::drop();
        Index::queue_all();
        // one batch right away, so the owner sees progress even where WP-Cron is slow
        Index::process_batch();
        $pending = (int) Index::status()['pending'];

        return rest_ensure_response([
            'ok' => true,
            'pending' => $pending,
            'message' => sprintf(
                /* translators: %s: number of pages waiting to be indexed */
                __('Rebuilding, %s pages queued.', 'woocommerce-shop-agent'),
                number_format_i18n($pending),
            ),
        ]);
    }

    public static function test_key(WP_REST_Request $request): WP_REST_Response
    {
        // an unchanged (masked) field means "test what is already saved"
        $submitted = trim((string) $request->get_param('key'));
        $key = ($submitted === '' || str_contains($submitted, '•')) ? Settings::api_key() : $submitted;
        $model = (string) ($request->get_param('model') ?: Settings::get('model'));

        if ($key === '') {
            return rest_ensure_response([
                'ok' => false,
                'message' => __('Add a key first.', 'woocommerce-shop-agent'),
            ]);
        }

        $result = Provider::test_key($key, array_key_exists($model, Settings::models()) ? $model : (string) Settings::get('model'));

        return rest_ensure_response($result instanceof WP_Error
            ? ['ok' => false, 'message' => $result->get_error_message()]
            : ['ok' => true, 'message' => __('Connected. The key works and the model is available.', 'woocommerce-shop-agent')]);
    }
}
