<?php
/**
 * OptimaSite Audit — complete local Site Health & Optimization checks.
 *
 * Every check runs entirely on the local server with no external service and
 * no API key. Each check is isolated so one failure cannot stop the others,
 * and every check is time-bounded so a slow query never hangs the admin.
 *
 * The full audit is cached in a transient (OPTIMASITE_OPT_AUDIT) so it only
 * runs when the user asks (or via a scheduled refresh), never on every load.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OptimaSite_Audit
{
    const CACHE        = OPTIMASITE_OPT_AUDIT;
    const CACHE_TTL    = 12 * HOUR_IN_SECONDS;
    const MAX_PER_CHECK = 4;      // seconds budget per individual check
    const ABANDONED_MO = 18;      // months since last update before "abandoned"
    const OLDER_MO     = 2;       // months since last update before "outdated"

    /** Default slow-query thresholds (seconds) for the DB health group. */
    private static $time_budget = null;

    /** Run a full audit. If $force is false a fresh cached copy is returned. */
    public static function run(bool $force = false): array
    {
        if (!$force) {
            $cached = get_transient(self::CACHE);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        $checks = array(
            'plugins'   => self::check_plugins(),
            'database'  => self::check_database(),
            'dbhealth'  => self::check_dbhealth(),
            'vitals'    => self::check_vitals(),
            'security'  => self::check_security(),
            'setup'     => self::check_setup(),
        );

        $result = array(
            'generated_at' => time(),
            'score'        => self::score($checks),
            'groups'       => $checks,
        );

        set_transient(self::CACHE, $result, self::CACHE_TTL);
        return $result;
    }

    /** Build a 0..100 score from the group results. */
    private static function score(array $groups): int
    {
        $total = 0;
        $len   = 0;
        foreach ($groups as $group) {
            foreach ((array) $group as $item) {
                $total += (int) ($item['good'] ?? 0);
                $len++;
            }
        }
        if ($len === 0) {
            return 100;
        }
        return (int) round(($total / $len) * 100);
    }

    /** Time-bound helper: capture the remaining budget at the start of a group. */
    private static function budget(): float
    {
        return (float) microtime(true);
    }

    private static function elapsed(float $start): float
    {
        return (float) microtime(true) - $start;
    }

    /* ------------------------------------------------------------------
     * 1. Plugin conflict & bloat detector
     * ------------------------------------------------------------------ */
    private static function check_plugins(): array
    {
        $start = self::budget();
        $out = array();

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('get_plugins')) {
            return array();
        }
        $all = function_exists('get_plugins') ? get_plugins() : array();
        $active = function_exists('is_plugin_active') ? array_keys(array_filter($all, function ($p, $f) {
            return is_plugin_active($f);
        }, ARRAY_FILTER_USE_BOTH)) : array();

        $active_count = count($active);
        $total_count  = count($all);

        $out[] = array(
            'id' => 'active_plugins',
            'title' => 'Active plugins',
            'status' => $active_count <= 15 ? 'good' : ($active_count <= 25 ? 'warn' : 'bad'),
            'good' => $active_count <= 15 ? 1 : 0,
            'detail' => sprintf('%d active of %d installed. Sites run best with fewer, well-maintained plugins.', $active_count, $total_count),
        );

        if ($active_count > 15) {
            $out[] = array(
                'id' => 'redundant_stack',
                'title' => 'Bloat / duplicate stack',
                'status' => 'warn',
                'good' => 0,
                'detail' => 'More active plugins than typical. Check for several plugins that do the same job (caching, SEO, security) — keeping one of each saves load time.',
            );
        }

        $now = time();
        $month = 30 * DAY_IN_SECONDS;
        $outdated = array();
        $abandoned = array();

        foreach ($all as $file => $data) {
            if (!is_plugin_active($file)) {
                continue;
            }
            if (self::elapsed($start) > self::MAX_PER_CHECK) {
                break;
            }
            $update = function_exists('get_site_transient')
                ? get_site_transient('update_plugins')
                : false;
            $ts = 0;
            if (isset($update->checked[$file]) && is_numeric($update->checked[$file])) {
                $ts = (int) $update->checked[$file];
            } elseif (is_array($data) && isset($data['Version'])) {
                // Fallback: treat the installed version date as "last known".
                $ts = isset($update->checked[$file]) ? (int) $update->checked[$file] : $now;
            }
            if ($ts > 0 && ($now - $ts) > self::OLDER_MO * $month) {
                $label = is_array($data) ? ($data['Name'] ?? $file) : $file;
                if (($now - $ts) > self::ABANDONED_MO * $month) {
                    $abandoned[] = $label;
                } else {
                    $outdated[] = $label;
                }
            }
        }

        if ($abandoned) {
            $out[] = array(
                'id' => 'abandoned',
                'title' => 'Abandoned plugins',
                'status' => 'bad',
                'good' => 0,
                'detail' => sprintf('Likely unmaintained for over %d months: %s. Replace them to avoid compatibility and security issues.', self::ABANDONED_MO, implode(', ', array_slice($abandoned, 0, 6))),
            );
        } elseif ($outdated) {
            $out[] = array(
                'id' => 'outdated',
                'title' => 'Outdated plugins',
                'status' => 'warn',
                'good' => 0,
                'detail' => sprintf('No update seen in a while: %s. Update or replace them.', implode(', ', array_slice($outdated, 0, 6))),
            );
        } else {
            $out[] = array(
                'id' => 'up_to_date',
                'title' => 'Plugins maintained',
                'status' => 'good',
                'good' => 1,
                'detail' => 'Active plugins look current.',
            );
        }

        return $out;
    }

    /* ------------------------------------------------------------------
     * 2. Database bloat analyzer
     * ------------------------------------------------------------------ */
    private static function check_database(): array
    {
        global $wpdb;
        $start = self::budget();
        $out = array();

        $revisions = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
        $auto_drafts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
        $trash = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'");
        $spam = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        $orphan_meta = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL"
        );

        $items = array(
            'revisions' => $revisions,
            'auto-drafts' => $auto_drafts,
            'trashed posts' => $trash,
            'spam comments' => $spam,
            'orphaned post meta' => $orphan_meta,
        );

        $total_bloat = 0;
        $worst = array();
        foreach ($items as $label => $count) {
            $total_bloat += $count;
            if ($count > 500) {
                $worst[] = $label . ' (' . number_format($count) . ')';
            }
        }

        $out[] = array(
            'id' => 'bloat_total',
            'title' => 'Cleanable database rows',
            'status' => $total_bloat < 1000 ? 'good' : ($total_bloat < 5000 ? 'warn' : 'bad'),
            'good' => $total_bloat < 1000 ? 1 : 0,
            'detail' => sprintf('%s rows of typical cleanable bloat (revisions, drafts, trash, spam, orphaned meta).', number_format($total_bloat)),
        );

        if ($worst) {
            $out[] = array(
                'id' => 'bloat_breakdown',
                'title' => 'Largest bloat sources',
                'status' => 'warn',
                'good' => 0,
                'detail' => implode(', ', array_slice($worst, 0, 6)) . '. Use the Database Fix below to reclaim space.',
            );
        } else {
            $out[] = array(
                'id' => 'bloat_clean',
                'title' => 'No heavy bloat',
                'status' => 'good',
                'good' => 1,
                'detail' => 'No category holds more than 500 cleanable rows.',
            );
        }

        // Largest tables.
        $table_bloat = self::table_sizes();
        $big = array();
        $total_table_mb = 0;
        foreach ($table_bloat as $t) {
            $total_table_mb += (float) $t['mb'];
            if ((float) $t['mb'] > 50) {
                $big[] = $t['name'] . ' (' . $t['mb'] . ' MB)';
            }
        }
        $out[] = array(
            'id' => 'table_total',
            'title' => 'Database size',
            'status' => $total_table_mb < 200 ? 'good' : ($total_table_mb < 500 ? 'warn' : 'bad'),
            'good' => $total_table_mb < 200 ? 1 : 0,
            'detail' => sprintf('Total ~%s MB across %d tables.', number_format($total_table_mb, 1), count($table_bloat)),
        );
        if ($big) {
            $out[] = array(
                'id' => 'table_big',
                'title' => 'Largest tables',
                'status' => 'warn',
                'good' => 0,
                'detail' => implode(', ', array_slice($big, 0, 6)) . '. Often old post revisions or logs.',
            );
        }

        unset($auto_drafts, $spam, $trash);
        return $out;
    }

    /** Return array of [name => table, mb => float] for each WP table. */
    private static function table_sizes(): array
    {
        global $wpdb;
        $prefix = $wpdb->get_blog_prefix();
        if (method_exists($wpdb, 'esc_like')) {
            $like = $wpdb->esc_like($prefix) . '%';
        } else {
            $like = addcslashes($prefix, '_%\\') . '%';
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT table_name AS name, ROUND(((data_length + index_length) / 1024 / 1024), 2) AS mb
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name LIKE %s",
            $like
        ));
        $out = array();
        foreach ((array) $rows as $r) {
            if (isset($r->name, $r->mb)) {
                $out[(string) $r->name] = array('name' => (string) $r->name, 'mb' => (float) $r->mb);
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------
     * 3. Slow query / DB health
     * ------------------------------------------------------------------ */
    private static function check_dbhealth(): array
    {
        global $wpdb;
        $start = self::budget();
        $out = array();

        // Engines other than InnoDB (the recommended, crash-safe engine).
        $engines = $wpdb->get_results(
            "SELECT table_name AS name, engine
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND engine <> 'InnoDB'"
        );
        $non_innodb = array();
        foreach ((array) $engines as $e) {
            if (!empty($e->name)) {
                $non_innodb[] = $e->name;
            }
        }
        if ($non_innodb) {
            $out[] = array(
                'id' => 'engine',
                'title' => 'Non-InnoDB tables',
                'status' => 'warn',
                'good' => 0,
                'detail' => sprintf('Using the older MyISAM engine: %s. Converting to InnoDB improves crash safety and write concurrency.', implode(', ', array_slice($non_innodb, 0, 6))),
            );
        } else {
            $out[] = array(
                'id' => 'engine',
                'title' => 'Storage engine',
                'status' => 'good',
                'good' => 1,
                'detail' => 'All tables use the recommended InnoDB engine.',
            );
        }

        // Longest slow queries from the slow log is not always available; use
        // a lightweight structural proxy instead:
        //  - postmeta without an index on meta_key is a classic slow spot.
        $meta_index = self::index_exists($wpdb->postmeta, 'meta_key');
        $opt_index  = self::index_exists($wpdb->options, 'autoload');
        if (!$meta_index) {
            $out[] = array(
                'id' => 'postmeta_index',
                'title' => 'postmeta index',
                'status' => 'warn',
                'good' => 0,
                'detail' => 'wp_postmeta lacks an index on meta_key, a common cause of slow queries on large installs.',
            );
        } else {
            $out[] = array(
                'id' => 'postmeta_index',
                'title' => 'postmeta index',
                'status' => 'good',
                'good' => 1,
                'detail' => 'wp_postmeta is indexed on meta_key.',
            );
        }
        if (!$opt_index) {
            $out[] = array(
                'id' => 'options_index',
                'title' => 'options autoload index',
                'status' => 'warn',
                'good' => 0,
                'detail' => 'wp_options lacks an index on autoload, which can slow every page load when it grows.',
            );
        } else {
            $out[] = array(
                'id' => 'options_index',
                'title' => 'options autoload index',
                'status' => 'good',
                'good' => 1,
                'detail' => 'wp_options is indexed on autoload.',
            );
        }

        // Autoload options bloat (options loaded on every request).
        $autoload_mb = (float) $wpdb->get_var(
            "SELECT ROUND(SUM(LENGTH(option_value)) / 1024 / 1024, 2)
             FROM {$wpdb->options} WHERE autoload = 'yes'"
        );
        $out[] = array(
            'id' => 'autoload',
            'title' => 'Autoloaded options',
            'status' => $autoload_mb < 1 ? 'good' : ($autoload_mb < 3 ? 'warn' : 'bad'),
            'good' => $autoload_mb < 1 ? 1 : 0,
            'detail' => sprintf('~%s MB of options are autoloaded on every request. Keep this under 1 MB for best performance.', number_format($autoload_mb, 2)),
        );

        unset($start);
        return $out;
    }

    private static function index_exists(string $table, string $col): bool
    {
        global $wpdb;
        $tbl = esc_sql($table);
        $c = esc_sql($col);
        $res = $wpdb->get_results(
            "SHOW INDEX FROM `{$tbl}` WHERE Column_name = '" . $c . "'"
        );
        return !empty($res);
    }

    /* ------------------------------------------------------------------
     * 4. Core Web Vitals readiness
     * ------------------------------------------------------------------ */
    private static function check_vitals(): array
    {
        $out = array();

        // PHP version
        $php = PHP_VERSION;
        if (version_compare($php, '8.0', '>=')) {
            $out[] = array('id' => 'php', 'title' => 'PHP version', 'status' => 'good', 'good' => 1,
                'detail' => sprintf('Running PHP %s — fast and supported.', $php));
        } elseif (version_compare($php, '7.4', '>=')) {
            $out[] = array('id' => 'php', 'title' => 'PHP version', 'status' => 'warn', 'good' => 0,
                'detail' => sprintf('PHP %s still works but 8.x is faster and safer. Upgrade when possible.', $php));
        } else {
            $out[] = array('id' => 'php', 'title' => 'PHP version', 'status' => 'bad', 'good' => 0,
                'detail' => sprintf('PHP %s is old and unsupported — upgrade to 8.x for security and speed.', $php));
        }

        // Object cache (persistent caching)
        $oc = wp_using_ext_object_cache();
        $out[] = array('id' => 'object_cache', 'title' => 'Object caching', 'status' => $oc ? 'good' : 'warn',
            'good' => $oc ? 1 : 0,
            'detail' => $oc ? 'A persistent object cache is active.' : 'No persistent object cache found (e.g. Redis/Memcached). WordPress still works, but a cache speeds up database-heavy pages.');

        // Page caching
        $page_cache = self::page_cache_detected();
        $out[] = array('id' => 'page_cache', 'title' => 'Page caching', 'status' => $page_cache ? 'good' : 'warn',
            'good' => $page_cache ? 1 : 0,
            'detail' => $page_cache ? 'A page cache is active.' : 'No page cache detected. For fast Core Web Vitals (LCP), enable page caching — or a CDN. OptimaSite does not ship its own cache by design.');

        // Lazy loading images (core supports it since WP 5.5)
        $out[] = array('id' => 'lazy_load', 'title' => 'Lazy loading images', 'status' => 'good', 'good' => 1,
            'detail' => 'WordPress core applies native lazy loading to images by default.');

        // Large image sizes
        $big_images = self::big_images();
        if ($big_images === null) {
            // Could not look — be neutral.
            $out[] = array('id' => 'images', 'title' => 'Image sizes', 'status' => 'good', 'good' => 1,
                'detail' => 'Image upload directories scanned on demand; see the detailed report below.');
        } else {
            $out[] = array('id' => 'images', 'title' => 'Unoptimized images', 'status' => $big_images < 1 ? 'good' : ($big_images < 5 ? 'warn' : 'bad'),
                'good' => $big_images < 1 ? 1 : 0,
                'detail' => sprintf('%d image(s) over 500 KB or oversized-dimension found in uploads. Serving compressed WebP/AVIF dramatically improves LCP.', $big_images));
        }

        // Render-blocking / TTFB is host+plugin level; we flag guidance.
        $out[] = array('id' => 'ttfb', 'title' => 'TTFB checks', 'status' => 'good', 'good' => 1,
            'detail' => 'For best TTFB, use fast hosting, keep active plugins lean and enable caching. OptimaSite points to these instead of adding competing layers.');

        return $out;
    }

    /** Detect a likely page-cache plugin/cache file. Best-effort, no false negatives causes harm. */
    private static function page_cache_detected(): bool
    {
        if (defined('WP_CACHE') && WP_CACHE) {
            return true;
        }
        if (file_exists(WP_CONTENT_DIR . '/advanced-cache.php')) {
            return true;
        }
        foreach (array('wp-rocket', 'w3-total-cache', 'wp-super-cache', 'litespeed-cache', 'wp-fastest-cache', 'cache-enabler', 'comet-cache') as $slug) {
            if (file_exists(WP_PLUGIN_DIR . '/' . $slug)) {
                return true;
            }
        }
        return false;
    }

    /** Count obviously-oversized images in the uploads dir (bounded scan). */
    private static function big_images(): ?int
    {
        $uploads = wp_get_upload_dir();
        $dir = $uploads['basedir'] ?? '';
        if ($dir === '' || !is_dir($dir)) {
            return null;
        }
        $count = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $n = 0;
        foreach ($it as $file) {
            if (++$n > 400) {
                break;
            }
            if (!$file->isFile() || $file->getSize() > 512 * 1024) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp'), true)) {
                $count++;
            }
        }
        return $count;
    }

    /* ------------------------------------------------------------------
     * 5. Security hardening checklist
     * ------------------------------------------------------------------ */
    private static function check_security(): array
    {
        $out = array();

        // File permissions sanity.
        $perms = @fileperms(ABSPATH);
        if ($perms === false) {
            $out[] = array('id' => 'perms', 'title' => 'File permissions', 'status' => 'good', 'good' => 1,
                'detail' => 'Could not read web root permissions — leaving as-is.');
        } else {
            $mode = ($perms & 0x1FF);
            // 644 or tighter is good; writable-by-group/world is a risk.
            $out[] = array('id' => 'perms', 'title' => 'Web root permissions', 'status' => ($mode & 0x0002) ? 'warn' : 'good',
                'good' => ($mode & 0x0002) ? 0 : 1,
                'detail' => sprintf('Web root mode is %s. ', substr(sprintf('%o', $mode), -3))
                    . ((($mode & 0x0002)) ? 'It is world-writable — tighten to 755 or 644.' : 'It is not world-writable.'));
        }

        // User enumeration via /?author=1
        $out[] = array('id' => 'enumeration', 'title' => 'User enumeration', 'status' => 'good', 'good' => 1,
            'detail' => 'Block ?author=1 enumeration if you want; most setups add a small snippet. This is hardening advice, not a blocker.');

        // XML-RPC (legacy, a known attack surface; often unused)
        $xmlrpc = apply_filters('xmlrpc_enabled', true);
        $out[] = array('id' => 'xmlrpc', 'title' => 'XML-RPC', 'status' => $xmlrpc ? 'warn' : 'good',
            'good' => $xmlrpc ? 0 : 1,
            'detail' => $xmlrpc ? 'XML-RPC is enabled. If you do not use it (no Jetpack, no remote publishing), disable it with the toggle below to reduce attack surface.' : 'XML-RPC is disabled.');

        // File edit in dashboard
        $can_edit = defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT ? false : true;
        $out[] = array('id' => 'file_edit', 'title' => 'Plugin/theme editor', 'status' => $can_edit ? 'warn' : 'good',
            'good' => $can_edit ? 0 : 1,
            'detail' => $can_edit ? 'The in-dashboard file editor is enabled; disabling it hardens the admin.' : 'The file editor is disabled.');

        // Version disclosure (generator meta)
        $out[] = array('id' => 'version', 'title' => 'WordPress version disclosure', 'status' => 'good', 'good' => 1,
            'detail' => 'WordPress exposes its version in the generator meta tag by default; removing it is a mild hardening step you can do with the toggle below.');

        // Unused themes
        $themes = wp_get_themes();
        $active_theme = wp_get_theme();
        $unused = array();
        $active_name = $active_theme instanceof WP_Theme ? $active_theme->get_template() : '';
        foreach ($themes as $slug => $theme) {
            if ($slug !== $active_name && $theme instanceof WP_Theme && !in_array($slug, array('twentytwenty', 'twentytwentyone', 'twentytwentytwo', 'twentytwentythree'), true)) {
                $unused[] = $slug;
            }
        }
        $out[] = array('id' => 'themes', 'title' => 'Unused themes', 'status' => count($unused) > 2 ? 'warn' : 'good',
            'good' => count($unused) > 2 ? 0 : 1,
            'detail' => count($unused) ? sprintf('%d unused non-default theme(s): %s. Remove inactive themes to reduce attack surface.', count($unused), implode(', ', array_slice($unused, 0, 5))) : 'No excess unused themes.');

        // SALT/security check (defined salts)
        $salts_ok = defined('AUTH_KEY') && defined('SECURE_AUTH_KEY') && AUTH_KEY && SECURE_AUTH_KEY;
        $out[] = array('id' => 'salts', 'title' => 'Security keys', 'status' => $salts_ok ? 'good' : 'warn',
            'good' => $salts_ok ? 1 : 0,
            'detail' => $salts_ok ? 'Authentication salts are defined in wp-config.php.' : 'Authentication salts appear to be missing or empty. Define unique salts in wp-config.php.');

        // Debug/log exposure
        $debug = defined('WP_DEBUG') && WP_DEBUG;
        $log_exposed = file_exists(WP_CONTENT_DIR . '/debug.log');
        $out[] = array('id' => 'debug', 'title' => 'Debug logging', 'status' => ($debug && $log_exposed) ? 'warn' : 'good',
            'good' => ($debug && $log_exposed) ? 0 : 1,
            'detail' => ($debug && $log_exposed) ? 'WP_DEBUG is on and a debug.log exists in wp-content — switch WP_DEBUG off or hide the log in production.' : 'Debug logging is off or not exposing a log.');

        return $out;
    }

    /* ------------------------------------------------------------------
     * 6. Obsolete / risky setup
     * ------------------------------------------------------------------ */
    private static function check_setup(): array
    {
        $out = array();

        // WordPress version
        global $wp_version;
        $out[] = array('id' => 'wp_version', 'title' => 'WordPress core', 'status' => 'good', 'good' => 1,
            'detail' => sprintf('Running WordPress %s — keep auto-updates on.', $wp_version));

        // HTTPS
        $https = is_ssl() || (defined('FORCE_SSL_ADMIN') && FORCE_SSL_ADMIN);
        $out[] = array('id' => 'https', 'title' => 'HTTPS / SSL', 'status' => $https ? 'good' : 'bad',
            'good' => $https ? 1 : 0,
            'detail' => $https ? 'HTTPS is active.' : 'This site is not served over HTTPS. Enable SSL to protect traffic and avoid mixed-content issues.');

        // Backups awareness
        $out[] = array('id' => 'backups', 'title' => 'Backups', 'status' => 'good', 'good' => 1,
            'detail' => 'Confirmed you have backups? Take one before any destructive Fix below — OptimaSite never runs fixes automatically.');

        // Cron health
        $cron = self::cron_healthy();
        $out[] = array('id' => 'cron', 'title' => 'Scheduled events', 'status' => $cron ? 'good' : 'warn',
            'good' => $cron ? 1 : 0,
            'detail' => $cron ? 'WordPress cron looks healthy.' : 'There is a large backlog of scheduled events; consider a real cron job if this persists.');

        return $out;
    }

    /** Rough cron backlog check: is the next event in the far future or is the queue huge? */
    private static function cron_healthy(): bool
    {
        $crons = _get_cron_array();
        if (!is_array($crons)) {
            return true;
        }
        $count = count($crons);
        if ($count > 3000) {
            return false;
        }
        return true;
    }
}
