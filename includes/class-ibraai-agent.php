<?php
/**
 * The conversation loop: assemble, call, run tools, call again, answer.
 *
 * Bounded at MAX_TURNS. The bound is not defensive politeness, it is the
 * guarantee that one customer message cannot become an unbounded number of
 * billed API calls if the model keeps asking for tools.
 */

namespace Ibracodes\AI_Assistant;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class Agent
{
    private const MAX_TURNS = 4;

    /** Hard ceiling; the owner's "products per reply" setting cuts below it. */
    private const MAX_CARDS = 4;

    /**
     * @param array{page_id?: int, thread_id?: int, live?: string} $context the page being read, the verified thread, and 'missed' when a person was asked for and nobody came (from the thread's own state)
     */
    public static function answer(array $history, array $context = []): array|WP_Error
    {
        $messages = [Prompt::system_message($context), ...Scrubber::sanitize_history($history)];

        if (count($messages) < 2 || end($messages)['role'] !== 'user') {
            return new WP_Error('ibraai_bad_request', __('No message received.', 'ibracodes-ai-assistant'), ['status' => 400]);
        }

        $tools = Tools::definitions();
        $cards = [];
        $reply = '';
        $no_match = false;
        $handoff = false;
        // 'pending' once the model asked for a person (the REST layer makes the request after the turn is recorded), '' otherwise
        $live = '';

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
                    $name = (string) ($call['function']['name'] ?? '');
                    $result = Tools::run(
                        $name,
                        is_array($arguments) ? $arguments : [],
                        $cards,
                        $context,
                    );
                    // a search that found nothing is the signal behind the
                    // "questions the agent could not answer" report
                    if ($name === 'search_products' && empty($result['results'])) {
                        $no_match = true;
                    }
                    // with live chat the tool asks a person in; without it, it is the contact button
                    if ($name === 'hand_off') {
                        if (isset($result['live'])) {
                            $live = (string) $result['live'];
                        } else {
                            $handoff = true;
                        }
                    }
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
        // back an empty bubble. During a live request the promise the tool
        // made is the answer, and no button goes with it
        if (trim($reply) === '') {
            if ($live !== '') {
                $reply = __('A person will join this chat shortly.', 'ibracodes-ai-assistant');
            } else {
                $reply = $cards
                    ? __('Here is what I found that might suit you:', 'ibracodes-ai-assistant')
                    : __('I could not answer that one. Try rephrasing, or get in touch and a person will help.', 'ibracodes-ai-assistant');
                $handoff = $handoff || ! $cards;
            }
        }

        $max = min(self::MAX_CARDS, max(1, (int) Settings::get('max_products')));
        $products = array_slice(array_values($cards), 0, $max);

        return [
            'reply' => trim($reply),
            'products' => $products,
            'chips' => array_values($chips),
            // true only when the model asked for the contact button (or the
            // fallback above suggested a person); the widget draws it then.
            // Never alongside a live request: a person is on the way
            'handoff' => $handoff && $live === '' && Prompt::handoff_label() !== '',
            'live' => $live,
            // the caller records the turn; a search that found nothing counts
            // as unanswered only when nothing was shown in the end
            'no_match' => $no_match && ! $products,
        ];
    }
}
