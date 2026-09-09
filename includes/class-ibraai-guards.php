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
 *
 * Every counter is one row in the plugin's counters table, moved by a single
 * INSERT ... ON DUPLICATE KEY UPDATE. Requests that arrive together queue
 * behind that row's lock instead of all reading the same number and all
 * writing it back plus one, so no increment is lost and no cap can be walked
 * past by racing it.
 *
 * Each gate reserves before it decides and releases what it reserved when it
 * refuses, so a rejected request never leaves a counter inflated.
 */

namespace Ibracodes\AI_Assistant;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the counters table is owned by this plugin, and a cached count is a cap that can be walked past

class Guards
{
    /**
     * The concurrency window. It is a fixed window, so the slot counter falls
     * back to one every SLOT_TTL even under continuous load: the same safety
     * valve the old transient expiry gave a request that died before it could
     * release its slot.
     */
    private const SLOT_TTL = 2 * MINUTE_IN_SECONDS;

    /** Live-chat polls per thread per minute: the widget's every-few-seconds cadence with room to spare. */
    private const POLLS_PER_THREAD = 40;

    /** Live-chat polls per address per minute, over every thread: one machine cannot poll the whole table. */
    private const POLLS_PER_ADDRESS = 300;

    /** The concurrency counter: one row, incremented while a request is in flight. */
    private const SLOT = 'ibraai_busy';

    /**
     * Checks every gate and, when they all pass, claims the caller's share of
     * the budget. Returns null to proceed.
     *
     * Each counter is reserved before it is judged, and given back the moment
     * a later gate refuses, so a request that is turned away costs nothing.
     */
    public static function check_and_acquire(): ?WP_Error
    {
        $ip = self::client_ip_hash();
        $burst_key = 'ibraai_rl_' . $ip;
        $day_key = 'ibraai_rld_' . $ip;

        if (self::bump($burst_key, self::expiry_in(10 * MINUTE_IN_SECONDS)) > (int) Settings::get('limit_ip_burst')) {
            self::drop($burst_key);

            return self::busy(__('That is a lot of messages at once. Try again in a few minutes.', 'ibracodes-ai-assistant'));
        }
        if (self::bump($day_key, self::day_expiry(gmdate('Y-m-d'))) > (int) Settings::get('limit_ip_day')) {
            self::drop($day_key);
            self::drop($burst_key);

            return self::busy(__('You have reached today\'s chat limit. Try again tomorrow, or use the contact page.', 'ibracodes-ai-assistant'));
        }
        // The store-wide counters are charged per upstream call, in
        // charge_upstream_call(), so they are only read here.
        if (self::store_day_count() >= (int) Settings::get('limit_store_day')) {
            self::drop($day_key);
            self::drop($burst_key);

            return self::busy(__('The chat is busy right now. Please try again later.', 'ibracodes-ai-assistant'));
        }
        if (self::month_count() >= (int) Settings::get('limit_month')) {
            self::drop($day_key);
            self::drop($burst_key);

            return self::busy(__('The chat is unavailable right now. Please use the contact page.', 'ibracodes-ai-assistant'));
        }
        if (self::bump(self::SLOT, self::expiry_in(self::SLOT_TTL)) > (int) Settings::get('limit_concurrent')) {
            self::drop(self::SLOT);
            self::drop($day_key);
            self::drop($burst_key);

            return self::busy(__('The chat is busy right now. Try again in a moment.', 'ibracodes-ai-assistant'));
        }

        return null;
    }

    public static function release(): void
    {
        self::drop(self::SLOT);
    }

    /**
     * Charged once per real upstream API call, not once per chat message: one
     * message can run several calls through the tool loop, and it is the calls
     * that cost money.
     *
     * The reservation is the check: the counter is moved first and the caller
     * is refused, and given its reservation back, when the new total is past
     * the cap.
     */
    public static function charge_upstream_call(): ?WP_Error
    {
        $day_key = self::day_key();
        $month_key = self::month_key();

        if (self::bump($day_key, self::day_expiry(gmdate('Y-m-d'))) > (int) Settings::get('limit_store_day')) {
            self::drop($day_key);

            return self::busy(__('The chat is busy right now. Please try again later.', 'ibracodes-ai-assistant'));
        }
        if (self::bump($month_key, self::month_expiry(gmdate('Y-m'))) > (int) Settings::get('limit_month')) {
            self::drop($month_key);
            self::drop($day_key);

            return self::busy(__('The chat is unavailable right now. Please use the contact page.', 'ibracodes-ai-assistant'));
        }

        return null;
    }

