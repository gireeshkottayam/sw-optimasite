<?php
/**
 * OptimaSite Updater — native WordPress plugin auto-updater backed by ShareWire.
 * Uses the standard update filter + the wp_remote_get package download.
 * Packages are ONLY armed for a license that is active AND bound to THIS domain.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OptimaSite_Updater
{
    const CACHE = 'optimasite_update_check';

    public static function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'check'));
        add_filter('plugins_api', array(__CLASS__, 'info'), 10, 3);
    }

    private static function cache_key(string $key, string $domain): string
    {
        return self::CACHE . '_' . md5($key . '|' . $domain);
    }

    /**
     * Pending-update info, or null when nothing is offered.
     * Returns data ONLY when the license is active AND bound to THIS domain AND a
     * newer version with a real package exists. Never reveals an update to an
     * unlicensed install (same no-leak rule as check()).
     */
    public static function available(): ?array
    {
        $key = OptimaSite_License::key();
        if ($key === '' || !OptimaSite_License::is_valid()) {
            return null;
        }
        $data = self::fetch_data($key, OptimaSite_Api::current_domain());
        if ($data === null) {
            return null;
        }
        if (empty($data['licensed']) || empty($data['package'])) {
            return null;
        }
        if (version_compare(OPTIMASITE_VERSION, $data['new_version'], '>=')) {
            return null;
        }
        return array(
            'new_version' => $data['new_version'],
            'package'     => $data['package'],
            'url'         => $data['url'],
        );
    }

    /** Single source of truth for the update payload (cached, armed-only). */
    private static function fetch_data(string $key, string $domain): ?array
    {
        $ck = self::cache_key($key, $domain);
        $data = get_transient($ck);
        if ($data !== false) {
            return $data;
        }
        $r = OptimaSite_Api::request('/api/update.php?product=' . rawurlencode(OPTIMASITE_SLUG)
            . '&key=' . rawurlencode($key)
            . '&domain=' . rawurlencode($domain)
            . '&version=' . rawurlencode(OPTIMASITE_VERSION), array(), 'GET');
        if (empty($r['ok']) || empty($r['new_version'])) {
            return null;
        }
        $new_version = (string) $r['new_version'];
        if (!preg_match('/^\d+(\.\d+){0,3}$/', $new_version)) {
            return null; // refuse a malformed version from the server
        }
        $data = array(
            'new_version' => $new_version,
            'package'     => (string) ($r['package'] ?? ''),
            'slug'        => (string) ($r['slug'] ?? OPTIMASITE_SLUG),
            'plugin'      => (string) ($r['plugin'] ?? OPTIMASITE_BASENAME),
            'url'         => (string) ($r['url'] ?? ''),
            'requires'    => (string) ($r['requires'] ?? '5.8'),
            'tested'      => (string) ($r['tested'] ?? '6.4'),
            'requires_php'=> (string) ($r['requires_php'] ?? '7.4'),
            'changelog'   => (string) ($r['changelog'] ?? ''),
            'licensed'    => !empty($r['licensed']),
        );
        set_transient($ck, $data, HOUR_IN_SECONDS);
        return $data;
    }

    /** Called by the WP updater on each update screen load. */
    public static function check($transient)
    {
        if (!is_object($transient)) {
            $transient = new stdClass();
        }
        $key = OptimaSite_License::key();
        if ($key === '' || !OptimaSite_License::is_valid()) {
            // No valid license — do not present an update (and never leak a package).
            return $transient;
        }
        $data = self::fetch_data($key, OptimaSite_Api::current_domain());
        if ($data === null) {
            return $transient;
        }

        if (empty($data['licensed']) || empty($data['package'])) {
            return $transient; // not licensed for this domain — no update offered
        }
        if (version_compare(OPTIMASITE_VERSION, $data['new_version'], '>=')) {
            return $transient;
        }

        $obj = (object) array(
            'slug'        => $data['slug'],
            'plugin'      => $data['plugin'],
            'new_version' => $data['new_version'],
            'url'         => $data['url'],
            'package'     => $data['package'],
            'requires'    => $data['requires'],
            'tested'      => $data['tested'],
            'requires_php'=> $data['requires_php'],
            'compatibility' => new stdClass(),
        );
        $transient->response[$data['plugin']] = $obj;
        return $transient;
    }

    /** "View details" popup data. */
    public static function info($res, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $res;
        }
        $slug = is_object($args) ? ($args->slug ?? '') : '';
        if ($slug !== OPTIMASITE_SLUG) {
            return $res;
        }
        return (object) array(
            'name'        => 'OptimaSite — Site Health & Optimization Auditor',
            'slug'        => OPTIMASITE_SLUG,
            'version'     => OPTIMASITE_VERSION,
            'author'      => 'ShareWire.in',
            'homepage'    => OPTIMASITE_BASE,
            'sections'    => array(
                'description' => self::product_description(),
                'changelog'   => 'Buy once, lifetime updates for a single domain. See your ShareWire portal for release notes.',
            ),
            'requires'    => '5.8',
            'tested'      => '6.4',
            'requires_php'=> '7.4',
            'downloaded'  => 0,
            'last_updated' => gmdate('Y-m-d'),
        );
    }

    private static function product_description(): string
    {
        return '<p>OptimaSite by ShareWire.in — a single-domain license with embedded Razorpay checkout, automatic updates, '
            . 'and a complete local Site Health & Optimization audit (plugin conflicts and bloat, database bloat, slow queries, '
            . 'Core Web Vitals readiness, security hardening and obsolete setup) with safe one-click fixes. No external service '
            . 'or API key required — every check runs on your own server.</p>';
    }
}
