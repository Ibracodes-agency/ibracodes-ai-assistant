<?php
/**
 * What this site can do. Decided once per request, filterable so tests and
 * unusual hosts can force either mode.
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

class Capabilities
{
    private const WC_MIN = '8.0';

    /** WooCommerce present and recent enough for the product tools. */
    public static function has_commerce(): bool
    {
        $present = class_exists('WooCommerce') && defined('WC_VERSION') && version_compare(WC_VERSION, self::WC_MIN, '>=');

        return (bool) apply_filters('wsa_has_commerce', $present);
    }

    /** Who may see the admin: shop managers on a shop, administrators elsewhere. */
    public static function admin_cap(): string
    {
        return self::has_commerce() ? 'manage_woocommerce' : 'manage_options';
    }
}
