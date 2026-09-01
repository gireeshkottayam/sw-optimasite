<?php
/**
 * OptimaSite License — validity state machine + single-domain gate.
 *
 * Host plugins can call optimasite_active() / OptimaSite_License::is_valid()
 * to gate their features; updates flow only when valid + bound to THIS domain.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OptimaSite_License
{
    const KEY_OPT   = OPTIMASITE_OPT_KEY;
    const STATE_OPT = OPTIMASITE_OPT_STATE;
    const CRON      = OPTIMASITE_CRON;
    const TTL       = 6 * HOUR_IN_SECONDS;

    public static function init(): void
    {
        add_action(self::CRON, array(__CLASS__, 'refresh'));
    }

    public static function activate(): void
    {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    public static function key(): string
    {
        return (string) get_option(self::KEY_OPT, '');
    }

    public static function set_key(string $key): void
    {
        update_option(self::KEY_OPT, $key);
    }

    public static function state(): array
    {
        $s = get_option(self::STATE_OPT);
        return is_array($s) ? $s : array();
    }

    /**
     * Valid when a key is stored AND it is active for THIS domain AND it was
     * re-confirmed within the TTL. Re-validates at most every few hours so
     * revocation / domain transfer binds in.
     */
    public static function is_valid(): bool
    {
        $key = self::key();
        if ($key === '') {
            return (bool) apply_filters('optimasite_active', false);
        }
        $st = self::state();
        if (empty($st['checked_at']) || (time() - (int) $st['checked_at']) > self::TTL
            || ($st['domain'] ?? '') !== OptimaSite_Api::current_domain()) {
            self::refresh();
            $st = self::state();
        }
        $base = (bool) apply_filters('optimasite_active', !empty($st['valid']));
        if (!$base) {
            return false;
        }
        // Anti-crack gate: the paid state only survives when the guard is
        // intact (valid license, valid per license signature, pristine
        // package). Any doubt degrades to not licensed and revalidates.
        return OptimaSite_Guard::paid_check();
    }

    public static function locked(): bool
    {
        return !self::is_valid();
    }

    /** Activate a key for this domain on ShareWire + store locally. */
    public static function activate_key(string $key): array
    {
        $key = trim($key);
        if ($key === '') {
            return array('ok' => false, 'error' => 'EMPTY_KEY');
        }
        $r = OptimaSite_Api::activate($key);
        if (empty($r['ok'])) {
            return $r;
        }
        self::set_key($key);
        OptimaSite_Guard::store_secret((string) ($r['lic_secret'] ?? ''));
        $licOk = OptimaSite_Guard::verify_signed($r);
        OptimaSite_Guard::stamp();
        update_option(self::STATE_OPT, array(
            'valid'      => true,
            'status'     => 'active',
            'domain'     => OptimaSite_Api::current_domain(),
            'site_token' => $r['site_token'] ?? '',
            'key_tail'   => substr($key, -4),
            'lic_ok'     => $licOk,
            'checked_at' => time(),
        ));
        delete_transient('optimasite_product_info');
        do_action('optimasite_activated', $key);
        return array('ok' => true, 'site_token' => $r['site_token'] ?? '');
    }

    /** Deactivate this domain + clear local state. */
    public static function deactivate_key(): array
    {
        $key = self::key();
        if ($key !== '') {
            OptimaSite_Api::deactivate($key);
        }
        delete_option(self::KEY_OPT);
        delete_option(self::STATE_OPT);
        OptimaSite_Guard::clear_secret();
        do_action('optimasite_deactivated');
        return array('ok' => true);
    }

    /** Pull fresh validation from ShareWire. */
    public static function refresh(): array
    {
        $key = self::key();
        if ($key === '') {
            update_option(self::STATE_OPT, array('valid' => false, 'checked_at' => time()));
            return self::state();
        }
        $r = OptimaSite_Api::validate($key);
        $valid = !empty($r['ok']) && ($r['license_status'] ?? '') === 'active';
        $licOk = OptimaSite_Guard::verify_signed($r);
        $prev = self::state();
        $st = array(
            'valid'    => $valid,
            'status'   => $r['license_status'] ?? 'unknown',
            'domain'   => OptimaSite_Api::current_domain(),
            'key_tail' => $prev['key_tail'] ?? substr($key, -4),
            'lic_ok'   => $licOk,
            'checked_at' => time(),
        );
        update_option(self::STATE_OPT, $st);
        if (!empty($prev['valid']) && !$valid) {
            do_action('optimasite_invalid');
            OptimaSite_Guard::clear_secret();
        }
        return $st;
    }
}
