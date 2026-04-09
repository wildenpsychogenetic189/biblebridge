/**
 * BibleBridge Cross-Reference Walker
 * ----------------------------------
 * Phase 1a Task 4 (2026-04-09).
 *
 * Self-contained IIFE that turns any element with `data-bb-xref-ref="<reference>"`
 * into a trigger for an interactive cross-reference walker panel. Clicking the
 * trigger opens a drawer (desktop right) / bottom sheet (mobile) listing the
 * verse's cross-references. Clicking any cross-reference inside the panel walks
 * forward to that verse's cross-references; a back button steps back through
 * the history stack. Close button dismisses.
 *
 * Configuration is read from `window.BB_XREF_WALKER_CONFIG`:
 *   - endpoint  (string, required) — base URL that accepts ?reference=&v=&limit=
 *                                     Append a `&` or `?` so query string can be appended.
 *                                     Examples:
 *                                       '/reader/xref.php?'
 *                                       'https://customer.org/wp-admin/admin-ajax.php?action=bb_api&endpoint=%2Fcross-references&'
 *   - version   (string)            — translation key (default 'kjv').
 *   - readUrl   (function)          — given a reference string, returns the
 *                                     "Read in context" URL. Optional; default
 *                                     parses "Book Ch:Vs" into '/read/<slug>/<ch>/<vs>'.
 *   - baseUrl   (string)            — prefix for the default readUrl (default '').
 *
 * Response shape: handles BOTH the normalized shape ({cross_references: [...]})
 * used by /reader/xref.php and /standalone/xref.php, AND the raw API shape
 * ({status, data: [...]}) returned by the WP plugin's bb_api_proxy.
 */

