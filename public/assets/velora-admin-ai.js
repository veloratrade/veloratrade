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

      if (entry.quota && typeof entry.quota.quotaLimit === 'number' && entry.quota.quotaLimit > 0) {
        var used = typeof entry.quota.dailyUsed === 'number' ? entry.quota.dailyUsed : 0;
        var quotaText = t(K('quota')) + ': ' + used + '/' + entry.quota.quotaLimit;
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

  /* --------------------------------------------------------------- init */

  function start() {
    if (!global.VeloraData) return;
    global.VeloraData.ready().then(function (user) {
      if (!user || user.role !== 'admin') return;
      var refresh = $('aiRefresh');
      if (refresh) refresh.addEventListener('click', loadOverview);
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
