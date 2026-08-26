<?php
/**
 * The settings screen.
 *
 * Plain WordPress admin on purpose: this is a settings form, and a build step
 * plus a JavaScript app would buy nothing a store owner can see. The one piece
 * of scripting is the Test connection button, because finding out the key is
 * wrong from a customer is the failure this plugin most needs to avoid.
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

class Admin
{
    private const SLUG = 'shop-agent';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_wsa_save', [self::class, 'save']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_filter('plugin_action_links_' . plugin_basename(WSA_FILE), [self::class, 'action_links']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Shop Agent', 'woocommerce-shop-agent'),
            __('Shop Agent', 'woocommerce-shop-agent'),
            'manage_woocommerce',
            self::SLUG,
            [self::class, 'render'],
        );
    }

    public static function action_links(array $links): array
    {
        array_unshift($links, sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=' . self::SLUG)),
            esc_html__('Settings', 'woocommerce-shop-agent'),
        ));

        return $links;
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'woocommerce_page_' . self::SLUG) {
            return;
        }
        wp_enqueue_style('wsa-admin', WSA_URL . 'assets/admin.css', [], WSA_VERSION);
        wp_enqueue_script('wsa-admin', WSA_URL . 'assets/admin.js', [], WSA_VERSION, true);
        wp_localize_script('wsa-admin', 'wsaAdmin', [
            'endpoint' => esc_url_raw(rest_url('wsa/v1/test-key')),
            'nonce' => wp_create_nonce('wp_rest'),
            'testing' => __('Testing…', 'woocommerce-shop-agent'),
            'test' => __('Test connection', 'woocommerce-shop-agent'),
        ]);
    }

    public static function save(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to do that.', 'woocommerce-shop-agent'));
        }
        check_admin_referer('wsa_save');

        $posted = wp_unslash($_POST);

        // an unchanged field still holds the mask, which must never be saved
        // over the real key
        if (! Settings::key_is_constant() && isset($posted['api_key'])) {
            $submitted = trim((string) $posted['api_key']);
            if ($submitted !== '' && ! str_contains($submitted, '•')) {
                Settings::save_api_key(sanitize_text_field($submitted));
            } elseif ($submitted === '') {
                Settings::save_api_key('');
            }
        }

        $input = (array) $posted;
        $input['enabled'] = ! empty($posted['enabled']);
        Settings::update($input);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, 'updated' => '1'],
            admin_url('admin.php'),
        ));
        exit;
    }

    public static function render(): void
    {
        $s = Settings::all();
        $usage = Guards::usage();
        $failure = Provider::last_failure();
        ?>
        <div class="wrap wsa-wrap">
            <h1><?php esc_html_e('Shop Agent', 'woocommerce-shop-agent'); ?></h1>

            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'woocommerce-shop-agent'); ?></p></div>
            <?php endif; ?>

            <?php if (! Settings::ready()) : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e('The chat is not live yet. Add an OpenAI key and turn the chat on below.', 'woocommerce-shop-agent'); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($failure) : ?>
                <div class="notice notice-error">
                    <p>
                        <strong><?php esc_html_e('The last request to OpenAI failed.', 'woocommerce-shop-agent'); ?></strong>
                        <?php echo esc_html(sprintf('HTTP %d. %s', $failure['code'], $failure['message'])); ?>
                        <?php echo esc_html(sprintf(/* translators: %s: date and time */ __('(%s)', 'woocommerce-shop-agent'), $failure['at'])); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="wsa-usage">
                <div><span><?php esc_html_e('Calls today', 'woocommerce-shop-agent'); ?></span>
                    <strong><?php echo esc_html($usage['today'] . ' / ' . $usage['today_limit']); ?></strong></div>
                <div><span><?php esc_html_e('Calls this month', 'woocommerce-shop-agent'); ?></span>
                    <strong><?php echo esc_html($usage['month'] . ' / ' . $usage['month_limit']); ?></strong></div>
                <div><span><?php esc_html_e('In flight now', 'woocommerce-shop-agent'); ?></span>
                    <strong><?php echo esc_html((string) $usage['in_flight']); ?></strong></div>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wsa_save">
                <?php wp_nonce_field('wsa_save'); ?>

                <h2 class="title"><?php esc_html_e('Connection', 'woocommerce-shop-agent'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Chat on the storefront', 'woocommerce-shop-agent'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked((bool) $s['enabled']); ?>>
                                <?php esc_html_e('Show the chat to customers', 'woocommerce-shop-agent'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('It only appears once a working key is saved.', 'woocommerce-shop-agent'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wsa-key"><?php esc_html_e('OpenAI API key', 'woocommerce-shop-agent'); ?></label></th>
                        <td>
                            <?php if (Settings::key_is_constant()) : ?>
                                <p><code><?php esc_html_e('Set in wp-config.php', 'woocommerce-shop-agent'); ?></code></p>
                                <p class="description"><?php esc_html_e('WSA_OPENAI_KEY is defined, so this field is disabled and the constant wins.', 'woocommerce-shop-agent'); ?></p>
                            <?php else : ?>
                                <input type="text" id="wsa-key" name="api_key" class="regular-text" autocomplete="off"
                                    value="<?php echo esc_attr(Settings::masked_key()); ?>"
                                    placeholder="sk-...">
                                <p class="description">
                                    <?php esc_html_e('The store pays OpenAI directly for what the chat uses. The key is stored in this site\'s database, so any administrator can read it: on sites where that matters, define WSA_OPENAI_KEY in wp-config.php instead and this field disappears.', 'woocommerce-shop-agent'); ?>
                                </p>
                            <?php endif; ?>
                            <p>
                                <button type="button" class="button" id="wsa-test"><?php esc_html_e('Test connection', 'woocommerce-shop-agent'); ?></button>
                                <span id="wsa-test-result" class="wsa-test-result"></span>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wsa-model"><?php esc_html_e('Model', 'woocommerce-shop-agent'); ?></label></th>
                        <td>
                            <select name="model" id="wsa-model">
                                <?php foreach (Settings::models() as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($s['model'], $value); ?>>
                                        <?php echo esc_html($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Shop questions rarely need the expensive model. Start cheap.', 'woocommerce-shop-agent'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('What it may say', 'woocommerce-shop-agent'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wsa-facts"><?php esc_html_e('Store facts', 'woocommerce-shop-agent'); ?></label></th>
                        <td>
                            <textarea name="store_facts" id="wsa-facts" rows="7" class="large-text" placeholder="<?php esc_attr_e('Free shipping over 50. Returns within 14 days. Warranty is one year. Support hours 9-17, Sunday to Thursday.', 'woocommerce-shop-agent'); ?>"><?php echo esc_textarea($s['store_facts']); ?></textarea>
                            <p class="description"><?php esc_html_e('The only non-product information the agent may state as fact. Anything not written here, it will say it does not know. Write it in your store\'s language.', 'woocommerce-shop-agent'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wsa-rules"><?php esc_html_e('Voice', 'woocommerce-shop-agent'); ?></label></th>
                        <td>
                            <textarea name="style_rules" id="wsa-rules" rows="8" class="large-text"><?php echo esc_textarea($s['style_rules']); ?></textarea>
                            <p class="description"><?php esc_html_e('Sent with every conversation. This is what stops it sounding like a chatbot.', 'woocommerce-shop-agent'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Prices in the text', 'woocommerce-shop-agent'); ?></th>
                        <td>
                            <label><input type="radio" name="price_policy" value="cards_only" <?php checked($s['price_policy'], 'cards_only'); ?>>
                                <?php esc_html_e('Never written in the reply, shown on the product card only (recommended)', 'woocommerce-shop-agent'); ?></label><br>
                            <label><input type="radio" name="price_policy" value="allow" <?php checked($s['price_policy'], 'allow'); ?>>
                                <?php esc_html_e('The agent may state prices in its answer', 'woocommerce-shop-agent'); ?></label>
                            <p class="description"><?php esc_html_e('The card price is rendered from WooCommerce and is always correct. A price written into a sentence can be repeated later in the conversation after it has changed.', 'woocommerce-shop-agent'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('When it cannot help', 'woocommerce-shop-agent'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wsa-handoff-url"><?php esc_html_e('Contact link', 'woocommerce-shop-agent'); ?></label></th>
                        <td>
                            <input type="url" name="handoff_url" id="wsa-handoff-url" class="regular-text" value="<?php echo esc_attr($s['handoff_url']); ?>" placeholder="https://">
                            <p class="description"><?php esc_html_e('Your contact page, or a wa.me link for WhatsApp. Offered as a button when the agent is stuck.', 'woocommerce-shop-agent'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wsa-handoff-label"><?php esc_html_e('Button label', 'woocommerce-shop-agent'); ?></label></th>
                        <td><input type="text" name="handoff_label" id="wsa-handoff-label" class="regular-text" value="<?php echo esc_attr($s['handoff_label']); ?>" placeholder="<?php esc_attr_e('Talk to a person', 'woocommerce-shop-agent'); ?>"></td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Appearance', 'woocommerce-shop-agent'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wsa-title"><?php esc_html_e('Header title', 'woocommerce-shop-agent'); ?></label></th>
                        <td><input type="text" name="title" id="wsa-title" class="regular-text" value="<?php echo esc_attr($s['title']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wsa-subtitle"><?php esc_html_e('Header subtitle', 'woocommerce-shop-agent'); ?></label></th>
                        <td><input type="text" name="subtitle" id="wsa-subtitle" class="regular-text" value="<?php echo esc_attr($s['subtitle']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wsa-welcome"><?php esc_html_e('Opening message', 'woocommerce-shop-agent'); ?></label></th>
                        <td><textarea name="welcome" id="wsa-welcome" rows="2" class="large-text"><?php echo esc_textarea($s['welcome']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wsa-chips"><?php esc_html_e('Opening suggestions', 'woocommerce-shop-agent'); ?></label></th>
                        <td>
                            <textarea name="chips" id="wsa-chips" rows="4" class="large-text"><?php echo esc_textarea($s['chips']); ?></textarea>
                            <p class="description"><?php esc_html_e('One per line, up to four. Shown as buttons under the opening message.', 'woocommerce-shop-agent'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wsa-accent"><?php esc_html_e('Accent colour', 'woocommerce-shop-agent'); ?></label></th>
                        <td><input type="color" name="accent" id="wsa-accent" value="<?php echo esc_attr($s['accent']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Position', 'woocommerce-shop-agent'); ?></th>
                        <td>
                            <label><input type="radio" name="position" value="right" <?php checked($s['position'], 'right'); ?>> <?php esc_html_e('Bottom right', 'woocommerce-shop-agent'); ?></label>
                            <label style="margin-inline-start:16px"><input type="radio" name="position" value="left" <?php checked($s['position'], 'left'); ?>> <?php esc_html_e('Bottom left', 'woocommerce-shop-agent'); ?></label>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Spending limits', 'woocommerce-shop-agent'); ?></h2>
                <p class="description wsa-limits-note"><?php esc_html_e('Every conversation costs you money at OpenAI. These caps are what stands between a bored bot and a large invoice. Lower is safer.', 'woocommerce-shop-agent'); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    $limits = [
                        'limit_ip_burst' => [__('Messages per visitor, per 10 minutes', 'woocommerce-shop-agent'), ''],
                        'limit_ip_day' => [__('Messages per visitor, per day', 'woocommerce-shop-agent'), __('Stops one person using up the whole store\'s budget.', 'woocommerce-shop-agent')],
                        'limit_store_day' => [__('API calls for the whole store, per day', 'woocommerce-shop-agent'), __('The hard daily cost bound. One message can use two or three calls.', 'woocommerce-shop-agent')],
                        'limit_month' => [__('API calls for the whole store, per month', 'woocommerce-shop-agent'), ''],
                        'limit_concurrent' => [__('Requests at the same time', 'woocommerce-shop-agent'), __('Protects your server, not your wallet: slow answers must not tie up every PHP worker.', 'woocommerce-shop-agent')],
                    ];
                    foreach ($limits as $key => [$label, $help]) : ?>
                        <tr>
                            <th scope="row"><label for="wsa-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                            <td>
                                <input type="number" min="1" name="<?php echo esc_attr($key); ?>" id="wsa-<?php echo esc_attr($key); ?>" value="<?php echo esc_attr((string) $s[$key]); ?>" class="small-text">
                                <?php if ($help) : ?><p class="description"><?php echo esc_html($help); ?></p><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
