/*
 * Language-neutral client data layer.
 * Live API -> request/stream -> normalized raw values -> page renderer.
 * Formatting and translation are intentionally absent from this module.
 */
(function (global) {
  'use strict';

  function ApiError(message, options) {
    options = options || {};
    this.name = 'ApiError';
    this.message = message || options.code || 'API request failed';
    this.code = options.code || 'API_ERROR';
    this.status = options.status || 0;
    this.messageKey = options.messageKey || 'errors.api';
    this.params = options.params || {};
    this.details = options.details || null;
    if (Error.captureStackTrace) Error.captureStackTrace(this, ApiError);
  }
  ApiError.prototype = Object.create(Error.prototype);
  ApiError.prototype.constructor = ApiError;

  // Access credentials and user state are intentionally memory-only.
  var accessToken = '';
  var currentUser = null;
  var refreshInFlight = null;
  var sessionReady = null;

  function purgeLegacyAuthStorage() {
    try {
      global.localStorage.removeItem('tj_access_token');
      global.localStorage.removeItem('tj_refresh_token');
      global.localStorage.removeItem('tj_user');
    } catch (_) {}
  }

  function token() { return accessToken; }
  function getUser() { return currentUser; }

  function emitSession() {
    try {
      global.dispatchEvent(new CustomEvent('velora:session', {
        detail: { authenticated: !!accessToken, user: currentUser }
      }));
    } catch (_) {}
  }

  function setSession(tokens) {
    if (tokens && Object.prototype.hasOwnProperty.call(tokens, 'refreshToken')) {
      throw new ApiError('Refresh credentials are not accepted by browser JavaScript', { status: 500, code: 'UNSAFE_AUTH_RESPONSE', messageKey: 'errors.api' });
    }
    if (!tokens || typeof tokens.accessToken !== 'string' || !tokens.accessToken) {
      throw new ApiError('Invalid auth response', { status: 401, code: 'UNAUTHORIZED', messageKey: 'errors.unauthorized' });
    }
    accessToken = tokens.accessToken;
    currentUser = tokens.user || currentUser || null;
    sessionReady = Promise.resolve(currentUser);
    emitSession();
    return accessToken;
  }

  function clearAuth() {
    accessToken = '';
    currentUser = null;
    purgeLegacyAuthStorage();
    emitSession();
  }

  function refreshAccessToken() {
    if (refreshInFlight) return refreshInFlight;
    refreshInFlight = request('/api/v1/auth/refresh', {
      method: 'POST', token: '', refresh: false, retryAuth: false, body: {}
    }).then(function (payload) {
      return setSession(payload && payload.tokens);
    }).finally(function () { refreshInFlight = null; });
    return refreshInFlight;
  }

  function ready() {
    if (!sessionReady) {
      sessionReady = refreshAccessToken().then(function () {
        return currentUser;
      }).catch(function (error) {
        if (error && error.status === 401) {
          clearAuth();
          return null;
        }
        throw error;
      });
    }
    return sessionReady;
  }

  function requireSession(redirectTo) {
    return ready().then(function (user) {
      if (!accessToken) {
        global.location.replace(redirectTo || '/login');
        return null;
      }
      return user;
    });
  }

  function logout() {
    return request('/api/v1/auth/logout', {
      method: 'POST', token: '', refresh: false, retryAuth: false, body: {}
    }).finally(function () {
      clearAuth();
      sessionReady = null;
    });
  }

  purgeLegacyAuthStorage();

  function request(path, options) {
    options = options || {};
    var headers = new Headers(options.headers || {});
    var authToken = options.token === undefined ? token() : options.token;
    if (authToken && !headers.has('Authorization')) headers.set('Authorization', 'Bearer ' + authToken);
    if (options.body !== undefined && !(options.body instanceof FormData) && !headers.has('Content-Type')) headers.set('Content-Type', 'application/json');

    var init = {
      method: options.method || (options.body === undefined ? 'GET' : 'POST'),
      headers: headers,
      credentials: options.credentials || 'same-origin',
      cache: options.cache || 'no-store',
      signal: options.signal
    };
    if (options.body !== undefined) init.body = options.body instanceof FormData || typeof options.body === 'string' ? options.body : JSON.stringify(options.body);

    /* Never attach locale headers or transform values here. */
    return fetch(path, init).then(function (response) {
      return response.text().then(function (text) {
        var payload = null;
        try { payload = text ? JSON.parse(text) : null; } catch (_) {}
        var error = payload && payload.error ? payload.error : {};
        var refreshableAuthCodes = ['UNAUTHORIZED', 'ACCESS_TOKEN_MISSING', 'INVALID_TOKEN', 'SESSION_REVOKED'];
        var terminalAuthCodes = ['ACCOUNT_INACTIVE', 'USER_NOT_FOUND'];
        var authBootstrapExcluded = [
          '/api/v1/auth/login', '/api/v1/auth/register', '/api/v1/auth/refresh',
          '/api/v1/auth/logout', '/api/v1/auth/forgot-password', '/api/v1/auth/reset-password',
          '/api/v1/auth/verify-email', '/api/v1/auth/resend-verification',
          '/api/v1/auth/resend-verification-email'
        ];
        var canRefresh = response.status === 401
          && options.refresh !== false && options.retryAuth !== false
          && authBootstrapExcluded.indexOf(path) === -1
          && refreshableAuthCodes.indexOf(error.code) !== -1;

        if (canRefresh) {
          // Only a failed refresh invalidates local credentials. A 401 returned
          // by the retried business endpoint must remain that endpoint's error.
          return refreshAccessToken().catch(function (refreshError) {
            if (refreshError && refreshError.status === 401) clearAuth();
            throw refreshError;
          }).then(function (newToken) {
            return request(path, Object.assign({}, options, { token: newToken, refresh: false }));
          });
        }

        if (response.status === 401 && terminalAuthCodes.indexOf(error.code) !== -1) {
          clearAuth();
        }
        if (!response.ok || (payload && payload.status === 'error')) {
          throw new ApiError(error.message || response.statusText, {
            code: error.code,
            status: response.status,
            messageKey: error.messageKey || ('errors.http.' + response.status),
            params: error.params,
            details: error.details
          });
        }
        return payload && Object.prototype.hasOwnProperty.call(payload, 'data') ? payload.data : payload;
      });
    });
  }

  function text(value, fallback) {
    return value === undefined || value === null ? (fallback === undefined ? null : fallback) : String(value);
  }

  /* Keep exact financial decimals as strings until a calculation/render boundary. */
  function decimal(value) {
    if (value === undefined || value === null || value === '') return null;
    var normalized = String(value).trim();
    return /^[+-]?(?:\d+\.?\d*|\.\d+)$/.test(normalized) ? normalized : null;
  }

  function integer(value) {
    if (value === undefined || value === null || value === '') return null;
    var number = Number(value);
    return Number.isSafeInteger(number) ? number : null;
  }

  function isoDate(value) {
    if (!value) return null;
    var input = String(value).trim();
    if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(input)) input = input.replace(' ', 'T') + 'Z';
    var parsed = new Date(input);
    return Number.isNaN(parsed.getTime()) ? null : parsed.toISOString();
  }

  function normalizeTrade(raw) {
    raw = raw || {};
    return Object.freeze({
      id: integer(raw.id),
      accountId: integer(raw.accountId !== undefined ? raw.accountId : raw.account_id),
      symbol: text(raw.symbol, ''),
      direction: text(raw.direction, '').toLowerCase(),
      entryPrice: decimal(raw.entryPrice !== undefined ? raw.entryPrice : raw.entry_price),
      exitPrice: decimal(raw.exitPrice !== undefined ? raw.exitPrice : raw.exit_price),
      volume: decimal(raw.volume),
      profitLoss: decimal(raw.profitLoss !== undefined ? raw.profitLoss : raw.profit_loss),
      rMultiple: decimal(raw.rMultiple !== undefined ? raw.rMultiple : raw.r_multiple),
      commission: decimal(raw.commission),
      swap: decimal(raw.swap),
      currency: text(raw.currency, 'USD').toUpperCase(),
      strategyTag: text(raw.strategyTag !== undefined ? raw.strategyTag : raw.strategy_tag),
      notes: text(raw.notes),
      source: text(raw.source, 'manual'),
      openTime: isoDate(raw.openTime !== undefined ? raw.openTime : raw.open_time),
      closeTime: isoDate(raw.closeTime !== undefined ? raw.closeTime : raw.close_time),
      createdAt: isoDate(raw.createdAt !== undefined ? raw.createdAt : raw.created_at)
    });
  }

  function normalizeAccount(raw) {
    raw = raw || {};
    return Object.freeze({
      id: integer(raw.id),
      label: text(raw.label),
      login: text(raw.mtLogin !== undefined ? raw.mtLogin : raw.mt_login),
      server: text(raw.server),
      broker: text(raw.broker),
      platform: text(raw.platform !== undefined ? raw.platform : raw.provider),
      status: text(raw.status, 'unknown').toLowerCase(),
      syncStatus: text(raw.syncStatus !== undefined ? raw.syncStatus : raw.sync_status, 'unknown').toLowerCase(),
      balance: decimal(raw.balance),
      equity: decimal(raw.equity),
      leverage: decimal(raw.leverage),
      currency: text(raw.currency, 'USD').toUpperCase(),
      lastSyncedAt: isoDate(raw.lastSyncedAt !== undefined ? raw.lastSyncedAt : raw.last_synced_at)
    });
  }

  function normalizeContent(raw) {
    raw = raw || {};
    var fields = raw.fields || { title: raw.title, summary: raw.summary, content: raw.content };
    return Object.freeze({
      contentType: text(raw.contentType !== undefined ? raw.contentType : raw.content_type, 'news'),
      contentId: text(raw.contentId !== undefined ? raw.contentId : raw.id, ''),
      sourceLocale: text(raw.sourceLocale !== undefined ? raw.sourceLocale : raw.source_locale, 'und'),
      sourceHash: text(raw.sourceHash !== undefined ? raw.sourceHash : raw.source_hash, ''),
      publishedAt: isoDate(raw.publishedAt !== undefined ? raw.publishedAt : raw.published_at),
      source: text(raw.source),
      url: text(raw.url),
      fields: Object.freeze({ title: text(fields.title, ''), summary: text(fields.summary), content: text(fields.content) })
    });
  }

  function Stream(url, options) {
    this.url = url;
    this.options = options || {};
    this.socket = null;
    this.listeners = new Map();
  }
  Stream.prototype.on = function (type, listener) {
    if (!this.listeners.has(type)) this.listeners.set(type, new Set());
    this.listeners.get(type).add(listener);
    return this;
  };
  Stream.prototype.emit = function (type, value) {
    (this.listeners.get(type) || []).forEach(function (listener) { listener(value); });
  };
  Stream.prototype.connect = function () {
    var self = this;
    var absolute = new URL(this.url, global.location.href);
    absolute.protocol = absolute.protocol === 'https:' ? 'wss:' : 'ws:';
    this.socket = new WebSocket(absolute.toString());
    this.socket.addEventListener('message', function (event) {
      try { self.emit('data', JSON.parse(event.data)); } catch (_) { self.emit('invalid', event.data); }
    });
    this.socket.addEventListener('open', function () { self.emit('open'); });
    this.socket.addEventListener('close', function (event) { self.emit('close', event); });
    this.socket.addEventListener('error', function (event) { self.emit('error', event); });
    return this;
  };
  Stream.prototype.close = function () { if (this.socket) this.socket.close(); };

  global.VeloraData = Object.freeze({
    request: request,
    getAccessToken: token,
    getUser: getUser,
    setSession: setSession,
    clearAuth: clearAuth,
    ready: ready,
    requireSession: requireSession,
    logout: logout,
    ApiError: ApiError,
    raw: Object.freeze({ text: text, decimal: decimal, integer: integer, isoDate: isoDate }),
    normalize: Object.freeze({ trade: normalizeTrade, account: normalizeAccount, content: normalizeContent }),
    Stream: Stream
  });
})(window);
