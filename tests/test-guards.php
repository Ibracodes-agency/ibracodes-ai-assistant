<?php
/**
 * The counters behind the spending caps and the rate limits.
 *
 * Every increment is one statement, so two requests arriving together cannot
 * both read the same number and both write it back plus one. That shape is
 * what is asserted here: the upsert is a single INSERT, a window that has
 * passed restarts, a refused request hands its reservation back, and each
 * gate counts exactly.
 *
 * The store's own day, month and concurrency counters are live rows on this
 * site, so they are snapshotted first and put back at the end and on failure.
 */
require_once __DIR__ . '/lib.php';

use Ibracodes\AI_Assistant\DB;
use Ibracodes\AI_Assistant\Guards;
use Ibracodes\AI_Assistant\Settings;

global $wpdb;

$snapshot = Settings::all();
// one address for the run, so the per-visitor gates have one counter each to clear afterwards
$_SERVER['REMOTE_ADDR'] = '203.0.113.42';
unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_TRUE_CLIENT_IP']);
$ip = md5('ibraai|203.0.113.42'); // mirrors Guards::client_ip_hash() and remote_addr_hash()

$day_key = 'ibraai_calls_' . gmdate('Y-m-d');
$month_key = 'ibraai_calls_month_' . gmdate('Y-m');
$slot_key = 'ibraai_busy';
$burst_key = 'ibraai_rl_' . $ip;
$ip_day_key = 'ibraai_rld_' . $ip;
$thread = 987654;
$poll_key = 'ibraai_poll_' . $thread;
$addr_poll_key = 'ibraai_pollip_' . $ip;
$scratch = 'ibraai_test_counter';

$live = [];
foreach ([$day_key, $month_key, $slot_key] as $name) {
    $live[$name] = ibraai_counter_row($name);
}
$owned = [$burst_key, $ip_day_key, $poll_key, $addr_poll_key, $scratch];
register_shutdown_function(static function () use ($snapshot, $live, $owned): void {
    foreach ($owned as $name) {
        ibraai_counter_forget($name);
    }
    foreach ($live as $name => $row) {
        $row === null
            ? ibraai_counter_forget($name)
            : ibraai_counter_set($name, (int) $row['value'], (string) $row['expires_at']);
    }
    Settings::update($snapshot);
});
$forget = static function (array $names): void {
    foreach ($names as $name) {
        ibraai_counter_forget($name);
    }
};
$in = static fn (int $seconds): string => gmdate('Y-m-d H:i:s', time() + $seconds);
$rate_limited = static fn ($result): bool => is_wp_error($result) && $result->get_error_code() === 'ibraai_rate_limited';

$table = DB::counters_table();
ibraai_assert_same($table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))), 'counters table exists');

// ---- the primitives
$forget([$scratch]);
ibraai_assert_same(1, Guards::bump($scratch, $in(MINUTE_IN_SECONDS)), 'a fresh counter starts at one');
ibraai_assert_same(2, Guards::bump($scratch, $in(MINUTE_IN_SECONDS)), 'the next event counts two');
ibraai_assert_same(2, Guards::count($scratch), 'count() reads what bump() returned');

ibraai_counter_set($scratch, 9, $in(-1));
ibraai_assert_same(0, Guards::count($scratch), 'a window that has passed counts as nothing');
ibraai_assert_same(9, (int) ibraai_counter_row($scratch)['value'], 'and reading it leaves the row where it is');
ibraai_assert_same(1, Guards::bump($scratch, $in(MINUTE_IN_SECONDS)), 'the first event after the window restarts at one');

$forget([$scratch]);
Guards::bump($scratch, $in(MINUTE_IN_SECONDS));
$expires_at = ibraai_counter_row($scratch)['expires_at'];
Guards::bump($scratch, $in(10 * MINUTE_IN_SECONDS));
ibraai_assert_same($expires_at, ibraai_counter_row($scratch)['expires_at'], 'an open window keeps its expiry, so a caller cannot push it forward and never see it reset');

ibraai_counter_set($scratch, 2, $in(MINUTE_IN_SECONDS));
Guards::drop($scratch);
ibraai_assert_same(1, Guards::count($scratch), 'drop() gives one back');
Guards::drop($scratch);
Guards::drop($scratch);
ibraai_assert_same(0, (int) ibraai_counter_row($scratch)['value'], 'and floors at zero rather than going negative');

