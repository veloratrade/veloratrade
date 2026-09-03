/* VELORA Admin — Security / RBAC module (Module C / Module I).
 *
 * Backing endpoint: GET /api/v1/admin/me  (server-derived, behind $admin RBAC).
 *
 * Roles are RBAC authorization roles: user | admin | super_admin.
 * Subscription plans (free | pro) are a SEPARATE axis and are NEVER shown as a
 * role here. A user with role=user but plan=pro must not be presented with any
 * admin capability — the backend denies it regardless of what this UI shows, so
 * this view only RENDERS the server's truth about the CURRENT authenticated
 * admin. It is never an authorization boundary.
 *
 * Conventions (mirror velora-admin-ai.js):
 *   - BASE, global.VeloraData.request(path, opts)
 *   - K(stem) reads the inline VeloraAdminSecurityKeys map, t(key) == VeloraLocale.t
 *   - credentials/tokens/secrets never reach the DOM
 *   - no optimistic updates; render only after the backend responds
 */
(function (window, document) {
  'use strict';
  if (!window.VeloraData || !window.VeloraLocale) return;
  if (!window.VeloraAdminSecurityKeys) return;

  var BASE = '/api/v1/admin/me';
  var $ = function (id) { return document.getElementById(id); };
  var KEYS = window.VeloraAdminSecurityKeys;
  function K(stem) { return KEYS[stem] || stem; }
  function t(key) { return window.VeloraLocale.t(key); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function request(path, opts) { return window.VeloraData.request(path, opts || {}); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function showError(msg) {
    var box = $('securityErrorBox');
    if (!box) return;
    box.hidden = false;
    if ($('securityErrorText')) $('securityErrorText').textContent = msg || t(K('title'));
  }
  function hideError() {
    var box = $('securityErrorBox');
    if (box) box.hidden = true;
  }

  function roleLabel(role) {
    if (role === 'super_admin') return t(K('role.super_admin'));
    if (role === 'admin') return t(K('role.admin'));
    return t(K('role.user'));
  }

  function render(me, recent) {
    hideError();
    var roleEl = $('securityRole');
    if (roleEl) {
      roleEl.textContent = '';
      roleEl.appendChild(el('span', 'role ' + (me.isSuperAdmin ? 'admin' : 'user'), roleLabel(me.role)));
    }

    var perms = me.permissions || [];
    var permsEl = $('securityPerms');
    if (permsEl) {
      permsEl.textContent = '';
      (perms || []).forEach(function (p) {
        permsEl.appendChild(el('span', 'sec-perm', p));
      });
      if (!(perms || []).length) {
        permsEl.appendChild(el('span', 'security-role', t(K('noActions'))));
      }
    }

    var body = $('securityActions');
    if (body) {
      body.textContent = '';
      var items = (recent || []);
      if (!items.length) {
        var tr = document.createElement('tr');
        var td = el('td', '', t(K('noActions')));
        td.colSpan = 4;
        td.style.textAlign = 'center';
        td.style.padding = '22px';
        td.style.color = 'var(--faint)';
        tr.appendChild(td);
        body.appendChild(tr);
      } else {
        items.forEach(function (a) {
          var row = document.createElement('tr');
          row.appendChild(el('td', '', String(a.actorUserId || '')));
          row.appendChild(el('td', 'sec-act', String(a.action || '—')));
          var result = el('span', 'st ' + (a.result === 'success' ? 'active' : 'suspended'), String(a.result || '—'));
          var resTd = document.createElement('td');
          resTd.appendChild(result);
          row.appendChild(resTd);
          row.appendChild(el('td', 't-count', window.VeloraLocale.date(a.createdAt)));
          body.appendChild(row);
        });
      }
    }
  }

  function load() {
    var panel = $('securityPanel');
    var prev = panel ? panel.getAttribute('data-busy') : null;
    if (panel) panel.setAttribute('data-busy', '1');
    return request(BASE).then(function (d) {
      render((d && d.me) || {}, (d && d.recentAdminActions) || []);
    }).catch(function (err) {
      if (err && err.status === 403) {
        showError('403 — ' + (err.message || t(K('title'))));
      } else {
        showError(err && err.message ? err.message : t(K('title')));
      }
    }).then(function (result) {
      if (panel) {
        if (prev) panel.setAttribute('data-busy', prev); else panel.removeAttribute('data-busy');
      }
      return result;
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var refresh = $('securityRefresh');
    if (refresh) refresh.addEventListener('click', load);
    if (window.VeloraData && VeloraData.ready) {
      VeloraData.ready().then(function (user) { if (user) load(); }).catch(function () { load(); });
    } else {
      load();
    }
  });
})(window, document);
