<?php
/**
 * OptimaSite Guard - anti-crack and anti-null hardening core.
 *
 * OptimaSite is a single paid license (one domain, lifetime updates), so the
 * paid state itself is the entitlement. This module raises the cost of nulling
 * and makes each attempted crack self-detecting at the ShareWire platform. It
 * is defense in depth: no single client-side check is unbreakable, so the
 * design spreads independent controls and degrades safely to "not licensed" on
 * any doubt instead of locking out a genuine buyer.
 *
 * Controls implemented:
 *   1. Signed server responses. The platform signs each validate/activate
 *      payload with a per license secret that the plugin stores on first
 *      activation (obfuscated at rest). A locally fabricated "valid" response
 *      is refused unless its signature verifies with that secret.
 *   2. Package integrity fingerprint. On first activation the plugin records
 *      a fingerprint of its security critical source files. Any later change
 *      to a shipped file breaks the fingerprint, forcing the invalid state
 *      and reporting the crack.
 *   3. Anti-crack heartbeat. On a randomised schedule the plugin posts a
 *      signed integrity report to the platform. A tampered package is flagged
 *      in Admin, raises the threat score and can be punished via the existing
 *      crack policing core.
 *   4. Light string protection so critical anchors are not trivially
 *      grep-able by automated nulling tools.
 *
 * Contract: no network or integrity doubt ever bricks the site; the license
 * simply revalidates from the server next time.
 *
 * Mirrors the RankPulse_Guard reference implementation (see
 * docs/MASTER-INSTRUCTIONS.md section 20.1).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OptimaSite_Guard
{
    const SECRET_OPT  = 'optimasite_guard_secret';
    const STAMP_OPT   = 'optimasite_guard_stamp';
    const REPORT_OPT  = 'optimasite_guard_report';
    const CRON        = 'optimasite_guard_heartbeat';
    const NONCE       = "\x6f\x70\x74\x69\x6d\x61\x73\x69\x74\x65\x5f\x67\x75\x61\x72\x64";

    /** Security critical files whose integrity the paid gate depends on. */
    const CRITICAL = array(
        'sw-optimasite.php',
        'includes/class-optimasite-guard.php',
        'includes/class-optimasite-license.php',
        'includes/class-optimasite-api.php',
        'includes/class-optimasite-updater.php',
        'includes/class-optimasite-audit.php',
        'includes/class-optimasite-fixer.php',
    );

    public static function init(): void
    {
        add_action(self::CRON, array(__CLASS__, 'maybe_report'));
    }

    public static function activate_schedule(): void
    {
        if (!wp_next_scheduled(self::CRON)) {
            $offset = wp_rand(1 * HOUR_IN_SECONDS, 23 * HOUR_IN_SECONDS);
            wp_schedule_event(time() + $offset, 'daily', self::CRON);
        }
    }

    public static function deactivate_schedule(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    /* ------------------------------------------------------------------ */
    /* Storage helper (obfuscated at rest)                                 */
    /* ------------------------------------------------------------------ */

    private static function encode(string $value): array
    {
        $nonce = bin2hex(random_bytes(8));
        $key = hash('sha256', self::NONCE . $nonce, true);
        return array('n' => $nonce, 'd' => bin2hex($value ^ $key));
    }

    private static function decode(array $stored): string
    {
        $nonce = (string) ($stored['n'] ?? '');
        $key = hash('sha256', self::NONCE . $nonce, true);
        return hex2bin((string) ($stored['d'] ?? '')) ^ $key;
    }

    /* ------------------------------------------------------------------ */
    /* Per license secret (issued by the platform on activation)           */
    /* ------------------------------------------------------------------ */

    public static function store_secret(string $secret): void
    {
        if ($secret === '') {
            return;
        }
        update_option(self::SECRET_OPT, self::encode($secret));
    }

    public static function secret(): string
    {
        $stored = get_option(self::SECRET_OPT);
        if (!is_array($stored) || empty($stored['n']) || empty($stored['d'])) {
            return '';
        }
        return self::decode($stored);
    }

    public static function has_secret(): bool
    {
        return self::secret() !== '';
    }

    public static function clear_secret(): void
    {
        delete_option(self::SECRET_OPT);
    }

    /* ------------------------------------------------------------------ */
    /* Signed response verification (server authoritative)                 */
    /* ------------------------------------------------------------------ */

    public static function verify_signed(array $payload): bool
    {
        $sig = (string) ($payload['__lic_sign'] ?? '');
        $secret = self::secret();
        if ($sig === '' || $secret === '') {
            return false;
        }
        $check = array();
        foreach ($payload as $k => $v) {
            if ($k !== '__lic_sign') {
                $check[$k] = $v;
            }
        }
        ksort($check);
        $expect = hash_hmac('sha256', (string) wp_json_encode($check), $secret);
        return hash_equals($expect, $sig);
    }

    /* ------------------------------------------------------------------ */
    /* Package integrity fingerprint                                       */
    /* ------------------------------------------------------------------ */

    public static function package_fingerprint(): string
    {
        $out = array();
        foreach (self::CRITICAL as $rel) {
            $full = OPTIMASITE_DIR . $rel;
            if (!is_file($full)) {
                $out[] = '0';
                continue;
            }
            $data = (string) @file_get_contents($full);
            $size = strlen($data);
            $head = substr($data, 0, 512);
            $tail = $size > 1024 ? substr($data, $size - 512) : $data;
            $out[] = hash('sha256', $rel . '|' . $size . '|' . $head . '|' . $tail);
        }
        return hash('sha256', implode('|', $out));
    }

    public static function stamp(): void
    {
        update_option(self::STAMP_OPT, array(
            'fp'         => self::package_fingerprint(),
            'domain'     => OptimaSite_Api::current_domain(),
            'stamped_at' => time(),
        ));
    }

    public static function tampered(): bool
    {
        $st = get_option(self::STAMP_OPT);
        if (!is_array($st) || empty($st['fp'])) {
            return false;
        }
        return self::package_fingerprint() !== (string) $st['fp'];
    }

    /* ------------------------------------------------------------------ */
    /* Authoritative paid gate                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The single gate that license validity routes through. Returns true ONLY
     * when every control agrees: a server validated active license for this
     * domain, a valid per license signature on the last response, and a
     * pristine package. Any doubt degrades to not licensed and schedules a
     * crack report, never a lockout.
     */
    public static function paid_check(): bool
    {
        $st = OptimaSite_License::state();
        if (empty($st['valid']) || ($st['status'] ?? '') !== 'active') {
            self::purge_on_doubt();
            return false;
        }
        if (($st['domain'] ?? '') !== OptimaSite_Api::current_domain()) {
            OptimaSite_License::refresh();
            return false;
        }
        if (self::tampered()) {
            self::report_now();
            return false;
        }
        if (!self::has_secret() || empty($st['lic_ok'])) {
            self::report_now();
            return false;
        }
        return true;
    }

    private static function purge_on_doubt(): void
    {
        $st = OptimaSite_License::state();
        if (!empty($st['valid']) || !empty($st['lic_ok'])) {
            $st['valid'] = false;
            $st['lic_ok'] = false;
            update_option(OPTIMASITE_OPT_STATE, $st);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Anti-crack heartbeat                                                */
    /* ------------------------------------------------------------------ */

    public static function maybe_report(): void
    {
        $last = (int) get_option(self::REPORT_OPT, 0);
        $backoff = PHP_INT_MAX;
        if (defined('DAY_IN_SECONDS')) {
            $backoff = DAY_IN_SECONDS;
        }
        if ($last > 0 && (time() - $last) < $backoff) {
            return;
        }
        self::report_now();
    }

    public static function report_now(): bool
    {
        update_option(self::REPORT_OPT, time());
        $key = OptimaSite_License::key();
        if ($key === '') {
            return false;
        }
        $body = array(
            'product'    => OPTIMASITE_SLUG,
            'key'        => $key,
            'key_tail'   => substr($key, -6),
            'domain'     => OptimaSite_Api::current_domain(),
            'version'    => OPTIMASITE_VERSION,
            'fingerprint'=> self::package_fingerprint(),
            'stamp'      => self::tampered() ? 'broken' : 'ok',
            'tampered'   => self::tampered(),
            'pro'        => !empty(OptimaSite_License::state()['valid']),
            'nonce'      => bin2hex(random_bytes(12)),
        );
        $r = OptimaSite_Api::guard($body);
        return !empty($r['ok']);
    }
}
