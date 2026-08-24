// @ts-check
(function () {
  var footer = document.getElementById('quota-footer');
  var toggleBtn = document.getElementById('quota-toggle-btn');
  var toggleIcon = document.getElementById('quota-toggle-icon');
  var el = document.getElementById('quota-info');
  var sessionName = footer.dataset.session || '';
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
    if (key === 'context') return 'Context';
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

  // The relative duration alone ("1h 53m") doesn't say WHEN that actually
  // is on the clock - this appends the absolute local time next to it, only
  // adding the date when the reset isn't today (the common case for the
  // 5-hour session bucket), so the compact mobile footer doesn't carry a
  // redundant date on every single line.
  function formatAbsolute(unixSeconds) {
    var d = new Date(unixSeconds * 1000);
    var now = new Date();
    var timeStr = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    var sameDay = d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();

    if (sameDay) return timeStr;

    return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + timeStr;
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
    // 'context' (per-session, no reset timer) leads, ahead of the
    // account-wide session/week buckets - only ever present when this
    // footer was given a session name (see sessionName above) and that
    // session's own pane currently has a status line to read; otherwise
    // simply absent from q, same graceful omission as the week_* buckets.
    var order = ['context', 'session', 'week_all'].concat(Object.keys(q).filter(function (k) {
      return k.indexOf('week_') === 0 && k !== 'week_all';
    }).sort());

    var nowSeconds = Math.floor(Date.now() / 1000);
    var lines = [];

    order.forEach(function (key) {
      var bar = q[key];
      if (!bar || typeof bar.pct !== 'number') return;

      var text = label(key) + ' ' + bar.pct + '%';
      var absolute = null;

      if (typeof bar.resets_at === 'number') {
        var kind = key === 'session' ? 'session' : 'week';
        text += ' · resets ' + formatDuration(bar.resets_at - nowSeconds, kind);
        absolute = formatAbsolute(bar.resets_at);
      }

      lines.push({ text: text, absolute: absolute, pct: bar.pct });
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

    // Each bucket always gets its own full-width line (#quota-info is a
    // column flex, not a wrapping row) - crammed onto shared lines at
    // mobile widths was the whole problem before this. The absolute reset
    // time is a visually secondary detail, not the main scannable fact (the
    // percentage + relative duration is), so it's a separate, smaller/muted
    // span rather than folded into the same colored text.
    lines.forEach(function (line) {
      var item = document.createElement('div');
      item.className = pctColorClass(line.pct);
      item.textContent = line.text;

      if (line.absolute) {
        var abs = document.createElement('span');
        abs.className = 'text-xs font-normal text-slate-500 ml-1';
        abs.textContent = '(' + line.absolute + ')';
        item.appendChild(abs);
      }

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

    var url = '/quota.php' + (sessionName !== '' ? ('?session=' + encodeURIComponent(sessionName)) : '');

    fetch(url, { credentials: 'same-origin' })
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
