/* VELORA Admin — External integration configuration module (Phase C).
 *
 * Backing endpoints:
 *   GET   /api/v1/admin/integrations            (inventory; P_INTEGRATIONS_VIEW)
 *   GET   /api/v1/admin/integrations/metaapi    (P_INTEGRATIONS_VIEW)
 *   PUT   /api/v1/admin/integrations/metaapi    (P_INTEGRATIONS_MANAGE, super only)
 *   DELETE/api/v1/admin/integrations/metaapi    (P_INTEGRATIONS_MANAGE, super only)
 *   POST  /api/v1/admin/integrations/metaapi/test (P_INTEGRATIONS_MANAGE)
 *   GET   /api/v1/admin/integrations/email      (P_INTEGRATIONS_VIEW)
 *   PUT   /api/v1/admin/integrations/email      (P_INTEGRATIONS_MANAGE, super only)
 *   DELETE/api/v1/admin/integrations/email      (P_INTEGRATIONS_MANAGE, super only)
 *   POST  /api/v1/admin/integrations/email/test (P_INTEGRATIONS_MANAGE)
 *
 * Security invariants (enforced server-side; mirrored here as UX only):
 *   - The server is ALWAYS the authorization boundary. A plain admin gets 403
 *     regardless of what this UI disables.
 *   - Secrets (platform token, resend key, smtp password) are write-only inputs.
 *     The GET response only ever carries booleans (tokenConfigured /
 *     apiKeyConfigured / webhookSecretConfigured) + a host, never the value.
 *   - We never optimistically show "Connected": Configured / Reachable are
 *     distinct and only a successful POST /test updates the reachability badge.
 *   - Test Connection never sends a real email for the Resend/SMTP probe; the
 *     probe does an authenticated GET /domains (Resend) or TCP+STARTTLS+AUTH
 *     handshake only (SMTP) — no RCPT/DATA.
 */
