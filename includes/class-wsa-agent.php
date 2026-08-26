<?php
/**
 * The conversation loop: assemble, call, run tools, call again, answer.
 *
 * Bounded at MAX_TURNS. The bound is not defensive politeness, it is the
 * guarantee that one customer message cannot become an unbounded number of
 * billed API calls if the model keeps asking for tools.
 */

namespace WSA;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class Agent
{
    private const MAX_TURNS = 4;

    private const MAX_CARDS = 4;

    public static function answer(array $history): array|WP_Error
    {
        $messages = [Prompt::system_message(), ...Scrubber::sanitize_history($history)];

        if (count($messages) < 2 || end($messages)['role'] !== 'user') {
            return new WP_Error('wsa_bad_request', __('No message received.', 'woocommerce-shop-agent'), ['status' => 400]);
        }

        $tools = Tools::definitions();
        $cards = [];
        $reply = '';

        for ($turn = 0; $turn < self::MAX_TURNS; $turn++) {
            $message = Provider::complete($messages, $tools);
            if ($message instanceof WP_Error) {
                // a later turn failing still has cards worth showing, but a
                // first-turn failure has nothing: let the customer see the error
                if ($turn === 0) {
                    return $message;
                }
                break;
            }

            if (! empty($message['tool_calls'])) {
                $messages[] = $message;
                foreach ($message['tool_calls'] as $call) {
                    $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                    $result = Tools::run(
                        (string) ($call['function']['name'] ?? ''),
                        is_array($arguments) ? $arguments : [],
                        $cards,
                    );
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $call['id'],
                        'content' => wp_json_encode($result, JSON_UNESCAPED_UNICODE),
                    ];
                }

                continue;
            }

            $reply = (string) ($message['content'] ?? '');
            break;
        }

        [$reply, $chips] = Scrubber::extract_chips($reply);
        $reply = Scrubber::scrub_prices($reply);
        $chips = array_map([Scrubber::class, 'scrub_prices'], $chips);

        // the loop can run out of turns with tool calls but no text; never hand
        // back an empty bubble
        if (trim($reply) === '') {
            $reply = $cards
                ? __('Here is what I found that might suit you:', 'woocommerce-shop-agent')
                : __('I could not answer that one. Try rephrasing, or get in touch and a person will help.', 'woocommerce-shop-agent');
        }

        return [
            'reply' => trim($reply),
            'products' => array_slice(array_values($cards), 0, self::MAX_CARDS),
            'chips' => array_values($chips),
        ];
    }
}