// the increment is one statement, which is the whole point of the table
$forget([$scratch]);
$queries = [];
$spy = static function ($query) use (&$queries) {
    $queries[] = (string) $query;

    return $query;
};
add_filter('query', $spy);
Guards::bump($scratch, $in(MINUTE_IN_SECONDS));
remove_filter('query', $spy);
$inserts = array_values(array_filter($queries, static fn (string $q): bool => stripos(ltrim($q), 'INSERT') === 0));
ibraai_assert_same(1, count($inserts), 'one bump() issues exactly one INSERT');
ibraai_assert(stripos($inserts[0], 'ON DUPLICATE KEY UPDATE') !== false, 'and the increment happens inside it, under the row lock');
$forget([$scratch]);

// ---- the daily cap on upstream calls
Settings::update(['limit_store_day' => 1, 'limit_month' => 5000]);
$forget([$day_key, $month_key]);
ibraai_assert_same(null, Guards::charge_upstream_call(), 'the first upstream call of the day is charged');
ibraai_assert($rate_limited(Guards::charge_upstream_call()), 'the second is refused by the daily cap');
ibraai_assert_same(1, Guards::count($day_key), 'and the refused reservation was released, so the day still reads one');
ibraai_assert_same(Guards::day_expiry(gmdate('Y-m-d')), (string) ibraai_counter_row($day_key)['expires_at'], 'the day counter expires with its own day, not a day after the last call');

// ---- the monthly cap, which releases the daily reservation it had already taken
Settings::update(['limit_store_day' => 5000, 'limit_month' => 1]);
$forget([$day_key, $month_key]);
ibraai_assert_same(null, Guards::charge_upstream_call(), 'the first upstream call of the month is charged');
ibraai_assert($rate_limited(Guards::charge_upstream_call()), 'the second is refused by the monthly cap');
ibraai_assert_same(1, Guards::count($month_key), 'the month still reads one');
ibraai_assert_same(1, Guards::count($day_key), 'and the day the refused call had already charged reads one too');
ibraai_assert_same(Guards::month_expiry(gmdate('Y-m')), (string) ibraai_counter_row($month_key)['expires_at'], 'the month counter expires with its own month');

// ---- the live-chat poll window
$forget([$poll_key, $addr_poll_key]);
$allowed = 0;
for ($i = 0; $i < 41; $i++) {
    if (Guards::poll_allowed($thread)) {
        $allowed++;
    }
}
ibraai_assert_same(40, $allowed, 'forty polls a minute per thread, the forty-first refused');
ibraai_assert_same(40, Guards::count($poll_key), 'the refused poll was released, so it did not extend the lockout');
ibraai_counter_set($poll_key, 40, $in(-1));
ibraai_assert_same(true, Guards::poll_allowed($thread), 'a window past its minute lets the widget poll again');

// ---- the chat gates: the burst limit, and the slot the passing call holds
Settings::update(['limit_ip_burst' => 1, 'limit_ip_day' => 40, 'limit_store_day' => 5000, 'limit_month' => 5000, 'limit_concurrent' => 5]);
$forget([$burst_key, $ip_day_key, $slot_key, $day_key, $month_key]);
ibraai_assert_same(null, Guards::check_and_acquire(), 'the first message passes every gate');
ibraai_assert($rate_limited(Guards::check_and_acquire()), 'the second is refused by the burst limit');
ibraai_assert_same(1, Guards::count($burst_key), 'the refused burst reservation was released');
ibraai_assert_same(1, Guards::count($ip_day_key), 'and the refused message never reached the per-visitor day counter');
ibraai_assert_same(1, Guards::count($slot_key), 'only the message that passed holds a concurrency slot');
Guards::release();
ibraai_assert_same(0, Guards::count($slot_key), 'which release() gives back');

// a refusal at the last gate hands back everything the request reserved on the way
Settings::update(['limit_ip_burst' => 50, 'limit_concurrent' => 1]);
$forget([$burst_key, $ip_day_key, $slot_key]);
ibraai_assert_same(null, Guards::check_and_acquire(), 'the first message takes the only slot');
ibraai_assert($rate_limited(Guards::check_and_acquire()), 'the second is refused by the concurrency cap');
ibraai_assert_same(1, Guards::count($slot_key), 'the slot count is still one');
ibraai_assert_same(1, Guards::count($burst_key), 'the refused message gave its burst reservation back');
ibraai_assert_same(1, Guards::count($ip_day_key), 'and its day reservation too');
Guards::release();

Settings::update($snapshot);
ibraai_done(__FILE__);
