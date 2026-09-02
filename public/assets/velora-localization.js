/*
 * VELORA presentation-only localization.
 * Server/build-localized HTML owns first paint. Browser catalogs are feature-scoped,
 * cacheable assets; live/data APIs remain locale-neutral and never pass through translation.
 */
(function (global, document) {
  'use strict';

  var registry = global.__VELORA_LOCALE_REGISTRY__;
  if (!registry || !registry.locales || typeof registry.locales !== 'object') {
    throw new Error('VELORA locale registry must load before velora-localization.js');
  }
  var localeIndex = Object.create(null);
  Object.keys(registry.locales).forEach(function (code) {
    var entry = registry.locales[code];
    if (entry && entry.enabled !== false) localeIndex[String(code).toLowerCase()] = entry;
  });
  var catalogs = Object.create(null);
  var pending = Object.create(null);
  var formatterCache = new Map();
  var current = global.__VELORA_LOCALE__ || registry.defaultLocale;
  var observer = null;
  var queuedRoots = new Set();
  var applyScheduled = false;
  /* R3: tracks the in-flight or last server-side preference persistence so that
     locale-dependent navigation can await it instead of racing the PATCH.
     Null means "no write in flight and none queued"; a resolved promise means
     the last write settled (success or failure — failure is non-fatal because
     the cookie/localStorage choice still drives server resolution). */
  var pendingPersist = null;

  function persistPreference(locale) {
    if (pendingPersist) return pendingPersist;
    try {
      var veloraData = global.VeloraData;
      var veloraAccessToken = veloraData && typeof veloraData.getAccessToken === 'function'
        ? veloraData.getAccessToken() : null;
      if (!veloraAccessToken || typeof global.fetch !== 'function') {
        pendingPersist = Promise.resolve({ persisted: false, reason: 'no-session' });
        return pendingPersist;
      }
      var controller = (typeof global.AbortController === 'function') ? new global.AbortController() : null;
      var timeoutId = controller ? global.setTimeout(function () {
        try { controller.abort(); } catch (_) {}
      }, 4000) : null;
      pendingPersist = global.fetch('/api/v1/auth/me/preferences', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + veloraAccessToken },
        body: JSON.stringify({ locale: locale }),
        signal: controller ? controller.signal : undefined,
        credentials: 'same-origin'
      }).then(function (response) {
        if (timeoutId) global.clearTimeout(timeoutId);
        if (!response.ok) {
          var err = new Error('Locale preference PATCH HTTP ' + response.status);
          document.dispatchEvent(new CustomEvent('velora:locale-persist-failed', {
            detail: { locale: locale, status: response.status, error: err }
          }));
          return { persisted: false, reason: 'http-' + response.status };
        }
        return { persisted: true, locale: locale };
      }).catch(function (error) {
        if (timeoutId) global.clearTimeout(timeoutId);
        /* Do not silently swallow: announce so callers/UI can react, but never
           break the client-side locale switch — cookie/localStorage already hold
           the choice and will drive the next server render. */
        document.dispatchEvent(new CustomEvent('velora:locale-persist-failed', {
          detail: { locale: locale, error: error }
        }));
        return { persisted: false, reason: 'network' };
      });
      return pendingPersist;
    } catch (error) {
      pendingPersist = Promise.resolve({ persisted: false, reason: 'unavailable' });
      return pendingPersist;
    }
  }

  function enabled(code) {
    return !!localeIndex[code];
  }

  function normalize(candidate) {
    var value = String(candidate || '').replace('_', '-').toLowerCase();
    if (enabled(value)) return value;
    var base = value.split('-')[0];
    return enabled(base) ? base : null;
  }

  function meta(code) {
    return localeIndex[normalize(code) || registry.fallbackLocale] || { intlLocale: code, direction: 'ltr' };
  }

  function explicitPathLocale() {
    var path = global.location && typeof global.location.pathname === 'string' ? global.location.pathname : '';
    var first = path.replace(/^\/+/, '').split('/')[0].toLowerCase().replace('_', '-');
    return enabled(first) ? first : null;
  }

  function pageFeatures() {
    var raw = document.documentElement.getAttribute('data-i18n-features') || 'common,errors';
    var seen = Object.create(null);
    return raw.split(',').map(function (item) { return item.trim(); }).filter(function (item) {
      if (!item || seen[item]) return false;
      seen[item] = true;
      return true;
    });
  }

  function latinDigits(value) {
    return String(value).replace(/[\u06F0-\u06F9\u0660-\u0669]/g, function (ch) {
      var code = ch.charCodeAt(0);
      if (code >= 0x06F0 && code <= 0x06F9) return String(code - 0x06F0);
      if (code >= 0x0660 && code <= 0x0669) return String(code - 0x0660);
      return ch;
    });
  }

  function interpolate(message, params) {
    if (!params) return latinDigits(message);
    return latinDigits(String(message).replace(/\{([A-Za-z0-9_.-]+)\}/g, function (_, key) {
      return Object.prototype.hasOwnProperty.call(params, key) ? String(params[key]) : '{' + key + '}';
    }));
  }

  function lookup(key, locale) {
    var catalog = catalogs[locale];
    return catalog && Object.prototype.hasOwnProperty.call(catalog, key) ? catalog[key] : undefined;
  }

  function t(key, params, defaultValue) {
    var message = lookup(key, current);
    if (message === undefined) message = lookup(key, registry.fallbackLocale);
    if (message === undefined) message = defaultValue === undefined ? key : defaultValue;
    return interpolate(message, params);
  }

  function formatOptions(el) {
    var source = el.getAttribute('data-format-options');
    if (!source) return {};
    try { return JSON.parse(source); } catch (_) { return {}; }
  }

  function formatElement(el) {
    var kind = el.getAttribute('data-format');
    if (!kind) return;
    var value = el.getAttribute('data-value');
    var options = formatOptions(el);
    if (kind === 'number') el.textContent = latinDigits(number(value, options));
    else if (kind === 'currency') el.textContent = latinDigits(currency(value, el.getAttribute('data-currency') || 'USD', options));
    else if (kind === 'percent') el.textContent = latinDigits(percent(value, options));
    else if (kind === 'date') el.textContent = latinDigits(date(value, options));
    else if (kind === 'datetime') el.textContent = latinDigits(dateTime(value, options));
    else if (kind === 'relative') el.textContent = latinDigits(relative(value));
    else if (kind === 'time') el.textContent = latinDigits(time(value, options));
  }

  function applyElement(el) {
    var key = el.getAttribute('data-i18n');
    if (key) el.textContent = t(key, null, el.textContent);
    ['title', 'placeholder', 'aria-label', 'alt', 'value', 'content'].forEach(function (attr) {
      var attrKey = el.getAttribute('data-i18n-' + attr);
      if (attrKey) el.setAttribute(attr, t(attrKey, null, el.getAttribute(attr) || ''));
    });
    formatElement(el);
  }

  function apply(root) {
    root = root || document;
    if (root.nodeType === 1 && (root.hasAttribute('data-i18n') || root.hasAttribute('data-format') || Array.prototype.some.call(root.attributes || [], function (a) { return a.name.indexOf('data-i18n-') === 0; }))) applyElement(root);
    if (!root.querySelectorAll) return;
    root.querySelectorAll('[data-i18n],[data-i18n-title],[data-i18n-placeholder],[data-i18n-aria-label],[data-i18n-alt],[data-i18n-value],[data-i18n-content],[data-format]').forEach(applyElement);
  }

  function flushApply() {
    applyScheduled = false;
    queuedRoots.forEach(apply);
    queuedRoots.clear();
  }

  function queueApply(root) {
    if (!root || root.nodeType !== 1) return;
    queuedRoots.add(root);
    if (!applyScheduled) {
      applyScheduled = true;
      (global.queueMicrotask || function (fn) { Promise.resolve().then(fn); })(flushApply);
    }
  }

  function observe() {
    if (observer || !global.MutationObserver || !document.documentElement) return;
    observer = new MutationObserver(function (records) {
      records.forEach(function (record) {
        record.addedNodes.forEach(queueApply);
      });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  function featureUrl(locale, feature) {
    var base = String(registry.featureCatalogBase || '/public/locales/chunks').replace(/\/$/, '');
    return base + '/' + encodeURIComponent(locale) + '/' + encodeURIComponent(feature) + '.json?v=' + encodeURIComponent(registry.version);
  }

  function loadFeature(locale, feature) {
    locale = normalize(locale);
    feature = String(feature || '').trim();
    if (!locale || !feature) return Promise.reject(new Error('Unsupported locale feature'));
    catalogs[locale] = catalogs[locale] || Object.create(null);
    var stateKey = locale + '|' + feature;
    if (catalogs[locale].__features && catalogs[locale].__features[feature]) return Promise.resolve(catalogs[locale]);
    if (pending[stateKey]) return pending[stateKey];
    pending[stateKey] = fetch(featureUrl(locale, feature), { credentials: 'same-origin', cache: 'force-cache' })
      .then(function (response) {
        if (!response.ok) throw new Error('Locale feature HTTP ' + response.status);
        return response.json();
      })
      .then(function (payload) {
        var messages = payload && payload.messages;
        if (!messages || typeof messages !== 'object') throw new Error('Invalid locale feature catalog');
        Object.keys(messages).forEach(function (key) { catalogs[locale][key] = messages[key]; });
        if (!catalogs[locale].__features) {
          Object.defineProperty(catalogs[locale], '__features', { value: Object.create(null), enumerable: false });
        }
        catalogs[locale].__features[feature] = true;
        delete pending[stateKey];
        return catalogs[locale];
      })
      .catch(function (error) {
        delete pending[stateKey];
        document.dispatchEvent(new CustomEvent('velora:locale-error', {
          detail: { locale: locale, feature: feature, error: error }
        }));
        throw error;
      });
    return pending[stateKey];
  }

  function load(locale, features) {
    locale = normalize(locale);
    if (!locale) return Promise.reject(new Error('Unsupported locale'));
    var requested = Array.isArray(features) && features.length ? features : pageFeatures();
    return Promise.all(requested.map(function (feature) { return loadFeature(locale, feature); }))
      .then(function () { return catalogs[locale]; });
  }

  function updateDocument(locale) {
    var info = meta(locale);
    var lang = info.intlLocale || locale;
    if (/^fa/i.test(lang)) lang = 'fa-IR-u-nu-latn';
    document.documentElement.lang = lang;
    document.documentElement.dir = info.direction || 'ltr';
    document.documentElement.setAttribute('data-locale', locale);
    document.documentElement.setAttribute('data-direction', info.direction || 'ltr');
    document.documentElement.setAttribute('data-numbering', 'latn');
    if (document.body) document.body.dir = info.direction || 'ltr';
  }

  function documentReady() {
    if (document.readyState !== 'loading') return Promise.resolve();
    return new Promise(function (resolve) {
      document.addEventListener('DOMContentLoaded', resolve, { once: true });
    });
  }

  function setLocale(locale, options) {
    options = options || {};
    locale = normalize(locale);
    if (!locale) return Promise.reject(new Error('Unsupported locale'));
    current = locale;
    global.__VELORA_LOCALE__ = locale;
    formatterCache.clear();
    updateDocument(locale);
    if (options.persist !== false) {
      try {
        global.localStorage.setItem(registry.storageKey, locale);
        document.cookie = encodeURIComponent(registry.cookieKey || 'velora_locale') + '=' + encodeURIComponent(locale)
          + '; Path=/; Max-Age=31536000; SameSite=Lax';
      } catch (_) {}
      /* PR-04/R3: persist the manual choice server-side when signed in so the
         saved preference follows the account across devices. The PATCH is now
         tracked by pendingPersist and can be awaited via whenPersisted() before
         locale-dependent navigation. It never blocks the UI switch itself — the
         cookie/localStorage choice is written synchronously above. */
      pendingPersist = null;
      persistPreference(locale);
      var routeLocale = explicitPathLocale();
      if (routeLocale && routeLocale !== locale && global.location && typeof global.location.assign === 'function') {
        var links = document.querySelectorAll('link[rel~="alternate"][hreflang]');
        for (var linkIndex = 0; linkIndex < links.length; linkIndex += 1) {
          if (links[linkIndex].getAttribute('hreflang') === locale) {
            var target = String(links[linkIndex].getAttribute('href') || '').replace(/^https?:\/\/[^/]+/i, '');
            if (target) {
              global.location.assign(target);
              return Promise.resolve(locale);
            }
          }
        }
      }
    }
    return load(locale).then(documentReady).then(function () {
      apply(document);
      syncSwitcher();
      document.documentElement.setAttribute('data-velora-prelocalized', locale);
      document.documentElement.classList.remove('velora-locale-booting');
      document.dispatchEvent(new CustomEvent('velora:locale-change', { detail: { locale: locale, direction: meta(locale).direction } }));
      return locale;
    });
  }

  function cacheKey(kind, locale, options) {
    var ordered = Object.keys(options || {}).sort().map(function (key) { return key + ':' + String(options[key]); }).join('|');
    return kind + '|' + locale + '|' + ordered;
  }

  function formatter(kind, options) {
    var info = meta(current);
    var locale = info.intlLocale || current;
    /* Product rule: digits stay Latin (0123456789) on every page and locale. */
    options = Object.assign({}, options || {}, { numberingSystem: 'latn' });
    var key = cacheKey(kind, locale, options);
    if (!formatterCache.has(key)) formatterCache.set(key, new Intl[kind](locale, options));
    return formatterCache.get(key);
  }

  function finite(value) {
    if (value === null || value === '' || typeof value === 'boolean') return null;
    var number = typeof value === 'number' ? value : Number(value);
    return Number.isFinite(number) ? number : null;
  }

  function number(value, options) {
    var parsed = finite(value);
    return parsed === null ? '—' : latinDigits(formatter('NumberFormat', Object.assign({}, options || {}, { numberingSystem: 'latn' })).format(parsed));
  }

  function currency(value, currencyCode, options) {
    var parsed = finite(value);
    if (parsed === null) return '—';
    return latinDigits(formatter('NumberFormat', Object.assign({ style: 'currency', currency: String(currencyCode || 'USD').toUpperCase(), currencyDisplay: 'narrowSymbol', numberingSystem: 'latn' }, options || {})).format(parsed));
  }

  function percent(value, options) {
    var parsed = finite(value);
    return parsed === null ? '—' : formatter('NumberFormat', Object.assign({ style: 'percent', maximumFractionDigits: 1 }, options || {})).format(parsed);
  }

  function dateValue(value) {
    if (value instanceof Date) return Number.isNaN(value.getTime()) ? null : value;
    if (typeof value === 'number') {
      var numeric = new Date(value);
      return Number.isNaN(numeric.getTime()) ? null : numeric;
    }
    if (!value) return null;
    var input = String(value).trim();
    // Canonical instants ONLY: an explicit UTC designator (Z) or numeric
    // offset. We must NOT append "Z" to a naive "YYYY-MM-DD HH:mm:ss" legacy
    // wall-clock — that would falsely declare the broker/server wall time as
    // UTC (Phase 3F). Naive SQL strings are returned as a display-only marker
    // (dateWall()) and never parsed to an absolute instant here.
    if (/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(input) && !/(Z$|[+-]\d{2}:?\d{2}$)/i.test(input)) {
      return null;
    }
    var parsed = new Date(input);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
  }

  /* Legacy/naive wall-clock value (original timezone unknown). Formatted
     verbatim for display only — never treated as, or converted to, UTC. */
  function dateWall(value, options) {
    if (value == null) return '—';
    var s = String(value).trim().replace('T', ' ');
    var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ ](\d{2}):(\d{2}))?/.exec(s);
    if (!m) return '—';
    var dateOnly = !m[4];
    var intlLocale = /^fa/i.test(current || '') ? 'fa-IR-u-nu-latn' : 'en-US-u-nu-latn';
    var opts = dateOnly
      ? { year: 'numeric', month: '2-digit', day: '2-digit', numberingSystem: 'latn' }
      : Object.assign({ year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false, numberingSystem: 'latn' }, options || {});
    // Build from the wall components with NO timezone (floating local-like),
    // using UTC fields so Intl does not apply any browser offset.
    var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0)));
    try {
      return new Intl.DateTimeFormat(intlLocale, Object.assign({ timeZone: 'UTC' }, opts)).format(d);
    } catch (_) {
      return s.slice(0, dateOnly ? 10 : 16);
    }
  }

  /* Canonical trade instant (occurred*AtUtc). Formats the already-UTC instant
     in the user's explicit display timezone + calendar via VeloraTime. Falls
     back to the UTC instant; returns '—' when no canonical instant exists. */
  function tradeDate(canonicalUtc, legacyWall, options) {
    if (global.VeloraTime) {
      var r = global.VeloraTime.tradeTimeDisplay(canonicalUtc, legacyWall, options || {});
      return r.text || '—';
    }
    var v = dateValue(canonicalUtc);
    if (v) return formatter('DateTimeFormat', options || { dateStyle: 'medium' }).format(v);
    return dateWall(legacyWall, options);
  }

  function date(value, options) {
    var parsed = dateValue(value);
    return parsed ? formatter('DateTimeFormat', options || { dateStyle: 'medium' }).format(parsed) : '—';
  }

  function dateTime(value, options) {
    var parsed = dateValue(value);
    return parsed ? formatter('DateTimeFormat', options || { dateStyle: 'medium', timeStyle: 'short' }).format(parsed) : '—';
  }

  function time(value, options) {
    var match = /^(\d{1,2}):(\d{2})$/.exec(String(value || ''));
    var parsed = match
      ? new Date(Date.UTC(2000, 0, 1, Number(match[1]), Number(match[2])))
      : dateValue(value);
    return parsed
      ? formatter('DateTimeFormat', Object.assign({ hour: '2-digit', minute: '2-digit', timeZone: 'UTC' }, options || {})).format(parsed)
      : '—';
  }

  function relative(value, base) {
    var target = dateValue(value);
    var origin = dateValue(base) || new Date();
    if (!target) return '—';
    var seconds = Math.round((target.getTime() - origin.getTime()) / 1000);
    var abs = Math.abs(seconds);
    var unit = 'second';
    var divisor = 1;
    if (abs >= 31536000) { unit = 'year'; divisor = 31536000; }
    else if (abs >= 2592000) { unit = 'month'; divisor = 2592000; }
    else if (abs >= 604800) { unit = 'week'; divisor = 604800; }
    else if (abs >= 86400) { unit = 'day'; divisor = 86400; }
    else if (abs >= 3600) { unit = 'hour'; divisor = 3600; }
    else if (abs >= 60) { unit = 'minute'; divisor = 60; }
    return formatter('RelativeTimeFormat', { numeric: 'auto' }).format(Math.round(seconds / divisor), unit);
  }

  function errorMessage(payload, fallbackKey) {
    var error = payload && payload.error ? payload.error : payload;
    var key = error && error.messageKey ? error.messageKey : fallbackKey || 'errors.unknown';
    var params = error && error.params ? error.params : null;
    var translated = t(key, params, '');
    if (translated) return translated;
    return t('errors.unknown', null, 'Something went wrong.');
  }

  function syncSwitcher() {
    document.querySelectorAll('[data-velora-locale-select]').forEach(function (select) { select.value = current; });
  }

  /*
   * Prefer a page-owned slot, then well-known navigation action areas. Pages with no
   * navigation receive a non-obstructive bottom-corner dock. This is layout policy only;
   * locale availability and labels continue to come entirely from the registry.
   */
  function switcherMount() {
    var plans = [
      { selector: '[data-velora-locale-slot]', context: 'slot', mode: 'append' },
      { selector: '.velora-nav-right', context: 'app', mode: 'prepend' },
      { selector: '.topbar .actions', context: 'app', mode: 'prepend' },
      { selector: '#header .nav', context: 'site', before: '#nav-toggle' },
      { selector: 'header.nav > div:last-child', context: 'site', mode: 'prepend' }
    ];
    for (var index = 0; index < plans.length; index += 1) {
      var target = document.querySelector(plans[index].selector);
      if (target) return { target: target, plan: plans[index] };
    }
    return { target: document.body, plan: { context: 'fallback', mode: 'append' } };
  }

  function mountSwitcher() {
    if (document.querySelector('[data-velora-locale-switcher]') || document.documentElement.hasAttribute('data-hide-locale-switcher')) return;
    var mount = switcherMount();
    if (!mount.target) return;
    var languageLabel = t('common.language', null, 'Language');
    var wrap = document.createElement('div');
    wrap.className = 'velora-locale-switcher';
    wrap.setAttribute('data-velora-locale-switcher', '');
    wrap.setAttribute('data-placement', mount.plan.context === 'fallback' ? 'dock' : 'inline');
    wrap.setAttribute('data-context', mount.plan.context);
    wrap.setAttribute('title', languageLabel);
    var icon = document.createElement('span');
    icon.className = 'velora-locale-icon';
    icon.setAttribute('aria-hidden', 'true');
    var label = document.createElement('label');
    label.htmlFor = 'velora-locale-select';
    label.textContent = languageLabel;
    var select = document.createElement('select');
    select.id = 'velora-locale-select';
    select.setAttribute('data-velora-locale-select', '');
    select.setAttribute('aria-label', languageLabel);
    Object.keys(localeIndex).forEach(function (code) {
      var info = localeIndex[code];
      var option = document.createElement('option');
      option.value = code;
      option.textContent = info.nativeName;
      select.appendChild(option);
    });
    select.value = current;
    select.addEventListener('change', function () { setLocale(select.value).catch(function () {}); });
    wrap.appendChild(icon);
    wrap.appendChild(label);
    wrap.appendChild(select);
    var before = mount.plan.before && mount.target.querySelector(mount.plan.before);
    if (before) mount.target.insertBefore(wrap, before);
    else if (mount.plan.mode === 'prepend') mount.target.insertBefore(wrap, mount.target.firstChild);
    else mount.target.appendChild(wrap);
    arrangeLandingLanguageControl();
  }

  /* On compact landing headers, language belongs inside the opened menu—not beside it.
     This keeps the header to one clear primary action: opening navigation. */
  function arrangeLandingLanguageControl() {
    var switcher = document.querySelector('#header .velora-locale-switcher');
    var links = document.querySelector('#header nav.links');
    var toggle = document.querySelector('#header #nav-toggle');
    if (!switcher || !links || !toggle || !global.matchMedia) return;
    if (global.matchMedia('(max-width: 1100px)').matches) {
      if (switcher.parentNode !== links) links.appendChild(switcher);
      switcher.setAttribute('data-mobile-menu-item', '');
    } else {
      if (switcher.parentNode !== toggle.parentNode) toggle.parentNode.insertBefore(switcher, toggle);
      switcher.removeAttribute('data-mobile-menu-item');
    }
  }
  global.addEventListener('resize', arrangeLandingLanguageControl, { passive: true });

  var api = {
    get locale() { return current; },
    get intlLocale() { return meta(current).intlLocale || current; },
    get direction() { return meta(current).direction || 'ltr'; },
    registry: registry,
    normalize: normalize,
    load: load,
    loadFeature: loadFeature,
    loadFeatures: function (features, locale) { return load(locale || current, features); },
    setLocale: setLocale,
    whenPersisted: function () { return pendingPersist || Promise.resolve({ persisted: false, reason: 'idle' }); },
    t: t,
    apply: apply,
    number: number,
    currency: currency,
    percent: percent,
    date: date,
    dateTime: dateTime,
    dateWall: dateWall,
    tradeDate: tradeDate,
    time: time,
    relative: relative,
    errorMessage: errorMessage,
    status: function (code) { return t('status.' + String(code || 'unknown').toLowerCase(), null, String(code || '—')); }
  };

  /* R2: when the authenticated session reports a server-side user.locale that
     differs from the current client locale, adopt it. The data layer emits
     'velora:user-locale' from setSession/refresh so a preference changed on
     another device is honored on this browser. We do not persist again (that
     would loop); we only re-apply catalogs and document direction. */
  if (global.addEventListener) {
    global.addEventListener('velora:user-locale', function (event) {
      var detail = event && event.detail ? event.detail : {};
      var desired = normalize(detail.locale);
      if (!desired || desired === current) return;
      /* Explicit URL prefix still wins — a user deliberately viewing /fa/...
         must not be bounced to English by a stale event. */
      if (explicitPathLocale()) return;
      setLocale(desired, { persist: false }).catch(function () {});
    });
  }

  global.VeloraLocale = global.I18n = api;
  observe();
  api.ready = setLocale(current, { persist: false }).then(function (locale) {
    mountSwitcher();
    return locale;
  }).catch(function () {
    /* Initial HTML remains usable and correctly localized even if non-critical chunks fail. */
    document.documentElement.classList.remove('velora-locale-booting');
    mountSwitcher();
    return current;
  });
})(window, document);