(function () {
    'use strict';

    var cfg = window.BB_XREF_WALKER_CONFIG || {};
    if (!cfg.endpoint) return;

    var version = cfg.version || 'kjv';
    var baseUrl = cfg.baseUrl || '';
    var limit   = cfg.limit   || 8;

    var panel       = null;
    var bodyEl      = null;
    var titleEl     = null;
    var backBtn     = null;
    var crumbEl     = null;
    var historyStack = []; // [{reference}]
    var loading     = false;
    var fetchCache  = {}; // reference → response object

    // ----------------------------------------------------------
    // DOM build (lazy on first open)
    // ----------------------------------------------------------
    function ensurePanel() {
        if (panel) return;
        panel = document.createElement('aside');
        panel.className = 'xref-panel xref-walker';
        panel.id = 'xrefWalker';
        panel.setAttribute('aria-hidden', 'true');
        panel.setAttribute('aria-label', 'Cross-reference walker');
        panel.innerHTML =
            '<div class="xref-panel-header">' +
                '<button class="xref-walker-back" type="button" aria-label="Back" hidden>&larr;</button>' +
                '<div class="xref-walker-title">' +
                    '<div class="panel-verse-label" data-walker-title>Cross-references</div>' +
                    '<div class="xref-walker-crumb" data-walker-crumb></div>' +
                '</div>' +
                '<button class="xref-close" type="button" aria-label="Close">&times;</button>' +
            '</div>' +
            '<div class="xref-panel-body" data-walker-body>' +
                '<p class="xref-hint">Loading\u2026</p>' +
            '</div>';
        document.body.appendChild(panel);

        bodyEl  = panel.querySelector('[data-walker-body]');
        titleEl = panel.querySelector('[data-walker-title]');
        crumbEl = panel.querySelector('[data-walker-crumb]');
        backBtn = panel.querySelector('.xref-walker-back');

        panel.querySelector('.xref-close').addEventListener('click', closePanel);
        backBtn.addEventListener('click', goBack);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && panel.classList.contains('open')) {
                closePanel();
            }
        });

        // Backdrop dismiss on mobile
        var backdrop = document.createElement('div');
        backdrop.className = 'xref-walker-backdrop';
        backdrop.addEventListener('click', closePanel);
        document.body.appendChild(backdrop);
        panel._backdrop = backdrop;
    }

    // ----------------------------------------------------------
    // Open / close
    // ----------------------------------------------------------
    function openWalker(reference) {
        if (!reference) return;
        ensurePanel();
        historyStack = [];
        navigateTo(reference, true);
        panel.classList.add('open');
        panel.removeAttribute('aria-hidden');
        if (panel._backdrop) panel._backdrop.classList.add('open');
        document.body.classList.add('xref-walker-open');
    }

    function closePanel() {
        if (!panel) return;
        panel.classList.remove('open');
        panel.setAttribute('aria-hidden', 'true');
        if (panel._backdrop) panel._backdrop.classList.remove('open');
        document.body.classList.remove('xref-walker-open');
    }

    // ----------------------------------------------------------
    // Navigation
    // ----------------------------------------------------------
    function navigateTo(reference, isInitial) {
        if (loading) return;
        if (!isInitial) historyStack.push(reference);
        updateChrome(reference);
        loadXrefs(reference);
    }

    function goBack() {
        if (historyStack.length <= 1) return;
        historyStack.pop(); // current
        var prev = historyStack[historyStack.length - 1];
        updateChrome(prev);
        loadXrefs(prev);
    }

    function updateChrome(reference) {
        if (historyStack.length === 0) historyStack.push(reference);
        titleEl.textContent = reference;
        backBtn.hidden = historyStack.length <= 1;
        if (historyStack.length > 1) {
            crumbEl.textContent = historyStack.slice(0, -1).join('  \u2192  ') + '  \u2192';
        } else {
            crumbEl.textContent = '';
        }
    }

    // ----------------------------------------------------------
    // Fetch + render
    // ----------------------------------------------------------
    function loadXrefs(reference) {
        if (fetchCache[reference]) {
            renderXrefs(fetchCache[reference], reference);
            return;
        }
        loading = true;
        bodyEl.innerHTML = '<p class="xref-loading"><span class="xref-spinner"></span>Loading\u2026</p>';

        var url = cfg.endpoint
            + 'reference=' + encodeURIComponent(reference)
            + '&v=' + encodeURIComponent(version)
            + '&limit=' + encodeURIComponent(limit);

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                if (r.status === 429) {
                    return { __rate_limited: true };
                }
                return r.json();
            })
            .then(function (data) {
                loading = false;
                if (data && data.__rate_limited) {
                    bodyEl.innerHTML = '<p class="xref-hint">Too many requests right now. Try again in a moment.</p>';
                    return;
                }
                // bb_api_proxy may wrap response in {success, data}
                if (data && data.data && data.data.status === 'success') {
                    data = data.data;
                }
                fetchCache[reference] = data;
                renderXrefs(data, reference);
            })
            .catch(function () {
                loading = false;
                bodyEl.innerHTML = '<p class="xref-hint">Could not load cross-references. Check your connection.</p>';
            });
    }

    function normalizeXrefs(data) {
        // Normalized shape from /reader/xref.php
        if (data && Array.isArray(data.cross_references)) {
            return data.cross_references.map(function (x) {
                return {
                    reference: x.reference,
                    text:      x.text || '',
                    weight:    x.weight || 0,
                    url:       x.url || null
                };
            });
        }
        // Raw API shape from bb_api_proxy → /cross-references
        if (data && Array.isArray(data.data)) {
            return data.data.map(function (x) {
                return {
                    reference: x.reference,
                    text:      x.text || '',
                    weight:    x.weight || 0,
                    url:       null
                };
            });
        }
        return [];
    }

    function renderXrefs(data, sourceRef) {
        var xrefs = normalizeXrefs(data);
        if (!xrefs.length) {
            bodyEl.innerHTML = '<p class="xref-hint">No cross-references for this verse.</p>';
            return;
        }
        var html = '<p class="xref-walker-source">From ' + escHtml(sourceRef) + ':</p>';
        html += '<ul class="xref-walker-list">';
        xrefs.forEach(function (x) {
            var refEnc = escAttr(x.reference);
            var readUrl = x.url || defaultReadUrl(x.reference);
            html += '<li class="xref-walker-item">';
            html +=   '<button type="button" class="xref-walker-step" data-walk-ref="' + refEnc + '">';
            html +=     '<span class="xref-walker-ref">' + escHtml(x.reference) + '</span>';
            if (x.text) {
                html += '<span class="xref-walker-text">' + escHtml(x.text) + '</span>';
            }
            html +=   '</button>';
            if (readUrl) {
                html +=   '<a class="xref-walker-context" href="' + escAttr(readUrl) + '" target="_blank" rel="noopener" title="Read in context">&rarr;</a>';
            }
            html += '</li>';
        });
        html += '</ul>';
        bodyEl.innerHTML = html;
        bodyEl.scrollTop = 0;

        // Bind walk-forward clicks
        var stepButtons = bodyEl.querySelectorAll('.xref-walker-step');
        for (var i = 0; i < stepButtons.length; i++) {
            stepButtons[i].addEventListener('click', function () {
                var ref = this.getAttribute('data-walk-ref');
                if (ref) navigateTo(ref, false);
            });
        }
    }

    // ----------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------
    function defaultReadUrl(reference) {
        if (cfg.readUrl) return cfg.readUrl(reference);
        var m = String(reference).match(/^(.+?)\s+(\d+):(\d+)$/);
        if (!m) return null;
        var slug = m[1].toLowerCase().replace(/\s+/g, '-');
        return baseUrl + '/read/' + slug + '/' + m[2] + '/' + m[3];
    }

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function escAttr(s) {
        return escHtml(s);
    }

    // ----------------------------------------------------------
    // Trigger binding
    // ----------------------------------------------------------
    function bindTriggers() {
        document.addEventListener('click', function (e) {
            var t = e.target;
            // Walk up to find a [data-bb-xref-ref]
            while (t && t !== document.body) {
                if (t.hasAttribute && t.hasAttribute('data-bb-xref-ref')) {
                    e.preventDefault();
                    openWalker(t.getAttribute('data-bb-xref-ref'));
                    return;
                }
                t = t.parentNode;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindTriggers);
    } else {
        bindTriggers();
    }
})();
