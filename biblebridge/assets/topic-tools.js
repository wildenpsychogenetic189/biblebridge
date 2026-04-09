/**
 * BibleBridge Topic Tools — Copy all verses
 * -----------------------------------------
 * Phase 1a Task 5 (2026-04-09).
 *
 * Self-contained IIFE. Binds clicks on `[data-bb-copy-anchors]` triggers and
 * copies the topic's anchor verses to the clipboard as plain text.
 *
 * The button must carry:
 *   data-bb-copy-anchors           — marker (no value needed)
 *   data-topic-name="Grace"         — used in the header line
 *   data-source-url="https://…"     — appended as a source attribution line
 *
 * The script reads anchor verses from the DOM by walking `.te-anchor-item`
 * elements that sit inside the same `<section>` as the trigger button. Each
 * item is expected to contain `.te-anchor-ref` and (optionally) `.te-anchor-text`.
 *
 * Output format:
 *   Grace — Anchor scriptures
 *
 *   Ephesians 2:8
 *   "For by grace are ye saved through faith…"
 *
 *   2 Corinthians 12:9
 *   "And he said unto me, My grace is sufficient for thee…"
 *
 *   …
 *
 *   Source: https://holybible.dev/topics/grace
 */

(function () {
    'use strict';

    function plainText(s) {
        if (s == null) return '';
        // strip surrounding curly quotes the template uses around verse text
        return String(s)
            .replace(/^[\u201c\u201d"\u2018\u2019']+/, '')
            .replace(/[\u201c\u201d"\u2018\u2019']+$/, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function buildText(button) {
        var topicName = button.getAttribute('data-topic-name') || '';
        var sourceUrl = button.getAttribute('data-source-url') || '';

        // Find the enclosing <section> (Anchor Scriptures)
        var section = button.closest ? button.closest('section') : null;
        if (!section) {
            // very old browsers — fallback walk
            var p = button.parentNode;
            while (p && p.tagName !== 'SECTION') p = p.parentNode;
            section = p;
        }
        if (!section) return null;

        var items = section.querySelectorAll('.te-anchor-item');
        if (!items.length) return null;

        var lines = [];
        if (topicName) lines.push(topicName + ' \u2014 Anchor scriptures');
        lines.push('');

        for (var i = 0; i < items.length; i++) {
            var refEl  = items[i].querySelector('.te-anchor-ref');
            var textEl = items[i].querySelector('.te-anchor-text');
            var ref    = refEl  ? plainText(refEl.textContent)  : '';
            var text   = textEl ? plainText(textEl.textContent) : '';
            if (!ref) continue;
            lines.push(ref);
            if (text) lines.push('"' + text + '"');
            lines.push('');
        }

        if (sourceUrl) {
            lines.push('Source: ' + sourceUrl);
        }
        return lines.join('\n');
    }

    function flashFeedback(button, message, ok) {
        var orig = button.getAttribute('data-orig-label');
        if (orig === null) {
            button.setAttribute('data-orig-label', button.textContent);
        }
        button.textContent = message;
        button.classList.toggle('te-copy-all--ok', !!ok);
        button.classList.toggle('te-copy-all--err', !ok);
        clearTimeout(button._restoreTimer);
        button._restoreTimer = setTimeout(function () {
            button.textContent = button.getAttribute('data-orig-label') || 'Copy all verses';
            button.classList.remove('te-copy-all--ok', 'te-copy-all--err');
        }, 1800);
    }

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        // Fallback: hidden textarea + execCommand
        return new Promise(function (resolve, reject) {
            try {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'absolute';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                var ok = document.execCommand && document.execCommand('copy');
                document.body.removeChild(ta);
                if (ok) resolve();
                else reject(new Error('execCommand copy failed'));
            } catch (e) {
                reject(e);
            }
        });
    }

    function onClick(e) {
        var t = e.target;
        while (t && t !== document.body) {
            if (t.hasAttribute && t.hasAttribute('data-bb-copy-anchors')) {
                e.preventDefault();
                var text = buildText(t);
                if (!text) {
                    flashFeedback(t, 'Nothing to copy', false);
                    return;
                }
                copyToClipboard(text).then(function () {
                    flashFeedback(t, 'Copied \u2713', true);
                }).catch(function () {
                    flashFeedback(t, 'Copy failed', false);
                });
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
