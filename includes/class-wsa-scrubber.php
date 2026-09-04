<?php
/**
 * Deterministic output guards.
 *
 * The prompt tells the model never to state a price; this makes it true. The
 * card is the only trustworthy price (rendered server-side from WooCommerce),
 * so any currency amount that reaches the reply text is replaced with a pointer
 * to it. A model that ignores an instruction is normal; a customer quoted a
 * stale price is a refund argument.
 *
 * Unlike a single-language store, this has to work in any currency without
 * knowing the language, so it keys off WooCommerce's own currency symbol and
 * code rather than words like "costs".
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

class Scrubber
{
    /** Strips the chips marker and returns [reply, chips]. */
    public static function extract_chips(string $reply): array
    {
        $chips = [];
        if (preg_match(Prompt::CHIPS_PATTERN, $reply, $match)) {
            $chips = array_slice(array_values(array_filter(array_map(
                static fn ($c) => mb_substr(trim($c), 0, 40),
                explode('|', $match[1]),
            ))), 0, 3);
        }

        // strip EVERY occurrence, not just the matched one: adversarial input
        // can plant extra markers to leave a stray one in the visible text
        $reply = trim((string) preg_replace(Prompt::CHIPS_PATTERN, '', $reply));

        return [$reply, $chips];
    }

    /**
     * Replaces any currency amount with a pointer to the card.
     *
     * Deliberately narrow: an amount only counts when it sits next to a
     * currency token, so specification numbers ("4MP", "IP66", "30 m") are
     * never touched. A bare number the model wrote in answer to "how much?"
     * survives, which is the known gap: closing it would need per-language
     * price vocabulary, and mangling every number in a spec sheet is a worse
     * failure than the one it would prevent.
     */
    public static function scrub_prices(string $text): string
    {
        if ($text === '' || Settings::get('price_policy') !== 'cards_only') {
            return $text;
        }

        $tokens = self::currency_tokens();
        if (! $tokens) {
            return $text;
        }

        // longest first so "ILS" wins over a bare symbol that prefixes it
        usort($tokens, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $currency = implode('|', array_map(static fn ($t) => preg_quote($t, '/'), $tokens));

        // digits with any grouping or decimal separator the store might use
        $amount = '\d[\d\s.,\x{00A0}\x{2009}]*\d|\d';
        $allowed = self::facts_amounts();
        $mask = __('(price is on the card below)', 'woocommerce-shop-agent');

        $keep_or_mask = static function (array $m) use ($allowed, $mask): string {
            // A figure the owner wrote into the store facts is theirs to state:
            // masking the free-shipping threshold out of "free over 399" would
            // break the answer this guard is supposed to protect.
            $digits = preg_replace('/\D/', '', $m['amount'] ?? '');

            return ($digits !== '' && in_array($digits, $allowed, true)) ? $m[0] : $mask;
        };

        $scrubbed = preg_replace_callback(
            [
                '/(?P<amount>' . $amount . ')\s*(?:' . $currency . ')/ui',
                '/(?:' . $currency . ')\s*(?P<amount>' . $amount . ')/ui',
            ],
            $keep_or_mask,
            $text,
        );

        // a catastrophic pattern or bad UTF-8 returns null; never hand back an
        // empty reply because a guard failed
        return is_string($scrubbed) ? $scrubbed : $text;
    }

    /**
     * Currency tokens to look for. WooCommerce knows the symbol and the code,
     * but shoppers write the word: an Israeli store prices in ILS, displays
     * a shekel sign, and everyone types ש"ח. The table covers the currencies
     * this is most likely to meet and the filter covers the rest. Without
     * WooCommerce there is no currency to start from, so nothing is masked
     * unless the filter names the tokens.
     */
    private static function currency_tokens(): array
    {
        $spoken = [
            'ILS' => ['ש"ח', 'ש״ח', 'שקלים', 'שקל', 'NIS'],
            'USD' => ['dollars', 'dollar', 'USD'],
            'EUR' => ['euros', 'euro', 'EUR'],
            'GBP' => ['pounds', 'pound', 'GBP'],
            'CAD' => ['dollars', 'dollar', 'CAD'],
            'AUD' => ['dollars', 'dollar', 'AUD'],
            'INR' => ['rupees', 'rupee', 'INR'],
            'BRL' => ['reais', 'real', 'BRL'],
            'MXN' => ['pesos', 'peso', 'MXN'],
            'ZAR' => ['rand', 'ZAR'],
        ];

        $code = '';
        $tokens = [];
        if (Capabilities::has_commerce()) {
            $code = get_woocommerce_currency();
            $symbol = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8');
            $tokens = array_merge([$symbol, $code], $spoken[$code] ?? []);
        }

        /** Add or replace the words that count as money on this store. */
        return array_values(array_unique(array_filter(
            (array) apply_filters('wsa_currency_tokens', $tokens, $code),
        )));
    }

    /**
     * Figures the owner wrote in the store facts, normalised to bare digits.
     * These are trusted: the prompt allows the agent to state them, so the
     * guard must not contradict it.
     *
     * @return array<int, string>
     */
    private static function facts_amounts(): array
    {
        preg_match_all('/\d[\d.,\s]*/u', (string) Settings::get('store_facts'), $matches);

        return array_values(array_filter(array_map(
            static fn ($n) => (string) preg_replace('/\D/', '', $n),
            $matches[0] ?? [],
        )));
    }

    /** Bounds and cleans the conversation the browser sent back to us. */
    public static function sanitize_history(array $raw, int $keep = 10, int $max_chars = 1200): array
    {
        $messages = [];
        foreach (array_slice($raw, -$keep) as $item) {
            $role = (($item['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
            $text = mb_substr(trim(wp_strip_all_tags((string) ($item['text'] ?? ''))), 0, $max_chars);
            if ($text !== '') {
                $messages[] = ['role' => $role, 'content' => $text];
            }
        }

        return $messages;
    }
}
