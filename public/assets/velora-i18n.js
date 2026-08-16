/**
 * VELORA — Auto-Translation System
 * - Detects Persian text automatically
 * - Translates known text via map
 * - Auto-translates unknown text via Google Translate (free, no API key)
 * - Caches translations in localStorage for performance
 */
(function() {
  'use strict';
  var langs = navigator.languages || [navigator.language || ''];
  var isPersian = langs.some(function(l){ return String(l).toLowerCase().indexOf('fa')===0; });
  if (isPersian) return;
  document.documentElement.lang='en';
  document.documentElement.dir='ltr';

  // ============================================
  // MANUAL TRANSLATIONS (fast, no API call)
  // ============================================
  var T = {
    'داشبورد':'Dashboard','بازارها':'Markets','ژورنال معاملات':'Trade Journal',
    'کیف پول':'Wallet','عملکرد':'Performance','اخبار':'News','پروفایل':'Profile',
    'پشتیبانی':'Support','مدیریت':'Admin','تحلیل‌ها':'Analytics','تنظیمات':'Settings',
    'پراپ فرم':'Prop Firm','محصول':'Product','وبلاگ':'Blog','سوالات':'FAQ',
    'ثبت معامله':'New Trade','بروزرسانی':'Refresh','خروج':'Logout','ورود':'Login',
    'ثبت‌نام':'Sign Up','شروع رایگان':'Start Free','ذخیره':'Save','لغو':'Cancel',
    'تأیید':'Confirm','حذف':'Delete','ویرایش':'Edit','بازگشت':'Back','ادامه':'Continue',
    'بستن':'Close','انصراف':'Cancel','مشاهده همه':'View All','جزئیات':'Details',
    'همگام‌سازی':'Sync','بله، خارج شو':'Yes, Logout',
    'معاملات':'Trades','نرخ برد':'Win Rate','سود خالص':'Net P&L','فاکتور سود':'Profit Factor',
    'منحنی سرمایه':'Equity Curve','معاملات اخیر':'Recent Trades',
    'حساب‌های بروکر':'Broker Accounts','در حال بارگذاری...':'Loading...',
    'نماد':'Symbol','جهت':'Direction','خرید':'Buy','فروش':'Sell',
    'قیمت ورود':'Entry Price','قیمت خروج':'Exit Price','حجم':'Volume',
    'حد ضرر':'Stop Loss','حد سود':'Take Profit','استراتژی':'Strategy',
    'ایمیل':'Email','رمز عبور':'Password','فراموشی رمز؟':'Forgot Password?',
    'حساب ندارید؟':'Don\'t have an account?','خوش برگشتید':'Welcome Back',
    'نام و نام خانوادگی':'Full Name','خروج از حساب':'Logout',
    'رایگان':'Free','حرفه‌ای':'Professional','سازمانی':'Enterprise',
    'متصل':'Connected','فعال':'Active','آنلاین':'Online','آفلاین':'Offline',
    'حریم خصوصی':'Privacy','شرایط استفاده':'Terms','صفحه اصلی':'Home',
    'کاربر':'User','تاریخ':'Date','یادداشت':'Notes',
    'صبر ✓':'Patience ✓','انضباط ✓':'Discipline ✓','انتقام ✗':'Revenge ✗',
    'اورترید ⚠':'Overtrade ⚠','ریسک پایین ✓':'Low Risk ✓',
    'ویژگی‌ها':'Features','امنیت بانکی':'Bank-Grade Security',
    'قیمت‌گذاری':'Pricing','مقایسه':'Comparison',
  };

  // ============================================
  // CACHE (localStorage)
  // ============================================
  var CACHE_KEY = 'velora_i18n_cache';
  var cache = {};
  try { cache = JSON.parse(localStorage.getItem(CACHE_KEY) || '{}'); } catch(e) {}

  function saveCache() {
    try { localStorage.setItem(CACHE_KEY, JSON.stringify(cache)); } catch(e) {}
  }

  // ============================================
  // AUTO-TRANSLATE via Google Translate (free)
  // ============================================
  var queue = [];
  var processing = false;

  function translateViaAPI(text, callback) {
    // Check cache first
    if (cache[text]) { callback(cache[text]); return; }
    // Check manual translations
    if (T[text]) { callback(T[text]); return; }
    
    queue.push({text: text, callback: callback});
    processQueue();
  }

  function processQueue() {
    if (processing || queue.length === 0) return;
    processing = true;
    
    var item = queue.shift();
    var url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=fa&tl=en&dt=t&q=' + 
              encodeURIComponent(item.text);
    
    fetch(url)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        var translated = data && data[0] && data[0][0] && data[0][0][0];
        if (translated && translated !== item.text) {
          cache[item.text] = translated;
          saveCache();
          item.callback(translated);
        }
      })
      .catch(function() {})
      .finally(function() {
        processing = false;
        // Process next after delay (rate limiting)
        setTimeout(processQueue, 200);
      });
  }

  // ============================================
  // TRANSLATION ENGINE
  // ============================================
  function translateNode(node) {
    if (node.nodeType === 3) {
      var text = node.textContent.trim();
      if (!text || text.length < 2) return;
      if (!/[\u0600-\u06FF]/.test(text)) return;
      
      // Try manual translation first
      if (T[text]) {
        node.textContent = node.textContent.replace(text, T[text]);
        return;
      }
      
      // Try cache
      if (cache[text]) {
        node.textContent = node.textContent.replace(text, cache[text]);
        return;
      }
      
      // Auto-translate via API
      var el = node;
      var original = text;
      translateViaAPI(text, function(translated) {
        if (el.parentNode && el.textContent.trim() === original) {
          el.textContent = el.textContent.replace(original, translated);
        }
      });
      
    } else if (node.nodeType === 1) {
      // Translate attributes
      ['title','placeholder','aria-label','alt'].forEach(function(attr){
        var v = node.getAttribute(attr);
        if (v && /[\u0600-\u06FF]/.test(v) && v.length > 1) {
          if (T[v]) { node.setAttribute(attr, T[v]); return; }
          if (cache[v]) { node.setAttribute(attr, cache[v]); return; }
          translateViaAPI(v, function(translated) {
            if (node.getAttribute(attr) === v) node.setAttribute(attr, translated);
          });
        }
      });
      
      // Translate children
      for (var i = 0; i < node.childNodes.length; i++) translateNode(node.childNodes[i]);
    }
  }

  function run() {
    if (!document.body) return;
    translateNode(document.body);
  }

  // Run multiple times for dynamic content
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
  setTimeout(run, 500);
  setTimeout(run, 1500);
  setTimeout(run, 3000);
  setTimeout(run, 5000);

  // ============================================
  // DEVELOPER API
  // ============================================
  window.VeloraI18n = {
    T: T,
    cache: cache,
    addTranslation: function(fa, en) { T[fa] = en; },
    clearCache: function() { cache = {}; saveCache(); },
    getCache: function() { return cache; }
  };
})();
