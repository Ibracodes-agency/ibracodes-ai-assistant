<?php
/**
 * The admin screen: the admin tabs, following the design file
 * (docs/admin-design.dc.html).
 *
 * Plain PHP rather than a JavaScript app. This is a settings form, a couple of
 * report panels and a conversation list, none of which need a client-side
 * framework, and every string stays translatable through the normal WordPress
 * pipeline instead of a separate JSON catalogue.
 */

namespace WSA;

if (! defined('ABSPATH')) {
    exit;
}

class Admin
{
    /** Public: the lead email links to the conversation and the leads tab. */
    public const SLUG = 'shop-agent';

    private const TABS = ['overview', 'appearance', 'agent', 'catalogue', 'conversations', 'live', 'leads'];

    /** Hook suffix of our page, wherever the menu put it, so assets() recognises the screen. */
    private static string $hook = '';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_wsa_save', [self::class, 'save']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_filter('plugin_action_links_' . plugin_basename(WSA_FILE), [self::class, 'action_links']);
    }

    public static function menu(): void
    {
        $label = __('AI Assistant', 'ibracodes-ai-assistant');
        if (Capabilities::has_commerce()) {
            self::$hook = (string) add_submenu_page('woocommerce', $label, $label, Capabilities::admin_cap(), self::SLUG, [self::class, 'render']);

            return;
        }
        self::$hook = (string) add_menu_page($label, $label, Capabilities::admin_cap(), self::SLUG, [self::class, 'render'], 'dashicons-format-chat', 58);
    }

    public static function action_links(array $links): array
    {
        array_unshift($links, sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::url()),
            esc_html__('Settings', 'ibracodes-ai-assistant'),
        ));

        return $links;
    }

    public static function assets(string $hook): void
    {
        if (self::$hook === '' || $hook !== self::$hook) {
            return;
        }
        // Heebo covers Latin and Hebrew from one family, which this admin needs
        // because a shop's own language decides what the owner types in here.
        wp_enqueue_style(
            'wsa-fonts',
            'https://fonts.googleapis.com/css2?family=Heebo:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap',
            [],
            null,
        );
        wp_enqueue_style('wsa-admin', WSA_URL . 'assets/admin.css', ['wsa-fonts'], WSA_VERSION);
        wp_enqueue_script('wsa-admin', WSA_URL . 'assets/admin.js', [], WSA_VERSION, true);
        wp_localize_script('wsa-admin', 'wsaAdmin', [
            'endpoint' => esc_url_raw(rest_url('wsa/v1/test-key')),
            'rebuildEndpoint' => esc_url_raw(rest_url('wsa/v1/rebuild-index')),
            'nonce' => wp_create_nonce('wp_rest'),
            'testing' => __('Testing…', 'ibracodes-ai-assistant'),
            'rebuilding' => __('Rebuilding…', 'ibracodes-ai-assistant'),
            'failed' => __('Request failed.', 'ibracodes-ai-assistant'),
        ]);

        // the console script only where the console is
        if (self::current_tab() === 'live' && Settings::live_ready()) {
            wp_enqueue_script('wsa-live', WSA_URL . 'assets/live.js', [], WSA_VERSION, true);
            wp_localize_script('wsa-live', 'wsaLive', [
                'open' => esc_url_raw(rest_url('wsa/v1/live/open')),
                'poll' => esc_url_raw(rest_url('wsa/v1/live/poll')),
                'claim' => esc_url_raw(rest_url('wsa/v1/live/claim')),
                'reply' => esc_url_raw(rest_url('wsa/v1/live/reply')),
                'close' => esc_url_raw(rest_url('wsa/v1/live/close')),
                'nonce' => wp_create_nonce('wp_rest'),
                'interval' => 3000,
                'me' => wp_get_current_user()->display_name,
                'i18n' => [
                    'states' => self::live_states(),
                    'empty' => __('No one is waiting.', 'ibracodes-ai-assistant'),
                    'noQuestion' => __('(no question recorded)', 'ibracodes-ai-assistant'),
                    'justNow' => __('Waiting under a minute', 'ibracodes-ai-assistant'),
                    /* translators: %s: number of minutes */
                    'waited' => __('Waiting %s min', 'ibracodes-ai-assistant'),
                    /* translators: %s: number of hours */
                    'waitedHours' => __('Waiting %s h', 'ibracodes-ai-assistant'),
                    /* translators: %s: number of days */
                    'waitedDays' => __('Waiting %s d', 'ibracodes-ai-assistant'),
                    /* translators: %s: number of unread visitor messages */
                    'unread' => __('%s unread', 'ibracodes-ai-assistant'),
                    'expired' => __('Session expired. Reload the page.', 'ibracodes-ai-assistant'),
                    'gone' => __('That conversation no longer exists.', 'ibracodes-ai-assistant'),
                    'claim' => __('Claim', 'ibracodes-ai-assistant'),
                    'close' => __('Close chat', 'ibracodes-ai-assistant'),
                    'send' => __('Send', 'ibracodes-ai-assistant'),
                    'reply' => __('Write a reply', 'ibracodes-ai-assistant'),
                    'claimFirst' => __('Claim the chat to reply', 'ibracodes-ai-assistant'),
                    /* translators: %s: the name of the manager who has the chat now */
                    'takeOver' => __('Replying takes over from %s', 'ibracodes-ai-assistant'),
                    'visitor' => __('Visitor', 'ibracodes-ai-assistant'),
                    'assistant' => __('Assistant', 'ibracodes-ai-assistant'),
                    'loading' => __('Loading…', 'ibracodes-ai-assistant'),
                    'failed' => __('Request failed.', 'ibracodes-ai-assistant'),
                ],
            ]);
        }
    }

    /** The state names the list and the pane show. */
    private static function live_states(): array
    {
        return [
            'waiting' => __('Waiting', 'ibracodes-ai-assistant'),
            'live' => __('Live', 'ibracodes-ai-assistant'),
            'missed' => __('Missed', 'ibracodes-ai-assistant'),
            'closed' => __('Closed', 'ibracodes-ai-assistant'),
            'ai' => __('AI', 'ibracodes-ai-assistant'),
        ];
    }

    /**
     * How long a visitor has been waiting: minutes up to two hours, then
     * hours up to two days, then days, in the words and the rounding the
     * console script uses too, so its first refresh changes nothing.
     */
    private static function waited(int $seconds): string
    {
        $minutes = intdiv($seconds, MINUTE_IN_SECONDS);
        if ($minutes < 1) {
            return __('Waiting under a minute', 'ibracodes-ai-assistant');
        }
        if ($minutes < 120) {
            /* translators: %s: number of minutes */
            return sprintf(__('Waiting %s min', 'ibracodes-ai-assistant'), number_format_i18n($minutes));
        }
        $hours = (int) round($minutes / 60);
        if ($hours < 48) {
            /* translators: %s: number of hours */
            return sprintf(__('Waiting %s h', 'ibracodes-ai-assistant'), number_format_i18n($hours));
        }

        /* translators: %s: number of days */
        return sprintf(__('Waiting %s d', 'ibracodes-ai-assistant'), number_format_i18n((int) round($hours / 24)));
    }

    private static function url(string $tab = '', array $extra = []): string
    {
        $args = array_merge(['page' => self::SLUG], $tab ? ['tab' => $tab] : [], $extra);

        return add_query_arg($args, admin_url('admin.php'));
    }

    private static function current_tab(): string
    {
        return self::valid_tab(isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview');
    }

    /** The tabs this site shows: Catalogue only exists on a shop. */
    private static function tabs(): array
    {
        return Capabilities::has_commerce() ? self::TABS : array_values(array_diff(self::TABS, ['catalogue']));
    }

    /** A tab from tabs(); anything else falls back to Overview. */
    private static function valid_tab(string $tab): string
    {
        return in_array($tab, self::tabs(), true) ? $tab : 'overview';
    }

    // -----------------------------------------------------------------------
    // Saving
    // -----------------------------------------------------------------------
    public static function save(): void
    {
        if (! current_user_can(Capabilities::admin_cap())) {
            wp_die(esc_html__('You are not allowed to do that.', 'ibracodes-ai-assistant'));
        }
        check_admin_referer('wsa_save');

        $posted = wp_unslash($_POST);
        $tab = self::valid_tab(isset($posted['tab']) ? sanitize_key($posted['tab']) : 'overview');

        // the leads tab holds no settings: its forms delete one lead or export them all
        if ($tab === 'leads') {
            if (! empty($posted['export_leads'])) {
                nocache_headers();
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="leads.csv"');
                self::csv_write(fopen('php://output', 'w'), self::csv_rows());
                exit;
            }
            $deleted = ! empty($posted['delete_lead']) && Leads::delete(absint($posted['delete_lead']));
            wp_safe_redirect(self::url('leads', $deleted ? ['deleted' => '1'] : []));
            exit;
        }

        // an unchanged field still holds the mask, which must never overwrite
        // the real key
        if (! Settings::key_is_constant() && isset($posted['api_key'])) {
            $submitted = trim((string) $posted['api_key']);
            if ($submitted === '') {
                Settings::save_api_key('');
            } elseif (! str_contains($submitted, '•')) {
                Settings::save_api_key(sanitize_text_field($submitted));
            }
        }

        // Checkboxes are absent from POST when off, so only the toggles this
        // tab actually rendered may be written; otherwise saving Appearance
        // would silently switch off everything on the Agent tab.
        $toggles = [
            'appearance' => ['show_launcher_label', 'show_credit'],
            'agent' => ['enabled', 'ask_first', 'leads_enabled', 'live_enabled'],
            'catalogue' => ['only_in_stock'],
            'conversations' => ['log_threads'],
        ];
        $input = $posted;
        foreach ($toggles[$tab] ?? [] as $key) {
            $input[$key] = ! empty($posted[$key]);
        }
        if ($tab === 'catalogue' && ! isset($posted['excluded_cats'])) {
            $input['excluded_cats'] = [];
        }
        if ($tab === 'agent') {
            if (! isset($posted['content_post_types'])) {
                $input['content_post_types'] = [];
            }
            if (isset($posted['content_pages'])) {
                // typed as "12, 40 41": ids separated by commas or spaces
                $input['content_pages'] = preg_split('/[\s,]+/', (string) $posted['content_pages'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
        }

        Settings::update($input);

        if ($tab === 'conversations' && ! empty($posted['purge_now'])) {
            DB::delete_all_threads();
        }

        wp_safe_redirect(self::url($tab, ['updated' => '1']));
        exit;
    }

    // -----------------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------------
    public static function render(): void
    {
        $tab = self::current_tab();
        $s = Settings::all();
        $leads_month = Leads::count_since(30);
        // the list, with its timeout pass, only where it is shown; every other
        // tab counts the waiting visitors for the badge and writes nothing
        $open = $tab === 'live' && Settings::live_ready() ? Live::open_threads() : [];
        $waiting = $tab === 'live'
            ? count(array_filter($open, static fn (array $row): bool => $row['status'] === 'waiting'))
            : (Settings::live_ready() ? Live::waiting_count() : 0);
        ?>
        <div class="wsa-admin">
            <?php self::band($tab, $leads_month, $waiting); ?>
            <div class="wsa-page">
                <?php
                self::notices();
                match ($tab) {
                    'appearance' => self::tab_appearance($s),
                    'agent' => self::tab_agent($s),
                    'catalogue' => self::tab_catalogue($s),
                    'conversations' => self::tab_conversations($s),
                    'live' => self::tab_live($open),
                    'leads' => self::tab_leads($s),
                    default => self::tab_overview($s, $leads_month),
                };
                ?>
            </div>
        </div>
        <?php
    }

    private static function band(string $tab, int $leads_month, int $waiting): void
    {
        $ready = Settings::ready();
        $threads = DB::stats(30);
        $catalogue = Capabilities::has_commerce() ? (int) (wp_count_posts('product')->publish ?? 0) : 0;
        ?>
        <div class="wsa-band">
            <div class="wsa-band-top">
                <div class="wsa-brand">
                    <div class="wsa-brand-mark">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#141519" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M20.5 12c0 4.1-3.8 7.4-8.5 7.4-1 0-2-.15-2.9-.42L4.4 20.5l1.1-3.3C4.1 15.85 3.5 14 3.5 12c0-4.1 3.8-7.4 8.5-7.4s8.5 3.3 8.5 7.4Z"></path>
                            <path d="M8.8 11.9h.01M12 11.9h.01M15.2 11.9h.01"></path>
                        </svg>
                    </div>
                    <div>
                        <div class="wsa-eyebrow">Ibracodes</div>
                        <h1 class="wsa-h1"><?php esc_html_e('AI Assistant', 'ibracodes-ai-assistant'); ?></h1>
                    </div>
                </div>
                <div class="wsa-band-actions">
                    <span class="wsa-live <?php echo $ready ? 'is-on' : 'is-off'; ?>">
                        <span class="wsa-live-dot"></span>
                        <?php echo esc_html($ready ? __('Live on the storefront', 'ibracodes-ai-assistant') : __('Not live', 'ibracodes-ai-assistant')); ?>
                    </span>
                </div>
            </div>
            <nav class="wsa-tabs">
                <?php
                $labels = [
                    'overview' => [__('Overview', 'ibracodes-ai-assistant'), ''],
                    'appearance' => [__('Appearance', 'ibracodes-ai-assistant'), ''],
                    'agent' => [__('Agent', 'ibracodes-ai-assistant'), $ready ? '' : __('Setup', 'ibracodes-ai-assistant')],
                    'catalogue' => [__('Catalogue', 'ibracodes-ai-assistant'), number_format_i18n($catalogue)],
                    'conversations' => [__('Conversations', 'ibracodes-ai-assistant'), $threads['threads'] ? number_format_i18n($threads['threads']) : ''],
                    'live' => [__('Live chats', 'ibracodes-ai-assistant'), $waiting ? number_format_i18n($waiting) : ''],
                    'leads' => [__('Leads', 'ibracodes-ai-assistant'), $leads_month ? number_format_i18n($leads_month) : ''],
                ];
                $labels = array_intersect_key($labels, array_flip(self::tabs()));
                foreach ($labels as $key => [$label, $badge]) : ?>
                    <a class="wsa-tab <?php echo $tab === $key ? 'is-on' : ''; ?>" href="<?php echo esc_url(self::url($key)); ?>">
                        <?php echo esc_html($label); ?>
                        <?php if ($badge !== '') : ?>
                            <span class="wsa-tab-badge"><?php echo esc_html($badge); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <?php
    }

    private static function notices(): void
    {
        if (isset($_GET['updated'])) {
            self::alert('good', '&#10003;', __('Settings saved.', 'ibracodes-ai-assistant'));
        }
        if (isset($_GET['deleted'])) {
            self::alert('good', '&#10003;', __('Lead deleted, along with its conversation.', 'ibracodes-ai-assistant'));
        }

        if (! Settings::ready()) {
            $why = Settings::api_key() === ''
                ? __('The agent is off because no API key is connected. Customers see nothing until you add one.', 'ibracodes-ai-assistant')
                : __('The agent is switched off. Customers see nothing until you turn it on.', 'ibracodes-ai-assistant');
            self::alert('warn', '!', $why, self::url('agent'), __('Connect now', 'ibracodes-ai-assistant'));
        }

        $failure = Provider::last_failure();
        if ($failure) {
            self::alert('bad', '!', sprintf(
                /* translators: 1: HTTP status code, 2: error message from OpenAI, 3: date and time */
                __('The last request to OpenAI failed with HTTP %1$d. %2$s (%3$s)', 'ibracodes-ai-assistant'),
                (int) $failure['code'],
                $failure['message'],
                $failure['at'],
            ));
        }
    }

    private static function alert(string $tone, string $mark, string $message, string $action_url = '', string $action_label = ''): void
    {
        ?>
        <div class="wsa-alert is-<?php echo esc_attr($tone); ?>">
            <span class="wsa-alert-mark"><?php echo esc_html(html_entity_decode($mark, ENT_QUOTES, 'UTF-8')); ?></span>
            <span><?php echo esc_html($message); ?></span>
            <?php if ($action_url) : ?>
                <a class="wsa-btn wsa-alert-action" href="<?php echo esc_url($action_url); ?>"><?php echo esc_html($action_label); ?></a>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function form_open(string $tab): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wsa_save">
            <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
            <?php wp_nonce_field('wsa_save'); ?>
        <?php
    }

    private static function save_bar(string $note = ''): void
    {
        ?>
            <div class="wsa-savebar">
                <span class="wsa-savebar-note"><?php echo esc_html($note); ?></span>
                <button type="submit" class="wsa-btn"><?php esc_html_e('Save settings', 'ibracodes-ai-assistant'); ?></button>
            </div>
        </form>
        <?php
    }

    private static function toggle(string $name, bool $on, string $title, string $help = ''): void
    {
        ?>
        <label class="wsa-toggle">
            <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($on); ?>>
            <span class="wsa-track"><span></span></span>
            <span>
                <span class="wsa-toggle-t"><?php echo esc_html($title); ?></span>
                <?php if ($help) : ?><span class="wsa-toggle-d"><br><?php echo esc_html($help); ?></span><?php endif; ?>
            </span>
        </label>
        <?php
    }

    // ----------------------------------------------------------- tab: overview
    private static function tab_overview(array $s, int $leads_month): void
    {
        $stats = DB::stats(30);
        $usage = Guards::usage();
        $unanswered = DB::unanswered(12);
        $commerce = Capabilities::has_commerce();
        $top = $commerce ? DB::top_products(30, 5) : [];

        $share = $usage['month_limit'] > 0 ? min(100, (int) round($usage['month'] / $usage['month_limit'] * 100)) : 0;
        $day_of_month = (int) current_time('j');
        $days_in_month = (int) gmdate('t');
        $projection = $day_of_month > 0 ? (int) round($usage['month'] / $day_of_month * $days_in_month) : 0;
        ?>
        <div class="wsa-stack">
            <div class="wsa-kpis">
                <?php
                $kpis = [
                    [__('Conversations', 'ibracodes-ai-assistant'), number_format_i18n($stats['threads']), __('last 30 days', 'ibracodes-ai-assistant')],
                    [__('Replies sent', 'ibracodes-ai-assistant'), number_format_i18n($stats['turns']), __('last 30 days', 'ibracodes-ai-assistant')],
                ];
                if ($commerce) {
                    $kpis[] = [__('Products shown', 'ibracodes-ai-assistant'), number_format_i18n($stats['products']), __('recommendations made', 'ibracodes-ai-assistant')];
                    $kpis[] = [__('Added to cart', 'ibracodes-ai-assistant'), number_format_i18n($stats['carts']), __('chats that led to a cart', 'ibracodes-ai-assistant')];
                }
                $kpis[] = [__('Leads, 30 days', 'ibracodes-ai-assistant'), number_format_i18n($leads_month), __('visitors who left their details', 'ibracodes-ai-assistant')];
                foreach ($kpis as [$k, $v, $t]) : ?>
                    <div class="wsa-kpi">
                        <div class="wsa-kpi-k"><?php echo esc_html($k); ?></div>
                        <div class="wsa-kpi-v"><?php echo esc_html($v); ?></div>
                        <div class="wsa-kpi-t"><?php echo esc_html($t); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="wsa-g2">
                <div class="wsa-card">
                    <div class="wsa-card-head">
                        <div>
                            <h2 class="wsa-card-title"><?php esc_html_e('Questions the agent could not answer', 'ibracodes-ai-assistant'); ?></h2>
                            <p class="wsa-card-sub"><?php esc_html_e('The catalogue search came back empty. Each one is a product you do not stock, or a word your product titles never use.', 'ibracodes-ai-assistant'); ?></p>
                        </div>
                    </div>
                    <?php if (! $unanswered) : ?>
                        <div class="wsa-empty"><?php esc_html_e('Nothing yet. Unanswered questions show up here as customers ask them.', 'ibracodes-ai-assistant'); ?></div>
                    <?php else : ?>
                        <div class="wsa-rows">
                            <?php foreach ($unanswered as $row) : ?>
                                <div class="wsa-row" style="grid-template-columns:minmax(0,1fr) auto;">
                                    <div class="wsa-truncate">
                                        <a href="<?php echo esc_url(self::url('conversations', ['thread' => (int) $row['thread_id']])); ?>"><?php echo esc_html($row['question']); ?></a>
                                    </div>
                                    <div class="wsa-num" style="color:var(--muted);font-size:12.5px;">
                                        <?php echo esc_html(self::ago($row['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="wsa-stack">
                    <div class="wsa-card">
                        <h2 class="wsa-card-title"><?php esc_html_e('Monthly usage', 'ibracodes-ai-assistant'); ?></h2>
                        <div class="wsa-kpi-v"><?php echo esc_html(number_format_i18n($usage['month'])); ?></div>
                        <div class="wsa-kpi-t">
                            <?php
                            printf(
                                /* translators: %s: the monthly cap on API calls */
                                esc_html__('of %s API calls', 'ibracodes-ai-assistant'),
                                esc_html(number_format_i18n($usage['month_limit'])),
                            );
                            ?>
                        </div>
                        <div class="wsa-meter">
                            <div class="wsa-meter-fill <?php echo $share > 90 ? 'is-bad' : ($share > 70 ? 'is-warn' : ''); ?>" style="width:<?php echo esc_attr((string) $share); ?>%"></div>
                        </div>
                        <p class="wsa-help">
                            <?php
                            printf(
                                /* translators: 1: calls used today, 2: daily cap, 3: projected month total */
                                esc_html__('%1$s today of %2$s. At this pace the month ends near %3$s calls.', 'ibracodes-ai-assistant'),
                                esc_html(number_format_i18n($usage['today'])),
                                esc_html(number_format_i18n($usage['today_limit'])),
                                esc_html(number_format_i18n($projection)),
                            );
                            ?>
                        </p>
                    </div>

                    <?php if ($commerce) : ?>
                        <div class="wsa-card">
                            <h2 class="wsa-card-title"><?php esc_html_e('Top products shown', 'ibracodes-ai-assistant'); ?></h2>
                            <?php if (! $top) : ?>
                                <div class="wsa-empty"><?php esc_html_e('No recommendations yet.', 'ibracodes-ai-assistant'); ?></div>
                            <?php else : ?>
                                <?php foreach ($top as $product_id => $count) :
                                    $product = wc_get_product($product_id);
                                    if (! $product) {
                                        continue;
                                    }
                                    $image = wp_get_attachment_image_url($product->get_image_id(), 'thumbnail');
                                    ?>
                                    <div class="wsa-mini">
                                        <?php if ($image) : ?>
                                            <img src="<?php echo esc_url($image); ?>" alt="">
                                        <?php else : ?>
                                            <span class="wsa-thumb"></span>
                                        <?php endif; ?>
                                        <span class="wsa-mini-n wsa-truncate"><?php echo esc_html($product->get_name()); ?></span>
                                        <span class="wsa-mini-c wsa-num"><?php echo esc_html(number_format_i18n($count)); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    private static function ago(string $mysql_date): string
    {
        $timestamp = strtotime($mysql_date);
        if (! $timestamp) {
            return '';
        }

        return sprintf(
            /* translators: %s: human readable time difference */
            __('%s ago', 'ibracodes-ai-assistant'),
            human_time_diff($timestamp, (int) current_time('timestamp')),
        );
    }

    // --------------------------------------------------------- tab: appearance
    private static function tab_appearance(array $s): void
    {
        self::form_open('appearance');
        ?>
        <div class="wsa-stack">
            <div class="wsa-card">
                <div class="wsa-card-head">
                    <div>
                        <h2 class="wsa-card-title"><?php esc_html_e('Launcher', 'ibracodes-ai-assistant'); ?></h2>
                        <p class="wsa-card-sub"><?php esc_html_e('How the closed widget appears on the storefront.', 'ibracodes-ai-assistant'); ?></p>
                    </div>
                </div>

                <div class="wsa-field">
                    <span class="wsa-label"><?php esc_html_e('Corner', 'ibracodes-ai-assistant'); ?></span>
                    <div class="wsa-choices">
                        <?php foreach ([
                            'right' => __('Bottom right', 'ibracodes-ai-assistant'),
                            'left' => __('Bottom left', 'ibracodes-ai-assistant'),
                        ] as $value => $label) : ?>
                            <label class="wsa-choice">
                                <input type="radio" name="position" value="<?php echo esc_attr($value); ?>" <?php checked($s['position'], $value); ?>>
                                <span class="wsa-choice-t"><?php echo esc_html($label); ?></span>
                                <span class="wsa-choice-d"><?php esc_html_e('side of the screen', 'ibracodes-ai-assistant'); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php self::toggle('show_launcher_label', (bool) $s['show_launcher_label'], __('Show a label next to the icon', 'ibracodes-ai-assistant'), __('A labelled pill gets noticed more than a bare bubble. It collapses to a circle on phones either way.', 'ibracodes-ai-assistant')); ?>

                <div class="wsa-field" style="margin-top:16px;">
                    <label class="wsa-label" for="wsa-launcher-label"><?php esc_html_e('Label', 'ibracodes-ai-assistant'); ?></label>
                    <input class="fld" type="text" id="wsa-launcher-label" name="launcher_label" value="<?php echo esc_attr($s['launcher_label']); ?>">
                </div>

                <div class="wsa-field">
                    <label class="wsa-label" for="wsa-accent"><?php esc_html_e('Accent colour', 'ibracodes-ai-assistant'); ?></label>
                    <input type="color" id="wsa-accent" name="accent" value="<?php echo esc_attr($s['accent']); ?>">
                    <p class="wsa-help"><?php esc_html_e('Used for the launcher, the customer\'s own messages and the add-to-cart button.', 'ibracodes-ai-assistant'); ?></p>
                </div>
            </div>

            <div class="wsa-card">
                <div class="wsa-card-head">
                    <div>
                        <h2 class="wsa-card-title"><?php esc_html_e('Copy', 'ibracodes-ai-assistant'); ?></h2>
                        <p class="wsa-card-sub"><?php esc_html_e('Write these in your store\'s language. The agent replies in it automatically.', 'ibracodes-ai-assistant'); ?></p>
                    </div>
                </div>
                <?php
                self::text_field('title', __('Header title', 'ibracodes-ai-assistant'), $s['title']);
                self::text_field('subtitle', __('Header subtitle', 'ibracodes-ai-assistant'), $s['subtitle']);
                ?>
                <div class="wsa-field">
                    <label class="wsa-label" for="wsa-welcome"><?php esc_html_e('Opening message', 'ibracodes-ai-assistant'); ?></label>
                    <textarea class="fld" id="wsa-welcome" name="welcome" rows="2" style="min-height:64px;"><?php echo esc_textarea($s['welcome']); ?></textarea>
                </div>
                <div class="wsa-field">
                    <label class="wsa-label" for="wsa-chips"><?php esc_html_e('Opening chips', 'ibracodes-ai-assistant'); ?></label>
                    <textarea class="fld" id="wsa-chips" name="chips" rows="4" style="min-height:92px;"><?php echo esc_textarea($s['chips']); ?></textarea>
                    <p class="wsa-help"><?php esc_html_e('One per line, up to four. Shown as buttons under the opening message.', 'ibracodes-ai-assistant'); ?></p>
                </div>
                <div style="margin-top:16px;">
                    <?php self::toggle('show_credit', (bool) $s['show_credit'], __('Show "Developed by Ibracodes" under the chat', 'ibracodes-ai-assistant'), __('A small credit line linking to ibracodes.com. Off by default.', 'ibracodes-ai-assistant')); ?>
                </div>
            </div>
        </div>
        <?php
        self::save_bar(__('Changes go live on the storefront as soon as you save.', 'ibracodes-ai-assistant'));
    }

    private static function text_field(string $name, string $label, string $value, string $help = '', string $type = 'text'): void
    {
        $id = 'wsa-' . str_replace('_', '-', $name);
        ?>
        <div class="wsa-field">
            <label class="wsa-label" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
            <input class="fld" type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
            <?php if ($help) : ?><p class="wsa-help"><?php echo esc_html($help); ?></p><?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------- tab: agent
    private static function tab_agent(array $s): void
    {
        self::form_open('agent');
        ?>
        <div class="wsa-stack">
            <div class="wsa-card">
                <div class="wsa-card-head">
                    <div>
                        <h2 class="wsa-card-title"><?php esc_html_e('Connection', 'ibracodes-ai-assistant'); ?></h2>
                        <p class="wsa-card-sub"><?php esc_html_e('The store uses its own OpenAI account and pays OpenAI directly for what the chat uses.', 'ibracodes-ai-assistant'); ?></p>
                    </div>
                </div>

                <?php self::toggle('enabled', (bool) $s['enabled'], __('Show the chat to customers', 'ibracodes-ai-assistant'), __('It only appears once a working key is saved.', 'ibracodes-ai-assistant')); ?>

                <div class="wsa-field" style="margin-top:16px;">
                    <label class="wsa-label" for="wsa-key"><?php esc_html_e('OpenAI API key', 'ibracodes-ai-assistant'); ?></label>
                    <?php if (Settings::key_is_constant()) : ?>
                        <p><span class="wsa-pill is-good"><?php esc_html_e('Set in wp-config.php', 'ibracodes-ai-assistant'); ?></span></p>
                        <p class="wsa-help"><?php esc_html_e('WSA_OPENAI_KEY is defined, so the constant wins and this field is hidden.', 'ibracodes-ai-assistant'); ?></p>
                    <?php else : ?>
                        <input class="fld is-mono" type="text" id="wsa-key" name="api_key" autocomplete="off" spellcheck="false"
                            value="<?php echo esc_attr(Settings::masked_key()); ?>" placeholder="sk-...">
                        <p class="wsa-help"><?php esc_html_e('Stored in this site\'s database, so any administrator can read it. Where that matters, define WSA_OPENAI_KEY in wp-config.php instead.', 'ibracodes-ai-assistant'); ?></p>
                    <?php endif; ?>
                    <p style="margin-top:10px;">
                        <button type="button" class="wsa-btn is-ghost" id="wsa-test"><?php esc_html_e('Test connection', 'ibracodes-ai-assistant'); ?></button>
                        <span id="wsa-test-result" class="wsa-test-result"></span>
                    </p>
                </div>

                <div class="wsa-field">
                    <span class="wsa-label"><?php esc_html_e('Model', 'ibracodes-ai-assistant'); ?></span>
                    <div class="wsa-choices">
                        <?php foreach (Settings::models() as $value => $label) : ?>
                            <label class="wsa-choice">
                                <input type="radio" name="model" value="<?php echo esc_attr($value); ?>" <?php checked($s['model'], $value); ?>>
                                <span class="wsa-choice-t"><?php echo esc_html($label); ?></span>
                                <span class="wsa-choice-d"><?php echo esc_html($value); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="wsa-help"><?php esc_html_e('Shop questions rarely need the expensive model. Start cheap and move up only if answers disappoint.', 'ibracodes-ai-assistant'); ?></p>
                </div>
            </div>

            <div class="wsa-card">
                <div class="wsa-card-head">
                    <div>
                        <h2 class="wsa-card-title"><?php esc_html_e('House rules', 'ibracodes-ai-assistant'); ?></h2>
                        <p class="wsa-card-sub"><?php esc_html_e('Plain sentences work better than a wall of instructions. Sent with every conversation.', 'ibracodes-ai-assistant'); ?></p>
                    </div>
                </div>
                <div class="wsa-field">
                    <textarea class="fld" name="style_rules" rows="8" aria-label="<?php esc_attr_e('House rules', 'ibracodes-ai-assistant'); ?>"><?php echo esc_textarea($s['style_rules']); ?></textarea>
                </div>
                <div class="wsa-field">
                    <label class="wsa-label" for="wsa-facts"><?php esc_html_e('Store facts', 'ibracodes-ai-assistant'); ?></label>
                    <textarea class="fld" id="wsa-facts" name="store_facts" rows="6" placeholder="<?php esc_attr_e('Free shipping over 50. Returns within 14 days. One year warranty.', 'ibracodes-ai-assistant'); ?>"><?php echo esc_textarea($s['store_facts']); ?></textarea>
                    <p class="wsa-help"><?php esc_html_e('The only non-product information the agent may state as fact. Anything not written here, it will say it does not know.', 'ibracodes-ai-assistant'); ?></p>
                </div>
            </div>

            <div class="wsa-card">
                <div class="wsa-card-head">
                    <div>
                        <h2 class="wsa-card-title"><?php esc_html_e('Content', 'ibracodes-ai-assistant'); ?></h2>
                        <p class="wsa-card-sub"><?php esc_html_e('The pages and posts the assistant may read and answer from. Drafts, private and password-protected content are never included.', 'ibracodes-ai-assistant'); ?></p>
                    </div>
                </div>

                <div class="wsa-field">
                    <span class="wsa-label"><?php esc_html_e('Content types', 'ibracodes-ai-assistant'); ?></span>
                    <div class="wsa-checks">
                        <?php
                        $post_types = array_map('strval', (array) $s['content_post_types']);
                        foreach (Settings::indexable_post_types() as $type) :
                            $object = get_post_type_object($type);
                            ?>
                            <label class="wsa-check">
                                <input type="checkbox" name="content_post_types[]" value="<?php echo esc_attr($type); ?>" <?php checked(in_array($type, $post_types, true)); ?>>
                                <span><?php echo esc_html($object ? $object->labels->name : $type); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="wsa-field">
                    <span class="wsa-label"><?php esc_html_e('Which pages', 'ibracodes-ai-assistant'); ?></span>
                    <div class="wsa-choices">
                        <?php foreach ([
                            'all' => __('All published pages and posts', 'ibracodes-ai-assistant'),
                            'selected' => __('Only the pages listed below', 'ibracodes-ai-assistant'),
                        ] as $value => $label) : ?>
                            <label class="wsa-choice">
                                <input type="radio" name="content_scope" value="<?php echo esc_attr($value); ?>" <?php checked($s['content_scope'], $value); ?>>
                                <span class="wsa-choice-t"><?php echo esc_html($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php
                self::text_field(
                    'content_pages',
                    __('Pages', 'ibracodes-ai-assistant'),
                    implode(', ', array_map('strval', (array) $s['content_pages'])),
                    __('Page ids, comma separated. The id is in the address bar when you edit a page.', 'ibracodes-ai-assistant'),
                );
                ?>

                <div class="wsa-field">
                    <span class="wsa-label"><?php esc_html_e('How the assistant finds content', 'ibracodes-ai-assistant'); ?></span>
                    <div class="wsa-choices">
                        <?php foreach ([
                            'search' => __('WordPress search (free)', 'ibracodes-ai-assistant'),
                            'embeddings' => __('Embeddings index (better answers)', 'ibracodes-ai-assistant'),
                        ] as $value => $label) : ?>
                            <label class="wsa-choice">
                                <input type="radio" name="retrieval" value="<?php echo esc_attr($value); ?>" <?php checked($s['retrieval'], $value); ?>>
                                <span class="wsa-choice-t"><?php echo esc_html($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="wsa-help"><?php esc_html_e('Building the index costs about one cent per hundred pages once, then a fraction of that per question. It uses your OpenAI key and counts against your daily and monthly limits.', 'ibracodes-ai-assistant'); ?></p>
                </div>

                <?php if (Index::enabled()) :
                    $status = Index::status();
                    ?>
                    <div class="wsa-field">
                        <p class="wsa-help" id="wsa-index-status">
                            <?php
                            printf(
                                /* translators: 1: pages indexed, 2: pages in scope, 3: pages waiting in the queue */
                                esc_html__('Indexed %1$s of %2$s pages, %3$s waiting.', 'ibracodes-ai-assistant'),
                                esc_html(number_format_i18n($status['posts'])),
                                esc_html(number_format_i18n($status['total'])),
                                esc_html(number_format_i18n($status['pending'])),
                            );
                            ?>
                        </p>
                        <p style="margin-top:10px;">
                            <button type="button" class="wsa-btn is-ghost" id="wsa-rebuild"><?php esc_html_e('Rebuild index', 'ibracodes-ai-assistant'); ?></button>
                            <span id="wsa-rebuild-result" class="wsa-test-result"></span>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="wsa-card">
                <div class="wsa-card-head">
                    <div><h2 class="wsa-card-title"><?php esc_html_e('Leads', 'ibracodes-ai-assistant'); ?></h2></div>
                </div>
                <?php self::toggle('leads_enabled', (bool) $s['leads_enabled'], __('Offer to take the visitor\'s details', 'ibracodes-ai-assistant'), __('The assistant asks for a name and a phone or email, saves the lead and emails you.', 'ibracodes-ai-assistant')); ?>
                <div class="wsa-field" style="margin-top:16px;">
                    <label class="wsa-label" for="wsa-leads-when"><?php esc_html_e('When to offer', 'ibracodes-ai-assistant'); ?></label>
                    <input class="fld" type="text" id="wsa-leads-when" name="leads_when" value="<?php echo esc_attr($s['leads_when']); ?>">
                    <p class="wsa-help"><?php esc_html_e('One line, for example: when someone wants a quote or a callback.', 'ibracodes-ai-assistant'); ?></p>
                </div>
                <?php self::text_field('leads_email', __('Send leads to', 'ibracodes-ai-assistant'), $s['leads_email'], '', 'email'); ?>
                <div class="wsa-field">
                    <label class="wsa-label" for="wsa-leads-retention-days"><?php esc_html_e('Keep leads for (days)', 'ibracodes-ai-assistant'); ?></label>
                    <input class="fld" style="max-width:110px;" type="number" min="1" max="365" id="wsa-leads-retention-days" name="leads_retention_days" value="<?php echo esc_attr((string) $s['leads_retention_days']); ?>">
                    <p class="wsa-help"><?php esc_html_e('Older leads are deleted once a day, along with the conversation they came from.', 'ibracodes-ai-assistant'); ?></p>
                </div>
                <div class="wsa-field">
                    <label class="wsa-label" for="wsa-privacy-note"><?php esc_html_e('Note under the chat input', 'ibracodes-ai-assistant'); ?></label>
                    <input class="fld" type="text" id="wsa-privacy-note" name="privacy_note" maxlength="240" value="<?php echo esc_attr($s['privacy_note']); ?>">
                    <p class="wsa-help"><?php esc_html_e('Say that details typed here are passed to the site owner and kept with the conversation.', 'ibracodes-ai-assistant'); ?></p>
                </div>
            </div>

            <div class="wsa-card">
                <div class="wsa-card-head">
                    <div><h2 class="wsa-card-title"><?php esc_html_e('Behaviour', 'ibracodes-ai-assistant'); ?></h2></div>
                </div>
                <div class="wsa-field">
                    <label class="wsa-label" for="wsa-max-products"><?php esc_html_e('Products per reply', 'ibracodes-ai-assistant'); ?></label>
                    <input class="fld" style="max-width:110px;" type="number" min="1" max="4" id="wsa-max-products" name="max_products" value="<?php echo esc_attr((string) $s['max_products']); ?>">
                    <p class="wsa-help"><?php esc_html_e('Three or fewer keeps a reply readable on a phone.', 'ibracodes-ai-assistant'); ?></p>
                </div>
                <?php
                self::toggle('ask_first', (bool) $s['ask_first'], __('Ask one question before recommending', 'ibracodes-ai-assistant'), __('On a vague request the agent asks a single clarifying question first. Better matches, one extra exchange.', 'ibracodes-ai-assistant'));
                ?>
                <div class="wsa-field" style="margin-top:16px;">
                    <span class="wsa-label"><?php esc_html_e('Prices in the reply text', 'ibracodes-ai-assistant'); ?></span>
                    <div class="wsa-choices">
                        <label class="wsa-choice">
                            <input type="radio" name="price_policy" value="cards_only" <?php checked($s['price_policy'], 'cards_only'); ?>>
                            <span class="wsa-choice-t"><?php esc_html_e('Card only', 'ibracodes-ai-assistant'); ?></span>
                            <span class="wsa-choice-d"><?php esc_html_e('recommended', 'ibracodes-ai-assistant'); ?></span>
                        </label>
                        <label class="wsa-choice">
                            <input type="radio" name="price_policy" value="allow" <?php checked($s['price_policy'], 'allow'); ?>>
                            <span class="wsa-choice-t"><?php esc_html_e('Allow in text', 'ibracodes-ai-assistant'); ?></span>
                            <span class="wsa-choice-d"><?php esc_html_e('may go stale', 'ibracodes-ai-assistant'); ?></span>
                        </label>
                    </div>
                    <p class="wsa-help"><?php esc_html_e('The card price is rendered from WooCommerce and is always correct. A price written into a sentence can be repeated later after it changed.', 'ibracodes-ai-assistant'); ?></p>
                </div>
            </div>

            <div class="wsa-card">
                <div class="wsa-card-head">
                    <div>
                        <h2 class="wsa-card-title"><?php esc_html_e('Handoff to a person', 'ibracodes-ai-assistant'); ?></h2>
                        <p class="wsa-card-sub"><?php esc_html_e('Offered as a button when the agent cannot help.', 'ibracodes-ai-assistant'); ?></p>
                    </div>
                </div>
                <?php
                self::text_field('handoff_label', __('Chip label', 'ibracodes-ai-assistant'), $s['handoff_label']);
                self::text_field('handoff_url', __('Destination', 'ibracodes-ai-assistant'), $s['handoff_url'], __('Your contact page, or a wa.me link for WhatsApp.', 'ibracodes-ai-assistant'), 'url');
                ?>
            </div>

            <div class="wsa-card">
                <div class="wsa-card-head">
                    <div>
                        <h2 class="wsa-card-title"><?php esc_html_e('Live chat', 'ibracodes-ai-assistant'); ?></h2>
                        <p class="wsa-card-sub"><?php esc_html_e('A visitor who asks for a person waits for you in the Live chats tab. The AI pauses until you answer or the wait runs out.', 'ibracodes-ai-assistant'); ?></p>
                    </div>
                </div>
                <?php self::toggle('live_enabled', (bool) $s['live_enabled'], __('Let visitors ask for a person', 'ibracodes-ai-assistant'), __('Turning it on also turns conversation logging on: a live chat lives on the conversation record.', 'ibracodes-ai-assistant')); ?>
                <div style="margin-top:16px;">
                    <?php
                    // resolved on save; until then, the field shows where a request would go
                    self::text_field('live_email', __('Send requests to', 'ibracodes-ai-assistant'), Settings::live_email(), __('One email per request, with a link to the conversation.', 'ibracodes-ai-assistant'), 'email');
                    ?>
                </div>
                <div class="wsa-field">
                    <label class="wsa-label" for="wsa-live-wait-minutes"><?php esc_html_e('Wait for a person (minutes)', 'ibracodes-ai-assistant'); ?></label>
                    <input class="fld" style="max-width:110px;" type="number" min="1" max="60" id="wsa-live-wait-minutes" name="live_wait_minutes" value="<?php echo esc_attr((string) $s['live_wait_minutes']); ?>">
                    <p class="wsa-help"><?php esc_html_e('If nobody joins in time, the visitor is offered lead capture and the contact option. The request stays in the list, so you can still answer later.', 'ibracodes-ai-assistant'); ?></p>
                </div>
                <?php
                self::text_field('live_text_waiting', __('Waiting text', 'ibracodes-ai-assistant'), $s['live_text_waiting']);
                self::text_field('live_text_joined', __('Joined text', 'ibracodes-ai-assistant'), $s['live_text_joined']);
                self::text_field('live_text_missed', __('Missed text', 'ibracodes-ai-assistant'), $s['live_text_missed']);
                self::text_field(
                    'live_text_closed',
                    __('Closed text', 'ibracodes-ai-assistant'),
                    $s['live_text_closed'],
                    /* translators: %s is literal here: the placeholder the owner writes into the joined and closed texts */
                    __('In the joined and closed texts, %s becomes the name of the person who joined.', 'ibracodes-ai-assistant'),
                );
                ?>
            </div>

            <div class="wsa-card">
                <div class="wsa-card-head">
                    <div>
                        <h2 class="wsa-card-title"><?php esc_html_e('Spending limits', 'ibracodes-ai-assistant'); ?></h2>
                        <p class="wsa-card-sub"><?php esc_html_e('Every conversation costs you money at OpenAI. These caps are what stands between a bored bot and a large invoice. One reply can use two or three calls.', 'ibracodes-ai-assistant'); ?></p>
                    </div>
                </div>
                <div class="wsa-g2-even">
                    <?php
                    $limits = [
                        'limit_ip_burst' => [__('Messages per visitor, per 10 minutes', 'ibracodes-ai-assistant'), ''],
                        'limit_ip_day' => [__('Messages per visitor, per day', 'ibracodes-ai-assistant'), __('Stops one person using up the store\'s budget.', 'ibracodes-ai-assistant')],
                        'limit_store_day' => [__('API calls for the whole store, per day', 'ibracodes-ai-assistant'), __('The hard daily cost bound.', 'ibracodes-ai-assistant')],
                        'limit_month' => [__('API calls for the whole store, per month', 'ibracodes-ai-assistant'), ''],
                        'limit_concurrent' => [__('Requests at the same time', 'ibracodes-ai-assistant'), __('Protects your server rather than your wallet.', 'ibracodes-ai-assistant')],
                    ];
                    foreach ($limits as $key => [$label, $help]) : ?>
                        <div class="wsa-field">
                            <label class="wsa-label" for="wsa-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                            <input class="fld" style="max-width:130px;" type="number" min="1" id="wsa-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr((string) $s[$key]); ?>">
                            <?php if ($help) : ?><p class="wsa-help"><?php echo esc_html($help); ?></p><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
        self::save_bar();
    }

    // ---------------------------------------------------------- tab: catalogue
    private static function tab_catalogue(array $s): void
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 200]);
        $terms = is_wp_error($terms) ? [] : $terms;
        $excluded = array_map('absint', (array) $s['excluded_cats']);
        self::form_open('catalogue');
        ?>
        <div class="wsa-stack">
            <div class="wsa-card">
                <div class="wsa-card-head">
                    <div>
                        <h2 class="wsa-card-title"><?php esc_html_e('Which products the agent may recommend', 'ibracodes-ai-assistant'); ?></h2>
                        <p class="wsa-card-sub"><?php esc_html_e('Exclude anything you do not want a bot selling unattended. Excluded categories are invisible to the agent: it cannot find them, mention them or link to them.', 'ibracodes-ai-assistant'); ?></p>
                    </div>
                </div>

                <?php self::toggle('only_in_stock', (bool) $s['only_in_stock'], __('Only recommend products in stock', 'ibracodes-ai-assistant'), __('Off means the agent may show out-of-stock products, marked as such on the card.', 'ibracodes-ai-assistant')); ?>

                <div class="wsa-field" style="margin-top:18px;">
                    <span class="wsa-label"><?php esc_html_e('Excluded categories', 'ibracodes-ai-assistant'); ?></span>
                    <?php if (! $terms) : ?>
                        <p class="wsa-help"><?php esc_html_e('This store has no product categories yet.', 'ibracodes-ai-assistant'); ?></p>
                    <?php else : ?>
                        <div class="wsa-checks">
                            <?php foreach ($terms as $term) : ?>
                                <label class="wsa-check">
                                    <input type="checkbox" name="excluded_cats[]" value="<?php echo esc_attr((string) $term->term_id); ?>" <?php checked(in_array((int) $term->term_id, $excluded, true)); ?>>
                                    <span><?php echo esc_html($term->name); ?></span>
                                    <span style="color:var(--muted);font-size:12px;">(<?php echo esc_html(number_format_i18n($term->count)); ?>)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="wsa-help"><?php esc_html_e('Child categories are excluded along with their parent.', 'ibracodes-ai-assistant'); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="wsa-card">
                <h2 class="wsa-card-title"><?php esc_html_e('How the agent searches', 'ibracodes-ai-assistant'); ?></h2>
                <p class="wsa-card-sub" style="margin-bottom:0;">
                    <?php esc_html_e('It searches your live catalogue on every question: product titles, descriptions and SKUs. There is no index to build and nothing to keep in sync, so a product you publish is findable immediately, and one you unpublish disappears the same second.', 'ibracodes-ai-assistant'); ?>
                </p>
            </div>
        </div>
        <?php
        self::save_bar();
    }

    // -------------------------------------------------------------- tab: leads
    // --------------------------------------------------------------- tab: live
    /**
     * The console: the open threads on one side, one conversation on the
     * other. The list is rendered here so the page is useful before the
     * script runs; the script rebuilds it from the same data and fills the
     * pane. Without live chat there is nothing to list, only the way to it.
     */
    private static function tab_live(array $open): void
    {
        if (! Settings::live_ready()) {
            ?>
            <div class="wsa-card" id="wsa-live-off">
                <div class="wsa-card-head">
                    <div>
                        <h2 class="wsa-card-title"><?php esc_html_e('Live chats', 'ibracodes-ai-assistant'); ?></h2>
                        <p class="wsa-card-sub"><?php esc_html_e('Live chat is off. With it on, a visitor who asks for a person waits here for you to answer, and the AI pauses until you do.', 'ibracodes-ai-assistant'); ?></p>
                    </div>
                </div>
                <a class="wsa-btn" href="<?php echo esc_url(self::url('agent')); ?>"><?php esc_html_e('Turn it on in the Agent tab', 'ibracodes-ai-assistant'); ?></a>
            </div>
            <?php
            return;
        }
        // the email links straight to one conversation; the console script opens it
        $linked = isset($_GET['thread']) ? absint($_GET['thread']) : 0;
        ?>
        <div id="wsa-live" class="wsa-console" data-thread="<?php echo esc_attr((string) $linked); ?>">
            <div class="wsa-live-list" id="wsa-live-list">
                <?php if (! $open) : ?>
                    <div class="wsa-empty"><?php esc_html_e('No one is waiting.', 'ibracodes-ai-assistant'); ?></div>
                <?php else : ?>
                    <?php foreach ($open as $row) : ?>
                        <?php self::live_item($row); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="wsa-live-pane" id="wsa-live-pane">
                <div class="wsa-empty"><?php esc_html_e('Pick a conversation from the list.', 'ibracodes-ai-assistant'); ?></div>
            </div>
        </div>
        <?php
    }

    /** One row of the list; the console script builds the same markup when it refreshes. */
    private static function live_item(array $row): void
    {
        $states = self::live_states();
        ?>
        <button type="button" class="wsa-live-item" data-thread="<?php echo esc_attr((string) (int) $row['id']); ?>">
            <span class="wsa-live-item-top">
                <span class="wsa-pill is-<?php echo esc_attr($row['status']); ?>"><?php echo esc_html($states[$row['status']] ?? $row['status']); ?></span>
                <?php if ((int) $row['unread'] > 0) : ?>
                    <?php /* translators: %s: number of unread visitor messages */ ?>
                    <span class="wsa-live-unread" aria-label="<?php echo esc_attr(sprintf(__('%s unread', 'ibracodes-ai-assistant'), number_format_i18n((int) $row['unread']))); ?>"><?php echo esc_html(number_format_i18n((int) $row['unread'])); ?></span>
                <?php endif; ?>
            </span>
            <span class="wsa-live-item-q"><?php echo esc_html($row['first_question'] ?: __('(no question recorded)', 'ibracodes-ai-assistant')); ?></span>
            <span class="wsa-live-item-meta"><?php echo esc_html($row['status'] === 'live' ? (string) $row['manager'] : self::waited((int) $row['waiting_seconds'])); ?></span>
        </button>
        <?php
    }

    private static function tab_leads(array $s): void
    {
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 20;
        $data = Leads::list($page, $per_page, $search);
        $pages = max(1, (int) ceil($data['total'] / $per_page));
        $columns = '100px minmax(0,1fr) minmax(0,1fr) minmax(0,1.8fr) minmax(0,1fr) 70px 80px';
        $paging = $search !== '' ? ['s' => $search] : [];
        ?>
        <div class="wsa-stack">
            <div class="wsa-card">
                <div class="wsa-card-head">
                    <div>
                        <h2 class="wsa-card-title"><?php esc_html_e('Leads', 'ibracodes-ai-assistant'); ?></h2>
                        <p class="wsa-card-sub">
                            <?php
                            printf(
                                /* translators: %s: number of days leads are kept */
                                esc_html__('Leads are kept %s days, then deleted along with the conversation they came from.', 'ibracodes-ai-assistant'),
                                esc_html(number_format_i18n((int) $s['leads_retention_days'])),
                            );
                            ?>
                        </p>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;gap:8px;">
                            <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                            <input type="hidden" name="tab" value="leads">
                            <input class="fld" style="max-width:220px;" type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search leads', 'ibracodes-ai-assistant'); ?>" aria-label="<?php esc_attr_e('Search leads', 'ibracodes-ai-assistant'); ?>">
                            <button type="submit" class="wsa-btn is-ghost"><?php esc_html_e('Search', 'ibracodes-ai-assistant'); ?></button>
                        </form>
                        <?php self::form_open('leads'); ?>
                            <button type="submit" class="wsa-btn is-ghost" name="export_leads" value="1"><?php esc_html_e('Export CSV', 'ibracodes-ai-assistant'); ?></button>
                        </form>
                    </div>
                </div>

                <?php if (! $data['rows']) : ?>
                    <div class="wsa-empty">
                        <?php echo esc_html($search === '' ? __('No leads yet. Turn on lead capture on the Agent tab.', 'ibracodes-ai-assistant') : __('No leads match that search.', 'ibracodes-ai-assistant')); ?>
                    </div>
                <?php else : ?>
                    <div class="wsa-rows">
                        <div class="wsa-row wsa-row-head" style="grid-template-columns:<?php echo esc_attr($columns); ?>;">
                            <div><?php esc_html_e('When', 'ibracodes-ai-assistant'); ?></div>
                            <div><?php esc_html_e('Name', 'ibracodes-ai-assistant'); ?></div>
                            <div><?php esc_html_e('Contact', 'ibracodes-ai-assistant'); ?></div>
                            <div><?php esc_html_e('Request', 'ibracodes-ai-assistant'); ?></div>
                            <div><?php esc_html_e('Page', 'ibracodes-ai-assistant'); ?></div>
                            <div><?php esc_html_e('Email', 'ibracodes-ai-assistant'); ?></div>
                            <div></div>
                        </div>
                        <?php foreach ($data['rows'] as $row) :
                            $permalink = $row['page_id'] ? (string) get_permalink((int) $row['page_id']) : '';
                            $thread_id = (int) $row['thread_id'];
                            ?>
                            <div class="wsa-row" style="grid-template-columns:<?php echo esc_attr($columns); ?>;">
                                <div style="color:var(--muted);font-size:12.5px;"><?php echo esc_html(self::ago($row['created_at'])); ?></div>
                                <div class="wsa-truncate">
                                    <?php if ($thread_id > 0) : ?>
                                        <a href="<?php echo esc_url(self::url('conversations', ['thread' => $thread_id])); ?>"><?php echo esc_html($row['name']); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($row['name']); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="wsa-truncate"><?php echo esc_html($row['contact']); ?></div>
                                <div class="wsa-truncate"><?php echo esc_html(wp_html_excerpt((string) $row['request'], 80, '…')); ?></div>
                                <div class="wsa-truncate">
                                    <?php if ($permalink) : ?>
                                        <a href="<?php echo esc_url($permalink); ?>"><?php echo esc_html(get_the_title((int) $row['page_id']) ?: $permalink); ?></a>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if ((int) $row['email_sent']) : ?>
                                        <span class="wsa-pill is-good"><?php esc_html_e('Sent', 'ibracodes-ai-assistant'); ?></span>
                                    <?php else : ?>
                                        <span class="wsa-pill is-warn"><?php esc_html_e('Failed', 'ibracodes-ai-assistant'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php self::form_open('leads'); ?>
                                        <button type="submit" class="wsa-btn is-ghost" name="delete_lead" value="<?php echo esc_attr((string) (int) $row['id']); ?>" data-wsa-confirm="<?php esc_attr_e('Delete this lead and the conversation it came from?', 'ibracodes-ai-assistant'); ?>"><?php esc_html_e('Delete', 'ibracodes-ai-assistant'); ?></button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($pages > 1) : ?>
                        <div style="display:flex;gap:10px;align-items:center;margin-top:14px;">
                            <?php if ($page > 1) : ?>
                                <a class="wsa-btn is-ghost" href="<?php echo esc_url(self::url('leads', ['paged' => $page - 1] + $paging)); ?>"><?php esc_html_e('Previous', 'ibracodes-ai-assistant'); ?></a>
                            <?php endif; ?>
                            <span class="wsa-help">
                                <?php
                                printf(
                                    /* translators: 1: current page, 2: total pages */
                                    esc_html__('Page %1$s of %2$s', 'ibracodes-ai-assistant'),
                                    esc_html(number_format_i18n($page)),
                                    esc_html(number_format_i18n($pages)),
                                );
                                ?>
                            </span>
                            <?php if ($page < $pages) : ?>
                                <a class="wsa-btn is-ghost" href="<?php echo esc_url(self::url('leads', ['paged' => $page + 1] + $paging)); ?>"><?php esc_html_e('Next', 'ibracodes-ai-assistant'); ?></a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /** Every lead as CSV rows, the header first, cells guarded against formulas; pages of 500 so a large table never sits in memory at once. */
    private static function csv_rows(): \Generator
    {
        yield ['id', 'created_at', 'name', 'contact', 'request', 'page'];
        $page = 1;
        do {
            $data = Leads::list($page, 500);
            foreach ($data['rows'] as $row) {
                yield array_map([self::class, 'csv_cell'], [
                    (string) $row['id'],
                    (string) $row['created_at'],
                    (string) $row['name'],
                    (string) $row['contact'],
                    (string) $row['request'],
                    $row['page_id'] ? (string) get_permalink((int) $row['page_id']) : '',
                ]);
            }
            $page++;
        } while (count($data['rows']) === 500);
    }

    /** Writes the rows behind a byte order mark, which is what makes Excel read Hebrew as Hebrew. */
    private static function csv_write($stream, iterable $rows): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            // no escape character: a backslash in a name stays a backslash, and quotes are doubled by the enclosure alone
            fputcsv($stream, $row, ',', '"', '');
        }
    }

    /** A cell that starts like a formula gets a quote in front, so a spreadsheet shows it instead of running it. */
    private static function csv_cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'" . $value : $value;
    }

    // ------------------------------------------------------ tab: conversations
    private static function tab_conversations(array $s): void
    {
        $thread_id = isset($_GET['thread']) ? absint($_GET['thread']) : 0;
        if ($thread_id) {
            self::render_thread($thread_id);

            return;
        }

        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 20;
        $data = DB::threads($page, $per_page);
        $pages = max(1, (int) ceil($data['total'] / $per_page));
        ?>
        <div class="wsa-stack">
            <div class="wsa-card">
                <div class="wsa-card-head">
                    <div>
                        <h2 class="wsa-card-title"><?php esc_html_e('Conversations', 'ibracodes-ai-assistant'); ?></h2>
                        <p class="wsa-card-sub">
                            <?php
                            printf(
                                /* translators: %s: number of days threads are kept */
                                esc_html__('Threads are kept %s days, then deleted. No email, name or payment detail is stored: only the messages and the product ids the agent showed.', 'ibracodes-ai-assistant'),
                                esc_html(number_format_i18n((int) $s['retention_days'])),
                            );
                            ?>
                        </p>
                    </div>
                </div>

                <?php if (! $data['rows']) : ?>
                    <div class="wsa-empty"><?php esc_html_e('No conversations yet.', 'ibracodes-ai-assistant'); ?></div>
                <?php else : ?>
                    <div class="wsa-rows">
                        <div class="wsa-row wsa-row-head" style="grid-template-columns:minmax(0,1.6fr) 110px 70px 90px 110px;">
                            <div><?php esc_html_e('First question', 'ibracodes-ai-assistant'); ?></div>
                            <div><?php esc_html_e('When', 'ibracodes-ai-assistant'); ?></div>
                            <div><?php esc_html_e('Turns', 'ibracodes-ai-assistant'); ?></div>
                            <div><?php esc_html_e('Shown', 'ibracodes-ai-assistant'); ?></div>
                            <div><?php esc_html_e('Outcome', 'ibracodes-ai-assistant'); ?></div>
                        </div>
                        <?php foreach ($data['rows'] as $row) : ?>
                            <div class="wsa-row" style="grid-template-columns:minmax(0,1.6fr) 110px 70px 90px 110px;">
                                <div class="wsa-truncate">
                                    <a href="<?php echo esc_url(self::url('conversations', ['thread' => (int) $row['id']])); ?>">
                                        <?php echo esc_html($row['first_question'] ?: __('(no question recorded)', 'ibracodes-ai-assistant')); ?>
                                    </a>
                                </div>
                                <div style="color:var(--muted);font-size:12.5px;"><?php echo esc_html(self::ago($row['created_at'])); ?></div>
                                <div class="wsa-num"><?php echo esc_html(number_format_i18n((int) $row['turns'])); ?></div>
                                <div class="wsa-num"><?php echo esc_html(number_format_i18n((int) $row['products_shown'])); ?></div>
                                <div>
                                    <?php if ((int) $row['added_to_cart']) : ?>
                                        <span class="wsa-pill is-good"><?php esc_html_e('Added to cart', 'ibracodes-ai-assistant'); ?></span>
                                    <?php elseif ((int) $row['no_match']) : ?>
                                        <span class="wsa-pill is-warn"><?php esc_html_e('No match', 'ibracodes-ai-assistant'); ?></span>
                                    <?php elseif ((int) $row['products_shown']) : ?>
                                        <span class="wsa-pill"><?php esc_html_e('Recommended', 'ibracodes-ai-assistant'); ?></span>
                                    <?php else : ?>
                                        <span class="wsa-pill"><?php esc_html_e('Answered', 'ibracodes-ai-assistant'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ($pages > 1) : ?>
                        <div style="display:flex;gap:10px;align-items:center;margin-top:14px;">
                            <?php if ($page > 1) : ?>
                                <a class="wsa-btn is-ghost" href="<?php echo esc_url(self::url('conversations', ['paged' => $page - 1])); ?>"><?php esc_html_e('Previous', 'ibracodes-ai-assistant'); ?></a>
                            <?php endif; ?>
                            <span class="wsa-help">
                                <?php
                                printf(
                                    /* translators: 1: current page, 2: total pages */
                                    esc_html__('Page %1$s of %2$s', 'ibracodes-ai-assistant'),
                                    esc_html(number_format_i18n($page)),
                                    esc_html(number_format_i18n($pages)),
                                );
                                ?>
                            </span>
                            <?php if ($page < $pages) : ?>
                                <a class="wsa-btn is-ghost" href="<?php echo esc_url(self::url('conversations', ['paged' => $page + 1])); ?>"><?php esc_html_e('Next', 'ibracodes-ai-assistant'); ?></a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php self::form_open('conversations'); ?>
                <div class="wsa-card">
                    <h2 class="wsa-card-title"><?php esc_html_e('Recording', 'ibracodes-ai-assistant'); ?></h2>
                    <?php self::toggle('log_threads', (bool) $s['log_threads'], __('Keep a record of conversations', 'ibracodes-ai-assistant'), __('Off means nothing is written at all. You lose the reports on the overview.', 'ibracodes-ai-assistant')); ?>
                    <div class="wsa-field" style="margin-top:16px;">
                        <label class="wsa-label" for="wsa-retention"><?php esc_html_e('Keep for (days)', 'ibracodes-ai-assistant'); ?></label>
                        <input class="fld" style="max-width:110px;" type="number" min="1" max="365" id="wsa-retention" name="retention_days" value="<?php echo esc_attr((string) $s['retention_days']); ?>">
                        <p class="wsa-help"><?php esc_html_e('Older threads are deleted automatically once a day.', 'ibracodes-ai-assistant'); ?></p>
                    </div>
                    <label class="wsa-check" style="margin-top:8px;">
                        <input type="checkbox" name="purge_now" value="1">
                        <span><?php esc_html_e('Delete every stored conversation when I save', 'ibracodes-ai-assistant'); ?></span>
                    </label>
                </div>
            <?php self::save_bar(); ?>
        </div>
        <?php
    }

    private static function render_thread(int $id): void
    {
        $thread = DB::thread($id);
        $commerce = Capabilities::has_commerce();
        ?>
        <div class="wsa-stack">
            <p><a href="<?php echo esc_url(self::url('conversations')); ?>">&larr; <?php esc_html_e('All conversations', 'ibracodes-ai-assistant'); ?></a></p>
            <?php if (! $thread) : ?>
                <div class="wsa-card"><div class="wsa-empty"><?php esc_html_e('That conversation is gone. It may have passed the retention window.', 'ibracodes-ai-assistant'); ?></div></div>
            <?php else : ?>
                <div class="wsa-card">
                    <div class="wsa-card-head">
                        <div>
                            <div class="wsa-eyebrow"><?php esc_html_e('Conversation', 'ibracodes-ai-assistant'); ?></div>
                            <h2 class="wsa-card-title" style="margin-top:4px;">
                                <?php
                                printf(
                                    /* translators: 1: relative time, 2: number of turns */
                                    esc_html__('%1$s · %2$s turns', 'ibracodes-ai-assistant'),
                                    esc_html(self::ago($thread['created_at'])),
                                    esc_html(number_format_i18n((int) $thread['turns'])),
                                );
                                ?>
                            </h2>
                            <p class="wsa-card-sub"><?php echo esc_html(trim(($thread['locale'] ?: '') . ' · ' . ($thread['device'] ?: ''), ' ·')); ?></p>
                        </div>
                    </div>
                    <div class="wsa-thread">
                        <?php
                        // the last person on the thread names every manager row; a deleted account gets the generic label
                        $manager = (int) ($thread['manager_id'] ?? 0) > 0 ? get_userdata((int) $thread['manager_id']) : false;
                        $manager_name = $manager ? (string) $manager->display_name : __('Manager', 'ibracodes-ai-assistant');
                        foreach ($thread['messages'] as $message) :
                            $role = in_array($message['role'], ['user', 'manager', 'system'], true) ? $message['role'] : 'assistant';
                            ?>
                            <div class="wsa-bubble is-<?php echo esc_attr($role); ?>"><?php if ($role === 'manager') : ?><span class="wsa-bubble-name"><?php echo esc_html($manager_name); ?></span><?php endif; ?><?php echo esc_html($message['content']); ?></div>
                            <?php
                            $ids = array_filter(array_map('absint', explode(',', (string) $message['product_ids'])));
                            if ($ids) :
                                ?>
                                <div style="padding-inline-start:6px;">
                                    <?php foreach ($ids as $product_id) :
                                        // without WooCommerce the id is all we can show
                                        $product = $commerce ? wc_get_product($product_id) : null;
                                        ?>
                                        <div class="wsa-mini">
                                            <?php
                                            $image = $product ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : '';
                                            if ($image) :
                                                ?>
                                                <img src="<?php echo esc_url($image); ?>" alt="">
                                            <?php else : ?>
                                                <span class="wsa-thumb"></span>
                                            <?php endif; ?>
                                            <span class="wsa-mini-n wsa-truncate">
                                                <?php if ($product) : ?>
                                                    <a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>"><?php echo esc_html($product->get_name()); ?></a>
                                                <?php elseif ($commerce) : ?>
                                                    <?php esc_html_e('(deleted product)', 'ibracodes-ai-assistant'); ?>
                                                <?php else : ?>
                                                    #<?php echo esc_html((string) $product_id); ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
