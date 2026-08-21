/* ============================================================
   VELORA — Symbol Icon Service (سرویس آیکون نمادها)
   ------------------------------------------------------------
   - رجیستری symbols.json را یکبار لود و در حافظه کش می‌کند
   - آیکون را با Skeleton نمایش می‌دهد تا لود شود
   - Fallback: دایره رنگی با حروف اختصاری
   - کاملاً لوکال — هیچ درخواست اینترنتی هنگام لود صفحه
   ============================================================ */

window.VeloraSymbols = (function () {
  'use strict';

  var registry = null;        // symbols.json (کش شده)
  var registryPromise = null; // پرامیس برای لود یکبار
  var loaded = false;

  // رنگ برند برای fallback
  var COLORS = {
    'BTC':'#F7931A','ETH':'#627EEA','USDT':'#26A17B','BNB':'#F3BA2F','SOL':'#9945FF',
    'XRP':'#23292F','ADA':'#0033AD','DOGE':'#C2A633','TRX':'#EF0027','AVAX':'#E84142',
    'DOT':'#E6007A','MATIC':'#8247E5','LINK':'#2A5ADA','LTC':'#345D9D','BCH':'#8DC351',
    'ATOM':'#2E3148','XLM':'#7D6FEA','UNI':'#FF007A','AAVE':'#B6509E','NEAR':'#00EC97',
    'ICP':'#29ABE2','XAU':'#D4AF37','XAG':'#C0C0C0','XPT':'#E5E4E2','XPD':'#A9B4C2','OIL':'#2C3E50','COPPER':'#B87333',
    'NAS100':'#0E5CAD','SPX500':'#7C3AED','US30':'#DC2626','GER40':'#DC2626',
    'EURUSD':'#005BBB','GBPUSD':'#005BBB','USDJPY':'#E60012',
  };

  /** لود رجیستری — فقط یکبار */
  function loadRegistry() {
    if (registry) return Promise.resolve(registry);
    if (registryPromise) return registryPromise;
    registryPromise = fetch('/public/assets/symbols/symbols.json?v=2026.08.22.1', { cache: 'no-store' })
      .catch(function () { return fetch('/assets/symbols/symbols.json?v=2026.08.22.1', { cache: 'no-store' }); })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        registry = data || {};
        loaded = true;
        return registry;
      })
      .catch(function () {
        registry = {};
        loaded = true;
        return registry;
      });
    return registryPromise;
  }

  var CCY_FLAG = {
    EUR: 'eu', USD: 'us', GBP: 'gb', JPY: 'jp', CHF: 'ch', AUD: 'au', CAD: 'ca', NZD: 'nz',
    CNH: 'cn', CNY: 'cn', HKD: 'hk', SGD: 'sg', TRY: 'tr', ZAR: 'za', MXN: 'mx',
    NOK: 'no', SEK: 'se', PLN: 'pl', HUF: 'hu', CZK: 'cz', ILS: 'il'
  };
  var CCY_COUNTRY_FA = {
    EUR: 'اروپا', USD: 'آمریکا', GBP: 'بریتانیا', JPY: 'ژاپن', CHF: 'سوئیس',
    AUD: 'استرالیا', CAD: 'کانادا', NZD: 'نیوزیلند', CNH: 'چین', CNY: 'چین',
    HKD: 'هنگ‌کنگ', SGD: 'سنگاپور', TRY: 'ترکیه', ZAR: 'آفریقای جنوبی',
    MXN: 'مکزیک', NOK: 'نروژ', SEK: 'سوئد', PLN: 'لهستان', HUF: 'مجارستان',
    CZK: 'چک', ILS: 'اسرائیل'
  };
  var METAL_ICON = {
    XAU: '/public/assets/symbols/metal/XAU.png',
    XAG: '/public/assets/symbols/metal/XAG.png',
    XPT: '/public/assets/symbols/metal/XPT.png',
    XPD: '/public/assets/symbols/metal/XPD.png'
  };
  var METAL_NAME_FA = {
    XAU: 'طلا', XAG: 'نقره', XPT: 'پلاتین', XPD: 'پالادیوم',
    XAUUSD: 'طلا / آمریکا', XAGUSD: 'نقره / آمریکا',
    XPTUSD: 'پلاتین / آمریکا', XPDUSD: 'پالادیوم / آمریکا'
  };
  var METAL_NAME_EN = {
    XAU: 'Gold', XAG: 'Silver', XPT: 'Platinum', XPD: 'Palladium',
    XAUUSD: 'Gold / United States', XAGUSD: 'Silver / United States',
    XPTUSD: 'Platinum / United States', XPDUSD: 'Palladium / United States'
  };
  var CCY_COUNTRY_EN = {
    EUR: 'Eurozone', USD: 'United States', GBP: 'United Kingdom', JPY: 'Japan', CHF: 'Switzerland',
    AUD: 'Australia', CAD: 'Canada', NZD: 'New Zealand', CNH: 'China', CNY: 'China',
    HKD: 'Hong Kong', SGD: 'Singapore', TRY: 'Turkey', ZAR: 'South Africa',
    MXN: 'Mexico', NOK: 'Norway', SEK: 'Sweden', PLN: 'Poland', HUF: 'Hungary',
    CZK: 'Czechia', ILS: 'Israel'
  };

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
    });
  }
  function safePathPart(value) {
    return encodeURIComponent(String(value == null ? '' : value));
  }
  function base(symbol) {
    return String(symbol || '').split('/')[0].trim().toUpperCase();
  }
  function full(symbol) {
    return String(symbol || '').replace(/\//g, '').trim().toUpperCase();
  }
  function pairParts(symbol) {
    var raw = full(symbol);
    if (raw.length >= 6) return [raw.slice(0, 3), raw.slice(3, 6)];
    return null;
  }
  function metalKey(symbol) {
    var b = base(symbol), f = full(symbol);
    if (METAL_ICON[b]) return b;
    var parts = pairParts(symbol);
    if (parts && METAL_ICON[parts[0]]) return parts[0];
    if (METAL_ICON[f]) return f;
    return '';
  }
  function halfJoinHtml(leftFlag, rightFlag, size, symbol) {
    var s = Number.isFinite(Number(size)) ? Math.max(8, Math.min(256, Number(size))) : 36;
    var safeSymbol = String(symbol || '');
    return '<span class="velora-sym velora-pair" data-symbol="' + escapeHtml(safeSymbol) + '" style="' +
      'display:inline-flex;align-items:center;justify-content:center;width:' + s + 'px;height:' + s + 'px;' +
      'border-radius:50%;overflow:hidden;flex-shrink:0;position:relative;background:#0b1220;' +
      'box-shadow:0 2px 8px rgba(0,0,0,.35), inset 0 0 0 1px rgba(252,227,138,.22);">' +
      '<img alt="" src="/public/assets/symbols/forex/' + safePathPart(safeSymbol) + '.png" style="width:100%;height:100%;object-fit:cover;display:block;" ' +
      'onerror="this.style.display=\'none\'">' +
      '</span>';
  }

  /** آیا آیکونی برای این نماد هست؟ */
  function hasIcon(symbol) {
    var b = base(symbol), f = full(symbol);
    var parts = pairParts(symbol);
    if (parts && CCY_FLAG[parts[0]] && CCY_FLAG[parts[1]]) return true;
    return !!(registry && (registry[f] || registry[b]));
  }

  /** نام رسمی */
  function isFa() {
    var lang = (document.documentElement.getAttribute('lang') || 'fa').toLowerCase();
    return lang.indexOf('fa') === 0;
  }
  function displayCode(symbol) {
    var mk = metalKey(symbol);
    var parts = pairParts(symbol);
    if (mk && parts && parts[1]) return mk + parts[1];
    if (mk) return mk;
    if (parts && CCY_FLAG[parts[0]] && CCY_FLAG[parts[1]]) return parts[0] + parts[1];
    var f = full(symbol);
    return f || base(symbol);
  }
  function countryNameOf(symbol) {
    var parts = pairParts(symbol);
    var map = isFa() ? CCY_COUNTRY_FA : CCY_COUNTRY_EN;
    if (parts && map[parts[0]] && map[parts[1]]) return map[parts[0]] + ' / ' + map[parts[1]];
    return '';
  }
  function nameOf(symbol) {
    var names = isFa() ? METAL_NAME_FA : METAL_NAME_EN;
    var mk = metalKey(symbol);
    var f = full(symbol);
    if (names[f]) return names[f];
    if (mk && names[mk]) return names[mk];
    var countries = countryNameOf(symbol);
    if (countries) return countries;
    var b = base(symbol);
    if (registry && registry[b]) return registry[b].name;
    if (registry && registry[f]) return registry[f].name;
    return '';
  }

  /**
   * HTML آیکون با Skeleton + Fallback
   * @param {string} symbol  BTC / EURUSD / BTC/USDT
   * @param {number} size    پیکسل
   * @param {string} [cls]   کلاس اضافه
   */
  function icon(symbol, size, cls) {
    var s = Number.isFinite(Number(size)) ? Math.max(8, Math.min(256, Number(size))) : 36;
    var b = base(symbol);
    var f = full(symbol);
    var c = COLORS[b] || COLORS[f] || '#D4AF37';
    var mk = metalKey(symbol);
    var metalSrc = mk ? METAL_ICON[mk] : '';
    var entry = registry ? (registry[f] || registry[b]) : null;

    var baseStyle = 'display:inline-flex;align-items:center;justify-content:center;' +
      'width:' + s + 'px;height:' + s + 'px;border-radius:50%;flex-shrink:0;overflow:hidden;';

    if (metalSrc) {
      return '<span class="velora-sym" data-symbol="' + escapeHtml(mk || b) + '" style="' + baseStyle +
        'background:#070b14;box-shadow:0 2px 8px rgba(0,0,0,.35), inset 0 0 0 1px rgba(255,255,255,.08);">' +
        '<img src="' + escapeHtml(metalSrc) + '" alt="' + escapeHtml(mk || b) + '" style="' +
        'width:100%;height:100%;object-fit:cover;display:block;border-radius:50%;">' +
        '</span>';
    }

    // آیکون موجود → <img> با skeleton در ابتدا
    if (entry && entry.icon) {
      var iconPath = String(entry.icon).replace(/^assets\//, 'public/assets/');
      if (!/^public\/assets\/[A-Za-z0-9._\/-]+$/.test(iconPath)) return fallback(b, s, c);
      return '<span class="velora-sym" data-symbol="' + escapeHtml(b) + '" style="' + baseStyle +
        'background:linear-gradient(145deg,#141E33,#0C1424);box-shadow:0 2px 8px rgba(0,0,0,.35), inset 0 0 0 1px rgba(255,255,255,.07);">' +
        '<img src="/' + escapeHtml(iconPath) + '" alt="' + escapeHtml(b) + '" loading="lazy" style="' +
        'width:100%;height:100%;object-fit:cover;display:block;border-radius:50%;" ' +
        'onload="this.parentElement.classList.remove(\'velora-sym-loading\')" ' +
        'onerror="this.style.display=\'none\'">' +
        '</span>';
    }

    var parts = pairParts(symbol);
    if (parts && CCY_FLAG[parts[0]] && CCY_FLAG[parts[1]]) {
      return halfJoinHtml(CCY_FLAG[parts[0]], CCY_FLAG[parts[1]], s, f);
    }

    // fallback → دایره رنگی با حروف
    return fallback(b, s, c, cls);
  }

  /** HTML Fallback */
  function fallback(symbol, size, color, cls) {
    var s = Number.isFinite(Number(size)) ? Math.max(8, Math.min(256, Number(size))) : 36;
    var c = /^#[0-9A-Fa-f]{6}$/.test(String(color || '')) ? String(color) : (COLORS[symbol] || '#D4AF37');
    var letter = escapeHtml(String(symbol).slice(0, 2));
    return '<span class="velora-sym" style="' +
      'display:inline-flex;align-items:center;justify-content:center;' +
      'width:' + s + 'px;height:' + s + 'px;border-radius:50%;flex-shrink:0;' +
      'background:linear-gradient(145deg,' + c + '26,' + c + '12);' +
      'border:1.5px solid ' + c + '55;' +
      'color:' + c + ';font-weight:800;font-size:' + Math.round(s * 0.34) + 'px;' +
      'font-family:Arial,sans-serif;">' + letter + '</span>';
  }

  /** آیتم کامل لیست (آیکون + نماد + نام) */
  function optionItem(symbol, size) {
    var s = size || 38;
    return icon(symbol, s) +
      '<span style="display:flex;flex-direction:column;gap:1px;min-width:0;">' +
      '<span style="font-weight:800;color:var(--txt,#EAF0FA);font-size:13.5px;letter-spacing:.04em;">' + escapeHtml(displayCode(symbol)) + '</span>' +
      (nameOf(symbol) ? '<span style="color:var(--faint,#5E6F92);font-size:10.5px;">' + escapeHtml(nameOf(symbol)) + '</span>' : '') +
      '</span>';
  }

  // برای onerror
  window.VeloraSymbolsFallback = function (sym, size) {
    return fallback(sym, size || 36);
  };

  return {
    load: loadRegistry,
    icon: icon,
    optionItem: optionItem,
    fallback: fallback,
    base: base,
    full: full,
    nameOf: nameOf,
    displayCode: displayCode,
    countryNameOf: countryNameOf,
    hasIcon: hasIcon,
    isLoaded: function () { return loaded; },
    _fallback: window.VeloraSymbolsFallback,
  };
})();


