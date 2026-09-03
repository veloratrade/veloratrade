/* VELORA Admin — System Health + System Logs module (Phase D).
 *
 * Backing endpoints (all RBAC-guarded server-side):
 *   GET  /api/v1/admin/system/diagnostics   (P_SYSTEM_HEALTH_VIEW)
 *   POST /api/v1/admin/system/diagnostics/refresh (P_SYSTEM_HEALTH_VIEW,
 *          bounded + rate-limited live probe of MetaAPI/Email — no email sent)
 *   GET  /api/v1/admin/logs/system           (P_SYSTEM_LOGS_VIEW)
 *
 * Invariants (server is the authorization boundary; mirrored here as UX):
 *   - Statuses are HEALTHY | DEGRADED | UNHEALTHY | NOT_CONFIGURED | UNKNOWN
 *     (+ NOT_APPLICABLE for Redis, which this architecture does not use).
 *   - Integration cards distinguish Configured / Reachable / Verified. A live
 *     probe result only appears after "Run diagnostics" and is cached; nothing
 *     is ever labelled verified without a real check.
 *   - No fabricated historical timestamps: until a real probe has run, the
 *     panel shows "No previous check recorded".
 *   - Log rows are redacted server-side at write AND read time; this UI never
 *     assumes it can safely render an unredacted value, and it never renders a
 *     stack trace.
 */
