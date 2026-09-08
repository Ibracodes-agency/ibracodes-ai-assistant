<?php
/**
 * Thread identity for the storefront.
 *
 * The chat endpoint is public, so a raw thread id travelling to the browser
 * would let anyone append to, or flag, someone else's conversation by guessing
 * a number. The client is handed an opaque token instead, and the server only
 * trusts an id it can re-derive from that token.
 *
 * The token is not a secret worth much: the worst an attacker can do with a
 * forged one is nothing, because forging it requires the site's own salts.
 */

namespace Ibracodes\AI_Assistant;

if (! defined('ABSPATH')) {
    exit;
}

class Threads
{
    public static function token(int $thread_id): string
    {
        return $thread_id . '.' . substr(wp_hash('ibraai_thread_' . $thread_id, 'nonce'), 0, 20);
    }

    /** The thread id a token refers to, or 0 when it does not verify. */
    public static function id_from_token(string $token): int
    {
        if (! str_contains($token, '.')) {
            return 0;
        }
        [$id, $signature] = explode('.', $token, 2);
        $id = (int) $id;
        if ($id <= 0) {
            return 0;
        }
        $expected = substr(wp_hash('ibraai_thread_' . $id, 'nonce'), 0, 20);

        return hash_equals($expected, $signature) ? $id : 0;
    }

    /** Records one exchange, opening a thread on the first turn. */
    public static function record(string $token, string $question, array $answer, string $device): string
    {
        if (! Settings::get('log_threads')) {
            return '';
        }

        $thread_id = self::id_from_token($token);
        if ($thread_id === 0) {
            $thread_id = DB::start_thread($question, $device);
        }

        DB::log_turn(
            $thread_id,
            $question,
            (string) ($answer['reply'] ?? ''),
            array_column($answer['products'] ?? [], 'id'),
            ! empty($answer['no_match']),
        );

        return self::token($thread_id);
    }
}
