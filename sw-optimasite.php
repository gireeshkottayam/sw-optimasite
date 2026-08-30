<?php
/**
 * Plugin Name:       OptimaSite — Site Health & Optimization Auditor
 * Plugin URI:        https://sharewire.in/product.php?product=sw-optimasite
 * Description:       Run a complete, local Site Health & Optimization audit: plugin conflicts and bloat, database bloat, slow queries, Core Web Vitals readiness, security hardening and obsolete setup — then apply safe, one-click fixes. Single-domain license with embedded Razorpay checkout and auto-updates powered by ShareWire.in (buy once, lifetime updates).
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            ShareWire.in
 * Author URI:        https://sharewire.in
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sw-optimasite
 *
 * This plugin is a licensed product of ShareWire.in (one domain per license).
 * The license, payment and update logic talks to the ShareWire platform
 * (https://sharewire.in) over HTTPS. No licensing core is embedded — the
 * activation gate and update delivery live server-side on sharewire.in.
 * All health and optimization checks run locally on your own server; no
 * external service or API key is required.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OPTIMASITE_VERSION', '1.1.0');
define('OPTIMASITE_BASE', defined('SW_LICENSE_BASE') ? rtrim((string) SW_LICENSE_BASE, '/') : 'https://sharewire.in');
define('OPTIMASITE_SLUG', 'sw-optimasite');           // ShareWire product slug (fixed)
define('OPTIMASITE_BASENAME', plugin_basename(__FILE__)); // e.g. sw-optimasite/sw-optimasite.php
define('OPTIMASITE_DIR', plugin_dir_path(__FILE__));
define('OPTIMASITE_URL', plugin_dir_url(__FILE__));

// Option keys
define('OPTIMASITE_OPT_KEY', 'optimasite_license_key');
define('OPTIMASITE_OPT_STATE', 'optimasite_license_state');
define('OPTIMASITE_OPT_AUDIT', 'optimasite_audit_cache');
define('OPTIMASITE_OPT_FIX', 'optimasite_fix_log');
define('OPTIMASITE_CRON', 'optimasite_daily_check');

require_once OPTIMASITE_DIR . 'includes/class-optimasite-api.php';
require_once OPTIMASITE_DIR . 'includes/class-optimasite-license.php';
require_once OPTIMASITE_DIR . 'includes/class-optimasite-updater.php';
require_once OPTIMASITE_DIR . 'includes/class-optimasite-audit.php';
require_once OPTIMASITE_DIR . 'includes/class-optimasite-fixer.php';
require_once OPTIMASITE_DIR . 'includes/class-optimasite-admin.php';

register_activation_hook(__FILE__, array('OptimaSite_License', 'activate'));
register_deactivation_hook(__FILE__, array('OptimaSite_License', 'deactivate'));

add_action('plugins_loaded', array('OptimaSite_License', 'init'), 5);
add_action('admin_menu', array('OptimaSite_Admin', 'menu'));
add_action('admin_init', array('OptimaSite_Admin', 'register_ajax'));
OptimaSite_Updater::init();

/**
 * Global gate: expose the current activation state to any hook/feature.
 * Returns true when OptimaSite is valid + active for THIS domain.
 */
if (!function_exists('optimasite_active')) {
    function optimasite_active(): bool
    {
        return OptimaSite_License::is_valid();
    }
}
