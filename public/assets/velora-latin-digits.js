/**
 * VELORA hard rule: every numeral shown by the product is Latin 0123456789.
 * Covers static copy, localization, live API/OCR data, dates, KPIs, attributes,
 * dynamically inserted DOM, and user/programmatic form input.
 */
(function (global, document) {
  'use strict';
  if (global.__VELORA_LATIN_DIGITS__) return;
  global.__VELORA_LATIN_DIGITS__ = true;

  var NON_LATIN_DIGIT = /[\u06F0-\u06F9\u0660-\u0669]/g;
  var HTML_NS = 'http://www.w3.org/1999/xhtml';

  function toLatin(value) {
    var s = String(value).replace(NON_LATIN_DIGIT, function (ch) {
      var code = ch.charCodeAt(0);
      if (code >= 0x06F0 && code <= 0x06F9) return String(code - 0x06F0);
      if (code >= 0x0660 && code <= 0x0669) return String(code - 0x0660);
      return ch;
    });
    // Arabic-Indic number separators (U+066B decimal / U+066C thousands) are
    // NOT digits but must render as canonical Latin punctuation: ١٣١٫٤٠ -> 131.40.
    return s.replace(/[\u066B\u066C]/g, function (ch) {
      return ch === '\u066B' ? '.' : ',';
    });
  }

  function withLatn(locales, options) {
    options = Object.assign({}, options || {}, { numberingSystem: 'latn' });
    if (!locales) return { locales: 'en-US-u-nu-latn', options: options };
    if (typeof locales === 'string') {
      return { locales: String(locales).split('-u-')[0] + '-u-nu-latn', options: options };
    }
    return { locales: locales, options: options };
  }

  var NativeNumberFormat = global.Intl && global.Intl.NumberFormat;
  var NativeDateTimeFormat = global.Intl && global.Intl.DateTimeFormat;
  var NativeRelativeTimeFormat = global.Intl && global.Intl.RelativeTimeFormat;
  if (NativeNumberFormat) {
    global.Intl.NumberFormat = function (locales, options) {
      var next = withLatn(locales, options);
      return new NativeNumberFormat(next.locales, next.options);
    };
    global.Intl.NumberFormat.prototype = NativeNumberFormat.prototype;
    if (NativeNumberFormat.supportedLocalesOf) {
      global.Intl.NumberFormat.supportedLocalesOf = NativeNumberFormat.supportedLocalesOf.bind(NativeNumberFormat);
    }
  }
  if (NativeDateTimeFormat) {
    global.Intl.DateTimeFormat = function (locales, options) {
      var next = withLatn(locales, options);
      return new NativeDateTimeFormat(next.locales, next.options);
    };
    global.Intl.DateTimeFormat.prototype = NativeDateTimeFormat.prototype;
  }
  if (NativeRelativeTimeFormat) {
    global.Intl.RelativeTimeFormat = function (locales, options) {
      var next = withLatn(locales, options);
      return new NativeRelativeTimeFormat(next.locales, next.options);
    };
    global.Intl.RelativeTimeFormat.prototype = NativeRelativeTimeFormat.prototype;
  }

  function patchLocaleMethod(obj, name) {
    if (!obj || !obj[name]) return;
    var native = obj[name];
    obj[name] = function (locales, options) {
      var next = withLatn(locales, options);
      return toLatin(native.call(this, next.locales, next.options));
    };
  }
  if (global.Number && global.Number.prototype) {
    patchLocaleMethod(global.Number.prototype, 'toLocaleString');
  }
  if (global.Date && global.Date.prototype) {
    patchLocaleMethod(global.Date.prototype, 'toLocaleString');
    patchLocaleMethod(global.Date.prototype, 'toLocaleDateString');
    patchLocaleMethod(global.Date.prototype, 'toLocaleTimeString');
  }

  /* Programmatic .value assignments do not create DOM mutations. Patch only
     text-like controls; passwords remain byte-for-byte unchanged. */
  function patchValueSetter(ctor) {
    if (!ctor || !ctor.prototype) return;
    var descriptor = Object.getOwnPropertyDescriptor(ctor.prototype, 'value');
    if (!descriptor || !descriptor.get || !descriptor.set || descriptor.configurable === false) return;
    try {
      Object.defineProperty(ctor.prototype, 'value', {
        configurable: descriptor.configurable,
        enumerable: descriptor.enumerable,
        get: descriptor.get,
        set: function (value) {
          var next = this && this.type === 'password' ? value : toLatin(value);
          return descriptor.set.call(this, next);
        }
      });
    } catch (_) {}
  }
  patchValueSetter(global.HTMLInputElement);
  patchValueSetter(global.HTMLTextAreaElement);

  function lockDocumentLocale() {
    var el = document.documentElement;
    if (!el) return;
    var current = String(el.getAttribute('lang') || 'fa').toLowerCase();
    el.setAttribute('lang', current.indexOf('fa') === 0 ? 'fa-IR-u-nu-latn' : 'en');
    el.setAttribute('data-numbering', 'latn');
    el.style.webkitLocale = 'en';
    if (document.title) {
      var title = toLatin(document.title);
      if (title !== document.title) document.title = title;
    }
  }

  var DISPLAY_ATTRIBUTES = ['placeholder', 'title', 'aria-label', 'alt', 'label'];
  function normalizeAttributes(el) {
    if (!el || el.nodeType !== 1) return;
    DISPLAY_ATTRIBUTES.forEach(function (name) {
      if (!el.hasAttribute(name)) return;
      var value = el.getAttribute(name);
      var next = toLatin(value);
      if (next !== value) el.setAttribute(name, next);
    });
  }

  function normalizeControl(el) {
    if (!el) return;
    normalizeAttributes(el);
    var tag = el.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA') {
      if (el.type !== 'password' && el.value) {
        var start = typeof el.selectionStart === 'number' ? el.selectionStart : null;
        var end = typeof el.selectionEnd === 'number' ? el.selectionEnd : null;
        var next = toLatin(el.value);
        if (next !== el.value) {
          el.value = next;
          if (start !== null && typeof el.setSelectionRange === 'function') {
            try { el.setSelectionRange(start, end); } catch (_) {}
          }
        }
      }
      el.setAttribute('lang', 'en');
      el.setAttribute('data-numbering', 'latn');
      el.style.webkitLocale = 'en';
      if (tag === 'INPUT') el.setAttribute('dir', 'ltr');
    } else if (tag === 'SELECT') {
      el.setAttribute('data-numbering', 'latn');
      el.style.webkitLocale = 'en';
    }
  }

  function insideEditable(el) {
    if (!el) return false;
    if (el.isContentEditable) return true;
    return !!(el.closest && el.closest('[contenteditable]:not([contenteditable="false"])'));
  }

  function wrapDigits(textNode) {
    var text = textNode && textNode.nodeValue;
    if (!text) return;
    var latin = toLatin(text);
    if (latin !== text) {
      textNode.nodeValue = latin;
      text = latin;
    }
    var parent = textNode.parentElement;
    if (!/[0-9]/.test(text) || !parent) return;
    if (parent.classList.contains('v-latn-num')) return;
    if (/^(TITLE|CODE|PRE|OPTION|OPTGROUP)$/.test(parent.tagName)) return;
    if (parent.namespaceURI && parent.namespaceURI !== HTML_NS) return;
    if (insideEditable(parent)) return;

    var parts = text.split(/([0-9][0-9.,:+\-/%]*)/g);
    if (parts.length < 2) return;
    var frag = document.createDocumentFragment();
    parts.forEach(function (part) {
      if (!part) return;
      if (/[0-9]/.test(part)) {
        var span = document.createElement('span');
        span.className = 'v-latn-num';
        span.lang = 'en';
        span.dir = 'ltr';
        span.textContent = part;
        frag.appendChild(span);
      } else {
        frag.appendChild(document.createTextNode(part));
      }
    });
    if (textNode.parentNode) textNode.parentNode.replaceChild(frag, textNode);
  }

  var SKIP = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1 };
  function walk(root) {
    if (!root) return;
    if (root.nodeType === 3) {
      wrapDigits(root);
      return;
    }
    if (root.nodeType !== 1 || SKIP[root.tagName]) return;
    normalizeAttributes(root);
    if (root.tagName === 'INPUT' || root.tagName === 'TEXTAREA') {
      normalizeControl(root);
      return;
    }
    if (root.tagName === 'SELECT') normalizeControl(root);

    var child = root.firstChild;
    while (child) {
      var next = child.nextSibling;
      walk(child);
      child = next;
    }
  }

  var scheduled = false;
  var scheduledRoot = null;
  function schedule(root) {
    scheduledRoot = scheduledRoot || root || document.documentElement;
    if (scheduled) return;
    scheduled = true;
    (global.requestAnimationFrame || setTimeout)(function () {
      var target = scheduledRoot;
      scheduled = false;
      scheduledRoot = null;
      lockDocumentLocale();
      walk(target || document.documentElement);
    }, 16);
  }

  function normalizeEventTarget(event) {
    var target = event && event.target;
    if (!target || target.nodeType !== 1) return;
    if (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT') {
      normalizeControl(target);
    } else if (insideEditable(target)) {
      walk(target);
    }
  }

  function boot() {
    lockDocumentLocale();
    walk(document.documentElement);
    document.addEventListener('input', normalizeEventTarget, true);
    document.addEventListener('change', normalizeEventTarget, true);
    if (!global.MutationObserver) return;
    var observer = new MutationObserver(function (records) {
      lockDocumentLocale();
      records.forEach(function (record) {
        if (record.type === 'characterData') {
          wrapDigits(record.target);
        } else if (record.type === 'attributes') {
          normalizeAttributes(record.target);
          if (/^(INPUT|TEXTAREA|SELECT)$/.test(record.target.tagName || '')) normalizeControl(record.target);
        } else {
          record.addedNodes.forEach(walk);
        }
      });
    });
    observer.observe(document.documentElement, {
      childList: true,
      subtree: true,
      characterData: true,
      attributes: true,
      attributeFilter: DISPLAY_ATTRIBUTES.concat(['value'])
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  global.VeloraLatinDigits = { toLatin: toLatin, apply: schedule };
})(window, document);
