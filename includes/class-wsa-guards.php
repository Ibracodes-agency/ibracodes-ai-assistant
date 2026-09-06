<?php
/**
 * Cost and abuse guards.
 *
 * The spend here is the store owner's money, so every gate is a visible
 * setting with an honest default. Four layers, cheapest first:
 *   per-IP burst      one visitor hammering the chat
 *   per-IP daily      one abuser draining the whole store's budget
 *   store-wide daily  a scripted attack becoming a four-figure invoice
 *   concurrency       slow upstream calls exhausting the PHP worker pool
 * plus a monthly call budget for slow, silent creep.
 */

namespace WSA;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class Guards
{
    /** Frees itself even if the request dies mid-flight, so a crash cannot leak a slot forever. */
    private const SLOT_TTL = 2 * MINUTE_IN_SECONDS;

    /** Live-chat polls per visitor per minute: the widget's every-few-seconds cadence with room to spare. */
    private const POLLS_PER_MINUTE = 40;

    /**
     * Checks every gate and, when they all pass, claims the caller's share of
     * the budget. Returns null to proceed.
     */
    public static function check_and_acquire(): ?WP_Error
    {
        $ip = self::client_ip_hash();
        $burst_key = 'wsa_rl_' . $ip;
        $day_key = 'wsa_rld_' . $ip;

        if ((int) get_transient($burst_key) >= (int) Settings::get('limit_ip_burst')) {
            return self::busy(__('That is a lot of messages at once. Try again in a few minutes.', 'woocommerce-shop-agent'));
        }
        if ((int) get_transient($day_key) >= (int) Settings::get('limit_ip_day')) {
            return self::busy(__('You have reached today\'s chat limit. Try again tomorrow, or use the contact page.', 'woocommerce-shop-agent'));
        }
        if (self::store_day_count() >= (int) Settings::get('limit_store_day')) {
            return self::busy(__('The chat is busy right now. Please try again later.', 'woocommerce-shop-agent'));
        }
        if (self::month_count() >= (int) Settings::get('limit_month')) {
            return self::busy(__('The chat is unavailable right now. Please use the contact page.', 'woocommerce-shop-agent'));
        }
        if ((int) get_transient('wsa_busy') >= (int) Settings::get('limit_concurrent')) {
            return self::busy(__('The chat is busy right now. Try again in a moment.', 'woocommerce-shop-agent'));
        }

        // Counters are get-then-set rather than atomic. The concurrency cap
        // bounds how many requests can be inside this window at once, so a
        // boundary race can overshoot a limit by at most that many calls,
        // which is a rounding error against the daily bound.
        set_transient($burst_key, (int) get_transient($burst_key) + 1, 10 * MINUTE_IN_SECONDS);
        set_transient($day_key, (int) get_transient($day_key) + 1, DAY_IN_SECONDS);
        set_transient('wsa_busy', (int) get_transient('wsa_busy') + 1, self::SLOT_TTL);

        return null;
    }

    public static function release(): void
    {
        set_transient('wsa_busy', max(0, (int) get_transient('wsa_busy') - 1), self::SLOT_TTL);
    }

    /**
     * Charged once per real upstream API call, not once per chat message: one
     * message can run several calls through the tool loop, and it is the calls
     * that cost money.
     */
    public static function charge_upstream_call(): ?WP_Error
    {
        if (self::store_day_count() >= (int) Settings::get('limit_store_day')) {
            return self::busy(__('The chat is busy right now. Please try again later.', 'woocommerce-shop-agent'));
        }
        if (self::month_count() >= (int) Settings::get('limit_month')) {
            return self::busy(__('The chat is unavailable right now. Please use the contact page.', 'woocommerce-shop-agent'));
        }

        set_transient(self::day_key(), self::store_day_count() + 1, DAY_IN_SECONDS);
        update_option(self::month_key(), self::month_count() + 1, false);

        return null;
    }

    /**
     * The gate for live-chat polling, separate from the chat limits so polling
     * never eats the chat budget. No upstream call sits behind it, so the
     * budget is about the database, not the bill.
     *
     * The minute window lives in the stored value rather than in the
     * transient's expiry: set_transient() pushes the expiry forward on every
     * write, and a widget polling every few seconds would otherwise never see
     * the counter reset and lock itself out after the fortieth poll.
     */
    public static function poll_allowed(): bool
    {
        $key = 'wsa_poll_' . self::client_ip_hash();
        $now = time();
        $window = (array) get_transient($key);
        if ((int) ($window['until'] ?? 0) <= $now) {
            $window = ['count' => 0, 'until' => $now + MINUTE_IN_SECONDS];
        }
        if ((int) ($window['count'] ?? 0) >= self::POLLS_PER_MINUTE) {
            return false;
        }
        $window['count'] = (int) ($window['count'] ?? 0) + 1;
        set_transient($key, $window, max(1, (int) $window['until'] - $now));

        return true;
    }

    public static function store_day_count(): int
    {
        return (int) get_transient(self::day_key());
    }

    public static function month_count(): int
    {
        return (int) get_option(self::month_key(), 0);
    }

    /** Usage for the settings screen, so the owner can see the bill forming. */
    public static function usage(): array
    {
        return [
            'today' => self::store_day_count(),
            'today_limit' => (int) Settings::get('limit_store_day'),
            'month' => self::month_count(),
            'month_limit' => (int) Settings::get('limit_month'),
            'in_flight' => (int) get_transient('wsa_busy'),
        ];
    }

    private static function day_key(): string
    {
        return 'wsa_calls_' . gmdate('Y-m-d');
    }

    private static function month_key(): string
    {
        return 'wsa_calls_month_' . gmdate('Y-m');
    }

    /**
     * The visitor's address, hashed: rate limiting needs to tell people apart,
     * not to know who they are, so the raw address is never stored.
     *
     * Proxy headers are preferred when present because a store behind
     * Cloudflare whose host does not restore the real address would otherwise
     * see every visitor as one IP and lock the entire shop out after a handful
     * of messages. Those headers are spoofable when the site is NOT behind such
     * a proxy, which downgrades the per-IP gates for an attacker who knows the
     * trick, but the store-wide daily and monthly caps are unspoofable and they
     * are the gates that actually bound the bill.
     */
    private static function client_ip_hash(): string
    {
        $ip = '';
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'REMOTE_ADDR'] as $header) {
            if (! empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                break;
            }
        }
        $ip = (string) apply_filters('wsa_client_ip', $ip);

        return md5('wsa|' . $ip);
    }

    private static function busy(string $message): WP_Error
    {
        return new WP_Error('wsa_rate_limited', $message, ['status' => 429]);
    }
}
