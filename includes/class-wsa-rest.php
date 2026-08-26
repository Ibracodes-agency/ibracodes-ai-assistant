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
            ],
        ]);

        // admin-only: verifies a key before it is trusted with customer traffic
        register_rest_route(self::NS, '/test-key', [
            'methods' => 'POST',
            'permission_callback' => static fn () => current_user_can('manage_woocommerce'),
            'callback' => [self::class, 'test_key'],
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
            $answer = Agent::answer((array) $request['messages']);
            if ($answer instanceof WP_Error) {
                return $answer;
            }

            return rest_ensure_response($answer);
        } finally {
            // runs even when the agent throws, so a fatal cannot leak a
            // concurrency slot for the length of its TTL
            Guards::release();
        }
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
