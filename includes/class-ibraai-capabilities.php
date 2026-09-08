<?php
/**
 * What this site can do. Recomputed on every call, which is cheap and lets
 * tests flip the filter mid-request; the filter also lets unusual hosts force
 * either mode.
 */

namespace Ibracodes\AI_Assistant;

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

        return (bool) apply_filters('ibraai_has_commerce', $present);
    }

    /** Who may see the admin: shop managers on a shop, administrators elsewhere. */
    public static function admin_cap(): string
    {
        return self::has_commerce() ? 'manage_woocommerce' : 'manage_options';
    }
}
