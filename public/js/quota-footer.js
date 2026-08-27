// @ts-check
(function () {
  var footer = document.getElementById('quota-footer');
  var toggleBtn = document.getElementById('quota-toggle-btn');
  var toggleIcon = document.getElementById('quota-toggle-icon');
  var el = document.getElementById('quota-info');
  var sessionName = (footer && footer.dataset.session) ? footer.dataset.session : '';
  var STORAGE_KEY = 'csm-quota-collapsed';

  if (!footer || !toggleBtn || !toggleIcon || !el) {
    return;
  }

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

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

  function label(key, bar) {
    if (bar && bar.group_name) return bar.group_name;
    if (key === 'context') return 'Context';
    if (key === 'session') return 'Session';
    if (key === 'week_all') return 'Week';
    if (key === 'month_all') return 'Monthly';
    if (key === 'gemini-weekly') return 'Gemini';
    if (key === '3p-weekly') return 'Claude & GPT';
    return key.replace(/^week_/, '').replace(/_/g, ' ').replace(/-/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }) + ' (week)';
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

  function renderBucketText(bar, kind) {
    if (!bar || typeof bar.pct !== 'number') return null;
    var nowSeconds = Math.floor(Date.now() / 1000);
    var text = bar.pct + '%';
    var duration = null;
    var absolute = null;

    if (typeof bar.resets_at === 'number') {
      duration = formatDuration(bar.resets_at - nowSeconds, kind);
      text += ' · ' + duration;
      absolute = formatAbsolute(bar.resets_at);
    }

    return { pct: bar.pct, text: text, duration: duration, absolute: absolute };
  }

  function dashboardBucketText(info) {
    return info.pct + '%' + (info.duration ? ' (' + info.duration + ')' : '');
  }

  function showUnavailable(data) {
    el.title = '';
    el.innerHTML = '';
    var line = document.createElement('span');
    line.className = 'text-slate-600';
    line.textContent = 'Quota unavailable' + (data && data.message ? ': ' + data.message : '');
    el.appendChild(line);
  }

  function renderDashboardTable(data) {
    var agents = data.agents || {};
    var claude = agents.claude;
    var ag = agents.antigravity;
    var oc = agents.opencode;

    var hasClaude = claude && claude.quota;
    var hasAg = ag && ag.quota;
    var hasOc = oc && oc.quota;

    if (!hasClaude && !hasAg && !hasOc) {
      showUnavailable(data);
      return;
    }

    var container = document.createElement('div');
    container.className = 'w-full overflow-x-auto';
    container.style.maxWidth = '100%';
    container.style.overflowX = 'auto';

    var table = document.createElement('table');
    table.className = 'w-full text-xs text-left border-collapse';
    table.style.minWidth = '34rem';

    var thead = document.createElement('thead');
    thead.innerHTML = '<tr class="text-slate-500 border-b border-slate-800">'
      + '<th class="py-1 pr-3 font-medium">Agent</th>'
      + '<th class="py-1 px-2 font-medium">5hr</th>'
      + '<th class="py-1 px-2 font-medium">Weekly</th>'
      + '<th class="py-1 pl-2 font-medium">Monthly</th>'
      + '</tr>';
    table.appendChild(thead);

    var tbody = document.createElement('tbody');
    tbody.className = 'divide-y divide-slate-800/60 font-medium';

    // Claude Code row
    var trClaude = document.createElement('tr');
    var claudeLabel = (claude && claude.label) ? claude.label : 'Claude Code';
    var tdC1 = document.createElement('td');
    tdC1.className = 'py-1.5 pr-3 text-slate-300 whitespace-nowrap font-medium';
    tdC1.textContent = claudeLabel;
    trClaude.appendChild(tdC1);

    var tdC2 = document.createElement('td');
    tdC2.className = 'py-1.5 px-2 whitespace-nowrap';
    if (claude && claude.quota && claude.quota.session) {
      var sInfo = renderBucketText(claude.quota.session, 'session');
      tdC2.innerHTML = '<span class="' + pctColorClass(sInfo.pct) + '">' + escapeHtml(dashboardBucketText(sInfo)) + '</span>';
    } else {
      tdC2.innerHTML = '<span class="text-slate-600 font-normal">' + (claude && claude.message ? 'No data' : '—') + '</span>';
    }
    trClaude.appendChild(tdC2);

    var tdC3 = document.createElement('td');
    tdC3.className = 'py-1.5 px-2 whitespace-nowrap';
    if (claude && claude.quota && claude.quota.week_all) {
      var wInfo = renderBucketText(claude.quota.week_all, 'week');
      tdC3.innerHTML = '<span class="' + pctColorClass(wInfo.pct) + '">' + escapeHtml(dashboardBucketText(wInfo)) + '</span>';
    } else {
      tdC3.innerHTML = '<span class="text-slate-600 font-normal">' + (claude && claude.message ? 'No data' : '—') + '</span>';
    }
    trClaude.appendChild(tdC3);
    var tdC4 = document.createElement('td');
    tdC4.className = 'py-1.5 pl-2 whitespace-nowrap text-slate-600';
    tdC4.textContent = '—';
    trClaude.appendChild(tdC4);
    tbody.appendChild(trClaude);

    // Antigravity row
    var trAg = document.createElement('tr');
    var agLabel = (ag && ag.label) ? ag.label : 'Antigravity';
    var tdA1 = document.createElement('td');
    tdA1.className = 'py-1.5 pr-3 text-slate-300 whitespace-nowrap font-medium';
    tdA1.textContent = agLabel;
    trAg.appendChild(tdA1);

    var tdA2 = document.createElement('td');
    tdA2.className = 'py-1.5 px-2 text-slate-600 whitespace-nowrap font-normal';
    tdA2.textContent = '—';
    trAg.appendChild(tdA2);

    var tdA3 = document.createElement('td');
    tdA3.className = 'py-1.5 px-2 whitespace-nowrap';
    if (ag && ag.quota && (ag.quota['gemini-weekly'] || ag.quota['3p-weekly'])) {
      var agItems = [];
      if (ag.quota['gemini-weekly']) {
        var gInfo = renderBucketText(ag.quota['gemini-weekly'], 'week');
        agItems.push('<div><span class="text-slate-400 font-normal">Gemini: </span><span class="' + pctColorClass(gInfo.pct) + '">' + escapeHtml(dashboardBucketText(gInfo)) + '</span></div>');
      }
      if (ag.quota['3p-weekly']) {
        var pInfo = renderBucketText(ag.quota['3p-weekly'], 'week');
        agItems.push('<div class="mt-0.5"><span class="text-slate-400 font-normal">Claude & GPT: </span><span class="' + pctColorClass(pInfo.pct) + '">' + escapeHtml(dashboardBucketText(pInfo)) + '</span></div>');
      }
      tdA3.innerHTML = agItems.join('');
    } else {
      tdA3.innerHTML = '<span class="text-slate-600 font-normal">' + (ag && ag.message ? 'No data' : '—') + '</span>';
    }
    trAg.appendChild(tdA3);
    var tdA4 = document.createElement('td');
    tdA4.className = 'py-1.5 pl-2 whitespace-nowrap text-slate-600';
    tdA4.textContent = '—';
    trAg.appendChild(tdA4);
    tbody.appendChild(trAg);

    // OpenCode row — account windows plus local cost/tokens.
    var trOc = document.createElement('tr');
    var ocLabel = (oc && oc.label) ? oc.label : 'OpenCode';
    var tdO1 = document.createElement('td');
    tdO1.className = 'py-1.5 pr-3 text-slate-300 whitespace-nowrap font-medium';
    tdO1.textContent = ocLabel;
    trOc.appendChild(tdO1);

    var tdO2 = document.createElement('td');
    tdO2.className = 'py-1.5 px-2 whitespace-nowrap text-xs';
    if (oc && oc.quota) {
      var ocQ = oc.quota;
      var ocSessionInfo = ocQ.session ? renderBucketText(ocQ.session, 'session') : null;
      tdO2.innerHTML = ocSessionInfo ? '<span class="' + pctColorClass(ocSessionInfo.pct) + '">' + escapeHtml(dashboardBucketText(ocSessionInfo)) + '</span>' : '<span class="text-slate-600">—</span>';
    } else {
      tdO2.innerHTML = '<span class="text-slate-600 font-normal">' + (oc && oc.message ? 'No data' : '—') + '</span>';
    }
    trOc.appendChild(tdO2);

    var tdO3 = document.createElement('td');
    tdO3.className = 'py-1.5 px-2 whitespace-nowrap text-xs';
    var ocWeekInfo = oc && oc.quota && oc.quota.week_all ? renderBucketText(oc.quota.week_all, 'week') : null;
    tdO3.innerHTML = ocWeekInfo ? '<span class="' + pctColorClass(ocWeekInfo.pct) + '">' + escapeHtml(dashboardBucketText(ocWeekInfo)) + '</span>' : '<span class="text-slate-600">—</span>';
    trOc.appendChild(tdO3);
    var tdO4 = document.createElement('td');
    tdO4.className = 'py-1.5 pl-2 whitespace-nowrap text-xs';
    var ocMonthInfo = oc && oc.quota && oc.quota.month_all ? renderBucketText(oc.quota.month_all, 'month') : null;
    tdO4.innerHTML = ocMonthInfo ? '<span class="' + pctColorClass(ocMonthInfo.pct) + '">' + escapeHtml(dashboardBucketText(ocMonthInfo)) + '</span>' : '<span class="text-slate-600">—</span>';
    trOc.appendChild(tdO4);
    tbody.appendChild(trOc);

    table.appendChild(tbody);
    container.appendChild(table);

    var capturedAt = (claude && claude.quota && claude.quota.captured_at) || (ag && ag.quota && ag.quota.captured_at) || (oc && oc.quota && oc.quota.captured_at);
    el.title = capturedAt ? 'Captured ' + relativeTimeAgo(capturedAt) : '';
    el.innerHTML = '';
    el.appendChild(container);
  }

  function render(data) {
    if (!data || (!data.quota && !data.agents)) {
      showUnavailable(data);
      return;
    }

    if (sessionName === '' && data.agents) {
      renderDashboardTable(data);
      return;
    }

    var q = data.quota;
    if (!q) {
      showUnavailable(data);
      return;
    }

    // OpenCode per-session quota is cost/tokens (not pct) — render differently
    if (data.agent === 'opencode' && !q.session && !q.week_all && !q.month_all && (typeof q.cost === 'number' || typeof q.tokens_input === 'number')) {
      el.title = q.captured_at ? 'Captured ' + relativeTimeAgo(q.captured_at) : '';
      el.innerHTML = '';
      var costLine = document.createElement('div');
      costLine.className = 'text-slate-300';
      var tokTotal = (q.tokens_input || 0) + (q.tokens_output || 0);
      var tokStr = tokTotal > 0 ? (' · ' + Math.round(tokTotal / 1000) + 'k tok') : '';
      var costStr = typeof q.cost === 'number' ? ('$' + q.cost.toFixed(2)) : '$0.00';
      costLine.textContent = costStr + tokStr + (q.session_count ? (' · ' + q.session_count + ' sessions') : '');
      el.appendChild(costLine);
      if (q.tokens_input || q.tokens_output) {
        var tokDetail = document.createElement('div');
        tokDetail.className = 'text-xs font-normal text-slate-500';
        tokDetail.textContent = 'in ' + (q.tokens_input || 0).toLocaleString() + ' · out ' + (q.tokens_output || 0).toLocaleString()
          + (q.tokens_cache_read ? (' · cache ' + Math.round(q.tokens_cache_read / 1000) + 'k') : '');
        el.appendChild(tokDetail);
      }
      if (data.agent_label) {
        var meta2 = document.createElement('span');
        meta2.className = 'text-xs font-normal text-slate-400';
        meta2.textContent = '(' + data.agent_label + ')';
        el.appendChild(meta2);
      }
      return;
    }

    // 'context' (per-session, no reset timer) leads, ahead of the
    // account-wide session/week buckets - only ever present when this
    // footer was given a session name (see sessionName above) and that
    // session's own pane currently has a status line to read; otherwise
    // simply absent from q, same graceful omission as the week_* buckets.
    var order = ['context', 'session', 'week_all', 'gemini-weekly', '3p-weekly'].concat(Object.keys(q).filter(function (k) {
      return k !== 'context' && k !== 'session' && k !== 'week_all' && k !== 'gemini-weekly' && k !== '3p-weekly' && k !== 'captured_at';
    }).sort());

    var nowSeconds = Math.floor(Date.now() / 1000);
    var lines = [];

    order.forEach(function (key) {
      var bar = q[key];
      if (!bar || typeof bar.pct !== 'number') return;

      var text = label(key, bar) + ' ' + bar.pct + '%';
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
    if (data.agent_label) metaParts.push(data.agent_label);
    if (data.cached) metaParts.push(data.stale ? 'cached, stale' : 'cached');
    if (data.refreshing) metaParts.push('refreshing in background…');

    el.title = q.captured_at ? 'Captured ' + relativeTimeAgo(q.captured_at) : '';
    el.innerHTML = '';

    // Each bucket always gets its own full-width line (#quota-info is a
    // column flex, not a wrapping row).
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
