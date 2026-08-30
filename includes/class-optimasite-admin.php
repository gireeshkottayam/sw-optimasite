<?php
/**
 * OptimaSite Admin — premium settings page with:
 *   - license status / embedded Razorpay checkout / activation
 *   - Site Health & Optimization audit
 *   - safe one-click fixes
 * All admin-ajax actions are nonce + capability guarded.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OptimaSite_Admin
{
    const PAGE       = 'sw-optimasite';
    const AJAX_NONCE = 'optimasite_admin';

    public static function menu(): void
    {
        add_menu_page(
            'OptimaSite',
            'OptimaSite',
            'manage_options',
            self::PAGE,
            array(__CLASS__, 'render'),
            'dashicons-health',
            81
        );
    }

    public static function register_ajax(): void
    {
        foreach (array('status', 'activate', 'deactivate', 'create_order', 'verify', 'domains',
            'audit', 'run_fix') as $action) {
            add_action('wp_ajax_optimasite_' . $action, array(__CLASS__, 'ajax_' . $action));
        }
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_notices', array(__CLASS__, 'locked_notice'));
        add_action('admin_notices', array(__CLASS__, 'update_notice'));
    }

    /**
     * In-dashboard "update available" banner (Rule 6). Shown only to admins of a
     * licensed + domain-bound install when ShareWire reports a newer version.
     * Mirrors the native WP update screen; links there to actually update.
     */
    public static function update_notice(): void
    {
        if (!current_user_can('manage_options') || !OptimaSite_License::is_valid()) {
            return;
        }
        $u = OptimaSite_Updater::available();
        if ($u === null) {
            return;
        }
        $upd = self::plugin_update_url();
        echo '<div class="notice notice-info" style="border-left-color:#37e0a5"><p>'
            . esc_html__('OptimaSite update available: version ', 'sw-optimasite')
            . '<strong>' . esc_html($u['new_version']) . '</strong>. '
            . '<a href="' . esc_url($upd) . '">' . esc_html__('Update now', 'sw-optimasite') . '</a></p></div>';
    }

    private static function plugin_update_url(): string
    {
        return network_admin_url('plugins.php?plugin_status=upgrade');
    }

    public static function assets(string $hook): void
    {
        if ('toplevel_page_' . self::PAGE !== $hook) {
            return;
        }
        wp_enqueue_style('optimasite-css', OPTIMASITE_URL . 'assets/css/admin.css', array(), OPTIMASITE_VERSION);
        wp_enqueue_script('optimasite-admin', OPTIMASITE_URL . 'assets/js/admin.js', array(), OPTIMASITE_VERSION, true);

        $info = get_transient('optimasite_product_info');
        if ($info === false) {
            $info = OptimaSite_Api::product_info();
            set_transient('optimasite_product_info', $info, HOUR_IN_SECONDS);
        }
        if (!is_array($info)) {
            $info = array();
        }

        $user = wp_get_current_user();
        wp_localize_script('optimasite-admin', 'OptimaSite', array(
            'ajax'     => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::AJAX_NONCE),
            'base'     => OptimaSite_Api::base(),
            'domain'   => OptimaSite_Api::current_domain(),
            'email'    => $user->user_email ? (string) $user->user_email : '',
            'name'     => (string) ($info['name'] ?? 'OptimaSite'),
            'price'    => isset($info['price']) ? (float) $info['price'] : 999.0,
            'currency' => (string) ($info['currency'] ?? 'INR'),
            'valid'    => OptimaSite_License::is_valid(),
            'keyTail'  => substr(OptimaSite_License::key(), -4),
            'state'    => OptimaSite_License::state(),
            'confirm_phrase' => 'i have a backup',
            'i18n'     => array(
                'enter_key'   => __('Enter your license key.', 'sw-optimasite'),
                'busy'        => __('Working', 'sw-optimasite'),
                'closed'      => __('Checkout closed — no payment taken.', 'sw-optimasite'),
                'valid_domain' => __('Enter a valid domain (e.g. mywebsite.com).', 'sw-optimasite'),
                'confirm_backup' => __('Type "%1$s" to confirm you have a backup before proceeding.', 'sw-optimasite'),
            ),
        ));
    }

    public static function locked_notice(): void
    {
        if (!current_user_can('manage_options') || OptimaSite_License::is_valid()) {
            return;
        }
        $href = admin_url('admin.php?page=' . self::PAGE);
        echo '<div class="notice notice-warning" style="border-left-color:#e3a23c"><p>'
            . esc_html__('OptimaSite is not activated on this domain. ', 'sw-optimasite')
            . '<a href="' . esc_url($href) . '">' . esc_html__('Activate, buy a license, or run a read-only audit', 'sw-optimasite') . '</a></p></div>';
    }

    public static function render(): void
    {
        $valid   = OptimaSite_License::is_valid();
        $state   = OptimaSite_License::state();
        $keyTail = substr(OptimaSite_License::key(), -4);
        $domain  = OptimaSite_Api::current_domain();
        $audit   = OptimaSite_Audit::run();
        $log     = OptimaSite_Fixer::get_log();
        ?>
        <div class="wrap optimasite-wrap">
            <div class="optimasite-hero">
                <div class="optimasite-hero__brand">
                    <span class="optimasite-hero__mark"></span>
                    <div>
                        <h1><?php esc_html_e('OptimaSite', 'sw-optimasite'); ?></h1>
                        <p class="optimasite-hero__sub"><?php esc_html_e('Site Health & Optimization Auditor', 'sw-optimasite'); ?></p>
                    </div>
                </div>
                <div class="optimasite-license-chip" id="optimasite-license-chip"
                     data-valid="<?php echo $valid ? '1' : '0'; ?>">
                    <span class="optimasite-chip-dot"></span>
                    <span id="optimasite-chip-text"><?php echo $valid ? esc_html__('Licensed', 'sw-optimasite') : esc_html__('Unlicensed', 'sw-optimasite'); ?></span>
                </div>
            </div>

            <div id="optimasite-msg" class="optimasite-msg" hidden></div>

            <?php if (!$valid) : ?>
            <section class="optimasite-card optimasite-card--license">
                <h2><?php esc_html_e('License', 'sw-optimasite'); ?></h2>
                <div class="optimasite-actions">
                    <div class="optimasite-col">
                        <label for="optimasite-key-input"><?php esc_html_e('Have a license key?', 'sw-optimasite'); ?></label>
                        <div class="optimasite-row">
                            <input type="text" id="optimasite-key-input" class="regular-text optimasite-mono"
                                   placeholder="SW-XXXX-XXXX-" autocomplete="off">
                            <button type="button" class="optimasite-btn optimasite-btn--primary" id="optimasite-activate-btn">
                                <?php esc_html_e('Activate', 'sw-optimasite'); ?>
                            </button>
                        </div>
                        <p class="optimasite-fine"><?php esc_html_e('Binds to this exact domain only.', 'sw-optimasite'); ?></p>
                    </div>
                    <div class="optimasite-col">
                        <div class="optimasite-or"><?php esc_html_e('or', 'sw-optimasite'); ?></div>
                        <label for="optimasite-buy-domain"><?php esc_html_e('Domain to license (single site)', 'sw-optimasite'); ?></label>
                        <div class="optimasite-row">
                            <input type="text" id="optimasite-buy-domain" value="<?php echo esc_attr($domain); ?>" class="regular-text optimasite-mono">
                            <button type="button" class="optimasite-btn optimasite-btn--hero" id="optimasite-buy-btn">
                                <?php esc_html_e('Pay with Razorpay', 'sw-optimasite'); ?>
                            </button>
                        </div>
                        <p class="optimasite-fine"><?php esc_html_e('One-time ₹999 · lifetime updates · one domain you can change later.', 'sw-optimasite'); ?></p>
                    </div>
                </div>
                <p class="optimasite-fine"><?php
                    echo esc_html(sprintf(
                        __('Powered by ShareWire.in — license and updates verified against %s. Valid for one domain (current: %s).', 'sw-optimasite'),
                        OptimaSite_Api::base(), $domain
                    ));
                ?></p>
            </section>
            <?php else : ?>
            <section class="optimasite-card optimasite-card--license">
                <div class="optimasite-licensebar">
                    <div class="optimasite-licensebar__status ok">
                        <span class="optimasite-status-dot"></span>
                        <strong><?php esc_html_e('License active', 'sw-optimasite'); ?></strong>
                        <span class="optimasite-licensebar__meta"><?php echo esc_html($domain); ?> · key …<?php echo esc_html($keyTail); ?></span>
                    </div>
                    <div class="optimasite-licensebar__ops">
                        <button type="button" class="optimasite-btn optimasite-btn--ghost" id="optimasite-domains-btn">
                            <?php esc_html_e('Manage domain', 'sw-optimasite'); ?>
                        </button>
                        <button type="button" class="optimasite-btn optimasite-btn--ghost" id="optimasite-deactivate-btn">
                            <?php esc_html_e('Deactivate', 'sw-optimasite'); ?>
                        </button>
                    </div>
                </div>
                <div id="optimasite-domains" class="optimasite-domains" hidden>
                    <table class="widefat striped optimasite-table"><thead><tr>
                        <th><?php esc_html_e('Domain', 'sw-optimasite'); ?></th>
                        <th><?php esc_html_e('Last seen', 'sw-optimasite'); ?></th>
                        <th></th>
                    </tr></thead><tbody></tbody></table>
                </div>
            </section>
            <?php endif; ?>

            <section class="optimasite-card">
                <div class="optimasite-section-head">
                    <div>
                        <h2><?php esc_html_e('Site Health Audit', 'sw-optimasite'); ?></h2>
                        <p class="optimasite-sub"><?php esc_html_e('All checks run locally on this server. No data leaves your site. No API key needed.', 'sw-optimasite'); ?></p>
                    </div>
                    <div class="optimasite-score" data-score="<?php echo esc_attr((int) ($audit['score'] ?? 0)); ?>">
                        <span class="optimasite-score__num"><?php echo esc_html((int) ($audit['score'] ?? 0)); ?></span>
                        <span class="optimasite-score__label">/100</span>
                    </div>
                </div>

                <div class="optimasite-audit-actions">
                    <button type="button" class="optimasite-btn optimasite-btn--primary" id="optimasite-audit-btn">
                        <?php esc_html_e('Re-run audit', 'sw-optimasite'); ?>
                    </button>
                    <span class="optimasite-audit-time"><?php
                        echo esc_html(sprintf(
                            __('Last scan: %s', 'sw-optimasite'),
                            $audit['generated_at'] ? wp_date('M j, Y g:i a', (int) $audit['generated_at']) : '—'
                        ));
                    ?></span>
                </div>

                <?php self::render_groups($audit['groups'] ?? array()); ?>
            </section>

            <section class="optimasite-card">
                <h2><?php esc_html_e('One-click Fixes', 'sw-optimasite'); ?></h2>
                <p class="optimasite-sub"><?php esc_html_e('Nothing runs automatically. Every fix requires your explicit confirmation; database and cleanup actions also require you to confirm you have a backup.', 'sw-optimasite'); ?></p>
                <?php self::render_fixes(); ?>
                <?php if ($log) : ?>
                <div class="optimasite-log">
                    <h3><?php esc_html_e('Action log', 'sw-optimasite'); ?></h3>
                    <table class="widefat striped optimasite-table"><thead><tr>
                        <th><?php esc_html_e('Time', 'sw-optimasite'); ?></th>
                        <th><?php esc_html_e('Action', 'sw-optimasite'); ?></th>
                        <th><?php esc_html_e('Result', 'sw-optimasite'); ?></th>
                    </tr></thead><tbody>
                    <?php foreach ($log as $entry) : ?>
                        <tr>
                            <td><code><?php echo esc_html($entry['time'] ?? ''); ?></code></td>
                            <td><?php echo esc_html($entry['action'] ?? ''); ?></td>
                            <td><?php echo esc_html($entry['detail'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </div>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    private static function render_groups(array $groups): void
    {
        $labels = array(
            'plugins'  => __('Plugins & Bloat', 'sw-optimasite'),
            'database' => __('Database', 'sw-optimasite'),
            'dbhealth' => __('Database Health', 'sw-optimasite'),
            'vitals'   => __('Core Web Vitals', 'sw-optimasite'),
            'security' => __('Security', 'sw-optimasite'),
            'setup'    => __('Setup & Environment', 'sw-optimasite'),
        );
        foreach ($groups as $key => $items) {
            $label = $labels[$key] ?? ucfirst((string) $key);
            $bad = 0;
            foreach ((array) $items as $it) {
                if (empty($it['good'])) {
                    $bad++;
                }
            }
            ?>
            <div class="optimasite-group">
                <div class="optimasite-group__head">
                    <h3><?php echo esc_html($label); ?></h3>
                    <?php if ($bad) : ?>
                        <span class="optimasite-badge optimasite-badge--warn"><?php echo esc_html($bad . ' ' . _n('item', 'items', $bad, 'sw-optimasite')); ?></span>
                    <?php else : ?>
                        <span class="optimasite-badge optimasite-badge--ok"><?php esc_html_e('All good', 'sw-optimasite'); ?></span>
                    <?php endif; ?>
                </div>
                <ul class="optimasite-checks">
                <?php foreach ((array) $items as $it) : ?>
                    <li class="optimasite-check optimasite-check--<?php echo esc_attr($it['status'] ?? 'good'); ?>">
                        <span class="optimasite-check__ic"></span>
                        <div class="optimasite-check__body">
                            <strong><?php echo esc_html($it['title'] ?? ''); ?></strong>
                            <p><?php echo esc_html($it['detail'] ?? ''); ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
            <?php
        }
    }

    private static function render_fixes(): void
    {
        $actions = OptimaSite_Fixer::actions();
        ?>
        <ul class="optimasite-fixes">
        <?php foreach ($actions as $id => $meta) : ?>
            <li class="optimasite-fix" data-action="<?php echo esc_attr($id); ?>" data-confirm="<?php echo !empty($meta['confirm']) ? '1' : '0'; ?>">
                <div class="optimasite-fix__info">
                    <strong><?php echo esc_html($meta['label']); ?></strong>
                    <p><?php echo !empty($meta['confirm'])
                        ? esc_html__('Requires backup confirmation.', 'sw-optimasite')
                        : esc_html__('Reversible toggle.', 'sw-optimasite'); ?></p>
                </div>
                <?php if (!empty($meta['confirm'])) : ?>
                <input type="text" class="optimasite-fix__confirm" placeholder="<?php echo esc_attr('i have a backup'); ?>" autocomplete="off">
                <?php endif; ?>
                <button type="button" class="optimasite-btn optimasite-btn--primary optimasite-fix__run">
                    <?php esc_html_e('Run', 'sw-optimasite'); ?>
                </button>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php
    }

    /* ------------------------------------------------------------------
     * admin-ajax (all guarded)
     * ------------------------------------------------------------------ */

    public static function ajax_status(): void
    {
        self::guard();
        wp_send_json(array(
            'ok'      => true,
            'valid'   => OptimaSite_License::is_valid(),
            'domain'  => OptimaSite_Api::current_domain(),
            'key_tail'=> substr(OptimaSite_License::key(), -4),
            'state'   => OptimaSite_License::state(),
        ));
    }

    public static function ajax_activate(): void
    {
        self::guard();
        $key = sanitize_text_field((string) ($_POST['key'] ?? ''));
        $r = OptimaSite_License::activate_key($key);
        wp_send_json($r, empty($r['ok']) ? 400 : 200);
    }

    public static function ajax_deactivate(): void
    {
        self::guard();
        $r = OptimaSite_License::deactivate_key();
        wp_send_json($r);
    }

    public static function ajax_create_order(): void
    {
        self::guard();
        $email  = sanitize_email((string) ($_POST['email'] ?? ''));
        $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
        $user   = wp_get_current_user();
        if (!is_email($email)) {
            $email = $user->user_email ? (string) $user->user_email : '';
        }
        if (!is_email($email)) {
            wp_send_json(array('ok' => false, 'error' => 'NO_EMAIL'), 400);
        }
        if ($domain === '' || !preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/i', $domain)) {
            wp_send_json(array('ok' => false, 'error' => 'BAD_DOMAIN'), 400);
        }
        $name = $user->display_name ? (string) $user->display_name : $email;
        $r = OptimaSite_Api::create_order($email, $name, $domain);
        wp_send_json($r, empty($r['ok']) ? 502 : 200);
    }

    public static function ajax_verify(): void
    {
        self::guard();
        $params = array(
            'razorpay_payment_id' => sanitize_text_field((string) ($_POST['razorpay_payment_id'] ?? '')),
            'razorpay_order_id'   => sanitize_text_field((string) ($_POST['razorpay_order_id'] ?? '')),
            'razorpay_signature'  => sanitize_text_field((string) ($_POST['razorpay_signature'] ?? '')),
            'our_order_id'        => (int) ($_POST['our_order_id'] ?? 0),
            'product'             => OPTIMASITE_SLUG,
        );
        $r = OptimaSite_Api::verify_payment($params);
        if (!empty($r['ok']) && !empty($r['license_key'])) {
            OptimaSite_License::set_key((string) $r['license_key']);
            $act = OptimaSite_License::activate_key((string) $r['license_key']);
            wp_send_json(array(
                'ok'             => true,
                'license_key'    => (string) $r['license_key'],
                'bound_domain'   => $r['bound_domain'] ?? null,
                'activated'      => !empty($act['ok']),
                'already_issued' => !empty($r['already_issued']),
            ));
        }
        wp_send_json($r, empty($r['ok']) ? 502 : 200);
    }

    public static function ajax_domains(): void
    {
        self::guard();
        $key    = OptimaSite_License::key();
        $op     = sanitize_key((string) ($_POST['op'] ?? 'status'));
        $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
        if ($key === '') {
            wp_send_json(array('ok' => false, 'error' => 'NO_KEY'), 400);
        }
        $r = OptimaSite_Api::domains($op === 'disconnect' ? 'disconnect' : 'status', $key, $domain);
        wp_send_json($r, empty($r['ok']) ? 404 : 200);
    }

    public static function ajax_audit(): void
    {
        self::guard();
        $r = OptimaSite_Audit::run(true);
        wp_send_json(array('ok' => true, 'audit' => self::audit_slim($r)));
    }

    public static function ajax_run_fix(): void
    {
        self::guard();
        $action       = sanitize_key((string) ($_POST['action_id'] ?? ''));
        $confirmation = sanitize_text_field((string) ($_POST['confirmation'] ?? ''));
        $r = OptimaSite_Fixer::run($action, $confirmation);
        wp_send_json($r, empty($r['ok']) ? 400 : 200);
    }

    private static function audit_slim(array $audit): array
    {
        return array(
            'score'        => (int) ($audit['score'] ?? 0),
            'generated_at' => (int) ($audit['generated_at'] ?? time()),
            'groups'       => $audit['groups'] ?? array(),
        );
    }

    private static function guard(): void
    {
        check_ajax_referer(self::AJAX_NONCE, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', 'sw-optimasite'), 403);
        }
    }
}
