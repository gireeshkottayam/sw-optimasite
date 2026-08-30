/* OptimaSite — admin UI: license checkout/activation, audit, one-click fixes. */
(function (window, document) {
    'use strict';
    var cfg = window.OptimaSite || {};
    var msg = document.getElementById('optimasite-msg');

    function show(text, ok, persist) {
        if (!msg) return;
        msg.hidden = false;
        msg.innerHTML = '<p class="' + (ok ? 'optimasite-ok' : 'optimasite-err') + '">' + escapeHtml(text) + '</p>';
        if (!persist) {
            clearTimeout(show._t);
            show._t = setTimeout(function () { msg.hidden = true; }, 6000);
        }
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function post(action, data, done) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', cfg.nonce);
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'BAD_JSON' }; }); })
            .then(done)
            .catch(function (e) { done({ ok: false, error: 'NETWORK ' + (e && e.message) }); });
    }

    var allBtns = Array.prototype.slice.call(document.querySelectorAll('.optimasite-btn'));
    function busy(b) {
        allBtns.forEach(function (el) { if (el) el.disabled = b; });
    }

    /* ---------- License ---------- */
    var buyBtn = document.getElementById('optimasite-buy-btn');
    var actBtn = document.getElementById('optimasite-activate-btn');
    var keyInp = document.getElementById('optimasite-key-input');
    var domainInp = document.getElementById('optimasite-buy-domain');
    var deactBtn = document.getElementById('optimasite-deactivate-btn');
    var domainsBtn = document.getElementById('optimasite-domains-btn');
    var domainsBox = document.getElementById('optimasite-domains');

    function reload() { setTimeout(function () { window.location.reload(); }, 900); }

    function activateKey(key, okText) {
        busy(true);
        post('optimasite_activate', { key: key }, function (r) {
            busy(false);
            if (r.ok) { if (okText) show(okText, true); reload(); }
            else show('Activation failed: ' + (r.error || 'unknown'), false);
        });
    }

    if (actBtn && keyInp) {
        actBtn.addEventListener('click', function () {
            var key = (keyInp.value || '').trim();
            if (!key) { show(cfg.i18n.enter_key, false); return; }
            activateKey(key, 'License activated for this domain.');
        });
    }

    if (deactBtn) {
        deactBtn.addEventListener('click', function () {
            if (!window.confirm('Deactivate this domain? Updates will stop until you reactivate.')) return;
            busy(true);
            post('optimasite_deactivate', {}, function (r) {
                busy(false);
                if (r.ok) reload(); else show('Deactivate failed: ' + (r.error || 'unknown'), false);
            });
        });
    }

    if (domainsBtn && domainsBox) {
        domainsBtn.addEventListener('click', loadDomains);
    }

    function loadDomains() {
        if (!domainsBox) return;
        post('optimasite_domains', { op: 'status' }, function (r) {
            if (!r.ok) { show('Could not load domains: ' + (r.error || 'unknown'), false); return; }
            var tbody = domainsBox.querySelector('tbody');
            tbody.innerHTML = '';
            (r.bound_domains || []).forEach(function (d) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td><code>' + escapeHtml(d.domain) + '</code></td>'
                    + '<td>' + escapeHtml(d.last_seen || '-') + '</td>'
                    + '<td><button class="button" data-disc="' + escapeHtml(d.domain) + '">Disconnect</button></td>';
                tr.querySelector('[data-disc]').addEventListener('click', function () {
                    disconnectDomain(d.domain);
                });
                tbody.appendChild(tr);
            });
            if (!r.bound_domains || !r.bound_domains.length) {
                tbody.innerHTML = '<tr><td colspan="3">No domain bound yet.</td></tr>';
            }
            domainsBox.hidden = false;
        });
    }

    function disconnectDomain(domain) {
        if (!window.confirm('Disconnect ' + domain + '? You can connect a new domain later with the same key.')) return;
        post('optimasite_domains', { op: 'disconnect', domain: domain }, function (r) {
            if (r.ok) { show('Domain disconnected.', true); loadDomains(); }
            else show('Disconnect failed: ' + (r.error || 'unknown'), false);
        });
    }

    /* ---------- Checkout ---------- */
    function startCheckout(rid, order) {
        if (!window.Razorpay) { show('Razorpay failed to load.', false); return; }
        var opts = {
            key: order.key_id,
            amount: order.amount,
            currency: order.currency,
            name: cfg.name || 'OptimaSite',
            description: cfg.name || 'OptimaSite license',
            prefill: { email: cfg.email },
            theme: { color: '#37e0a5' },
            order_id: order.order_id,
            handler: function (res) {
                verifyPayment({
                    razorpay_payment_id: res.razorpay_payment_id,
                    razorpay_order_id: res.razorpay_order_id,
                    razorpay_signature: res.razorpay_signature,
                    our_order_id: rid
                });
            },
            modal: { ondismiss: function () { busy(false); show(cfg.i18n.closed, false); } }
        };
        var rzp = new window.Razorpay(opts);
        rzp.on('payment.failed', function () { busy(false); show('Payment failed. No license issued.', false); });
        rzp.open();
    }

    function verifyPayment(data) {
        busy(true);
        show(cfg.i18n.busy, true, true);
        post('optimasite_verify', data, function (r) {
            busy(false);
            if (r.ok && r.license_key) {
                show('Payment confirmed. License ' + r.license_key + (r.bound_domain ? ' for ' + r.bound_domain : '') + '. Activating this site…', true, true);
                reload();
            } else {
                show('Payment captured but activation failed: ' + (r.error || 'unknown'), false);
            }
        });
    }

    if (buyBtn) {
        buyBtn.addEventListener('click', function () {
            var domain = (domainInp ? domainInp.value : cfg.domain).trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '');
            if (!/^[a-z0-9\-\.]+\.[a-z]{2,}$/i.test(domain)) { show(cfg.i18n.valid_domain, false); return; }
            busy(true);
            show(cfg.i18n.busy, true, true);
            post('optimasite_create_order', { email: cfg.email, domain: domain }, function (r) {
                if (r.ok && r.key_id && r.order_id && r.our_order_id) {
                    loadRazorpay(function () {
                        startCheckout(r.our_order_id, { key_id: r.key_id, order_id: r.order_id, amount: r.amount_paisa, currency: r.currency });
                    });
                } else {
                    busy(false);
                    show('Could not create order: ' + (r.error || 'unknown'), false);
                }
            });
        });
    }

    function loadRazorpay(cb) {
        if (window.Razorpay) { cb(); return; }
        var s = document.createElement('script');
        s.src = 'https://checkout.razorpay.com/v1/checkout.js';
        s.onload = cb;
        s.onerror = function () { busy(false); show('Could not load Razorpay.', false); };
        document.head.appendChild(s);
    }

    /* ---------- Audit ---------- */
    var auditBtn = document.getElementById('optimasite-audit-btn');
    if (auditBtn) {
        auditBtn.addEventListener('click', function () {
            busy(true);
            show(cfg.i18n.busy, true, true);
            post('optimasite_audit', {}, function (r) {
                if (!r.ok || !r.audit) {
                    busy(false);
                    show('Audit failed: ' + (r.error || 'unknown'), false);
                    return;
                }
                // Fresh audit cached server-side; reload for the canonical render.
                reload();
            });
        });
    }

    /* ---------- Fixes ---------- */
    var fixRuns = document.querySelectorAll('.optimasite-fix__run');
    Array.prototype.forEach.call(fixRuns, function (btn) {
        btn.addEventListener('click', function () {
            var li = btn.closest('.optimasite-fix');
            var action = li.getAttribute('data-action');
            var needsConfirm = li.getAttribute('data-confirm') === '1';
            var confirmation = '';
            var confirmInput = li.querySelector('.optimasite-fix__confirm');
            if (needsConfirm) {
                confirmation = (confirmInput ? confirmInput.value : '').trim().toLowerCase();
                if (!confirmInput) return;
            }
            busy(true);
            show(cfg.i18n.busy, true, true);
            post('optimasite_run_fix', { action_id: action, confirmation: confirmation }, function (r) {
                busy(false);
                if (r.ok) {
                    if (confirmInput) { confirmInput.value = ''; }
                    show(r.detail || 'Done.', true, true);
                } else {
                    show('Action failed: ' + (r.error || 'unknown') + (r.hint ? ' ' + r.hint : ''), false);
                }
            });
        });
    });

    /* ---------- / ---------- */
})(window, document);
