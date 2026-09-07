<?php
/**
 * Static scan: no WooCommerce function may be reachable on a site without
 * WooCommerce. The Catalog may call them freely, because every public entry
 * point returns early without commerce. Anywhere else a call has to sit right
 * under a commerce check, and only at the sites listed here.
 */
require_once __DIR__ . '/lib.php';

$root = dirname(__DIR__);
$files = array_merge([$root . '/ibracodes-ai-assistant.php', $root . '/uninstall.php'], glob($root . '/includes/*.php'));
$call = '/\b(?:wc_[a-z_]+|get_woocommerce_[a-z_]+)\(|\bWC\(\)/';
$guard = '/Capabilities::has_commerce\(\)|\$commerce\b/';
$window = 12; // lines above a call in which its commerce check must appear

/** First and last line index of a method body, so a call can be tied to the method it sits in. */
$method_span = static function (array $lines, string $name): array {
    $first = null;
    foreach ($lines as $i => $line) {
        if ($first === null && preg_match('/function ' . preg_quote($name, '/') . '\(/', $line)) {
            $first = $i;
        } elseif ($first !== null && $line === '    }') {
            return [$first, $i];
        }
    }

    return [-1, -1];
};

$offending = [];
$catalog_calls = 0;
$admin_calls = 0;
$scrubber_calls = 0;
foreach ($files as $file) {
    $rel = substr($file, strlen($root) + 1);
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    [$tokens_from, $tokens_to] = $rel === 'includes/class-wsa-scrubber.php' ? $method_span($lines, 'currency_tokens') : [-1, -1];

    foreach ($lines as $i => $line) {
        if (! preg_match($call, $line)) {
            continue;
        }
        if ($rel === 'includes/class-wsa-catalog.php') {
            $catalog_calls++;

            continue;
        }
        $start = max(0, $i - $window);
        $guarded = (bool) preg_grep($guard, array_slice($lines, $start, $i - $start + 1));
        if ($rel === 'includes/class-wsa-admin.php') {
            $admin_calls++;
            if ($guarded && str_contains($line, 'wc_get_product(')) {
                continue;
            }
        } elseif ($rel === 'includes/class-wsa-scrubber.php' && $guarded && $i >= $tokens_from && $i <= $tokens_to) {
            $scrubber_calls++;

            continue;
        }
        $offending[] = $rel . ':' . ($i + 1) . '  ' . trim($line);
    }
}

foreach ($offending as $where) {
    echo "  offending: {$where}\n";
}
wsa_assert($catalog_calls > 0, 'the scan recognises the WooCommerce calls the Catalog makes');
wsa_assert($offending === [], 'no WooCommerce call outside the Catalog and the guarded sites');
wsa_assert_same(2, $admin_calls, 'the admin has exactly two guarded wc_get_product() calls');
wsa_assert_same(2, $scrubber_calls, 'the scrubber has exactly two guarded currency calls');

wsa_done(__FILE__);
