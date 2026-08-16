/** Independent symbol picker — works even if the page start() script throws. */
(function () {
  'use strict';
  if (!/\/trades\/new/.test(location.pathname)) return;

  function byId(id) { return document.getElementById(id); }

  function place() {
    var btn = byId('symBtn');
    var drop = byId('symDropdown');
    if (!btn || !drop || !drop.classList.contains('show')) return;
    var r = btn.getBoundingClientRect();
    drop.style.position = 'fixed';
    drop.style.top = Math.round(r.bottom + 8) + 'px';
    drop.style.left = Math.round(Math.min(r.left, window.innerWidth - r.width - 12)) + 'px';
    drop.style.width = Math.round(r.width) + 'px';
    drop.style.right = 'auto';
    drop.style.zIndex = '8000';
    drop.style.maxHeight = Math.min(420, Math.max(220, window.innerHeight - r.bottom - 24)) + 'px';
    drop.style.display = 'flex';
    drop.style.flexDirection = 'column';
  }

  function open(show) {
    var btn = byId('symBtn');
    var drop = byId('symDropdown');
    if (!btn || !drop) return;
    drop.classList.toggle('show', show);
    btn.classList.toggle('open', show);
    if (show) {
      place();
      var search = byId('symSearch');
      if (search) setTimeout(function () { try { search.focus(); } catch (e) {} }, 40);
    } else {
      drop.style.position = '';
      drop.style.top = '';
      drop.style.left = '';
      drop.style.width = '';
    }
  }

  function bind() {
    var btn = byId('symBtn');
    var drop = byId('symDropdown');
    if (!btn || !drop || btn.getAttribute('data-vsp') === '1') return;
    btn.setAttribute('data-vsp', '1');
    btn.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      open(!drop.classList.contains('show'));
    });
    drop.addEventListener('click', function (event) { event.stopPropagation(); });
    drop.addEventListener('wheel', function (event) { event.stopPropagation(); }, { passive: true });
    document.addEventListener('click', function (event) {
      if (!event.target.closest('#symSelect')) open(false);
    });
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
  else bind();
  setTimeout(bind, 120);
})();