(function (window, document) {
  'use strict';
  if (!window.VeloraData || !window.VeloraLocale) return;
  if (!window.VeloraAdminSystemKeys) return;

  var HEALTH_BASE = '/api/v1/admin/system/diagnostics';
  var LOGS_BASE = '/api/v1/admin/logs/system';
  var $ = function (id) { return document.getElementById(id); };
  function K(stem) { return window.VeloraAdminSystemKeys[stem] || stem; }
  function t(key, params) { return window.VeloraLocale.t(key, params); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function request(path, opts) { return window.VeloraData.request(path, opts || {}); }
  function txt(el, value) { if (el) el.textContent = value; }

  var logState = { severity: '', source: '', q: '', since: '', until: '', page: 1 };
  var logTimer = null;

  function showError(box, err) {
    if (!box) return;
    var m = err && err.message ? err.message : t(K('logs.error'));
    box.textContent = m;
    box.hidden = false;
  }
  function hideError(box) { if (box) box.hidden = true; }

  function statusClass(status) {
    var s = String(status || '').toLowerCase().replace(/[^a-z_]/g, '');
    return 'st-' + s;
  }
  // Status → inline KEYS-map stem (values are the literal catalog keys, so the
  // feature-chunk planner covers admin.system.status.* in the template).
  function statusStem(status) {
    var m = {
      HEALTHY: 'stHealthy', DEGRADED: 'stDegraded', UNHEALTHY: 'stUnhealthy',
      'NOT_CONFIGURED': 'stNotConfigured', 'NOT_APPLICABLE': 'stNotApplicable',
      UNKNOWN: 'stUnknown'
    };
    return m[String(status).toUpperCase()] || 'stUnknown';
  }

  // ---------------------------------------------------------------- health
  function badge(statusLabelKey) {
    return '<span class="sys-badge ' + statusClass(statusLabelKey) + '"></span>';
  }

  function componentCard(key, comp) {
    var st = comp.status || 'UNKNOWN';
    var label = t(K(statusStem(st)));
    var lines = [];
    if (comp.latencyMs !== undefined && comp.latencyMs !== null) {
      lines.push('<div class="sys-kv"><span>' + t(K('lastCheck')) + '</span><b>' + esc(t(K('latencyMs'), { ms: comp.latencyMs })) + '</b></div>');
    }
    if (comp.jobsPending !== undefined) {
      lines.push('<div class="sys-kv"><span>' + t(K('jobsPending')) + '</span><b>' + esc(comp.jobsPending) + '</b></div>');
      lines.push('<div class="sys-kv"><span>' + t(K('jobsFailed')) + '</span><b>' + esc(comp.jobsFailed) + '</b></div>');
    }
    if (comp.lastCheckedAt) {
      lines.push('<div class="sys-kv"><span>' + t(K('lastCheck')) + '</span><b>' + esc(comp.lastCheckedAt) + '</b></div>');
    }
    if (comp.errorCode) {
      lines.push('<div class="sys-kv"><span>' + t(K('errorCode')) + '</span><b>' + esc(comp.errorCode) + '</b></div>');
    }
    var msg = comp.message ? '<div class="sys-msg">' + esc(comp.message) + '</div>' : '';
    var noPrev = comp.lastCheckedAt == null && (comp.component === 'metaapi' || comp.component === 'n8n_relay' || comp.component === 'ai' || comp.component === 'email')
      ? '<div class="sys-noprev">' + t(K('noPreviousCheck')) + '</div>' : '';
    return '<div class="sys-card" data-status="' + esc(st) + '">'
      + '<div class="sys-card-head"><span>' + esc(t(K(key))) + '</span>' + badge(st) + '<em>' + esc(label) + '</em></div>'
      + msg + noPrev + lines.join('')
      + '</div>';
  }

  function renderHealth(d) {
    var grid = $('healthGrid');
    if (!grid) return;
    var h = (d && d.data && d.data.health) ? d.data.health : (d && d.health ? d.health : null);
    if (!h || !h.components) {
      if (grid) grid.innerHTML = '<div class="sys-empty">' + esc(t(K('logs.error'))) + '</div>';
      return;
    }
    var order = ['api', 'database', 'redis', 'workers', 'metaapi', 'n8n_relay', 'ai', 'email'];
    var html = order.map(function (c) {
      if (!h.components[c]) return '';
      return componentCard(c, h.components[c]);
    }).join('');
    grid.innerHTML = html;
    if (h.checkedAt && $('healthCheckedAt')) txt($('healthCheckedAt'), t(K('checkedAt'), { time: h.checkedAt }));
  }

  function loadHealth(opts) {
    var panel = $('healthPanel');
    if (panel) panel.setAttribute('data-busy', '1');
    return request(HEALTH_BASE).then(function (d) {
      renderHealth(d);
      hideError($('healthErrorBox'));
    }).catch(function (err) {
      showError($('healthErrorBox'), err);
    }).then(function (r) {
      if (panel) panel.removeAttribute('data-busy');
      return r;
    });
  }

  function runDiagnostics() {
    var btn = $('diagRun');
    if (btn) { btn.disabled = true; txt(btn, t(K('diagnosticsRunning'))); }
    return request(HEALTH_BASE + '/refresh', { method: 'POST' }).then(function (d) {
      renderHealth(d);
      hideError($('healthErrorBox'));
    }).catch(function (err) {
      showError($('healthErrorBox'), err);
    }).then(function () {
      if (btn) { btn.disabled = false; txt(btn, t(K('refreshDiagnostics'))); }
    });
  }

  // ---------------------------------------------------------------- logs
  function labelSeverity(sev) {
    var m = { DEBUG: 'debug', INFO: 'info', WARN: 'warn', ERROR: 'error' };
    return t(K('sev.' + (m[String(sev).toUpperCase()] || 'info')));
  }

  function renderLogs(d) {
    var body = $('logBody');
    if (!body) return;
    var items = (d && d.data && d.data.items) ? d.data.items : (d && d.items ? d.items : []);
    var total = (d && d.data && d.data.total) != null ? d.data.total : (d && d.total != null ? d.total : 0);
    if (!items || !items.length) {
      body.innerHTML = '<tr><td colspan="6" class="sys-empty">' + esc(t(K('empty'))) + '</td></tr>';
    } else {
      body.innerHTML = items.map(function (r) {
        var sev = String(r.severity || 'INFO').toUpperCase();
        return '<tr class="log-row" data-sev="' + esc(sev.toLowerCase()) + '">'
          + '<td class="log-time">' + esc(r.createdAt || '') + '</td>'
          + '<td><span class="sys-badge sev-' + esc(sev.toLowerCase()) + '"></span>' + esc(labelSeverity(sev)) + '</td>'
          + '<td>' + esc(r.source || '') + '</td>'
          + '<td class="log-msg">' + esc(r.message || '') + '</td>'
          + '<td class="log-id">' + esc(r.requestId || '') + '</td>'
          + '<td class="log-id">' + esc(r.correlationId || '') + '</td>'
          + '</tr>';
      }).join('');
    }
    if ($('logCount')) txt($('logCount'), String(total));
    if ($('logPage')) txt($('logPage'), t(K('page'), { page: logState.page }));
    if ($('logPrev')) $('logPrev').disabled = logState.page <= 1;
  }

  function buildQuery() {
    var qs = [];
    if (logState.severity) qs.push('severity=' + encodeURIComponent(logState.severity));
    if (logState.source) qs.push('source=' + encodeURIComponent(logState.source));
    if (logState.q) qs.push('q=' + encodeURIComponent(logState.q));
    if (logState.since) qs.push('since=' + encodeURIComponent(logState.since));
    if (logState.until) qs.push('until=' + encodeURIComponent(logState.until));
    qs.push('page=' + logState.page);
    qs.push('per_page=50');
    return qs.join('&');
  }

  function loadLogs() {
    var panel = $('logsPanel');
    if (panel) panel.setAttribute('data-busy', '1');
    return request(LOGS_BASE + '?' + buildQuery()).then(function (d) {
      renderLogs(d);
      hideError($('logErrorBox'));
    }).catch(function (err) {
      showError($('logErrorBox'), err);
    }).then(function () {
      if (panel) panel.removeAttribute('data-busy');
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var lr = $('healthRefresh');
    if (lr) lr.addEventListener('click', loadHealth);
    var dr = $('diagRun');
    if (dr) dr.addEventListener('click', runDiagnostics);

    var ls = $('logSeverity');
    if (ls) ls.addEventListener('change', function () { logState.severity = ls.value; logState.page = 1; });
    var lsrc = $('logSource');
    if (lsrc) lsrc.addEventListener('change', function () { logState.source = lsrc.value; logState.page = 1; });
    var lq = $('logSearch');
    if (lq) lq.addEventListener('input', function () {
      if (logTimer) clearTimeout(logTimer);
      logTimer = setTimeout(function () { logState.q = lq.value.trim(); logState.page = 1; loadLogs(); }, 350);
    });
    var ls2 = $('logSince');
    if (ls2) ls2.addEventListener('change', function () { logState.since = ls2.value; logState.page = 1; });
    var lu = $('logUntil');
    if (lu) lu.addEventListener('change', function () { logState.until = lu.value; logState.page = 1; });
    var la = $('logApply');
    if (la) la.addEventListener('click', function () { logState.page = 1; loadLogs(); });
    var lp = $('logPrev');
    if (lp) lp.addEventListener('click', function () { if (logState.page > 1) { logState.page -= 1; loadLogs(); } });
    var ln = $('logNext');
    if (ln) ln.addEventListener('click', function () { logState.page += 1; loadLogs(); });

    /* Only fire panel requests once the memory session is ready (access token
       present), so the panels do not issue doomed 401 requests that the browser
       logs as console errors on every load. */
    if (window.VeloraData && VeloraData.ready) {
      VeloraData.ready().then(function (user) {
        if (!user) return;
        loadHealth();
        loadLogs();
      }).catch(function () { loadHealth(); loadLogs(); });
    } else {
      loadHealth();
      loadLogs();
    }
  });

  window.VeloraAdminSystem = { reloadHealth: loadHealth, reloadLogs: loadLogs };
})(window, document);
