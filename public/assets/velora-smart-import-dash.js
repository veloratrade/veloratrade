(function () {
  'use strict';
  var path = location.pathname || '';
  if (!/\/dashboard|\/trades(?!\/new)/.test(path)) return;
  if (window.__vsiDashLink) return;
  window.__vsiDashLink = true;

  function label() {
    var fa = (document.documentElement.lang || 'fa').toLowerCase().indexOf('fa') === 0;
    return fa ? 'ثبت با عکس MT5' : 'Register from photo';
  }

  function style(a) {
    a.id = 'vsiDashLink';
    a.href = '/trades/new/';
    a.style.cssText = [
      'display:inline-flex', 'align-items:center', 'gap:7px',
      'padding:8px 14px', 'border-radius:10px', 'font:800 12px inherit',
      'text-decoration:none', 'color:#1a1403',
      'background:linear-gradient(135deg,#fce38a,#d4af37 55%,#b88d1d)',
      'border:1px solid rgba(232,196,90,.8)',
      'box-shadow:0 6px 16px rgba(212,175,55,.28)'
    ].join(';');
    a.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.4"/><path d="m21 16-5.2-5.2L9 18"/></svg><span>' + label() + '</span>';
  }

  function add() {
    if (document.getElementById('vsiDashLink')) return;
    var host = document.querySelector('.velora-nav-right') || document.querySelector('.topbar .actions');
    var src = document.querySelector('a[href*="/trades/new"]');
    var a = document.createElement('a');
    style(a);
    if (host) host.insertBefore(a, host.firstChild);
    else if (src && src.parentNode) src.parentNode.insertBefore(a, src);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', add);
  else add();
  setTimeout(add, 200);
  setTimeout(add, 800);
})();
