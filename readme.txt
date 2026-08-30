=== OptimaSite — Site Health & Optimization Auditor ===
Contributors: sharewire
Tags: site health, audit, optimization, performance, security, database, cleanup, checklist
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later

A complete local site health and optimization audit with safe, one-click fixes — plugin conflicts and bloat, database bloat, slow queries, Core Web Vitals readiness, security hardening and obsolete setup. Single-domain license with embedded Razorpay checkout and auto-updates, powered by ShareWire.in.

== Description ==

**OptimaSite** turns WordPress's own diagnostics into an **actionable, one-click
health and optimization audit** for your site. Every check runs **locally on
your server** — no external service, no SaaS, no API key. Buy once (₹999,
lifetime updates), activate on ONE domain, and keep your site fast, clean and
secure.

* **Plugin conflict & bloat detector** — flags outdated, abandoned, duplicate and resource-heavy plugins.
* **Database bloat analyzer** — revisions, auto-drafts, trash, spam, orphaned meta, oversized tables; MB before/after.
* **Slow query / database health** — storage engine, missing indexes, autoload option bloat.
* **Core Web Vitals readiness** — PHP version, object/page caching, image sizes, TTFB guidance, lazy loading.
* **Security hardening checklist** — file permissions, XML-RPC, file editor, version disclosure, salts, debug logs, unused themes.
* **Obsolete/risky setup** — core version, HTTPS/SSL, backups, cron health.
* **Safe one-click fixes** — database cleanup with configurable revision retention, autoload bloat cleanup, and reversible security toggles. Nothing runs automatically; destructive actions require you to confirm you have a backup.

== Installation ==

1. Upload the `sw-optimasite` folder to `/wp-content/plugins/` (or install the .zip from your ShareWire portal), then **Activate**.
2. Go to the **OptimaSite** menu.
3. If you haven't bought yet: click **Pay with Razorpay** (your current site domain is pre-filled — keep it on one site).
4. If you already have a key: enter it and click **Activate**.
5. Open the **Site Health Audit** and run your first scan, then apply the fixes you choose.

== Frequently Asked Questions ==

= Is the license limited to one domain? = Yes. An OptimaSite license is valid for one exact domain. You can change that domain later from your ShareWire portal, but only one site can be active at a time.

= Do the checks send my data anywhere? = No. Every audit check runs entirely on your own server and nothing leaves your site. OptimaSite only contacts ShareWire.in to verify your license and check for updates.

= Does OptimaSite need an API key? = No. All checks are local. It deliberately avoids any external service or API key.

= How do updates work? = OptimaSite uses the standard WordPress updater. On your Plugins → Installed Plugins screen, an update appears whenever ShareWire publishes a new build for your version. Updates are only offered when your license is active for that exact domain.

= Are the fixes safe? = Yes. Nothing runs automatically on activation. Database and cleanup actions require you to confirm you have a backup before they run, and everything is recorded in an action log. You choose which fixes to apply.

== Changelog ==

= 1.0.0 =
* Initial release: full local Site Health audit (plugins, database, DB health, Core Web Vitals, security, setup), safe one-click fixes, embedded Razorpay checkout, single-domain activation and native WordPress auto-updates via ShareWire.
