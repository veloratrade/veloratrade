/* Resolves locale before first paint. Initial copy is already localized by the server/build. */
(function (global, document) {
  'use strict';
  var registry = global.__VELORA_LOCALE_REGISTRY__;
  if (!registry || !registry.locales || typeof registry.locales !== 'object') {
    throw new Error('VELORA locale registry must load before bootstrap.');
  }
  var localeIndex = {};
  Object.keys(registry.locales).forEach(function (code) {
    var entry = registry.locales[code];
    if (entry && entry.enabled !== false) localeIndex[String(code).toLowerCase()] = entry;
  });

  function normalize(candidate) {
    var value = String(candidate || '').trim().replace('_', '-').toLowerCase();
    if (!value) return null;
    if (localeIndex[value]) return value;
    var base = value.split('-')[0];
    return localeIndex[base] ? base : null;
  }

  function cookieLocale() {
    var key = String(registry.cookieKey || 'velora_locale').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + key + '=([^;]*)'));
    if (!match) return null;
    try { return normalize(decodeURIComponent(match[1])); } catch (_) { return normalize(match[1]); }
  }

  function storedLocale() {
    try { return normalize(global.localStorage.getItem(registry.storageKey)); } catch (_) { return null; }
  }

  function browserLocale() {
    var candidates = global.navigator.languages && global.navigator.languages.length
      ? global.navigator.languages
      : [global.navigator.language || ''];
    var primary = String(candidates[0] || '').trim();
    if (!primary) return null;
    return normalize(primary) || normalize(registry.fallbackLocale);
  }

  function explicitRouteLocale() {
    var path = global.location && typeof global.location.pathname === 'string' ? global.location.pathname : '';
    var first = path.replace(/^\/+/, '').split('/')[0].toLowerCase().replace('_', '-');
    return localeIndex[first] ? first : null;
  }

  function declaredRouteLocale() {
    return normalize(document.documentElement.getAttribute('data-route-locale'));
  }

  /* Contract shared with locale-router.php: explicit locale URL, persisted manual
     choice, primary browser language, then route/default. */
  var resolved = explicitRouteLocale()
    || cookieLocale()
    || storedLocale()
    || browserLocale()
    || declaredRouteLocale()
    || normalize(registry.defaultLocale)
    || Object.keys(localeIndex)[0];
  var meta = localeIndex[resolved] || localeIndex[registry.fallbackLocale] || { intlLocale: resolved, direction: 'ltr' };
  var root = document.documentElement;
  var prelocalized = normalize(root.getAttribute('data-velora-prelocalized'));
  root.lang = meta.intlLocale || resolved;
  root.dir = meta.direction || 'ltr';
  root.setAttribute('data-locale', resolved);
  root.setAttribute('data-direction', root.dir);

  /* F-03: internal links that opt in via [data-velora-localized-href] are rewritten
     to carry the resolved locale prefix, so cross-page navigation (e.g. the pricing
     CTA -> /checkout/) preserves the active locale instead of re-negotiating it. */
  function localizeAnchors(scope) {
    if (!scope || typeof scope.querySelectorAll !== 'function') return;
    var anchors = scope.querySelectorAll('[data-velora-localized-href]');
    for (var index = 0; index < anchors.length; index += 1) {
      var anchor = anchors[index];
      var href = anchor.getAttribute('href');
      if (!href || href.charAt(0) !== '/' || href.indexOf('//') === 0) continue;
      var firstSegment = href.replace(/^\/+/, '').split(/[/?#]/)[0].toLowerCase().replace('_', '-');
      if (localeIndex[firstSegment]) continue; /* already explicit */
      anchor.setAttribute('href', href === '/' ? '/' + resolved + '/' : '/' + resolved + href);
    }
  }
  if (typeof document.addEventListener === 'function') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', function () { localizeAnchors(document); });
    } else {
      localizeAnchors(document);
    }
  }

  /* Generated HTML that matches the resolved locale is paint-ready. The concealment
     path exists only for stale/static fallback HTML or a migrated localStorage choice. */
  if (prelocalized !== resolved) {
    root.classList.add('velora-locale-booting');
    if (typeof global.setTimeout === 'function') {
      global.setTimeout(function () { root.classList.remove('velora-locale-booting'); }, 4000);
    }
  }
  global.__VELORA_LOCALE__ = resolved;
})(window, document);
