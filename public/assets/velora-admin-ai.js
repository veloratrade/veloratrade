/*
 * Velora — admin AI settings panel.
 *
 * Contract (mission rules):
 *  - state comes ONLY from GET /api/v1/admin/ai/overview (authoritative backend);
 *  - NO client-side optimistic updates: every mutation response is ignored for
 *    rendering and the overview is re-fetched afterwards;
 *  - credentials are displayed as Configured / Not Configured booleans only —
 *    values never reach the DOM, logs, or any client state after submit;
 *  - server messages surface verbatim (no fabricated success text).
 */
(function (global) {
  'use strict';

  var BASE = '/api/v1/admin/ai';
  var overview = null;

  /* i18n keys live in ONE place: the inline VeloraAdminAIKeys map in
   * admin/index.html (canonical pattern — keys must appear in the template
   * so the feature-chunk planner covers them). */
  function K(stem) {
    var map = global.VeloraAdminAIKeys || {};
    return map[stem] || '';
  }

  function t(key) {
    try { return global.VeloraLocale.t(key); } catch (e) { return key; }
  }
  function $(id) { return document.getElementById(id); }
  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null && text !== '') node.textContent = String(text);
    return node;
  }
  function txt(node, value) { node.textContent = value === null || value === undefined ? '—' : String(value); return node; }

  function request(path, options) {
    return global.VeloraData.request(path, options || {});
  }

  function showError(message) {
    var box = $('aiErrorBox');
    if (!box) return;
    txt($('aiErrorText'), message || t(K('error')));
    box.hidden = false;
  }
  function hideError() { var box = $('aiErrorBox'); if (box) box.hidden = true; }

  /* ------------------------------------------------------------ status UX (Phase L.2)
     Single-authoritative presentation map: backend machine enum -> localized
     label key + visual class. The machine enum is NEVER replaced by the label
     (it stays in the technical line below); VeloraLocale.t() resolves the key
     so FA/EN text is fully catalog-driven. Unmapped enums fall back to the raw
     machine value (never a fabricated label). Both the AI panel and the
     Integrations panel consume these helpers. */
  function ensureStatusCss() {
    if (document.getElementById('velora-admin-status-css')) return;
    var style = document.createElement('style');
    style.id = 'velora-admin-status-css';
    style.textContent =
      '.velora-st{display:inline-flex;align-items:center;gap:6px;border-radius:999px;' +
      'padding:2px 10px;font-size:11px;font-weight:700;margin-inline-start:4px;' +
      'max-width:100%;overflow-wrap:anywhere;line-height:1.4;}' +
      '.velora-st-ok{background:rgba(76,211,154,.14);color:#4cd39a;border:1px solid rgba(76,211,154,.4);}' +
      '.velora-st-warn{background:rgba(245,158,11,.16);color:#fbbf24;border:1px solid rgba(245,158,11,.5);}' +
      '.velora-st-err{background:rgba(239,68,68,.12);color:#f87171;border:1px solid rgba(239,68,68,.4);}' +
      '.velora-st-unavail{background:rgba(139,92,246,.14);color:#c4b5fd;border:1px solid rgba(139,92,246,.4);}' +
      '.velora-st-muted{background:rgba(255,255,255,.06);color:#8fa0c0;border:1px solid rgba(255,255,255,.14);}' +
      '.ai-tech{font-family:Consolas,Menlo,monospace;color:var(--faint,#5E6F92);font-size:10.5px;}';
    document.head.appendChild(style);
  }
  ensureStatusCss();

  /* Enum -> K-map stem (the template VeloraAdminAIKeys map is the single source
     of the key literal; K() resolves stem -> full catalog key -> localized text). */
  var STATUS_MAP = {
    'VALID': 'aiStatus.valid',
    'SUCCESS': 'aiStatus.success',
    'UNVERIFIED': 'aiStatus.unverified',
    'NOT_CONFIGURED': 'aiStatus.notConfigured',
    'INVALID_CREDENTIAL': 'aiStatus.invalidCredential',
    'AUTH_FAILED': 'aiStatus.authFailed',
    'EXPIRED': 'aiStatus.expired',
    'REVOKED': 'aiStatus.revoked',
    'DISABLED': 'aiStatus.disabled',
    'INSUFFICIENT_PERMISSION': 'aiStatus.insufficientPermission',
    'QUOTA_EXCEEDED': 'aiStatus.quotaExceeded',
    'RATE_LIMITED': 'aiStatus.rateLimited',
    'REGION_RESTRICTED': 'aiStatus.regionRestricted',
    'PROVIDER_UNAVAILABLE': 'aiStatus.providerUnavailable',
    'SERVICE_UNAVAILABLE': 'aiStatus.serviceUnavailable',
    'UNKNOWN': 'aiStatus.unknown',
    'NETWORK_ERROR': 'aiStatus.networkError',
    'TIMEOUT': 'aiStatus.timeout',
    'PROVIDER_ERROR': 'aiStatus.providerError'
  };
  var STATUS_CLS = {
    'VALID': 'velora-st-ok', 'SUCCESS': 'velora-st-ok',
    'UNVERIFIED': 'velora-st-muted', 'NOT_CONFIGURED': 'velora-st-muted',
    'INVALID_CREDENTIAL': 'velora-st-err', 'AUTH_FAILED': 'velora-st-err',
    'EXPIRED': 'velora-st-err', 'REVOKED': 'velora-st-err', 'DISABLED': 'velora-st-err',
    'INSUFFICIENT_PERMISSION': 'velora-st-warn',
    'QUOTA_EXCEEDED': 'velora-st-warn', 'RATE_LIMITED': 'velora-st-warn',
    'REGION_RESTRICTED': 'velora-st-warn',
    'PROVIDER_UNAVAILABLE': 'velora-st-unavail',
    'SERVICE_UNAVAILABLE': 'velora-st-unavail', 'UNKNOWN': 'velora-st-unavail',
    'NETWORK_ERROR': 'velora-st-err', 'TIMEOUT': 'velora-st-err', 'PROVIDER_ERROR': 'velora-st-err'
  };
  function statusMeta(status) {
    if (!STATUS_MAP[status]) return null;
    return [STATUS_MAP[status], STATUS_CLS[status] || 'velora-st-muted'];
  }
  function statusLabel(status) {
    var e = statusMeta(status);
    if (!e) return status || '';
    var key = K(e[0]);
    if (!key) return status || '';
    try { return t(key) || status || ''; } catch (err) { return status || ''; }
  }
  function statusCls(status) { var e = statusMeta(status); return e ? e[1] : 'velora-st-muted'; }
  /* Expose for the Integrations panel (single source). */
  global.VeloraStatus = { meta: statusMeta, label: statusLabel, cls: statusCls };

  /* ---------------------------------------------------------------- data */

  function loadOverview() {
    hideError();
    return request(BASE + '/overview').then(function (data) {
      overview = data;
      render();
    }).catch(function (err) {
      showError(err && err.message ? err.message : null);
    });
  }

  /* Every mutation follows the same rule: call backend, then RE-FETCH. */
  function mutate(path, options) {
    var btnState = busy(true);
    return request(path, options).then(function () {
      return loadOverview();
    }).catch(function (err) {
      showError(err && err.message ? err.message : null);
      return loadOverview();
    }).then(function (result) { busy(btnState); return result; });
  }

  function busy(on) {
    var panel = $('aiSettingsPanel');
    if (!panel) return null;
    var prev = panel.getAttribute('data-busy');
    if (on) panel.setAttribute('data-busy', '1'); else panel.removeAttribute('data-busy');
    return prev;
  }

  /* ------------------------------------------------------------- render */

  function credentialBadge(entry) {
    var cs = entry.credentialStatus || {};
    if (!cs.required) {
      return el('span', 'ai-badge ai-muted', t(K('credentialNotRequired')));
    }
    return el('span', 'ai-badge ' + (cs.configured ? 'ai-ok' : 'ai-off'),
      cs.configured ? t(K('credentialConfigured')) : t(K('credentialNotConfigured')));
  }

  function renderProviders() {
    var wrap = $('aiProviders');
    if (!wrap || !overview) return;
    wrap.textContent = '';
    var head = el('div', 'ai-subhead', t(K('providers')));
    wrap.appendChild(head);
    (overview.providers || []).forEach(function (entry) {
      var row = el('div', 'ai-prov');

      var nameCol = el('div', 'ai-prov-name');
      nameCol.appendChild(el('b', '', entry.provider));
      var avail = el('span', 'ai-badge ' + (entry.available ? 'ai-ok' : 'ai-muted'),
        entry.available ? t(K('available')) : t(K('unavailable')));
      nameCol.appendChild(avail);
      row.appendChild(nameCol);

      var credCol = el('div', 'ai-prov-cred');
      credCol.appendChild(credentialBadge(entry));
      if (entry.credentialStatus && entry.credentialStatus.required) {
        var input = el('input', 'f-input');
        input.type = 'password';
        input.autocomplete = 'off';
        input.setAttribute('data-ai-cred-input', entry.provider);
        input.placeholder = t(K('credentialInput'));
        credCol.appendChild(input);

        var save = el('button', 'chip', t(K('credentialSet')));
        save.type = 'button';
        save.addEventListener('click', function () { saveCredential(entry.provider, input); });
        credCol.appendChild(save);

        var del = el('button', 'chip', t(K('credentialDelete')));
        del.type = 'button';
        del.addEventListener('click', function () { deleteCredential(entry.provider); });
        credCol.appendChild(del);
      }
      row.appendChild(credCol);

      // Phase 2: expose the credential VALIDATION truth (configured ≠ verified).
      // Phase L.2: localized human-readable status badge + machine enum kept in
      // a muted technical line (the enum is the contract value, never replaced).
      // No secret, no raw provider response body is ever rendered.
      var vc = entry.credential || {};
      if (entry.credentialStatus && entry.credentialStatus.required) {
        var valCol = el('div', 'ai-prov-cred');
        var stBadge = el('span', 'ai-kv', t(K('credentialStatus')));
        var statusEl = el('span', 'velora-st ' + statusCls(vc.status), statusLabel(vc.status));
        statusEl.setAttribute('role', 'status');
        valCol.appendChild(stBadge);
        valCol.appendChild(statusEl);
        // Machine enum (secret-free contract value) in a muted technical line.
        valCol.appendChild(el('span', 'ai-kv ai-tech', t(K('statusDetail')) + ': ' + (vc.status || '—')));
        valCol.appendChild(el('span', 'ai-kv', t(K('verified')) + ': ' + (vc.verified ? t(K('verified')) : t(K('unverified')))));
        if (vc.lastCheckedAt) { valCol.appendChild(el('span', 'ai-kv', t(K('lastChecked')) + ': ' + vc.lastCheckedAt)); }
        if (typeof vc.latencyMs === 'number' && vc.latencyMs > 0) { valCol.appendChild(el('span', 'ai-kv', t('admin.system.latencyMs', { ms: vc.latencyMs }))); }
        if (vc.errorCode) { valCol.appendChild(el('span', 'ai-kv', t('admin.system.errorCode') + ': ' + vc.errorCode)); }
        var verifyBtn = el('button', 'chip', t(K('verify')));
        var testBtn = el('button', 'chip', t(K('testConnection')));
        verifyBtn.type = 'button';
        testBtn.type = 'button';
        verifyBtn.addEventListener('click', function () { runProviderOp(entry.provider, 'verify', verifyBtn, false); });
        testBtn.addEventListener('click', function () { runProviderOp(entry.provider, 'test-connection', testBtn, true); });
        valCol.appendChild(verifyBtn);
        valCol.appendChild(testBtn);
        row.appendChild(valCol);
      }

      if (entry.quota && typeof entry.quota.quotaLimit === 'number' && entry.quota.quotaLimit > 0) {
        var used = typeof entry.quota.dailyUsed === 'number' ? entry.quota.dailyUsed : 0;
        // quota.source==='internal' => Velora internal budget, NOT provider-reported.
        var quotaLabel = (entry.quota.source === 'internal') ? t(K('quotaInternal')) : t(K('quota'));
        var quotaText = quotaLabel + ': ' + used + '/' + entry.quota.quotaLimit;
        row.appendChild(el('span', 'ai-badge ai-muted', quotaText));
      }
      if (entry.provider === 'gemini' && entry.relay) {
        var relayCol = el('div', 'ai-prov-relay');
        relayCol.appendChild(el('span', 'ai-kv', t(K('relayUrl')) + ': ' + relayYesNo(entry.relay.urlConfigured)));
        relayCol.appendChild(el('span', 'ai-kv', t(K('relayToken')) + ': ' + relayYesNo(entry.relay.tokenConfigured)));
        if (entry.effectiveRoute) {
          relayCol.appendChild(el('span', 'ai-kv', t(K('effectiveRoute')) + ': ' + entry.effectiveRoute));
        }
        row.appendChild(relayCol);
      }
      wrap.appendChild(row);
    });
  }

  function relayYesNo(configured) {
    return configured
      ? t(K('credentialConfigured'))
      : t(K('credentialNotConfigured'));
  }

  function renderFeatures() {
    var wrap = $('aiFeatures');
    if (!wrap || !overview) return;
    wrap.textContent = '';
    (overview.features || []).forEach(function (feature) {
      var card = el('div', 'panel ai-feature');

      var head = el('div', 'panel-head');
      var title = el('div', 't');
      title.appendChild(el('span', '', K('feature.' + feature.feature) ? t(K('feature.' + feature.feature)) : feature.feature));
      head.appendChild(title);

      var meta = el('span', 'meta');
      var src = feature.source === 'db'
        ? t(K('source.db'))
        : t(K('source.envDefault'));
      meta.appendChild(el('span', 'ai-badge ' + (feature.source === 'db' ? 'ai-ok' : 'ai-muted'), src));
      head.appendChild(meta);
      card.appendChild(head);

      var rows = feature.rows || [];
      if (rows.length === 0) {
        card.appendChild(el('div', 'ai-empty', t(K('empty'))));
      } else {
        var table = el('table', 'ai-table');
        var thead = el('thead');
        var hr = el('tr');
        [t(K('priority')), t(K('provider')), t(K('model')),
         t(K('route')), t(K('status')), ''].forEach(function (label) {
          hr.appendChild(el('th', '', label));
        });
        thead.appendChild(hr);
        table.appendChild(thead);

        var tbody = el('tbody');
        rows.forEach(function (row, index) {
          tbody.appendChild(renderRow(feature.feature, row, index, rows.length));
        });
        table.appendChild(tbody);
        card.appendChild(table);
      }

      card.appendChild(renderAddForm(feature));
      wrap.appendChild(card);
    });
  }

  function renderRow(feature, row, index, total) {
    var tr = el('tr');
    tr.appendChild(el('td', '', String(row.priority)));
    tr.appendChild(el('td', '', row.provider));
    tr.appendChild(txt(el('td'), row.model || t(K('auto'))));
    tr.appendChild(txt(el('td'), row.route === null || row.route === undefined ? '—' : row.route));

    var statusCell = el('td');
    statusCell.appendChild(el('span', 'ai-badge ' + (row.enabled ? 'ai-ok' : 'ai-off'),
      row.enabled ? t(K('enabled')) : t(K('disabled'))));
    var toggle = el('button', 'chip', row.enabled
      ? t(K('disabled'))
      : t(K('enabled')));
    toggle.type = 'button';
    toggle.addEventListener('click', function () {
      mutate(BASE + '/feature-providers/' + row.id, {
        method: 'PATCH',
        body: { enabled: !row.enabled }
      });
    });
    statusCell.appendChild(toggle);
    tr.appendChild(statusCell);

    var actions = el('td');
    if (index > 0) {
      actions.appendChild(actionButton(t(K('moveUp')), function () { moveRow(feature, index, index - 1); }));
    }
    if (index < total - 1) {
      actions.appendChild(actionButton(t(K('moveDown')), function () { moveRow(feature, index, index + 1); }));
    }
    actions.appendChild(actionButton(t(K('remove')), function () { removeRow(row); }));
    tr.appendChild(actions);
    return tr;
  }

  function actionButton(label, handler) {
    var btn = el('button', 'chip', label);
    btn.type = 'button';
    btn.addEventListener('click', handler);
    return btn;
  }

  function moveRow(feature, from, to) {
    if (!overview) return;
    var rows = [];
    (overview.features || []).forEach(function (f) {
      if (f.feature === feature) rows = f.rows || [];
    });
    if (!rows.length) return;
    var ordered = rows.slice();
    var moved = ordered.splice(from, 1)[0];
    ordered.splice(to, 0, moved);
    mutate(BASE + '/feature-providers/reorder', {
      method: 'POST',
      body: { feature: feature, orderedIds: ordered.map(function (r) { return r.id; }) }
    });
  }

  function removeRow(row) {
    if (!global.confirm(t(K('confirmRemove')))) return;
    mutate(BASE + '/feature-providers/' + row.id, { method: 'DELETE' });
  }

  function providerOptions() {
    var out = [];
    (overview && overview.providers ? overview.providers : []).forEach(function (p) {
      if (p.provider) out.push(p.provider);
    });
    return out;
  }

  function modelOptions(provider) {
    var out = [];
    (overview && overview.providers ? overview.providers : []).forEach(function (p) {
      if (p.provider === provider && p.modelAllowlist) out = p.modelAllowlist;
    });
    return out;
  }

  function routeOptions(provider) {
    var out = [];
    (overview && overview.providers ? overview.providers : []).forEach(function (p) {
      if (p.provider === provider && p.routeAllowlist) out = p.routeAllowlist;
    });
    return out;
  }

  function renderAddForm(feature) {
    var form = el('div', 'ai-add');

    var providerSelect = el('select', 'f-input');
    providerOptions().forEach(function (name) {
      var option = el('option', '', name);
      option.value = name;
      providerSelect.appendChild(option);
    });

    var modelSelect = el('select', 'f-input');
    modelSelect.appendChild(noneOption(t(K('auto'))));
    var refreshModels = function () {
      modelSelect.textContent = '';
      modelSelect.appendChild(noneOption(t(K('auto'))));
      modelOptions(providerSelect.value).forEach(function (model) {
        var option = el('option', '', model);
        option.value = model;
        modelSelect.appendChild(option);
      });
    };
    providerSelect.addEventListener('change', refreshModels);
    refreshModels();

    var routeSelect = el('select', 'f-input');
    var refreshRoutes = function () {
      routeSelect.textContent = '';
      routeSelect.appendChild(noneOption('—'));
      routeOptions(providerSelect.value).forEach(function (route) {
        var option = el('option', '', route);
        option.value = route;
        routeSelect.appendChild(option);
      });
    };
    providerSelect.addEventListener('change', refreshRoutes);
    refreshRoutes();

    var priorityInput = el('input', 'f-input');
    priorityInput.type = 'number';
    priorityInput.min = '0';
    priorityInput.max = '20';
    priorityInput.value = '0';

    var add = el('button', 'chip', t(K('addProvider')));
    add.type = 'button';
    add.addEventListener('click', function () {
      var body = {
        feature: feature.feature,
        provider: providerSelect.value,
        priority: parseInt(priorityInput.value, 10) || 0
      };
      if (modelSelect.value) body.model = modelSelect.value;
      if (routeSelect.value) body.route = routeSelect.value;
      mutate(BASE + '/feature-providers', { method: 'POST', body: body });
    });

    form.appendChild(labelWrap(K('provider'), providerSelect));
    form.appendChild(labelWrap(K('model'), modelSelect));
    form.appendChild(labelWrap(K('route'), routeSelect));
    form.appendChild(labelWrap(K('priority'), priorityInput));
    form.appendChild(add);
    return form;
  }

  function noneOption(label) {
    var option = el('option', '', label);
    option.value = '';
    return option;
  }

  function labelWrap(key, control) {
    var wrap = el('label', 'ai-field');
    wrap.appendChild(el('span', 'ai-field-label', t(key)));
    wrap.appendChild(control);
    return wrap;
  }

  function renderMeta() {
    var meta = $('aiRoutingMeta');
    if (!meta || !overview) return;
    var count = String(overview.routingRowCount);
    var template = t(K('routingRows')).replace('{count}', count);
    txt(meta, template);
  }

  function render() {
    renderMeta();
    renderProviders();
    renderFeatures();
  }

  /* --------------------------------------------------------- credentials */

  function saveCredential(provider, input) {
    var value = (input.value || '').trim();
    if (!value) return;
    input.value = '';
    input.blur();
    mutate(BASE + '/credentials/' + provider, { method: 'POST', body: { value: value } });
  }

  function deleteCredential(provider) {
    mutate(BASE + '/credentials/' + provider, { method: 'DELETE' });
  }

  /* Phase 2/Phase L.2: live provider-side verification — server returns
     classified status metadata only (never a secret). Results are then
     re-fetched. Per-provider busy guard prevents duplicate submissions and the
     Test-Connection button surfaces a visible "Testing…" state during the call. */
  var providerBusy = {};
  function runProviderOp(provider, op, btn, isTest) {
    if (providerBusy[provider]) return; /* duplicate submission guard */
    providerBusy[provider] = true;
    var originalLabel = btn.textContent;
    btn.disabled = true;
    if (isTest) txt(btn, t('admin.integrations.testing'));
    mutate('/api/v1/admin/providers/' + encodeURIComponent(provider) + '/' + op, { method: 'POST', body: {} })
      .then(function () {
        btn.disabled = false;
        txt(btn, originalLabel);
        providerBusy[provider] = false;
      }, function () {
        btn.disabled = false;
        txt(btn, originalLabel);
        providerBusy[provider] = false;
      });
  }

  /* --------------------------------------------------------------- relay config
     Phase A — n8n Gemini Relay admin configuration.
     - State comes ONLY from GET /api/v1/admin/integrations/relay/config.
     - Super Admin only (isSuper): a plain admin is denied write server-side and
       the controls are disabled here (server remains the authorization boundary;
       this is UX only).
     - The relay TOKEN is never rendered, logged or stored client-side. The token
       input is a write-only field; it is cleared after submit and the status is
       re-fetched (Configured / Not configured + masked block). We never echo it.
     - URL host shown is the server's safe host representation. */

  var RELAY_BASE = '/api/v1/admin/integrations/relay/config';

  function showRelayError(message) {
    var box = $('relayErrorBox');
    if (!box) return;
    txt($('relayErrorText'), message || '');
    box.hidden = false;
  }
  function hideRelayError() { var box = $('relayErrorBox'); if (box) box.hidden = true; }

  function loadRelay(isSuper) {
    hideRelayError();
    var urlInput = $('relayUrlInput');
    if (!urlInput) return;
    /* Server remains the authorization boundary — the API will 403 anyway. */
    var canWrite = isSuper;
    if ($('relaySave')) $('relaySave').disabled = !canWrite;
    if ($('relayClear')) $('relayClear').disabled = !canWrite;
    request(RELAY_BASE).then(function (data) {
      var c = (data && data.config) || {};
      var statusEl = $('relayStatus');
      var hostEl = $('relayHost');
      var maskedEl = $('relayMasked');
      if (statusEl) txt(statusEl, (c.configured ? K('relay.configured') : K('relay.notConfigured')) + '');
      if (hostEl) txt(hostEl, (c.urlConfigured && c.urlHost) ? (K('relay.host') + ': ' + c.urlHost) : '');
      if (maskedEl) txt(maskedEl, c.tokenConfigured ? K('relay.maskedToken') : '');
    }).catch(function (err) {
      showRelayError(err && err.message ? err.message : null);
    });
  }

  function mutateRelay(method, body) {
    hideRelayError();
    request(RELAY_BASE, { method: method, body: body || {} }).then(function () {
      /* Always re-read authoritative state; never keep client-authored values. */
      return loadRelay(true);
    }).catch(function (err) {
      showRelayError(err && err.message ? err.message : null);
      return loadRelay(true);
    });
  }

  /* --------------------------------------------------------------- global AI route
     Phase B — Admin-managed GLOBAL AI default route.
     - State comes ONLY from GET /api/v1/admin/ai/route (authoritative backend).
     - Save = Super Admin only (P_AI_ROUTE_MANAGE enforced server-side; here the
       controls are disabled for a plain admin as UX only — the server is the
       boundary and returns 403 for an admin).
     - No secrets involved. Renders Configured / Effective / Source and keeps
       them distinct so an ENV fallback is never presented as a saved Admin
       setting. No "n8n Connected" claim — configuration state only. */

  var ROUTE_BASE = '/api/v1/admin/ai/route';

  function showRouteError(message) {
    var box = $('globalRouteErrorBox');
    if (!box) return;
    txt($('globalRouteErrorText'), message || '');
    box.hidden = false;
  }
  function hideRouteError() { var box = $('globalRouteErrorBox'); if (box) box.hidden = true; }

  function routeSourceLabel(source) {
    if (source === 'admin') return t(K('route.sourceAdmin'));
    if (source === 'env') return t(K('route.sourceEnv'));
    if (source === 'legacy_flag') return t(K('route.sourceFlag'));
    return t(K('route.sourceDefault'));
  }
  function routeValueLabel(value) {
    if (value === 'n8n_relay') return t(K('route.relay'));
    if (value === 'direct') return t(K('route.direct'));
    return '';
  }

  function loadGlobalRoute(canWrite) {
    hideRouteError();
    var sel = $('globalRouteSelect');
    if (!sel) return;
    if ($('globalRouteSave')) $('globalRouteSave').disabled = !canWrite;
    if ($('globalRouteReset')) $('globalRouteReset').disabled = !canWrite;
    request(ROUTE_BASE).then(function (data) {
      var r = (data && data.route) || {};
      var cfg = r.configured || '';
      sel.value = (cfg === 'direct' || cfg === 'n8n_relay') ? cfg : '';
      /* Configured vs Effective vs Source, kept distinct. */
      var cfgEl = $('globalRouteConfigured');
      var effEl = $('globalRouteEffective');
      var srcEl = $('globalRouteSource');
      if (cfgEl) txt(cfgEl, K('route.configured') + ': ' + (cfg ? routeValueLabel(cfg) : t(K('route.unset'))));
      if (effEl) txt(effEl, K('route.effective') + ': ' + routeValueLabel(r.effective));
      if (srcEl) txt(srcEl, K('route.source') + ': ' + routeSourceLabel(r.source));
    }).catch(function (err) {
      showRouteError(err && err.message ? err.message : null);
    });
  }

  function mutateRoute(method, body) {
    hideRouteError();
    request(ROUTE_BASE, { method: method, body: body || {} }).then(function () {
      /* Always re-read authoritative state; never keep client-authored values. */
      return loadGlobalRoute(true);
    }).catch(function (err) {
      showRouteError(err && err.message ? err.message : null);
      return loadGlobalRoute(true);
    });
  }

  /* --------------------------------------------------------------- init */

  function start() {
    if (!global.VeloraData) return;
    global.VeloraData.ready().then(function (user) {
      if (!user || (user.role !== 'admin' && user.role !== 'super_admin')) return;
      var refresh = $('aiRefresh');
      if (refresh) refresh.addEventListener('click', loadOverview);
      var isSuper = user.role === 'super_admin';
      var relaySave = $('relaySave');
      var relayClear = $('relayClear');
      var relayUrlInput = $('relayUrlInput');
      var relayTokenInput = $('relayTokenInput');
      if (relaySave) relaySave.addEventListener('click', function () {
        var body = {};
        if (relayUrlInput) { var u = relayUrlInput.value.trim(); if (u) body.url = u; }
        if (relayTokenInput) { var tk = relayTokenInput.value; if (tk) body.token = tk; }
        if (relayUrlInput) relayUrlInput.value = '';
        if (relayTokenInput) relayTokenInput.value = '';
        mutateRelay('PUT', body);
      });
      if (relayClear) relayClear.addEventListener('click', function () { mutateRelay('DELETE', {}); });
      loadRelay(isSuper);
      var routeSave = $('globalRouteSave');
      var routeReset = $('globalRouteReset');
      var routeSel = $('globalRouteSelect');
      if (routeSave) routeSave.addEventListener('click', function () {
        var value = routeSel && routeSel.value ? routeSel.value : '';
        if (!value) return;
        mutateRoute('PUT', { route: value });
      });
      if (routeReset) routeReset.addEventListener('click', function () { mutateRoute('DELETE', {}); });
      loadGlobalRoute(isSuper);
      return loadOverview();
    }).catch(function () { /* auth bootstrap handles routing */ });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  global.VeloraAdminAI = { reload: loadOverview };
})(window);
