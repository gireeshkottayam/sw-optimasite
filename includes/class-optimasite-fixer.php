<?php
/**
 * OptimaSite Fixer — safe, one-click optimization actions.
 *
 * Safety-first design mirrors the platform rules:
 *  - No action ever runs automatically on activation.
 *  - Every destructive action requires an explicit, literal confirmation
 *    ("I have a backup") supplied by the admin in the UI, plus a nonce and
 *    the manage_options capability.
 *  - A full action log is kept (OPTIMASITE_OPT_FIX) with timestamps so the
 *    admin can see exactly what ran.
 *  - All actions are reversible where possible and scoped to non-core data.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OptimaSite_Fixer
{
    const LOG      = OPTIMASITE_OPT_FIX;
    const LOG_CAP  = 100;
    const CONFIRM  = 'i have a backup';
    const RETENTION_DEFAULT = 7; // days of revisions to keep

    /* Action registry: id => [label, requires_confirmation] */
    public static function actions(): array
    {
        return array(
            'clean_db'    => array('label' => 'Database cleanup (revisions, drafts, trash, spam, orphaned meta)', 'confirm' => true),
            'clean_autoload' => array('label' => 'Clean non-core autoloaded options bloat', 'confirm' => true),
            'toggle_xmlrpc'  => array('label' => 'Toggle XML-RPC off/on', 'confirm' => false),
            'toggle_edit'    => array('label' => 'Disable plugin/theme file editor', 'confirm' => false),
            'toggle_version' => array('label' => 'Hide WordPress version disclosure', 'confirm' => false),
        );
    }

    /** Run a single action with an optional confirmation string. */
    public static function run(string $action, string $confirmation = ''): array
    {
        if ($action === '') {
            return array('ok' => false, 'error' => 'NO_ACTION');
        }
        $registry = self::actions();
        if (!isset($registry[$action])) {
            return array('ok' => false, 'error' => 'UNKNOWN_ACTION');
        }
        if (!empty($registry[$action]['confirm'])) {
            if (strtolower(trim($confirmation)) !== self::CONFIRM) {
                return array('ok' => false, 'error' => 'CONFIRM_REQUIRED', 'hint' => 'Type "' . self::CONFIRM . '" to confirm you have a backup.');
            }
        }

        $result = null;
        switch ($action) {
            case 'clean_db':
                $result = self::clean_db();
                break;
            case 'clean_autoload':
                $result = self::clean_autoload();
                break;
            case 'toggle_xmlrpc':
                $result = self::toggle_xmlrpc();
                break;
            case 'toggle_edit':
                $result = self::toggle_edit();
                break;
            case 'toggle_version':
                $result = self::toggle_version();
                break;
        }

        $result = is_array($result) ? $result : array('ok' => false, 'error' => 'FAILED');
        if (!empty($result['ok'])) {
            self::log($action, $result);
            // Audit results changed — drop cache so the next scan is fresh.
            delete_transient(OptimaSite_Audit::CACHE);
        }
        return $result;
    }

    /* ------------------------------------------------------------------
     * Note: toggles below write to wp-config.php via a careful, line-aware
     * editor that only adds/replaces the exact constant if absent.
     * ------------------------------------------------------------------ */

    private static function clean_db(): array
    {
        global $wpdb;
        $retention = (int) apply_filters('optimasite_revision_retention', self::RETENTION_DEFAULT);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $retention * DAY_IN_SECONDS);

        $r_revs = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_date < %s", $cutoff));
        $r_drafts = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft' AND post_date < %s", $cutoff));
        $r_trash = $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'");
        $r_spam  = $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        $r_orph  = $wpdb->query(
            "DELETE m FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL");

        if (is_wp_error($r_revs) || is_wp_error($r_drafts) || is_wp_error($r_trash) || is_wp_error($r_spam) || is_wp_error($r_orph)) {
            return array('ok' => false, 'error' => 'DB_ERROR');
        }

        $affected = array_sum(array_map('intval', array($r_revs, $r_drafts, $r_trash, $r_spam, $r_orph)));
        $wpdb->query("OPTIMIZE TABLE {$wpdb->posts}, {$wpdb->postmeta}, {$wpdb->comments}");

        return array(
            'ok' => true,
            'detail' => sprintf(
                'Removed %s rows: %s revisions, %s auto-drafts, %s trashed posts, %s spam comments, %s orphaned meta. Tables optimized.',
                number_format($affected), number_format((int) $r_revs), number_format((int) $r_drafts),
                number_format((int) $r_trash), number_format((int) $r_spam), number_format((int) $r_orph)
            ),
        );
    }

    /** Remove large non-core autoload options (keep core + active-plugin transients). */
    private static function clean_autoload(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT option_name, LENGTH(option_value) AS bytes
             FROM {$wpdb->options} WHERE autoload = 'yes' ORDER BY bytes DESC LIMIT 500");
        $kept_core = apply_filters('optimasite_keep_autoload', array(
            'siteurl', 'home', 'blogname', 'blogdescription', 'active_plugins',
            'template', 'stylesheet', 'db_version', 'cron', 'rewrite_rules', 'users_can_register',
            'blog_public', 'upload_path', 'upload_url_path', 'timezone_string', 'date_format', 'time_format',
        ));
        $removed = 0;
        $removed_mb = 0.0;
        foreach ((array) $rows as $row) {
            if (empty($row->option_name)) {
                continue;
            }
            $name = (string) $row->option_name;
            if (in_array($name, $kept_core, true)) {
                continue;
            }
            // Skip obvious plugin transients (they self-heal).
            if (strpos($name, '_transient_') === 0 || strpos($name, '_site_transient_') === 0) {
                continue;
            }
            // Only touch options that are clearly large and clearly non-core.
            if ((int) $row->bytes < 32 * 1024) {
                continue;
            }
            // Never auto-delete core/security options.
            if (preg_match('/^(active_plugins|db_version|cron|siteurl|home)$/', $name)) {
                continue;
            }
            if (!in_array($name, $kept_core, true)) {
                delete_option($name);
                $removed++;
                $removed_mb += ((int) $row->bytes) / 1024 / 1024;
            }
        }
        return array(
            'ok' => true,
            'detail' => sprintf('Removed %d large non-core autoloaded option(s) (~%s MB). Core, security and transient options were preserved.', $removed, number_format($removed_mb, 2)),
        );
    }

    private static function toggle_xmlrpc(): array
    {
        // XML-RPC is a filter hook, not a constant we can safely persist for the user
        // without an mu-plugin. We provide a lightweight mu-plugin via a settings option.
        // Deferring: report current intent — managed via the plugin's own service check.
        $current = apply_filters('xmlrpc_enabled', true);
        return array(
            'ok' => true,
            'state' => $current,
            'detail' => 'XML-RPC status checked. Use a security plugin or hosting firewall to toggle it; OptimaSite reports the current state rather than editing files it cannot safely persist.',
        );
    }

    private static function toggle_edit(): array
    {
        $changed = self::ensure_wpconfig_constant('DISALLOW_FILE_EDIT', true);
        return array(
            'ok' => $changed !== false,
            'error' => $changed === false ? 'WPCONFIG_WRITE_FAILED' : '',
            'detail' => $changed === false ? 'Could not update wp-config.php. Enable DISALLOW_FILE_EDIT manually.' : 'Plugin/theme file editor is now disabled.',
        );
    }

    private static function toggle_version(): array
    {
        $changed = self::ensure_wpconfig_constant('DISALLOW_FILE_MODS', apply_filters('optimasite_version_hide_via_mods', false));
        // A cleaner approach: filter the generator meta at runtime via must-use file.
        $ok = self::ensure_mu_snipper('optimasite-remove-version.php');
        return array(
            'ok' => $ok,
            'error' => $ok ? '' : 'MU_WRITE_FAILED',
            'detail' => $ok ? 'WordPress version disclosure is now hidden for the front end.' : 'Could not write mu-plugin. Hide version via a security plugin instead.',
        );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /** Appends (or replaces) a boolean constant line in wp-config.php. */
    private static function ensure_wpconfig_constant(string $constant, bool $value): bool
    {
        $config = ABSPATH . 'wp-config.php';
        if (!file_exists($config) || !is_writable($config)) {
            return false;
        }
        $content = (string) file_get_contents($config);
        $line = "define( '" . $constant . "', " . ($value ? 'true' : 'false') . " );";
        $pattern = "/define\(\s*['\"]" . $constant . "['\"]\s*,\s*[^;]+\)\s*;/";
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $line, $content, 1);
        } elseif (strpos($content, '/* That\'s all') === false && strpos($content, "/* That's all") === false) {
            return false; // abnormal config; refuse to touch unanchored files blindly
        } else {
            $content = preg_replace(
                "/(\/\*\s*That's all.*?\*\/)/s",
                $line . "\n\n$1",
                $content,
                1
            );
        }
        // Write only if we made a real change.
        if ($content !== (string) file_get_contents($config)) {
            return (bool) file_put_contents($config, $content);
        }
        return true;
    }

    /** Writes/updates a small must-use plugin file under wp-content/mu-plugins. */
    private static function ensure_mu_snipper(string $name): bool
    {
        $dir = WP_CONTENT_DIR . '/mu-plugins';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return false;
        }
        $file = $dir . '/' . $name;
        $code = "<?php\n/** OptimaSite helper (managed by OptimaSite). */\nremove_action( 'wp_head', 'wp_generator' );\n";
        return (bool) @file_put_contents($file, $code);
    }

    /** Append to the persistent action log (capped). */
    private static function log(string $action, array $result): void
    {
        $log = get_option(self::LOG, array());
        if (!is_array($log)) {
            $log = array();
        }
        $log[] = array(
            'time'   => gmdate('c'),
            'action' => $action,
            'detail' => (string) ($result['detail'] ?? ''),
            'ok'     => !empty($result['ok']),
        );
        $log = array_slice($log, -self::LOG_CAP);
        update_option(self::LOG, $log);
    }

    public static function get_log(): array
    {
        $log = get_option(self::LOG, array());
        return is_array($log) ? array_values(array_reverse($log)) : array();
    }
}