(function (window, document) {
  'use strict';
  if (!window.VeloraData || !window.VeloraLocale) return;
  if (!window.VeloraAdminIntegrationKeys) return;

  var BASE = '/api/v1/admin/integrations';
  var $ = function (id) { return document.getElementById(id); };
  var KEYS = window.VeloraAdminIntegrationKeys;
  function K(stem) { return KEYS[stem] || stem; }
  function t(key) { return window.VeloraLocale.t(key); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function request(path, opts) { return window.VeloraData.request(path, opts || {}); }
  function txt(el, value) { if (el) el.textContent = value; }
  function showError(msg) {
    var box = $('integrationErrorBox');
    if (!box) return;
    box.hidden = false;
    txt($('integrationErrorText'), msg || t(K('title')));
  }
  function hideError() { var box = $('integrationErrorBox'); if (box) box.hidden = true; }
  function sourceLabel(source) {
    if (source === 'admin') return t(K('sourceAdmin'));
    if (source === 'env') return t(K('sourceEnv'));
    if (source === 'velora_env') return t(K('sourceVeloraEnv'));
    // email uses 'default' when unset, metaapi uses 'unset'
    if (source === 'unset' || source === 'default') return t(K('sourceUnset'));
    return t(K('sourceUnset'));
  }
  function statusText(status) {
    if (status === 'SUCCESS') return t(K('configured')) + ' · ' + t(K('reachable'));
    if (status === 'AUTH_FAILED') return t(K('notConfigured')) + ' · 401';
    return '—';
  }

  var busy = false;
  function markBusy(el, on) { if (el) el.disabled = !!on; }

  /* ---- MetaAPI ---- */
  function renderMetaapi(c) {
    var cfg = (c && c.config) || (c && c.integration) || c || {};
    txt($('metaapiStatus'), (cfg.configured ? t(K('configured')) : t(K('notConfigured'))) +
      (cfg.reachability && cfg.reachability !== 'unknown'
        ? ' · ' + statusText(cfg.reachability) : ''));
    txt($('metaapiSource'), t(K('source')) + ': ' + sourceLabel(cfg.source));
    txt($('metaapiHost'), t(K('host')) + ': ' + (cfg.baseUrlHost || (cfg.baseUrl ? safeHost(cfg.baseUrl) : '—')));
    if ($('metaapiLastVerified')) {
      txt($('metaapiLastVerified'), cfg.lastCheckedAt && cfg.lastCheckedAt !== 'unknown'
        ? t(K('lastVerified')) + ': ' + window.VeloraLocale.date(cfg.lastCheckedAt)
        : '');
    }
    if ($('metaapiTokenInput')) $('metaapiTokenInput').placeholder = cfg.tokenConfigured ? t(K('tokenConfigured')) : '••••••••';
    if ($('metaapiBaseUrlInput') && !$('metaapiBaseUrlInput').value) $('metaapiBaseUrlInput').value = cfg.baseUrl || '';
  }

  function loadMetaapi() {
    request(BASE + '/metaapi').then(function (d) {
      renderMetaapi(d);
    }).catch(function (err) { showError(err && err.message ? err.message : null); });
  }

  function mutateMetaapi(method, body) {
    hideError();
    markBusy($('metaapiSave'), true); markBusy($('metaapiClear'), true);
    request(BASE + '/metaapi', { method: method, body: body || {} }).then(function (d) {
      renderMetaapi(d);
      if ($('metaapiTokenInput')) $('metaapiTokenInput').value = '';
      if ($('metaapiBaseUrlInput')) $('metaapiBaseUrlInput').value = '';
    }).catch(function (err) {
      showError(err && err.message ? err.message : null);
    }).then(function () {
      markBusy($('metaapiSave'), false); markBusy($('metaapiClear'), false);
      loadMetaapi();
    });
  }

  function testMetaapi() {
    hideError();
    markBusy($('metaapiTest'), true);
    txt($('metaapiTest'), t(K('testing')));
    request(BASE + '/metaapi/test', { method: 'POST', body: {} }).then(function (d) {
      renderMetaapi(d);
      renderStatusResult('metaapiStatus', d && d.test && d.test.status);
      showInfo(d && d.test ? statusLabel(d.test.status) : null);
    }).catch(function (err) {
      showError(err && err.message ? err.message : null);
    }).then(function () {
      markBusy($('metaapiTest'), false);
      txt($('metaapiTest'), t(K('test')));
    });
  }

  /* ---- Email ---- */
  function renderEmail(c) {
    var cfg = (c && c.config) || (c && c.integration) || c || {};
    txt($('emailStatus'), (cfg.configured ? t(K('configured')) : t(K('notConfigured'))) +
      (cfg.reachability && cfg.reachability !== 'unknown'
        ? ' · ' + statusText(cfg.reachability) : ''));
    txt($('emailSource'), t(K('source')) + ': ' + sourceLabel(cfg.source));
    if ($('emailLastVerified')) {
      txt($('emailLastVerified'), cfg.lastCheckedAt && cfg.lastCheckedAt !== 'unknown'
        ? t(K('lastVerified')) + ': ' + window.VeloraLocale.date(cfg.lastCheckedAt) : '');
    }
    var sel = $('emailDriverInput');
    if (sel) {
      sel.value = cfg.driver || '';
    }
    if ($('emailFromInput') && !$('emailFromInput').value) $('emailFromInput').value = cfg.from || '';
    if ($('emailFromNameInput') && !$('emailFromNameInput').value) $('emailFromNameInput').value = cfg.fromName || '';
    if ($('emailHostInput') && !$('emailHostInput').value) $('emailHostInput').value = cfg.smtpHost || '';
    if ($('emailPortInput') && !$('emailPortInput').value) $('emailPortInput').value = cfg.smtpPort || '';
    if ($('emailUserInput') && !$('emailUserInput').value) $('emailUserInput').value = cfg.smtpUser || '';
    if ($('emailApiKeyInput')) $('emailApiKeyInput').placeholder = cfg.resendApiKeyConfigured ? t(K('apiKeyConfigured')) : '••••••••';
  }

  function loadEmail() {
    request(BASE + '/email').then(function (d) { renderEmail(d); })
      .catch(function (err) { showError(err && err.message ? err.message : null); });
  }

  function mutateEmail(method, body) {
    hideError();
    markBusy($('emailSave'), true); markBusy($('emailClear'), true);
    request(BASE + '/email', { method: method, body: body || {} }).then(function (d) {
      renderEmail(d);
      ['emailDriverInput', 'emailFromInput', 'emailFromNameInput', 'emailApiKeyInput',
       'emailHostInput', 'emailPortInput', 'emailUserInput'].forEach(function (id) {
        if ($(id) && id !== 'emailDriverInput') $(id).value = '';
      });
    }).catch(function (err) {
      showError(err && err.message ? err.message : null);
    }).then(function () {
      markBusy($('emailSave'), false); markBusy($('emailClear'), false);
      loadEmail();
    });
  }

  function testEmail() {
    hideError();
    markBusy($('emailTest'), true);
    txt($('emailTest'), t(K('testing')));
    request(BASE + '/email/test', { method: 'POST', body: {} }).then(function (d) {
      renderEmail(d);
      renderStatusResult('emailStatus', d && d.test && d.test.status);
      showInfo(d && d.test ? statusLabel(d.test.status) : null);
    }).catch(function (err) {
      showError(err && err.message ? err.message : null);
    }).then(function () {
      markBusy($('emailTest'), false);
      txt($('emailTest'), t(K('test')));
    });
  }

  /* Phase L.2 — presentation delegates to the single-authoritative VeloraStatus
     mapper (shared with the AI panel). Machine enum is never replaced; the
     localized human label is resolved via the catalog (FA/EN). Unknown enums
     fall back to the raw machine value (never a fabricated label). */
  function statusLabel(status) {
    if (window.VeloraStatus && window.VeloraStatus.label) return window.VeloraStatus.label(status);
    var key = 'admin.integrations.result.' + {
      SUCCESS: 'success', AUTH_FAILED: 'authFailed', TIMEOUT: 'timeout',
      NETWORK_ERROR: 'networkError', SERVICE_UNAVAILABLE: 'serviceUnavailable',
      NOT_CONFIGURED: 'notConfigured', INVALID: 'invalid'
    }[status];
    return key ? t(key) : status;
  }
  function statusCls(status) {
    return (window.VeloraStatus && window.VeloraStatus.cls)
      ? window.VeloraStatus.cls(status) : 'velora-st-muted';
  }
  /* Render a localized, color-coded connection-status badge on a card's status
     line. A text loader is improved but a success is never claimed: the badge
     reflects the server's classified result; a malformed/unknown value shows
     the raw enum, never a fabricated label. */
  function renderStatusResult(id, status) {
    var host = $(id);
    if (!host) return;
    var prev = host.querySelector('.velora-st');
    if (prev) prev.remove();
    if (!status) return;
    var badge = document.createElement('span');
    badge.className = 'velora-st ' + statusCls(status);
    badge.textContent = statusLabel(status);
    badge.setAttribute('role', 'status');
    badge.setAttribute('aria-live', 'polite');
    host.appendChild(document.createTextNode(' · '));
    host.appendChild(badge);
  }

  function safeHost(url) {
    if (!url) return '—';
    try {
      var p = new URL(url);
      return p.hostname;
    } catch (e) { return '—'; }
  }

  var infoTimer = null;
  function showInfo(msg) {
    if (!msg) return;
    showError(msg);
    if (infoTimer) clearTimeout(infoTimer);
    infoTimer = setTimeout(function () { hideError(); }, 4000);
  }

  function load() {
    var panel = $('integrationPanel');
    if (panel) panel.setAttribute('data-busy', '1');
    return request(BASE).then(function (d) {
      renderMetaapi(d);
      renderEmail(d);
    }).catch(function (err) {
      showError(err && err.message ? err.message : null);
    }).then(function (r) {
      if (panel) panel.removeAttribute('data-busy');
      return r;
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var refresh = $('integrationRefresh');
    if (refresh) refresh.addEventListener('click', load);

    var ms = $('metaapiSave');
    if (ms) ms.addEventListener('click', function () {
      var body = {};
      if ($('metaapiBaseUrlInput') && $('metaapiBaseUrlInput').value.trim()) body.base_url = $('metaapiBaseUrlInput').value.trim();
      if ($('metaapiTokenInput') && $('metaapiTokenInput').value) body.token = $('metaapiTokenInput').value;
      if (!body.base_url && !body.token) { showError(t(K('nopermissions'))); return; }
      mutateMetaapi('PUT', body);
    });
    var mc = $('metaapiClear');
    if (mc) mc.addEventListener('click', function () {
      if (window.confirm(t(K('clearConfirm')))) mutateMetaapi('DELETE', {});
    });
    var mt = $('metaapiTest');
    if (mt) mt.addEventListener('click', testMetaapi);

    var es = $('emailSave');
    if (es) es.addEventListener('click', function () {
      var body = {};
      var map = {
        driver: 'emailDriverInput', from: 'emailFromInput', from_name: 'emailFromNameInput',
        smtp_host: 'emailHostInput', smtp_port: 'emailPortInput', smtp_user: 'emailUserInput'
      };
      Object.keys(map).forEach(function (k) {
        var el = $(map[k]);
        if (el && el.value) body[k] = el.value;
      });
      if ($('emailApiKeyInput') && $('emailApiKeyInput').value) body.resend_api_key = $('emailApiKeyInput').value;
      if (!Object.keys(body).length) { showError(t(K('nopermissions'))); return; }
      mutateEmail('PUT', body);
    });
    var ec = $('emailClear');
    if (ec) ec.addEventListener('click', function () {
      if (window.confirm(t(K('clearConfirm')))) mutateEmail('DELETE', {});
    });
    var et = $('emailTest');
    if (et) et.addEventListener('click', testEmail);

    if (window.VeloraData && VeloraData.ready) {
      VeloraData.ready().then(function (user) { if (user) load(); }).catch(function () { load(); });
    } else {
      load();
    }
  });

  window.VeloraAdminIntegrations = { reload: load };
})(window, document);
