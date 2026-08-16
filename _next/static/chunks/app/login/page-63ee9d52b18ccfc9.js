/* VELORA — صفحه لاگین داخلی قدیمی حذف شد.
   این فایل فقط به صفحه سفارشی /login ریدایرکت می‌کند. */
(function () {
  'use strict';
  try { window.location.replace('/login'); } catch (e) {}
  if (typeof document !== 'undefined') {
    document.documentElement.innerHTML = '';
  }
})();
