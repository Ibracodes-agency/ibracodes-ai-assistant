<?php
/**
 * Settings storage and sanitization.
 *
 * The API key lives in its own non-autoloaded option, separate from the
 * settings blob, and can be overridden by a WSA_OPENAI_KEY constant in
 * wp-config.php. On sites where "any administrator can read the key from the
 * database" is not acceptable, the constant is the answer, and the admin UI
 * says so rather than pretending the option is a secret store.
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

class Settings
{
    private const OPTION = 'wsa_settings';

    private const KEY_OPTION = 'wsa_openai_key';

    /** Cached per request: the widget and the agent both read these. */
    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'model' => 'gpt-5-mini',

            // what the agent is allowed to claim beyond the catalog
            'store_facts' => '',
            'style_rules' => self::default_style_rules(),

            // human handoff
            'handoff_label' => '',
            'handoff_url' => '',

            // widget presentation
            'show_launcher_label' => true,
            'launcher_label' => __('Ask us anything', 'woocommerce-shop-agent'),
            'title' => __('Have a question?', 'woocommerce-shop-agent'),
            'subtitle' => __('Ask me anything', 'woocommerce-shop-agent'),
            'welcome' => __('Hi, can I help you find a product or answer a question?', 'woocommerce-shop-agent'),
            'chips' => '',
            'accent' => '#111827',
            'position' => 'right',

            // behaviour
            'max_products' => 3,
            'only_in_stock' => true,
            'ask_first' => false,
            'excluded_cats' => [],

            // conversations
            'log_threads' => true,
            'retention_days' => 30,

            // guards
            'price_policy' => 'cards_only',
            'limit_ip_burst' => 15,
            'limit_ip_day' => 40,
            'limit_store_day' => 400,
            'limit_concurrent' => 5,
            'limit_month' => 5000,
        ];
    }

    public static function default_style_rules(): string
    {
        return implode("\n", [
            'Write like a human shop assistant, not a chatbot.',
            'Short sentences. A normal reply is one to three sentences, not a speech.',
            'No empty openers like "Certainly!" or "Great question".',
            'No emoji, no chains of exclamation marks.',
            'No numbered lists unless the customer asked for a comparison.',
            'Do not mention that you are an AI unless asked directly, and then keep it brief.',
            'When you are not sure, say so and point to the contact option instead of inventing an answer.',
        ]);
    }

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = array_merge(self::defaults(), (array) get_option(self::OPTION, []));
        }

        return self::$cache;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public static function update(array $input): array
    {
        $current = self::all();
        $clean = [];

        foreach (self::defaults() as $key => $default) {
            if (! array_key_exists($key, $input)) {
                $clean[$key] = $current[$key] ?? $default;

                continue;
            }
            $value = $input[$key];

            $clean[$key] = match ($key) {
                'enabled', 'only_in_stock', 'ask_first', 'log_threads', 'show_launcher_label' => (bool) $value,
                'max_products' => max(1, min(4, absint($value))),
                'retention_days' => max(1, min(365, absint($value))),
                'excluded_cats' => array_values(array_unique(array_filter(array_map('absint', (array) $value)))),
                'model' => array_key_exists($value, self::models()) ? $value : $default,
                'position' => $value === 'left' ? 'left' : 'right',
                'price_policy' => $value === 'allow' ? 'allow' : 'cards_only',
                'accent' => sanitize_hex_color((string) $value) ?: $default,
                'handoff_url' => esc_url_raw(trim((string) $value)),
                'store_facts', 'style_rules', 'chips' => sanitize_textarea_field((string) $value),
                // a limit of 0 would silently disable a guard, so floor at 1
                'limit_ip_burst', 'limit_ip_day', 'limit_store_day', 'limit_concurrent', 'limit_month' => max(1, absint($value)),
                default => sanitize_text_field((string) $value),
            };
        }

        update_option(self::OPTION, $clean);
        self::$cache = null;

        return self::all();
    }

    /** Allowed models. Filterable so a store can pin one this plugin has not heard of. */
    public static function models(): array
    {
        return apply_filters('wsa_models', [
            'gpt-5-mini' => __('gpt-5-mini (recommended, cheapest)', 'woocommerce-shop-agent'),
            'gpt-5' => __('gpt-5 (best answers, costs more)', 'woocommerce-shop-agent'),
            'gpt-4.1-mini' => __('gpt-4.1-mini', 'woocommerce-shop-agent'),
        ]);
    }

    // -----------------------------------------------------------------------
    // API key
    // -----------------------------------------------------------------------
    public static function api_key(): string
    {
        if (self::key_is_constant()) {
            return (string) constant('WSA_OPENAI_KEY');
        }

        return (string) get_option(self::KEY_OPTION, '');
    }

    public static function key_is_constant(): bool
    {
        return defined('WSA_OPENAI_KEY') && constant('WSA_OPENAI_KEY');
    }

    public static function save_api_key(string $key): void
    {
        $key = trim($key);
        if ($key === '') {
            delete_option(self::KEY_OPTION);

            return;
        }
        update_option(self::KEY_OPTION, $key, false);
    }

    /** Never render a key back into the page: the last four characters are enough to recognise it. */
    public static function masked_key(): string
    {
        $key = self::api_key();
        if ($key === '') {
            return '';
        }

        return str_repeat('•', 8) . substr($key, -4);
    }

    /** The widget only renders when the owner turned it on AND a key exists to answer with. */
    public static function ready(): bool
    {
        return (bool) self::get('enabled') && self::api_key() !== '';
    }

    /** Opening suggestion chips, one per line in the settings field, capped at four. */
    public static function opening_chips(): array
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) self::get('chips')) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines)));

        return array_slice($lines, 0, 4);
    }
}
