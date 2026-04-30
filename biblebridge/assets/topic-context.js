/**
 * BibleBridge Inline Context Expansion
 * ------------------------------------
 * Phase 1b (2026-04-09).
 *
 * Self-contained IIFE. Turns any element with `data-bb-context-ref="<reference>"`
 * into a toggle that fetches ±2 surrounding verses and renders them inline below
 * the anchor verse text. Clicking again collapses the panel.
 *
 * Configuration via `window.BB_CONTEXT_CONFIG`:
 *   - endpoint  (string, required) — base URL for context API, e.g. '/api/context?'
 *                                     or admin-ajax.php proxy path. Must end with ? or &.
 *   - version   (string)           — translation key matching current page (default 'kjv').
 *   - window    (number)           — verses before/after (default 2).
 */

(function () {
    'use strict';

    var cfg = window.BB_CONTEXT_CONFIG || {};
    if (!cfg.endpoint) return;

    var version = cfg.version || 'kjv';
    var ctxWindow = cfg.window || 2;
    var fetchCache = {}; // reference → parsed response

    // ----------------------------------------------------------
    // DOM helpers
    // ----------------------------------------------------------
    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function findAnchorItem(button) {
        var el = button;
        while (el && !el.classList.contains('te-anchor-item')) {
            el = el.parentNode;
        }
        return el;
    }

    // ----------------------------------------------------------
    // Expand / collapse
    // ----------------------------------------------------------
    function toggle(button) {
        var item = findAnchorItem(button);
        if (!item) return;

        var panel = item.querySelector('.te-context-panel');

        // Collapse
        if (panel) {
            panel.remove();
            button.textContent = 'Expand context';
            button.classList.remove('te-expand--open');
            return;
        }

        // Expand — create panel, fetch
        panel = document.createElement('div');
        panel.className = 'te-context-panel';
        panel.innerHTML = '<p class="te-context-loading">Loading\u2026</p>';
        item.appendChild(panel);
        button.textContent = 'Collapse';
        button.classList.add('te-expand--open');

        var ref = button.getAttribute('data-bb-context-ref');
        if (!ref) return;

        if (fetchCache[ref]) {
            renderPanel(panel, fetchCache[ref], ref);
            return;
        }

        var url = cfg.endpoint
            + 'reference=' + encodeURIComponent(ref)
            + '&version=' + encodeURIComponent(version)
            + '&window=' + encodeURIComponent(ctxWindow);

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                if (r.status === 429) return { __rate_limited: true };
                return r.json();
            })
            .then(function (data) {
                // Handle bb_api_proxy wrapper
                if (data && data.data && data.data.status === 'success') {
                    data = data.data;
                }
                if (data && data.__rate_limited) {
                    panel.innerHTML = '<p class="te-context-error">Too many requests. Try again in a moment.</p>';
                    return;
                }
                if (!data || data.status !== 'success') {
                    panel.innerHTML = '<p class="te-context-error">Could not load context.</p>';
                    return;
                }
                fetchCache[ref] = data;
                renderPanel(panel, data, ref);
            })
            .catch(function () {
                panel.innerHTML = '<p class="te-context-error">Could not load context. Check your connection.</p>';
            });
    }

    function renderPanel(panel, data, centerRef) {
        var verses = data.data || [];
        if (!verses.length) {
            panel.innerHTML = '<p class="te-context-error">No context available.</p>';
            return;
        }

        var html = '<div class="te-context-verses">';
        for (var i = 0; i < verses.length; i++) {
            var v = verses[i];
            var isCenter = v.offset === 0;
            var cls = 'te-context-verse' + (isCenter ? ' te-context-verse--center' : '');
            html += '<div class="' + cls + '">';
            html += '<span class="te-context-ref">' + escHtml(v.reference) + '</span> ';
            html += '<span class="te-context-text">' + escHtml(v.text || '') + '</span>';
            html += '</div>';
        }
        html += '</div>';
        panel.innerHTML = html;
    }

    // ----------------------------------------------------------
    // Delegated click binding
    // ----------------------------------------------------------
    function onClick(e) {
        var t = e.target;
        while (t && t !== document.body) {
            if (t.hasAttribute && t.hasAttribute('data-bb-context-ref')) {
                e.preventDefault();
                toggle(t);
                return;
            }
            t = t.parentNode;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            document.addEventListener('click', onClick);
        });
    } else {
        document.addEventListener('click', onClick);
    }
})();
