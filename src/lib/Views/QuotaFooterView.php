<?php

declare(strict_types=1);

namespace App\Views;

/**
 * The sticky quota footer's markup + its own self-contained fetch-and-poll
 * script - shared between index.php (its own standalone sticky bar) and
 * session.php (folded into the same sticky bar as the message compose
 * box, rather than stacking two separate fixed bars on mobile).
 */
class QuotaFooterView
{
    /**
     * A caller echoes this once; it renders itself and keeps itself
     * updated. Sized for mobile (small text, not the dashboard's original
     * text-xl) and user-collapsible (persisted in localStorage, shared
     * across both pages since it's the same feature either place).
     *
     * $extraHtml renders on the same row as the "Quota" collapse toggle
     * (outside #quota-info, which the fetch/poll script above fully
     * replaces on every refresh - anything placed inside it would get wiped
     * out) - session.php uses this slot for its mode-toggle button so the
     * two controls share one line instead of stacking.
     */
    public static function quota_footer_html(string $extraHtml = ''): string
    {
        $html = <<<'HTML'
        <div id="quota-footer">
          <div class="flex items-center justify-between gap-2 mb-1">
            <button type="button" id="quota-toggle-btn" class="flex items-center gap-1 text-xs text-slate-500 active:text-slate-300">
              <span id="quota-toggle-icon">&#9662;</span>
              <span>Quota</span>
            </button>
            {{EXTRA_HTML}}
          </div>
          <div id="quota-info" class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 min-w-0 text-sm font-medium" aria-live="polite">
            <span class="text-slate-500">Loading quota&hellip;</span>
          </div>
        </div>
        <script>
        (function () {
          var footer = document.getElementById('quota-footer');
          var toggleBtn = document.getElementById('quota-toggle-btn');
          var toggleIcon = document.getElementById('quota-toggle-icon');
          var el = document.getElementById('quota-info');
          var STORAGE_KEY = 'csm-quota-collapsed';

          function applyCollapsed(collapsed) {
            el.classList.toggle('hidden', collapsed);
            toggleIcon.innerHTML = collapsed ? '&#9656;' : '&#9662;';
          }

          // Collapsed by default - only expanded if the user has explicitly
          // expanded it before (stored '0'). Private browsing / storage
          // disabled just falls back to the same collapsed default, no
          // persistence either way.
          var storedCollapsed = true;
          try {
            storedCollapsed = window.localStorage.getItem(STORAGE_KEY) !== '0';
          } catch (e) {}
          applyCollapsed(storedCollapsed);

          toggleBtn.addEventListener('click', function () {
            var collapsed = !el.classList.contains('hidden');
            applyCollapsed(collapsed);
            try {
              window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
            } catch (e) {}
          });

          function pctColorClass(pct) {
            if (pct >= 90) return 'text-red-400';
            if (pct >= 70) return 'text-amber-400';
            return 'text-slate-300';
          }

          function label(key) {
            if (key === 'session') return 'Session';
            if (key === 'week_all') return 'Week';
            return key.replace(/^week_/, '').replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }) + ' (week)';
          }

          // No leading zeros by construction (Math.floor results are used bare).
          function formatDuration(seconds, kind) {
            if (seconds <= 0) return 'now';

            if (kind === 'session') {
              var h = Math.floor(seconds / 3600);
              var m = Math.floor((seconds % 3600) / 60);
              return h > 0 ? (h + 'h ' + m + 'm') : (m + 'm');
            }

            var d = Math.floor(seconds / 86400);
            var wh = Math.floor((seconds % 86400) / 3600);
            return d > 0 ? (d + 'd ' + wh + 'h') : (wh + 'h');
          }

          // Mirrors App\Views\SessionRowView::relative_time() so "Captured ..."
          // reads the same relative-time style as the rest of the app,
          // instead of a raw ISO timestamp.
          function relativeTimeAgo(isoTimestamp) {
            var ms = Date.parse(isoTimestamp);
            if (isNaN(ms)) return isoTimestamp;

            var diff = Math.floor(Date.now() / 1000) - Math.floor(ms / 1000);
            if (diff < 60) return 'just now';
            if (diff < 3600) return Math.floor(diff / 60) + ' min ago';

            if (diff < 86400) {
              var h = Math.floor(diff / 3600);
              return h + ' hr' + (h > 1 ? 's' : '') + ' ago';
            }

            var d = Math.floor(diff / 86400);
            return d + ' day' + (d > 1 ? 's' : '') + ' ago';
          }

          function showUnavailable(data) {
            el.title = '';
            el.innerHTML = '';
            var line = document.createElement('span');
            line.className = 'text-slate-600';
            line.textContent = 'Quota unavailable' + (data && data.message ? ': ' + data.message : '');
            el.appendChild(line);
          }

          function render(data) {
            if (!data || !data.quota) {
              showUnavailable(data);
              return;
            }

            var q = data.quota;
            var order = ['session', 'week_all'].concat(Object.keys(q).filter(function (k) {
              return k.indexOf('week_') === 0 && k !== 'week_all';
            }).sort());

            var nowSeconds = Math.floor(Date.now() / 1000);
            var lines = [];

            order.forEach(function (key) {
              var bar = q[key];
              if (!bar || typeof bar.pct !== 'number') return;

              var text = label(key) + ' ' + bar.pct + '%';

              if (typeof bar.resets_at === 'number') {
                var kind = key === 'session' ? 'session' : 'week';
                text += ' · resets ' + formatDuration(bar.resets_at - nowSeconds, kind);
              }

              lines.push({ text: text, pct: bar.pct });
            });

            if (lines.length === 0) {
              showUnavailable(data);
              return;
            }

            var metaParts = [];
            if (data.cached) metaParts.push(data.stale ? 'cached, stale' : 'cached');
            if (data.refreshing) metaParts.push('refreshing in background…');

            el.title = q.captured_at ? 'Captured ' + relativeTimeAgo(q.captured_at) : '';
            el.innerHTML = '';

            // A left border marks every item after the first when there's room for
            // them to sit on one row (sm: and up). On mobile, where each bucket
            // stacks onto its own line, that border/padding is dropped so the text
            // lines up flush left instead of looking indented.
            lines.forEach(function (line, i) {
              var item = document.createElement('span');
              item.className = pctColorClass(line.pct) + (i > 0 ? ' sm:pl-2 sm:border-l sm:border-slate-700' : '');
              item.textContent = line.text;
              el.appendChild(item);
            });

            if (metaParts.length > 0) {
              var meta = document.createElement('span');
              meta.className = 'text-xs font-normal text-slate-400';
              meta.textContent = '(' + metaParts.join(' · ') + ')';
              el.appendChild(meta);
            }
          }

          var loading = false;

          function load() {
            if (loading) return; // a slow request is still out there - don't pile another on top of it
            loading = true;

            fetch('/quota.php', { credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(render)
              .catch(function () {
                showUnavailable(null);
              })
              .finally(function () {
                loading = false;
              });
          }

          load();
          setInterval(load, 60000);
        })();
        </script>
        HTML;

        return str_replace('{{EXTRA_HTML}}', $extraHtml, $html);
    }
}
