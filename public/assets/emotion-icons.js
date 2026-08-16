/* ============================================================
   VELORA — Emotion Icons (طرح C — گلس/گرادیان)
   ------------------------------------------------------------
   - گرادیان عمیق (TradingView/Linear) + برق شیشه‌ای + صورت سفید
   - 5 سطح: قرمز → نارنجی → کهربایی → سبز → زمردی
   - ID یکتا برای هر نمونه → امن برای رندر صدها ردیف
   ============================================================ */
window.VeloraEmotions = (function () {
  'use strict';

  var __uid = 0;

  // [گرادیان روشن, گرادیان تیره]
  var GRADS = [
    ['#FF6B6B', '#B91C1C'], // 1 خیلی بد
    ['#FFA24D', '#C2410C'], // 2 بد
    ['#F5C84C', '#B45309'], // 3 خنثی
    ['#7BD88A', '#4D7C0F'], // 4 خوب
    ['#4CD3A6', '#047857'], // 5 عالی
  ];

  var LABELS = ['', 'خیلی بد', 'بد', 'خنثی', 'خوب', 'عالی'];

  function clamp(level) {
    var n = Number(level) || 3;
    return Math.max(1, Math.min(5, n));
  }

  /** SVG چهره گلس برای سطح 1-5 */
  function svg(level, size) {
    var lv = clamp(level);
    var s = size || 34;
    var id = 'veg' + (__uid++);
    var g = GRADS[lv - 1][0], d = GRADS[lv - 1][1];
    var f = '';

    if (lv === 1) {
      // خیلی بد — ابروی شیب‌دار + اشک
      f = '<path d="M21 26l5 2.6M43 26l5-2.6" stroke="#fff" stroke-width="2.2" fill="none" stroke-linecap="round"/>'
        + '<circle cx="25" cy="34" r="3" fill="#fff"/><circle cx="39" cy="34" r="3" fill="#fff"/>'
        + '<path d="M25 45q7-3.5 14 0" stroke="#fff" stroke-width="2.6" fill="none" stroke-linecap="round"/>'
        + '<path d="M22 36c1.8 2.8 1.8 4.4 0 5.6-1.8-1.2-1.8-2.8 0-5.6z" fill="#BFDBFE"/>';
    } else if (lv === 2) {
      // بد — اخم
      f = '<circle cx="25" cy="34" r="3" fill="#fff"/><circle cx="39" cy="34" r="3" fill="#fff"/>'
        + '<path d="M25 45q7-3 14 0" stroke="#fff" stroke-width="2.6" fill="none" stroke-linecap="round"/>';
    } else if (lv === 3) {
      // خنثی
      f = '<circle cx="25" cy="34" r="2.8" fill="#fff"/><circle cx="39" cy="34" r="2.8" fill="#fff"/>'
        + '<path d="M27 43h10" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>';
    } else if (lv === 4) {
      // خوب — لبخند
      f = '<circle cx="25" cy="33" r="3" fill="#fff"/><circle cx="39" cy="33" r="3" fill="#fff"/>'
        + '<path d="M25 42q7 5 14 0" stroke="#fff" stroke-width="2.6" fill="none" stroke-linecap="round"/>';
    } else {
      // عالی — چشم خوشحال + لبخند بزرگ + لپ
      f = '<path d="M20 29q4-4 8 0M36 29q4-4 8 0" stroke="#fff" stroke-width="2.6" fill="none" stroke-linecap="round"/>'
        + '<path d="M23 41q9 9 18 0" stroke="#fff" stroke-width="3" fill="none" stroke-linecap="round"/>'
        + '<circle cx="16" cy="37" r="3.4" fill="rgba(255,255,255,.35)"/><circle cx="48" cy="37" r="3.4" fill="rgba(255,255,255,.35)"/>';
    }

    return '<svg width="' + s + '" height="' + s + '" viewBox="0 0 64 64" style="display:inline-block;vertical-align:middle;flex-shrink:0">'
      + '<defs><linearGradient id="' + id + '" x1="0" y1="0" x2="1" y2="1">'
      + '<stop offset="0" stop-color="' + g + '"/><stop offset="1" stop-color="' + d + '"/>'
      + '</linearGradient></defs>'
      + '<circle cx="32" cy="32" r="30" fill="url(#' + id + ')"/>'
      + '<ellipse cx="24" cy="20" rx="14" ry="10" fill="rgba(255,255,255,.30)"/>'
      + '<circle cx="32" cy="32" r="30" fill="none" stroke="rgba(255,255,255,.35)" stroke-width="1.5"/>'
      + f
      + '</svg>';
  }

  function label(level) {
    return LABELS[clamp(level)] || '';
  }

  function paintSlots(root) {
    var scope = root || document;
    var slots = scope.querySelectorAll('.emot-ico');
    slots.forEach(function (slot) {
      var host = slot.closest('[data-e]');
      if (!host) return;
      slot.innerHTML = svg(host.getAttribute('data-e'), 30);
    });
  }

  function upgradeSelectors(root) {
    paintSlots(root);
  }

  function boot() {
    try { paintSlots(document); } catch (e) {}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  setTimeout(boot, 80);
  setTimeout(boot, 400);

  return {
    svg: svg,
    label: label,
    colors: GRADS,
    upgradeSelectors: upgradeSelectors,
    paintSlots: paintSlots,
  };
})();
