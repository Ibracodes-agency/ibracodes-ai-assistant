<?php
/**
 * The OpenAI call. Isolated behind one method so the rest of the plugin never
 * touches HTTP, and so a second provider later is a new class rather than a
 * rewrite of the agent. The embeddings call for the content index lives here
 * too, behind the same key and the same spend guards.
 */

namespace WSA;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class Provider
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /** Seconds one upstream call may take; the index sizes its batch lock by it. */
    public const TIMEOUT = 25;

    private const MAX_TOKENS = 700;

    private const EMBED_ENDPOINT = 'https://api.openai.com/v1/embeddings';

    public const EMBED_MODEL = 'text-embedding-3-small';

    public const EMBED_DIMS = 512;

    /**
     * One completion. Returns the raw assistant message (which may be a tool
     * call rather than text), or a WP_Error the REST layer can hand back.
     */
    public static function complete(array $messages, array $tools): array|WP_Error
    {
        // tests hand a message back here, so nothing touches the network or the budget
        $pre = apply_filters('wsa_pre_complete', null, $messages, $tools);
        if (is_array($pre) || $pre instanceof WP_Error) {
            return $pre;
        }

        $key = Settings::api_key();
        if ($key === '') {
            return new WP_Error('wsa_no_key', __('The chat is not configured.', 'woocommerce-shop-agent'), ['status' => 503]);
        }

        $charged = Guards::charge_upstream_call();
        if ($charged instanceof WP_Error) {
            return $charged;
        }

        $model = (string) Settings::get('model');
        $body = [
            'model' => $model,
            'messages' => $messages,
            'tools' => $tools,
            'max_completion_tokens' => self::MAX_TOKENS,
        ];
        // gpt-5 family: skip the reasoning pass. A shop answer needs latency,
        // not depth, and reasoning tokens are billed.
        if (str_starts_with($model, 'gpt-5')) {
            $body['reasoning_effort'] = 'minimal';
        }

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
        ]);

        if (is_wp_error($response)) {
            self::log('transport: ' . $response->get_error_message());

            return self::unavailable();
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($data['choices'][0]['message'])) {
            // the upstream error body names the real problem (bad key, quota,
            // unknown model) and the owner needs it, but it never reaches the
            // customer
            self::log(sprintf('http %d: %s', $code, substr((string) wp_remote_retrieve_body($response), 0, 300)));
            self::remember_failure($code, $data);

            return self::unavailable();
        }

        self::clear_failure();

        return (array) $data['choices'][0]['message'];
    }

    /**
     * Embeds up to a batch of texts. One upstream call per batch, charged
     * against the same guards as a chat completion so a huge site cannot
     * blow the monthly cap building its index.
     *
     * @return array<int, array<int, float>>|WP_Error
     */
    public static function embed(array $texts): array|WP_Error
    {
        $texts = array_values(array_filter(array_map('strval', $texts), static fn ($t) => trim($t) !== ''));
        if (! $texts) {
            return [];
        }
        // tests and the index tests hand vectors back here, so nothing touches the network
        $pre = apply_filters('wsa_pre_embed', null, $texts);
        if (is_array($pre) || $pre instanceof WP_Error) {
            return $pre;
        }

        $key = Settings::api_key();
        if ($key === '') {
            return new WP_Error('wsa_no_key', __('The chat is not configured.', 'woocommerce-shop-agent'), ['status' => 503]);
        }

        $charged = Guards::charge_upstream_call();
        if ($charged instanceof WP_Error) {
            return $charged;
        }

        $response = wp_remote_post(self::EMBED_ENDPOINT, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => self::EMBED_MODEL,
                'input' => $texts,
                'dimensions' => self::EMBED_DIMS,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if (is_wp_error($response)) {
            self::log('embed transport: ' . $response->get_error_message());

            return self::unavailable();
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($data['data']) || ! is_array($data['data'])) {
            self::log(sprintf('embed http %d: %s', $code, substr((string) wp_remote_retrieve_body($response), 0, 300)));
            self::remember_failure($code, $data);

            return self::unavailable();
        }

        self::clear_failure();

        // the API may return rows out of order; index says which input each belongs to
        $vectors = [];
        foreach ($data['data'] as $row) {
            $vectors[(int) $row['index']] = array_map('floatval', (array) $row['embedding']);
        }
        ksort($vectors);

        return array_values($vectors);
    }

    /**
     * Verifies a key with the cheapest possible round trip. Used by the Test
     * connection button so the owner finds out at setup, not from a customer.
     */
    public static function test_key(string $key, string $model): bool|WP_Error
    {
        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $model,
                'messages' => [['role' => 'user', 'content' => 'ping']],
                'max_completion_tokens' => 1,
            ]),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('wsa_test_failed', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            return true;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $message = (string) ($data['error']['message'] ?? '');

        return new WP_Error('wsa_test_failed', match (true) {
            $code === 401 => __('OpenAI rejected that key.', 'woocommerce-shop-agent'),
            $code === 429 => __('The key works, but the account is out of quota or rate limited.', 'woocommerce-shop-agent'),
            $code === 404 => __('The key works, but this account cannot use the selected model.', 'woocommerce-shop-agent'),
            default => $message !== '' ? $message : sprintf(
                /* translators: %d: HTTP status code */
                __('OpenAI returned an error (HTTP %d).', 'woocommerce-shop-agent'),
                $code,
            ),
        });
    }

    /** The last upstream failure, surfaced in the admin so a dead key is visible. */
    public static function last_failure(): ?array
    {
        $failure = get_option('wsa_last_failure');

        return is_array($failure) ? $failure : null;
    }

    private static function remember_failure(int $code, mixed $data): void
    {
        update_option('wsa_last_failure', [
            'code' => $code,
            'message' => (string) (is_array($data) ? ($data['error']['message'] ?? '') : ''),
            'at' => current_time('mysql'),
        ], false);
    }

    private static function clear_failure(): void
    {
        if (get_option('wsa_last_failure') !== false) {
            delete_option('wsa_last_failure');
        }
    }

    private static function unavailable(): WP_Error
    {
        return new WP_Error(
            'wsa_upstream',
            __('The chat is unavailable right now. Please try again.', 'woocommerce-shop-agent'),
            ['status' => 502],
        );
    }

    private static function log(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[shop-agent] ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
}