    /**
     * The gate for live-chat polling, separate from the chat limits so polling
     * never eats the chat budget. No upstream call sits behind it, so the
     * budget is about the database, not the bill.
     *
     * Keyed on the thread, not the address: the signed token already binds
     * the caller to one thread, whereas the proxy headers client_ip_hash()
     * honours are spoofable on a site that is not behind such a proxy, and a
     * rotating header would mint a fresh counter per value. A coarse backstop
     * on REMOTE_ADDR alone bounds one machine polling many threads.
     */
    public static function poll_allowed(int $thread_id): bool
    {
        return self::within_window('ibraai_poll_' . $thread_id, self::POLLS_PER_THREAD)
            && self::within_window('ibraai_pollip_' . self::remote_addr_hash(), self::POLLS_PER_ADDRESS);
    }

    /**
     * Counts one call against a fixed one-minute window; false once the limit
     * is reached, with the refused call given back so a rejected poll cannot
     * extend the lockout.
     *
     * The window lives in the row's own expiry rather than in a sliding one:
     * an expiry pushed forward on every write would never let a widget polling
     * every few seconds see the counter reset, and it would lock itself out
     * after the fortieth poll.
     */
    private static function within_window(string $key, int $limit): bool
    {
        if (self::bump($key, self::expiry_in(MINUTE_IN_SECONDS)) > $limit) {
            self::drop($key);

            return false;
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // The counters themselves
    // -----------------------------------------------------------------------
    /**
     * Counts one event against a fixed window and returns the new total. The
     * whole update is one statement, so MySQL settles parallel callers under a
     * row lock and no increment is ever lost. A window that has passed restarts
     * at one; a window still open keeps its original expiry, so a caller cannot
     * push the window forward and never see it reset.
     *
     * The total is read back in a second statement, so it can already include
     * increments from callers running alongside this one. That only makes the
     * gate stricter, never looser: the count can come back high, never low.
     */
    public static function bump(string $name, string $expires_at): int
    {
        global $wpdb;
        $table = DB::counters_table();
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (name, value, expires_at) VALUES (%s, 1, %s)
             ON DUPLICATE KEY UPDATE
                 value = IF(expires_at <= %s, 1, value + 1),
                 expires_at = IF(expires_at <= %s, %s, expires_at)',
            $table,
            $name,
            $expires_at,
            $now,
            $now,
            $expires_at,
        ));

        return (int) $wpdb->get_var($wpdb->prepare('SELECT value FROM %i WHERE name = %s', $table, $name));
    }

    /**
     * Gives back a reservation the caller did not use. Floors at zero: the
     * value is unsigned, and a window that reset between the reservation and
     * the release would otherwise be asked to go below it.
     */
    public static function drop(string $name): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET value = GREATEST(0, CAST(value AS SIGNED) - 1) WHERE name = %s',
            DB::counters_table(),
            $name,
        ));
    }

    /** The current count, or 0 when the window has passed. */
    public static function count(string $name): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT value FROM %i WHERE name = %s AND expires_at > %s',
            DB::counters_table(),
            $name,
            gmdate('Y-m-d H:i:s'),
        ));
    }

    public static function store_day_count(): int
    {
        return self::count(self::day_key());
    }

    public static function month_count(): int
    {
        return self::count(self::month_key());
    }

    /** Usage for the settings screen, so the owner can see the bill forming. */
    public static function usage(): array
    {
        return [
            'today' => self::store_day_count(),
            'today_limit' => (int) Settings::get('limit_store_day'),
            'month' => self::month_count(),
            'month_limit' => (int) Settings::get('limit_month'),
            'in_flight' => self::count(self::SLOT),
        ];
    }

    private static function day_key(): string
    {
        return 'ibraai_calls_' . gmdate('Y-m-d');
    }

    private static function month_key(): string
    {
        return 'ibraai_calls_month_' . gmdate('Y-m');
    }

    /** A window that ends $seconds from now, in the GMT form the rows store. */
    private static function expiry_in(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + $seconds);
    }

    /**
     * When a day counter may be dropped: the end of the UTC day its key names,
     * plus two days. Computed from the key's own date rather than from now
     * plus a day, so a counter can never reset in the middle of the day it
     * counts, and the spare days leave the row for the daily purge to clear
     * rather than expiring it while the date is still current.
     */
    public static function day_expiry(string $date): string
    {
        return gmdate('Y-m-d H:i:s', (int) strtotime($date . ' 00:00:00 UTC +3 days'));
    }

    /** The same for a month counter: the first day of the following month, plus two. */
    public static function month_expiry(string $month): string
    {
        return gmdate('Y-m-d H:i:s', (int) strtotime($month . '-01 00:00:00 UTC +1 month +2 days'));
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
        $ip = (string) apply_filters('ibraai_client_ip', $ip);

        return md5('ibraai|' . $ip);
    }

    /** The connecting address alone, hashed like client_ip_hash(): the one header a caller cannot choose. */
    private static function remote_addr_hash(): string
    {
        $ip = empty($_SERVER['REMOTE_ADDR']) ? '' : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));

        return md5('ibraai|' . $ip);
    }

    private static function busy(string $message): WP_Error
    {
        return new WP_Error('ibraai_rate_limited', $message, ['status' => 429]);
    }
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
