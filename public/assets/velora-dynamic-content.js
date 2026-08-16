/*
 * Non-blocking dynamic-content localization.
 * Original content renders immediately. This module only reads reusable server-side translation cache.
 * It never calls an AI/provider and never submits translation work from the rendering path.
 */
(function (global, document) {
  'use strict';

  var memory = new Map();
  var inFlight = new Map();
  var sourceSnapshots = new WeakMap();

  function cacheKey(item, locale) {
    return [item.contentType, item.contentId, item.sourceHash, locale].join(':');
  }

  function original(item) {
    return Object.assign({ locale: item.sourceLocale, translated: false }, item.fields || {});
  }

  function cached(item, locale) {
    return memory.get(cacheKey(item, locale)) || null;
  }

  function renderImmediately(item, locale) {
    return cached(item, locale) || original(item);
  }

  function sameLocale(left, right) {
    var normalize = global.VeloraLocale && global.VeloraLocale.normalize;
    var a = normalize ? normalize(left) : String(left || '').toLowerCase().split('-')[0];
    var b = normalize ? normalize(right) : String(right || '').toLowerCase().split('-')[0];
    return !!a && a === b;
  }

  function lookupBatch(items, locale) {
    var wanted = items.filter(function (item) {
      return item && item.contentType && item.contentId && item.sourceHash && item.sourceLocale &&
        !sameLocale(item.sourceLocale, locale) && !cached(item, locale);
    });
    if (!wanted.length) return Promise.resolve(new Map());
    var signature = locale + '|' + wanted.map(function (item) { return cacheKey(item, locale); }).sort().join(',');
    if (inFlight.has(signature)) return inFlight.get(signature);

    var promise = global.VeloraData.request('/api/v1/content-translations/lookup', {
      method: 'POST',
      body: {
        targetLocale: locale,
        items: wanted.map(function (item) {
          return { contentType: item.contentType, contentId: item.contentId, sourceHash: item.sourceHash };
        })
      },
      /* Cache lookup is a localization concern and does not alter the source/live data request. */
      cache: 'no-store'
    }).then(function (data) {
      var found = new Map();
      (data && data.translations || []).forEach(function (entry) {
        var key = [entry.contentType, entry.contentId, entry.sourceHash, locale].join(':');
        var value = Object.assign({ locale: locale, translated: true }, entry.fields || {});
        memory.set(key, value);
        found.set(key, value);
      });
      return found;
    }).finally(function () { inFlight.delete(signature); });

    inFlight.set(signature, promise);
    return promise;
  }

  function localize(items, locale, onTranslation) {
    locale = locale || (global.VeloraLocale && global.VeloraLocale.locale);
    var immediate = items.map(function (item) { return renderImmediately(item, locale); });
    /* Caller paints `immediate` before this promise can resolve. */
    lookupBatch(items, locale).then(function () {
      items.forEach(function (item, index) {
        var value = cached(item, locale);
        if (value && typeof onTranslation === 'function') onTranslation(value, item, index);
      });
    }).catch(function () { /* Original-language content remains visible by design. */ });
    return immediate;
  }

  function bind(root) {
    root = root || document;
    var nodes = Array.from(root.querySelectorAll('[data-content-type][data-content-id][data-source-hash]'));
    if (!nodes.length || !global.VeloraData || !global.VeloraLocale) return;
    var items = nodes.map(function (node) {
      var identity = [node.dataset.contentType, node.dataset.contentId, node.dataset.sourceHash].join(':');
      var snapshot = sourceSnapshots.get(node);
      if (!snapshot || snapshot.identity !== identity) {
        snapshot = {
          identity: identity,
          contentType: node.dataset.contentType,
          contentId: node.dataset.contentId,
          sourceLocale: node.dataset.sourceLocale,
          sourceHash: node.dataset.sourceHash,
          fields: {
            title: (node.querySelector('[data-content-field="title"]') || {}).textContent,
            summary: (node.querySelector('[data-content-field="summary"]') || {}).textContent,
            content: (node.querySelector('[data-content-field="content"]') || {}).textContent
          }
        };
        sourceSnapshots.set(node, snapshot);
      }
      return global.VeloraData.normalize.content(snapshot);
    });

    var requestedLocale = global.VeloraLocale.locale;
    function paint(value, index) {
      var node = nodes[index];
      var item = items[index];
      var currentIdentity = [node.dataset.contentType, node.dataset.contentId, node.dataset.sourceHash].join(':');
      if (global.VeloraLocale.locale !== requestedLocale || currentIdentity !== [item.contentType, item.contentId, item.sourceHash].join(':')) return;
      ['title', 'summary', 'content'].forEach(function (field) {
        var target = node.querySelector('[data-content-field="' + field + '"]');
        if (target && value[field] !== null && value[field] !== undefined) target.textContent = value[field];
      });
      node.dataset.contentLocale = value.locale;
    }

    var immediate = localize(items, requestedLocale, function (translation, item, index) {
      paint(translation, index);
    });
    immediate.forEach(paint);
  }

  document.addEventListener('velora:locale-change', function () { bind(document); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { bind(document); }, { once: true });
  else bind(document);

  global.VeloraDynamicContent = Object.freeze({ renderImmediately: renderImmediately, lookupBatch: lookupBatch, localize: localize, bind: bind });
})(window, document);
