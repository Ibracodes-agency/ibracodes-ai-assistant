<?php
/**
 * The REST surface.
 *
 * The visitor routes are public by necessity: shop visitors are not logged
 * in. That is exactly why every guard in Guards runs before a single upstream
 * call, why the API key never leaves the server, and why the live-chat routes
 * trust nothing but the signed thread token. The manager routes sit behind the
 * admin capability, with the REST nonce the admin script already sends.
 */

namespace Ibracodes\AI_Assistant;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (! defined('ABSPATH')) {
    exit;
}

class Rest
{
    private const NS = 'ibraai/v1';

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

        // Live chat, visitor side. Public like /chat: the signed thread token is
        // the credential, and each route verifies it before touching a row.
        register_rest_route(self::NS, '/live/thread', [
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'live_visitor_poll'],
            'args' => [
                'thread' => ['type' => 'string', 'required' => true],
                'since' => ['type' => 'integer', 'required' => false, 'default' => 0],
            ],
        ]);
        register_rest_route(self::NS, '/live/thread/message', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [self::class, 'live_visitor_message'],
            'args' => [
                'thread' => ['type' => 'string', 'required' => true],
                'text' => ['type' => 'string', 'required' => true],
            ],
        ]);

        // Live chat, manager side: the admin capability, cookie auth with the REST nonce
        $manager = static fn () => current_user_can(Capabilities::admin_cap());
        $by_id = ['id' => ['type' => 'integer', 'required' => true]];
        register_rest_route(self::NS, '/live/open', [
            'methods' => 'GET',
            'permission_callback' => $manager,
            'callback' => [self::class, 'live_open'],
        ]);
        register_rest_route(self::NS, '/live/poll', [
            'methods' => 'GET',
            'permission_callback' => $manager,
            'callback' => [self::class, 'live_manager_poll'],
            'args' => $by_id + ['since' => ['type' => 'integer', 'required' => false, 'default' => 0]],
        ]);
        register_rest_route(self::NS, '/live/claim', [
            'methods' => 'POST',
            'permission_callback' => $manager,
            'callback' => [self::class, 'live_claim'],
            'args' => $by_id,
        ]);
        register_rest_route(self::NS, '/live/reply', [
            'methods' => 'POST',
            'permission_callback' => $manager,
            'callback' => [self::class, 'live_reply'],
            'args' => $by_id + ['text' => ['type' => 'string', 'required' => true]],
        ]);
        register_rest_route(self::NS, '/live/close', [
            'methods' => 'POST',
            'permission_callback' => $manager,
            'callback' => [self::class, 'live_close'],
            'args' => $by_id,
        ]);
    }

    public static function chat(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! Settings::ready()) {
            return new WP_Error('ibraai_off', __('The chat is not available right now.', 'ibracodes-ai-assistant'), ['status' => 503]);
        }

        // A thread a person owns, or is about to, is not the AI's to answer:
        // the widget switches to the live routes on this reply. Checked before
        // the guards so the refusal costs no chat budget.
        $token = sanitize_text_field((string) $request->get_param('thread'));
        $thread_id = Threads::id_from_token($token);
        $page_id = absint($request->get_param('page'));
        $state = '';
        if ($thread_id > 0 && Settings::live_ready()) {
            $state = Live::state($thread_id);
            if (in_array($state, ['waiting', 'live'], true)) {
                // the shape of a WP_Error response, built by hand: WordPress
                // reads a WP_Error's data.status as the HTTP code, and here
                // that key has to carry the live state for the widget
                return new WP_REST_Response([
                    'code' => 'ibraai_live_owned',
                    'message' => __('A person has this conversation right now.', 'ibracodes-ai-assistant'),
                    'data' => ['status' => $state],
                ], 409);
            }
        }

        $gate = Guards::check_and_acquire();
        if ($gate instanceof WP_Error) {
            return $gate;
        }

        try {
            $messages = (array) $request['messages'];
            $context = [
                'page_id' => $page_id,
                'thread_id' => $thread_id,
                // a missed request changes what the agent is told, and the thread is the only word on that
                'live' => $state === 'missed' ? 'missed' : '',
            ];
            $answer = Agent::answer($messages, $context);
            if ($answer instanceof WP_Error) {
                return $answer;
            }

            $last = end($messages);
            $answer['thread'] = Threads::record(
                $token,
                (string) ($last['text'] ?? ''),
                $answer,
                sanitize_key((string) $request->get_param('device')),
            );
            unset($answer['no_match']); // server-side signal, not the customer's business

            // The one place a person is asked for: after the turn is recorded,
            // so the waiting line follows the question and the answer on the
            // thread, and a first turn has a thread to sit on by then.
            if ($answer['live'] === 'pending') {
                $live_id = Threads::id_from_token((string) $answer['thread']);
                $answer['live'] = $live_id > 0 ? Live::request($live_id, $page_id)['status'] : '';
            }

            // nobody came: the AI answers again and each answer carries the contact option, where there is one
            if ($state === 'missed') {
                $answer['handoff'] = $answer['live'] === '' && Prompt::handoff_label() !== '';
            }

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
            return new WP_Error('ibraai_index_off', __('Switch retrieval to the embeddings index first.', 'ibracodes-ai-assistant'), ['status' => 400]);
        }
        if (Settings::api_key() === '') {
            return new WP_Error('ibraai_no_key', __('Add an OpenAI key first.', 'ibracodes-ai-assistant'), ['status' => 400]);
        }
        Index::drop();
        // the batches run on WP-Cron, off this request; due now, so the spawn below actually fires
        Index::queue_all(0);
        spawn_cron();
        $pending = (int) Index::status()['pending'];

        return rest_ensure_response([
            'ok' => true,
            'pending' => $pending,
            'message' => sprintf(
                /* translators: %s: number of pages waiting to be indexed */
                __('Rebuilding, %s pages queued.', 'ibracodes-ai-assistant'),
                number_format_i18n($pending),
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // Live chat, visitor side
    // -----------------------------------------------------------------------
    public static function live_visitor_poll(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $thread_id = self::live_visitor_thread($request);
        if ($thread_id instanceof WP_Error) {
            return $thread_id;
        }

        return rest_ensure_response(Live::poll_visitor($thread_id, absint($request->get_param('since'))));
    }

    public static function live_visitor_message(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $thread_id = self::live_visitor_thread($request);
        if ($thread_id instanceof WP_Error) {
            return $thread_id;
        }
        $state = Live::state($thread_id);
        if (! in_array($state, ['waiting', 'live'], true)) {
            return new WP_Error('ibraai_not_live', __('No person is on this chat right now.', 'ibracodes-ai-assistant'), ['status' => 409]);
        }
        $id = Live::visitor_message($thread_id, (string) $request->get_param('text'));
        if ($id === 0) {
            return self::empty_message();
        }

        return rest_ensure_response(['id' => $id, 'status' => $state]);
    }

    // -----------------------------------------------------------------------
    // Live chat, manager side
    // -----------------------------------------------------------------------
    public static function live_open(): WP_REST_Response|WP_Error
    {
        if (! Settings::live_ready()) {
            return self::live_off();
        }

        return rest_ensure_response(['threads' => Live::open_threads()]);
    }

    public static function live_manager_poll(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $thread_id = self::live_manager_thread($request);
        if ($thread_id instanceof WP_Error) {
            return $thread_id;
        }

        return rest_ensure_response(Live::poll_manager($thread_id, absint($request->get_param('since'))));
    }

    public static function live_claim(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $thread_id = self::live_manager_thread($request);
        if ($thread_id instanceof WP_Error) {
            return $thread_id;
        }

        return rest_ensure_response(Live::claim($thread_id, get_current_user_id()));
    }

    public static function live_reply(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $thread_id = self::live_manager_thread($request);
        if ($thread_id instanceof WP_Error) {
            return $thread_id;
        }
        if (Live::state($thread_id) !== 'live') {
            return new WP_Error('ibraai_not_live', __('Claim the chat before replying.', 'ibracodes-ai-assistant'), ['status' => 409]);
        }
        $id = Live::manager_reply($thread_id, get_current_user_id(), (string) $request->get_param('text'));
        if ($id === 0) {
            return self::empty_message();
        }

        return rest_ensure_response(['id' => $id, 'status' => 'live']);
    }

    public static function live_close(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $thread_id = self::live_manager_thread($request);
        if ($thread_id instanceof WP_Error) {
            return $thread_id;
        }

        return rest_ensure_response(Live::close($thread_id));
    }

    /** The visitor's gate, in cost order: live chat on, token verified, poll budget left. Returns the thread id. */
    private static function live_visitor_thread(WP_REST_Request $request): int|WP_Error
    {
        if (! Settings::live_ready()) {
            return self::live_off();
        }
        $thread_id = Threads::id_from_token(sanitize_text_field((string) $request->get_param('thread')));
        if ($thread_id === 0) {
            return new WP_Error('ibraai_bad_token', __('This conversation could not be verified.', 'ibracodes-ai-assistant'), ['status' => 403]);
        }
        if (! Guards::poll_allowed($thread_id)) {
            return new WP_Error('ibraai_rate_limited', __('Too many requests. Slow down a little.', 'ibracodes-ai-assistant'), ['status' => 429]);
        }

        return $thread_id;
    }

    /** The manager's gate: live chat on and the thread exists. Returns the thread id. */
    private static function live_manager_thread(WP_REST_Request $request): int|WP_Error
    {
        if (! Settings::live_ready()) {
            return self::live_off();
        }
        $thread_id = absint($request->get_param('id'));
        if ($thread_id === 0 || Live::state($thread_id) === '') {
            return new WP_Error('ibraai_no_thread', __('That conversation no longer exists.', 'ibracodes-ai-assistant'), ['status' => 404]);
        }

        return $thread_id;
    }

    private static function live_off(): WP_Error
    {
        return new WP_Error('ibraai_live_off', __('Live chat is not available right now.', 'ibracodes-ai-assistant'), ['status' => 503]);
    }

    private static function empty_message(): WP_Error
    {
        return new WP_Error('ibraai_empty_message', __('Write something first.', 'ibracodes-ai-assistant'), ['status' => 400]);
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
                'message' => __('Add a key first.', 'ibracodes-ai-assistant'),
            ]);
        }

        $result = Provider::test_key($key, array_key_exists($model, Settings::models()) ? $model : (string) Settings::get('model'));

        return rest_ensure_response($result instanceof WP_Error
            ? ['ok' => false, 'message' => $result->get_error_message()]
            : ['ok' => true, 'message' => __('Connected. The key works and the model is available.', 'ibracodes-ai-assistant')]);
    }
}
