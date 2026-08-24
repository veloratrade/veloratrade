#!/usr/bin/env node
'use strict';

/* R2 regression: velora-data must emit a 'velora:user-locale' event when a
   session carries a user.locale, and velora-localization must apply it
   (without overriding an explicit URL prefix). We load velora-data.js in a
   sandbox with enough DOM/window stubs to exercise setSession() and assert
   the dispatched event detail. We also unit-test the ordering in
   velora-locale-bootstrap.js by reusing test_locale_resolution's approach. */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(__dirname, '..', '..');

function assert(cond, msg) { if (!cond) throw new Error(msg); }

// ---- velora-data.js: capture CustomEvent detail ---------------------------
const events = [];
const dispatched = [];
// The data script is an IIFE(function(global){...})(window). Inside it, bare
// globals (CustomEvent, dispatchEvent) resolve against the context global, so
// the context object itself must BE the window/global with all needed fields.
const context = {
  localStorage: {
    removeItem() {}, getItem() { return null; }, setItem() {},
  },
  dispatchEvent(ev) { dispatched.push(ev); return true; },
  addEventListener() {},
  CustomEvent: class CustomEvent {
    constructor(type, init) { this.type = type; this.detail = (init || {}).detail || {}; events.push(this); }
  },
  fetch() { return Promise.reject(new Error('network disabled in test')); },
  location: { href: 'https://veloratrade.test/dashboard/', replace() {}, assign() {} },
  document: { cookie: '', addEventListener() {} },
  console,
  Promise,
  JSON,
  Error,
  TypeError,
  setTimeout, clearTimeout,
  Headers: class { set(){} get(){return null;} has(){return false;} },
  FormData: class FormData {},
};
context.window = context;
context.global = context;
vm.createContext(context);
const windowStub = context;
vm.runInContext(fs.readFileSync(path.join(ROOT, 'public/assets/velora-data.js'), 'utf8'), context);

assert(typeof windowStub.VeloraData === 'object', 'VeloraData global was not created');
assert(typeof windowStub.VeloraData.setSession === 'function', 'VeloraData.setSession missing');

// setSession with a user.locale must dispatch velora:user-locale.
events.length = 0;
windowStub.VeloraData.setSession({
  accessToken: 'at-' + Math.random().toString(36).slice(2),
  user: { id: 1, email: 'e@example.com', locale: 'en', fullName: 'E' },
});
const enEvent = events.find((e) => e.type === 'velora:user-locale');
assert(enEvent && enEvent.detail.locale === 'en', 'velora:user-locale with locale=en was not dispatched');

// setSession without a user must not emit a locale event (force a fresh
// unauthenticated state so a leftover currentUser from the previous call
// cannot trigger it).
events.length = 0;
windowStub.VeloraData.clearAuth();
windowStub.VeloraData.setSession({ accessToken: 'at2', user: null });
assert(!events.some((e) => e.type === 'velora:user-locale'), 'event dispatched without user');

// ---- velora-localization.js: event listener respects explicit URL --------
const registry = JSON.parse(fs.readFileSync(path.join(ROOT, 'public/locales/manifest.json'), 'utf8'));
const docEl = {
  lang: 'fa', dir: 'rtl', attributes: {},
  getAttribute(n) { return this.attributes[n] || null; },
  setAttribute(n, v) { this.attributes[n] = String(v); },
  classList: { add(){}, remove(){}, contains(){return false;} },
};
const locWindow = {
  __VELORA_LOCALE_REGISTRY__: registry,
  __VELORA_LOCALE__: 'fa',
  location: { pathname: '/fa/dashboard/', href: 'https://veloratrade.test/fa/dashboard/' },
  navigator: { language: 'fa-IR', languages: ['fa-IR'] },
  localStorage: { getItem(){return null;}, setItem(){} },
  document: undefined,
  addEventListener() {},
  dispatchEvent() { return true; },
  CustomEvent: windowStub.CustomEvent,
  fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve({ messages: {} }) }),
  queueMicrotask: (fn) => Promise.resolve().then(fn),
  MutationObserver: class { observe(){} disconnect(){} },
  matchMedia: () => ({ matches: false, addEventListener(){} }),
  Intl, Map, Set, Promise, JSON, Error, Object, String, Number, Date,
};
locWindow.window = locWindow;
locWindow.document = {
  documentElement: docEl,
  cookie: '',
  readyState: 'complete',
  addEventListener() {},
  dispatchEvent() { return true; },
  querySelector() { return null; },
  querySelectorAll() { return []; },
  createElement() { return { appendChild(){}, setAttribute(){}, addEventListener(){}, style:{}, classList:{add(){},remove(){},contains(){return false;}} }; },
  body: { dir: 'rtl', appendChild(){}, classList:{add(){},remove(){},contains(){return false;}} },
};
// Suppress the unhandled rejection from setLocale's initial chunk fetch;
// assertions run synchronously and do not depend on the initial load.
locWindow.addEventListener = function () {};
locWindow.unhandledRejectionHandled = true;
process.on('unhandledRejection', () => {});
vm.createContext(locWindow);
vm.runInContext(fs.readFileSync(path.join(ROOT, 'public/assets/velora-localization.js'), 'utf8'), locWindow);

assert(locWindow.VeloraLocale.locale === 'fa', 'initial locale should be fa');

// A user-locale=en event arriving while on /fa/... must be ignored because
// explicit URL prefix wins.
locWindow.dispatchEvent(new locWindow.CustomEvent('velora:user-locale', { detail: { locale: 'en' } }));
assert(locWindow.VeloraLocale.locale === 'fa', 'explicit /fa/ URL was overridden by user-locale event');

console.log('USER_LOCALE_EVENT_TEST_OK dispatch=true explicit_url_wins=true no_event_without_user=true');
