<?php
/**
 * OptimaSite API — server-side proxy to ShareWire.in.
 * Uses the WordPress HTTP API so there are no CORS issues and the ShareWire
 * Razorpay gateway keys never reach the browser or this plugin.
 *
 * Endpoints used:
 *   api/razorpay/order.php   create Razorpay order (enter domain + pay)
 *   api/razorpay/verify.php  verify payment + confirm issued key
 *   api/license.php?action=activate   activate key for THIS domain
 *   api/license.php?action=validate   re-validate
 *   api/license.php?action=deactivate deactivate THIS domain
 *   api/license/domains.php  list / disconnect bound domains (self-service)
 *   api/update.php           check + arm the update package for this domain
 *   api/catalogue.php        product name/price/currency
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OptimaSite_Api
{
    const TIMEOUT = 20;
    const PRODUCT = OPTIMASITE_SLUG;

    public static function base(): string
    {
        return OPTIMASITE_BASE;
    }

    public static function current_domain(): string
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        return strtolower(preg_replace('/^www\./i', '', trim((string) $host)) ?: 'unknown');
    }

    /** Core request helper (public so the updater can reuse it). */
    public static function request(string $path, array $data = array(), string $method = 'POST'): array
    {
        $args = array(
            'timeout'     => self::TIMEOUT,
            'redirection' => 3,
            'user-agent'  => 'OptimaSite/' . OPTIMASITE_VERSION . ' (+' . OPTIMASITE_BASE . ')',
            'sslverify'   => apply_filters('optimasite_sslverify', true),
        );
        $resp = null;
        if ($method === 'POST') {
            $args['headers'] = array('Content-Type' => 'application/json', 'Accept' => 'application/json');
            $args['body'] = wp_json_encode($data);
            $resp = wp_remote_post(self::base() . $path, $args);
        } else {
            $resp = wp_remote_get(self::base() . $path, $args);
        }
        if (is_wp_error($resp)) {
            return array('ok' => false, 'error' => 'HTTP_ERROR: ' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $json = json_decode((string) wp_remote_retrieve_body($resp), true);
        $out = is_array($json) ? $json : array('ok' => false, 'error' => 'BAD_RESPONSE');
        $out['http'] = $code;
        return $out;
    }

    /** Create a Razorpay order bound to the buyer's domain. */
    public static function create_order(string $email, string $name, string $domain): array
    {
        return self::request('/api/razorpay/order.php', array(
            'product_slug' => self::PRODUCT,
            'email'        => $email,
            'name'         => $name,
            'domain'       => $domain,
        ), 'POST');
    }

    public static function verify_payment(array $params): array
    {
        return self::request('/api/razorpay/verify.php', $params, 'POST');
    }

    public static function product_info(): array
    {
        $r = self::request('/api/catalogue.php', array(), 'GET');
        if (empty($r['ok']) || !is_array($r['products'])) {
            return array();
        }
        foreach ($r['products'] as $p) {
            if (is_array($p) && ($p['slug'] ?? '') === self::PRODUCT) {
                return $p;
            }
        }
        return array();
    }

    /** Activate this key for THIS domain (binds the domain). */
    public static function activate(string $key): array
    {
        return self::request('/api/license.php?action=activate', array(
            'key'      => $key,
            'product'  => self::PRODUCT,
            'site'     => self::current_domain(),
            'version'  => OPTIMASITE_VERSION,
            'platform' => 'wordpress',
            'nonce'    => bin2hex(random_bytes(16)),
        ), 'POST');
    }

    public static function deactivate(string $key): array
    {
        return self::request('/api/license.php?action=deactivate', array(
            'key'     => $key,
            'product' => self::PRODUCT,
            'site'    => self::current_domain(),
        ), 'POST');
    }

    public static function validate(string $key): array
    {
        return self::request('/api/license.php?action=validate'
            . '&product=' . rawurlencode(self::PRODUCT) . '&key=' . rawurlencode($key), array(), 'GET');
    }

    /** Self-service: list + disconnect bound domains (keyed by the license key). */
    public static function domains(string $action, string $key, string $domain = ''): array
    {
        return self::request('/api/license/domains.php', array(
            'action'  => $action,
            'key'     => $key,
            'product' => self::PRODUCT,
            'domain'  => $domain,
        ), 'POST');
    }
}
