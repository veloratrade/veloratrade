/**
 * VELORA Smart Manual Trade Registration — screenshot assist.
 * Fills the EXISTING /trades/new form. Never auto-saves.
 */
(function () {
  'use strict';
  if (!/\/trades\/new/.test(location.pathname)) return;
  if (window.__veloraSmartImport) return;
  window.__veloraSmartImport = true;

  var FA = (document.documentElement.lang || 'fa').toLowerCase().indexOf('fa') === 0;
  function t(fa, en) { return FA ? fa : en; }
  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char];
    });
  }
  function safeDataImageUrl(value) {
    value = String(value || '');
    return /^data:image\/(?:png|jpeg|webp);base64,[A-Za-z0-9+/=\s]+$/i.test(value) ? value : '';
  }

  var FIELDS = [
    { key: 'symbol', label: t('نماد', 'Symbol'), id: 'symbol' },
    { key: 'direction', label: t('جهت', 'Direction'), id: null },
    { key: 'volume', label: t('حجم / لات', 'Volume'), id: 'volume' },
    { key: 'entryPrice', label: t('قیمت ورود', 'Entry'), id: 'entry' },
    { key: 'exitPrice', label: t('قیمت خروج', 'Exit'), id: 'exit' },
    { key: 'stopLoss', label: t('حد ضرر', 'Stop loss'), id: 'sl' },
    { key: 'takeProfit', label: t('حد سود', 'Take profit'), id: 'tp' },
    { key: 'openTime', label: t('زمان باز', 'Open time'), id: 'openTime' },
    { key: 'closeTime', label: t('زمان بسته', 'Close time'), id: 'closeTime' },
    { key: 'profitLoss', label: t('سود / زیان', 'P/L'), id: null },
    { key: 'commission', label: t('کمیسیون', 'Commission'), id: 'commission' },
    { key: 'swap', label: t('سوآپ', 'Swap'), id: 'swap' },
    { key: 'ticketId', label: t('شماره تیکت', 'Ticket'), id: null }
  ];

  var MAX_SHOTS = 4;
  var MAX_CANVAS_DIMENSION = 4096;
  var MAX_CANVAS_PIXELS = 12000000;
  var shots = [];
  var pendingReads = 0;
  var merged = {};
  var conflicts = [];
  var conf = {};
  var editing = false;

  function boundedCanvasSize(width, height, desiredScale, maxDimension) {
    width = Number(width);
    height = Number(height);
    if (!Number.isFinite(width) || !Number.isFinite(height) || width < 1 || height < 1) return null;
    var scale = Math.max(0.01, Number(desiredScale) || 1);
    var dimensionCap = Math.min(MAX_CANVAS_DIMENSION, maxDimension || MAX_CANVAS_DIMENSION);
    scale = Math.min(
      scale,
      dimensionCap / width,
      dimensionCap / height,
      Math.sqrt(MAX_CANVAS_PIXELS / (width * height))
    );
    return {
      width: Math.max(1, Math.round(width * scale)),
      height: Math.max(1, Math.round(height * scale))
    };
  }

  function compressImage(dataUrl) {
    return new Promise(function (resolve) {
      if (!dataUrl || dataUrl.length < 1400000) { resolve(dataUrl); return; }
      var img = new Image();
      img.onload = function () {
        var size = boundedCanvasSize(img.naturalWidth, img.naturalHeight, 1, 1800);
        if (!size) { resolve(dataUrl); return; }
        var c = document.createElement('canvas');
        c.width = size.width;
        c.height = size.height;
        var ctx = c.getContext('2d');
        if (!ctx) { resolve(dataUrl); return; }
        ctx.drawImage(img, 0, 0, c.width, c.height);
        resolve(c.toDataURL('image/jpeg', 0.86));
      };
      img.onerror = function () { resolve(dataUrl); };
      img.src = dataUrl;
    });
  }

  function el(html) {
    var d = document.createElement('div');
    d.innerHTML = html.trim();
    return d.firstElementChild;
  }

  function panelMode(name) {
    var panel = document.querySelector('main .panel');
    if (!panel) return;
    panel.classList.remove('vsi-hero', 'vsi-manual', 'vsi-journal');
    panel.classList.add(name);
  }

  function mount() {
    var panel = document.querySelector('main .panel');
    if (!panel || document.getElementById('vsiWrap')) return;
    var wrap = el('<div class="vsi-wrap" id="vsiWrap"><div class="vsi-body" id="vsiBody"></div></div>');
    var err = panel.querySelector('#errBox');
    panel.insertBefore(wrap, err || panel.firstChild);
    ['entry','exit','volume','contract','commission','swap','sl','tp','openTime','closeTime','symbol','notes','strategy'].forEach(function (id) {
      var node = document.getElementById(id);
      if (node) {
        node.setAttribute('lang', 'en');
        node.setAttribute('dir', 'ltr');
        node.style.unicodeBidi = 'isolate';
      }
    });
    if (/mode=manual/.test(location.search)) setMode('manual');
    else setMode('ai');
  }

  function setMode(mode) {
    if (mode === 'ai') {
      shots = []; merged = {}; conflicts = []; conf = {}; editing = false;
    }
    var wrap = document.getElementById('vsiWrap');
    if (!wrap) return;
    wrap.classList.remove('ai', 'review');
    if (mode === 'manual') {
      panelMode('vsi-manual');
      document.getElementById('vsiBody').innerHTML =
        '<button type="button" class="vsi-manual-link" id="vsiBackHero">← ' + t('بازگشت به اسکرین‌شات', 'Back to screenshot') + '</button>';
      document.getElementById('vsiBackHero').onclick = function () { setMode('ai'); };
      return;
    }
    wrap.classList.add('ai');
    panelMode('vsi-hero');
    renderCapture();
  }

  function renderCapture() {
    document.getElementById('vsiBody').innerHTML =
      '<div class="vsi-hero-card">' +
        '<div class="vsi-kicker">VELORA DESK</div>' +
        '<h2>' + t('اسکرین را رها کنید', 'Drop the screenshot') + '</h2>' +
        '<p class="lead">' + t('کارت بسته‌شده MT4/MT5 را بیاورید. اعداد را می‌خوانیم؛ ذخیره فقط با تأیید شماست.', 'Bring a closed MT4/MT5 card. We read the numbers. Nothing saves until you confirm.') + '</p>' +
        '<div class="vsi-drop" id="vsiDrop" tabindex="0">' +
          '<strong>' + t('کارت معامله بسته‌شده', 'Closed trade card') + '</strong>' +
          '<p>' + t('آپلود · درگ‌دراپ · پیست · دوربین', 'Upload · drag & drop · paste · camera') + '</p>' +
        '</div>' +
        '<div class="vsi-actions">' +
          '<button type="button" class="vsi-btn gold" id="vsiPick">' + t('انتخاب تصویر', 'Choose image') + '</button>' +
          '<button type="button" class="vsi-btn ghost" id="vsiCam">' + t('دوربین', 'Camera') + '</button>' +
          '<button type="button" class="vsi-btn ghost" id="vsiSample">' + t('نمونه', 'Sample') + '</button>' +
        '</div>' +
        '<input id="vsiFile" type="file" accept="image/png,image/jpeg,image/webp" multiple hidden>' +
        '<input id="vsiCamIn" type="file" accept="image/*" capture="environment" hidden>' +
        '<div class="vsi-thumbs" id="vsiThumbs"></div>' +
        '<div class="vsi-actions">' +
          '<button type="button" class="vsi-btn gold" id="vsiRun" disabled>' + t('خواندن معامله', 'Read trade') + '</button>' +
        '</div>' +
        '<div class="vsi-prog" id="vsiProg" hidden></div>' +
        '<button type="button" class="vsi-manual-link" id="vsiToManual">' + t('ورود دستی اعداد', 'Enter numbers manually') + '</button>' +
      '</div>';

    var drop = document.getElementById('vsiDrop');
    drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('drag'); });
    drop.addEventListener('dragleave', function () { drop.classList.remove('drag'); });
    drop.addEventListener('drop', function (e) { e.preventDefault(); drop.classList.remove('drag'); addFiles(e.dataTransfer.files); });
    document.addEventListener('paste', onPaste);
    document.getElementById('vsiPick').onclick = function () { document.getElementById('vsiFile').click(); };
    document.getElementById('vsiCam').onclick = function () { document.getElementById('vsiCamIn').click(); };
    document.getElementById('vsiFile').onchange = function (e) { addFiles(e.target.files); e.target.value = ''; };
    document.getElementById('vsiCamIn').onchange = function (e) { addFiles(e.target.files); e.target.value = ''; };
    document.getElementById('vsiSample').onclick = loadSample;
    document.getElementById('vsiRun').onclick = runExtract;
    document.getElementById('vsiToManual').onclick = function () { setMode('manual'); };
    renderThumbs();
  }

  function onPaste(e) {
    if (!document.getElementById('vsiWrap') || !document.getElementById('vsiWrap').classList.contains('ai')) return;
    var items = e.clipboardData && e.clipboardData.items;
    if (!items) return;
    var files = [];
    for (var i = 0; i < items.length; i++) if (items[i].type.indexOf('image/') === 0) files.push(items[i].getAsFile());
    if (files.length) { e.preventDefault(); addFiles(files); }
  }

  function addFiles(list) {
    var added = 0;
    var limitReached = false;
    Array.prototype.forEach.call(list || [], function (file) {
      if (!file) return;
      if (shots.length + pendingReads >= MAX_SHOTS) {
        limitReached = true;
        return;
      }
      var type = String(file.type || '').toLowerCase();
      var name = String(file.name || 'shot.jpg').toLowerCase();
      var supportedImage = /^(image\/(?:png|jpeg|webp))$/.test(type) || (!type && /\.(png|jpe?g|webp)$/.test(name));
      if (!supportedImage) return;
      if (file.size > 8 * 1024 * 1024) {
        prog(t('حجم تصویر بیش از 8MB است.', 'Image is larger than 8MB.'));
        return;
      }
      added += 1;
      pendingReads += 1;
      var reader = new FileReader();
      reader.onload = function () {
        pendingReads = Math.max(0, pendingReads - 1);
        var dataUrl = safeDataImageUrl(reader.result);
        if (!dataUrl) {
          prog(t('فرمت تصویر پشتیبانی نمی‌شود. PNG، JPEG یا WebP بفرست.', 'Unsupported image format. Use PNG, JPEG, or WebP.'));
          return;
        }
        if (shots.length >= MAX_SHOTS) return;
        shots.push({
          id: 's' + Date.now() + Math.random().toString(16).slice(2),
          name: file.name || 'screenshot.jpg',
          dataUrl: dataUrl
        });
        renderThumbs();
        if (document.getElementById('vsiRun')) document.getElementById('vsiRun').disabled = false;
        showScanning();
        clearTimeout(window.__vsiAutoRead);
        window.__vsiAutoRead = setTimeout(function () { runExtract(); }, 60);
      };
      reader.onerror = function () {
        pendingReads = Math.max(0, pendingReads - 1);
        prog(t('خواندن تصویر ناموفق بود.', 'The image could not be read.'));
      };
      reader.readAsDataURL(file);
    });
    if (limitReached) {
      prog(t('حداکثر 4 تصویر در هر بار قابل پردازش است.', 'A maximum of 4 images can be processed at once.'));
    } else if (!added) {
      prog(t('این فایل به‌عنوان تصویر شناخته نشد. JPG یا PNG بفرست.', 'This file was not recognized as an image. Use JPG or PNG.'));
    }
  }

  function renderThumbs() {
    var box = document.getElementById('vsiThumbs');
    if (!box) return;
    box.innerHTML = shots.map(function (s) {
      return '<figure><img src="' + s.dataUrl + '" alt=""><button type="button" data-id="' + s.id + '">×</button></figure>';
    }).join('');
    box.querySelectorAll('button').forEach(function (b) {
      b.onclick = function () {
        shots = shots.filter(function (s) { return s.id !== b.getAttribute('data-id'); });
        renderThumbs();
      };
    });
    var run = document.getElementById('vsiRun');
    if (run) run.disabled = !shots.length;
  }

  function loadSample() {
    fetch('/public/samples/mt5-fa-card.jpg').then(function (r) { return r.blob(); }).then(function (b) {
      addFiles([new File([b], 'mt5-fa-card.jpg', { type: 'image/jpeg' })]);
    }).catch(function () {
      fetch('/public/samples/mt5-closed-trade.png').then(function (r) { return r.blob(); }).then(function (b) {
        addFiles([new File([b], 'mt5-closed-trade.png', { type: 'image/png' })]);
      });
    });
  }

  function showScanning() {
    var shot = shots[0];
    if (!shot) return;
    var wrap = document.getElementById('vsiWrap');
    if (wrap) wrap.classList.add('ai');
    panelMode('vsi-hero');
    document.getElementById('vsiBody').innerHTML =
      '<div class="vsi-scan">' +
        '<div class="vsi-shimmer"></div>' +
        '<img src="' + shot.dataUrl + '" alt="">' +
        '<div class="vsi-scan-bar">' + t('در حال خواندن کارت معامله', 'Reading trade card') + '<span></span></div>' +
      '</div>';
  }

  function prog(msg) {
    var bar = document.querySelector('.vsi-scan-bar');
    if (bar) {
      bar.textContent = String(msg || '');
      bar.appendChild(document.createElement('span'));
      return;
    }
    var p = document.getElementById('vsiProg');
    if (!p) return;
    p.hidden = false;
    p.textContent = '';
    var label = document.createElement('b');
    label.textContent = t('در حال تحلیل…', 'Analyzing…');
    p.appendChild(label);
    p.appendChild(document.createTextNode(' ' + String(msg || '')));
  }

  async function readNative(dataUrl) {
    if (!('TextDetector' in window)) return '';
    try {
      var blob = await (await fetch(dataUrl)).blob();
      var bmp = await createImageBitmap(blob);
      var lines = await new TextDetector().detect(bmp);
      return (lines || []).map(function (l) { return l.rawValue || ''; }).join('\n');
    } catch (e) {
      return '';
    }
  }

  function looksLikeTrade(text) {
    var s = faDigits(String(text || ''));
    return /XAU|XAG|EURUSD|GBPUSD|USDJPY|BTCUSD|NAS100|US30|buy|sell|#\s*\d{5,}/i.test(s);
  }

  function showFail(reason) {
    var hint = reason || t(
      'نتوانستیم کارت معامله را بخوانیم. کارت بسته‌شده MT4/MT5 را بفرست (نه چارت شمعی) یا ورود دستی را بزن.',
      'Could not read the trade card. Send a closed MT4/MT5 card (not a candlestick chart) or enter manually.'
    );
    document.getElementById('vsiBody').innerHTML =
      '<div class="vsi-alert warn">' + escapeHtml(hint) + '</div>' +
      '<div class="vsi-actions">' +
        '<button type="button" class="vsi-btn gold" id="vsiFailRetry">' + t('تلاش دوباره', 'Try again') + '</button>' +
        '<button type="button" class="vsi-btn ghost" id="vsiFailManual">' + t('ورود دستی اعداد', 'Enter manually') + '</button>' +
      '</div>';
    var r = document.getElementById('vsiFailRetry');
    if (r) r.onclick = function () { setMode('ai'); };
    var b = document.getElementById('vsiFailManual');
    if (b) b.onclick = function () { setMode('manual'); };
  }

  async function runExtract() {
    if (!shots.length) return;
    merged = {}; conflicts = []; conf = {}; editing = false;
    window.__vsiTimes = null;
    showScanning();
    var texts = [];
    try {
      prog(t('خواندن روی سرور…', 'Reading on server…'));
      texts = await ocrServer(shots.map(function (s) { return s.dataUrl; }));
    } catch (e2) { texts = []; }
    if (!texts.some(looksLikeTrade)) {
      try {
        prog(t('خواندن در مرورگر… ممکن است چند ثانیه طول بکشد.', 'Reading in the browser… this can take a few seconds.'));
        var local = await ocr(shots[0].dataUrl);
        if (local) texts = texts.concat([local]);
      } catch (e3) {}
    }
    var parsed = texts.map(function (text, i) {
      var fields = {};
      try { fields = parseMt(text || ''); } catch (err) { fields = {}; }
      return { label: t('اسکرین', 'Shot') + ' ' + (i + 1), fields: fields };
    });
    mergeAll(parsed);
    if (window.__vsiTimes) {
      if (__vsiTimes.openTime) merged.openTime = faDigits(__vsiTimes.openTime);
      if (__vsiTimes.closeTime) merged.closeTime = faDigits(__vsiTimes.closeTime);
    }
    if (!Object.keys(merged).length) {
      showFail();
      return;
    }
    renderReview();
  }

  async function ocrServer(dataUrls) {
    var packed = [];
    for (var i = 0; i < dataUrls.length; i++) packed.push(await compressImage(dataUrls[i]));
    if (!window.VeloraData || !window.VeloraData.request) throw new Error('auth-client-unavailable');
    var data = await window.VeloraData.request('/api/v1/trades/extract-screenshot', {
      method: 'POST',
      body: { images: packed }
    });
    var list = data && data.texts;
    if (!list || !list.length) throw new Error('empty');
    window.__vsiTimes = data.times || null;
    return list;
  }

  var TESSERACT_OPTIONS = Object.freeze({
    workerPath: 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/worker.min.js',
    corePath: 'https://cdn.jsdelivr.net/npm/tesseract.js-core@5.1.1',
    langPath: '/public/assets/tesseract-data',
    workerBlobURL: true
  });
  var tessWorker = null;
  async function ocrFast(dataUrl) {
    if (!window.Tesseract) {
      await new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
        s.integrity = 'sha384-GJqSu7vueQ9qN0E9yLPb3Wtpd7OrgK8KmYzC8T1IysG1bcvxvIO4qtYR/D3A991F';
        s.crossOrigin = 'anonymous';
        s.referrerPolicy = 'no-referrer';
        s.onload = resolve; s.onerror = reject; document.head.appendChild(s);
      });
    }
    if (!tessWorker) {
      tessWorker = await window.Tesseract.createWorker('eng', 1, TESSERACT_OPTIONS);
    }
    var prepared = await preprocess(dataUrl);
    var res = await tessWorker.recognize(prepared);
    return (res && res.data && res.data.text) || '';
  }

  function faDigits(s) {
    return String(s).replace(/[\u06F0-\u06F9\u0660-\u0669]/g, function (c) {
      var code = c.charCodeAt(0);
      if (code >= 0x06F0 && code <= 0x06F9) return String(code - 0x06F0);
      if (code >= 0x0660 && code <= 0x0669) return String(code - 0x0660);
      return c;
    });
  }

  // Robust number normalization for OCR text: faDigits -> resolve decimal/thousands
  // separators. Fixes the comma/dot corruption (e.g. "1,234.56" stayed "1.234.56").
  //   "1,234.56" -> 1234.56  |  "1.234,56" -> 1234.56  |  "1,0854" -> 1.0854 (trading default)
  function normalizeNumber(raw) {
    var s = faDigits(String(raw == null ? '' : raw)).replace(/\s+/g, '')
           .replace(/\u066B/g, '.').replace(/\u066C/g, ',') // Arabic decimal/thousands separators
           .replace(/[^\d.,\-+]/g, '');
    if (!s) return '';
    var sign = /^[+-]/.test(s) ? s.charAt(0) : '';
    s = s.replace(/^[+-]/, '');
    var hasDot = s.indexOf('.') !== -1, hasComma = s.indexOf(',') !== -1;
    if (hasDot && hasComma) {
      if (s.lastIndexOf(',') > s.lastIndexOf('.')) s = s.replace(/\./g, '').replace(',', '.');
      else s = s.replace(/,/g, '');
    } else if (hasComma) {
      s = s.replace(/,/g, '.'); // comma-only -> decimal (MT5/localized trading default)
    }
    return sign + s;
  }

  function showNum(value) {
    var text = faDigits(value == null ? '' : value);
    if (!text) return '';
    return '<bdi class="vsi-num" lang="en" dir="ltr">' + escapeHtml(text) + '</bdi>';
  }

  function joinThousands(s) {
    return String(s).replace(/(^|[^\d])(\d{1,3}) (\d{3}(?:[.,]\d+)?)/g, '$1$2$3');
  }

  function signedMoney(s) {
    s = String(s).replace(',', '.').trim();
    if (/^\d+[.,]\d+-$/.test(s)) s = '-' + s.replace(/-$/, '');
    return s;
  }

  // Broker reports use signed adjustments (negative charge, positive credit).
  // Velora stores positive costs and negative credits, so invert once at import.
  function brokerAdjustmentToCost(value) {
    var normalized = signedMoney(faDigits(value));
    if (!/^[+-]?\d+(?:\.\d+)?$/.test(normalized)) return normalized;
    normalized = normalized.replace(/^\+/, '');
    if (/^-?0+(?:\.0+)?$/.test(normalized)) return normalized.replace(/^-/, '');
    return normalized.charAt(0) === '-' ? normalized.slice(1) : '-' + normalized;
  }

  function recoverSplitPrice(value, reference) {
    var x = parseFloat(value);
    var r = parseFloat(reference);
    if (!isFinite(x) || !isFinite(r) || r === 0) return value;
    if (x > Math.abs(r) * 0.2) return value;
    var rec = parseFloat(String(Math.floor(Math.abs(r) / 1000)) + String(value));
    if (isFinite(rec) && Math.abs(rec - r) / Math.abs(r) < 0.05) return String(rec);
    return value;
  }

  function preprocess(dataUrl) {
    return new Promise(function (resolve) {
      var img = new Image();
      img.onload = function () {
        var desiredScale = img.naturalWidth < 900 ? 2.4 : 1.6;
        var size = boundedCanvasSize(img.naturalWidth, img.naturalHeight, desiredScale);
        if (!size) { resolve(dataUrl); return; }
        var c = document.createElement('canvas');
        c.width = size.width;
        c.height = size.height;
        var ctx = c.getContext('2d');
        if (!ctx) { resolve(dataUrl); return; }
        ctx.drawImage(img, 0, 0, c.width, c.height);
        var id = ctx.getImageData(0, 0, c.width, c.height);
        var d = id.data;
        var sum = 0;
        for (var i = 0; i < d.length; i += 16) sum += d[i];
        var invert = (sum / (d.length / 16)) < 90;
        for (var j = 0; j < d.length; j += 4) {
          var g = 0.299 * d[j] + 0.587 * d[j + 1] + 0.114 * d[j + 2];
          if (invert) g = 255 - g;
          g = g < 150 ? 0 : 255;
          d[j] = d[j + 1] = d[j + 2] = g;
        }
        ctx.putImageData(id, 0, 0);
        resolve(c.toDataURL('image/png'));
      };
      img.onerror = function () { resolve(dataUrl); };
      img.src = dataUrl;
    });
  }

  async function ocr(dataUrl) {
    if (!window.Tesseract) {
      await new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
        s.integrity = 'sha384-GJqSu7vueQ9qN0E9yLPb3Wtpd7OrgK8KmYzC8T1IysG1bcvxvIO4qtYR/D3A991F';
        s.crossOrigin = 'anonymous';
        s.referrerPolicy = 'no-referrer';
        s.onload = resolve; s.onerror = reject; document.head.appendChild(s);
      });
    }
    var prepared = await preprocess(dataUrl);
    var texts = [];
    try {
      var a = await window.Tesseract.recognize(prepared, 'eng', TESSERACT_OPTIONS);
      if (a && a.data && a.data.text) texts.push(a.data.text);
    } catch (e1) {}
    try {
      var b = await window.Tesseract.recognize(prepared, 'fas+eng', TESSERACT_OPTIONS);
      if (b && b.data && b.data.text) texts.push(b.data.text);
    } catch (e2) {}
    if (!texts.length) {
      var c = await window.Tesseract.recognize(dataUrl, 'eng', TESSERACT_OPTIONS);
      return (c && c.data && c.data.text) || '';
    }
    return texts.join('\n');
  }

  function parseMt(raw) {
    var text = faDigits(String(raw || '').replace(/\u00a0/g, ' '));
    text = text.replace(/(\d{1,3})\s+(\d{3})([.,]\d{1,5})/g, '$1$2$3');
    text = joinThousands(text);
    text = text.replace(/[→—–‒−]/g, '->');
    var out = {};
    var NUMERIC_KEYS = { volume: 1, entryPrice: 1, exitPrice: 1, stopLoss: 1, takeProfit: 1, swap: 1, commission: 1, profitLoss: 1 };
    function set(key, value, score) {
      if (value == null || value === '' || value === '-' || out[key]) return;
      var v = String(value).trim();
      if (NUMERIC_KEYS[key]) {
        v = normalizeNumber(v);
        if (!isFinite(parseFloat(v))) return; // reject OCR garbage that is not a number
      }
      out[key] = { value: v, confidence: score };
    }
    function grab(re, g) {
      var m = text.match(re);
      return m && m[g || 1] ? m[g || 1].trim() : '';
    }

    set('symbol', grab(/\b(XAUUSD|XAGUSD|EURUSD|GBPUSD|USDJPY|USDCHF|USDCAD|AUDUSD|NZDUSD|BTCUSD|ETHUSD|US30|NAS100|USTEC|GER40)\b/), 98);
    set('symbol', grab(/\b([A-Z]{6})\b/), 88);

    var dirVol = text.match(/\b(buy|buv|bu[y1]|sell|sel[!l1]|sei[l1])\s+([0-9]+(?:[.,][0-9]{1,2})?)\b/i);
    if (dirVol) {
      set('direction', /^sel/i.test(dirVol[1]) ? 'sell' : 'buy', 98);
      set('volume', dirVol[2].replace(',', '.'), 97);
    } else {
      var dirOnly = grab(/\b(buy\s*limit|sell\s*limit|buy|buv|sell|sel[!l1])\b/i);
      if (dirOnly) set('direction', /^sel/i.test(dirOnly) ? 'sell' : 'buy', 92);
      var volOnly = grab(/\b(?:lot|lots|volume|حجم)\s*[:#]?\s*([0-9]+(?:[.,][0-9]{1,2})?)/i) ||
                    grab(/\b([0]\.[0-9]{1,2})\b/);
      if (volOnly) set('volume', volOnly.replace(',', '.'), 86);
    }

    var pair = text.match(/([0-9]+[.,][0-9]{1,5})\s*(?:->|-)\s*([0-9]+[.,][0-9]{1,5})/);
    if (pair) {
      set('entryPrice', pair[1].replace(',', '.'), 96);
      set('exitPrice', pair[2].replace(',', '.'), 96);
    }
    set('entryPrice', grab(/(?:open\s*price|entry|قیمت\s*ورود)\s*[:#]?\s*([0-9]+[.,][0-9]+)/i), 94);
    set('exitPrice', grab(/(?:close\s*price|exit|قیمت\s*خروج)\s*[:#]?\s*([0-9]+[.,][0-9]+)/i), 90);

    set('ticketId', grab(/#\s*([0-9]{5,})/), 97);

    var sl = grab(/([0-9]+[.,][0-9]+)\s+\d?\s*حد\s*ضرر/) ||
             grab(/حد\s*ضرر[:\s]*([0-9]+[.,][0-9]+)/) ||
             grab(/(?:s\/?l|stop\s*loss)\s*[:#]?\s*([0-9]+[.,][0-9]+)/i);
    if (sl && sl !== '-') {
      sl = recoverSplitPrice(sl.replace(',', '.'), out.exitPrice && out.exitPrice.value);
      set('stopLoss', sl, 93);
    }

    var tp = grab(/حد\s*سود[:\s]*([0-9]+[.,][0-9]+)/) ||
             grab(/(?:t\/?p|take\s*profit)\s*[:#]?\s*([0-9]+[.,][0-9]+)/i);
    if (tp && tp !== '-') set('takeProfit', tp.replace(',', '.'), 90);

    var sw = grab(/([+-]?[0-9]+(?:[.,][0-9]+)?-?)\s*(?:سواپ|سوآپ)/) ||
             grab(/(?:سواپ|سوآپ)\s*[:#]?\s*([+-]?[0-9]+[.,][0-9]+)/) ||
             grab(/(?:^|[\s])(?:swap)\s*[:#]?\s*([+-]?[0-9]+[.,][0-9]+)/im);
    if (sw && Math.abs(parseFloat(signedMoney(sw))) < 500) {
      set('swap', brokerAdjustmentToCost(sw === '000' || sw === '00' ? '0.00' : sw), 90);
    }

    var cm = grab(/([+-]?[0-9]+[.,][0-9]+-?)\s*(?:کمیسیون|کميسيون|کهیسپون|کهيسیون)/) ||
             grab(/(?:کمیسیون|کميسيون|کهیسپون)\s*[:#]?\s*([+-]?[0-9]+[.,][0-9]+-?)/) ||
             grab(/(?:commission|comm)\s*[:#]?\s*([+-]?[0-9]+[.,][0-9]+)/i);
    if (cm) set('commission', brokerAdjustmentToCost(cm), 90);

    var pnl = grab(/([+-]\d+[.,]\d{2})(?!\d)/) || grab(/(\d+[.,]\d{2}-)/);
    if (pnl) set('profitLoss', signedMoney(pnl), 86);

    var openT = grab(/گشایش[:\s]*([0-9]{4}[./-][0-9]{2}[./-][0-9]{2}[ T][0-9]{2}:[0-9]{2}(?::[0-9]{2})?)/) ||
                grab(/(?:open\s*time|opened)\s*[:#]?\s*([0-9]{4}[./-][0-9]{2}[./-][0-9]{2}[ T][0-9]{2}:[0-9]{2}(?::[0-9]{2})?)/i);
    if (openT) set('openTime', openT, 84);
    var closeT = grab(/(?:close\s*time|closed)\s*[:#]?\s*([0-9]{4}[./-][0-9]{2}[./-][0-9]{2}[ T][0-9]{2}:[0-9]{2}(?::[0-9]{2})?)/i);
    if (closeT) set('closeTime', closeT, 80);

    fillCardExtras(text, out, set);
    validateFields(out);
    return out;
  }

  // Lower confidence on implausible numeric values so the existing "Review needed"
  // badge warns the user before anything is saved. Values are never silently dropped.
  function validateFields(out) {
    function num(key) { return out[key] ? parseFloat(normalizeNumber(out[key].value)) : NaN; }
    function demote(key) { if (out[key]) out[key].confidence = Math.min(out[key].confidence, 40); }
    ['entryPrice', 'exitPrice', 'stopLoss', 'takeProfit', 'volume'].forEach(function (k) {
      var n = num(k);
      if (out[k] && (!isFinite(n) || n <= 0)) demote(k);
    });
    var vol = num('volume');
    if (isFinite(vol) && vol > 10000) demote('volume');
    var dir = out.direction && out.direction.value;
    var entry = num('entryPrice'), sl = num('stopLoss'), tp = num('takeProfit');
    if (dir === 'buy') {
      if (isFinite(entry) && isFinite(sl) && sl >= entry) demote('stopLoss');
      if (isFinite(entry) && isFinite(tp) && tp <= entry) demote('takeProfit');
    } else if (dir === 'sell') {
      if (isFinite(entry) && isFinite(sl) && sl <= entry) demote('stopLoss');
      if (isFinite(entry) && isFinite(tp) && tp >= entry) demote('takeProfit');
    }
  }

  function fillCardExtras(text, out, set) {
    var used = {};
    ['entryPrice', 'exitPrice', 'volume', 'profitLoss', 'stopLoss', 'takeProfit', 'commission', 'swap'].forEach(function (k) {
      if (out[k]) used[out[k].value] = true;
    });
    var nums = [];
    String(text).replace(/[+-]?\d+[.,]\d{1,5}-?/g, function (raw) {
      var v = signedMoney(raw.replace(',', '.'));
      var n = parseFloat(v);
      if (isFinite(n)) nums.push({ raw: v, n: n });
      return raw;
    });
    var exitN = out.exitPrice ? parseFloat(out.exitPrice.value) : null;
    var entryN = out.entryPrice ? parseFloat(out.entryPrice.value) : null;
    nums.forEach(function (item) {
      if (used[item.raw]) return;
      if (!out.stopLoss && exitN && Math.abs(item.n - exitN) / Math.max(1, Math.abs(exitN)) < 0.01) {
        set('stopLoss', item.raw, 82);
        used[item.raw] = true;
        return;
      }
      if (!out.stopLoss && entryN && Math.abs(item.n - entryN) / Math.max(1, Math.abs(entryN)) < 0.015) {
        set('stopLoss', item.raw, 78);
        used[item.raw] = true;
      }
    });
    nums.forEach(function (item) {
      if (used[item.raw]) return;
      if (!out.swap && item.n === 0) {
        set('swap', '0.00', 80);
        used[item.raw] = true;
      }
    });
    nums.forEach(function (item) {
      if (used[item.raw]) return;
      if (!out.commission && Math.abs(item.n) > 0 && Math.abs(item.n) < 50) {
        set('commission', brokerAdjustmentToCost(item.raw), 80);
        used[item.raw] = true;
      }
    });

    var dates = extractDatetimes(text);
    dates.sort();
    if (dates.length >= 2) {
      if (!out.openTime) set('openTime', dates[0], 88);
      if (!out.closeTime) set('closeTime', dates[dates.length - 1], 88);
    } else if (dates.length === 1) {
      if (!out.openTime) set('openTime', dates[0], 84);
      if (!out.closeTime) set('closeTime', dates[0], 84);
    }
  }

  function pad2(n) { return String(n).padStart(2, '0'); }

  function validStamp(y, m, d, hh, mm, ss) {
    y = Number(y); m = Number(m); d = Number(d);
    hh = Number(hh); mm = Number(mm); ss = Number(ss || 0);
    if (y < 2018 || y > 2035) return '';
    if (m < 1 || m > 12 || d < 1 || d > 31) return '';
    if (hh > 23 || mm > 59 || ss > 59) return '';
    return y + '-' + pad2(m) + '-' + pad2(d) + 'T' + pad2(hh) + ':' + pad2(mm);
  }

  function extractDatetimes(text) {
    var src = faDigits(String(text || ''));
    var found = [];
    function add(v) {
      if (v && found.indexOf(v) === -1) found.push(v);
    }

    src.replace(/([12][0-9]{3})[./\- ]([01]?[0-9])[./\- ]([0-3]?[0-9])[ T]+([0-2]?[0-9])[:.]([0-5][0-9])(?:[:.]([0-5][0-9]))?/g, function (_, y, m, d, hh, mm, ss) {
      add(validStamp(y, m, d, hh, mm, ss));
      return _;
    });

    src.replace(/([12][0-9]{3})(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])[^\d]{0,3}([01][0-9]|2[0-3])([0-5][0-9])([0-5][0-9])?/g, function (_, y, m, d, hh, mm, ss) {
      add(validStamp(y, m, d, hh, mm, ss || '00'));
      return _;
    });

    src.replace(/\b(20[12][0-9])(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])([01][0-9]|2[0-3])([0-5][0-9])([0-5][0-9])\b/g, function (_, y, m, d, hh, mm, ss) {
      add(validStamp(y, m, d, hh, mm, ss));
      return _;
    });

    src.replace(/([12][0-9]{3})[,./\-](0?[1-9]|1[0-2])[,./\-](0[1-9]|[12][0-9]|3[01])/g, function (_, y, m, d) {
      var stamp = validStamp(y, m, d, 0, 0, 0);
      if (stamp && found.every(function (f) { return f.indexOf(stamp.slice(0, 10)) !== 0; })) add(stamp);
      return _;
    });

    var gosh = src.search(/گشایش|goshayesh|opened/i);
    if (gosh >= 0) {
      var around = src.slice(Math.max(0, gosh - 30), gosh + 40);
      var dm = around.match(/([12][0-9]{3})[,./\-](0?[1-9]|1[0-2])[,./\-](0[1-9]|[12][0-9]|3[01])/);
      if (dm) add(validStamp(dm[1], dm[2], dm[3], 0, 0, 0));
    }

    var compact = src.replace(/[^\d]/g, ' ');
    compact.replace(/\b(20[12][0-9])\s+(0?[1-9]|1[0-2])\s+([0-3]?[0-9])\s+([0-2]?[0-9])\s+([0-5][0-9])(?:\s+([0-5][0-9]))?\b/g, function (_, y, m, d, hh, mm, ss) {
      add(validStamp(y, m, d, hh, mm, ss || '00'));
      return _;
    });

    return found;
  }

  function mergeAll(parsed) {
    FIELDS.forEach(function (f) {
      var hits = [];
      parsed.forEach(function (p) {
        if (p.fields[f.key]) hits.push({ label: p.label, value: p.fields[f.key].value, confidence: p.fields[f.key].confidence });
      });
      if (!hits.length) return;
      var uniq = [];
      hits.forEach(function (h) {
        var n = String(h.value).toLowerCase();
        if (uniq.every(function (u) { return String(u.value).toLowerCase() !== n; })) uniq.push(h);
      });
      if (uniq.length > 1) {
        conflicts.push({ field: f.key, values: uniq });
      } else {
        merged[f.key] = uniq[0].value;
        conf[f.key] = uniq[0].confidence;
      }
    });
  }

  function badge(score) {
    if (score == null) return '<span class="vsi-badge vsi-none">' + t('تشخیص نشد', 'Not detected') + '</span>';
    if (score >= 90) return '<span class="vsi-badge vsi-high">' + score + '% · ' + t('بالا', 'High') + '</span>';
    if (score >= 70) return '<span class="vsi-badge vsi-mid">' + score + '% · ' + t('متوسط', 'Medium') + '</span>';
    return '<span class="vsi-badge vsi-low">' + score + '% · ' + t('نیاز به بررسی', 'Review') + '</span>';
  }

  function cell(label, key) {
    var c = conflicts.find(function (x) { return x.field === key; });
    if (c) {
      return '<div><small>' + escapeHtml(label) + '</small>' +
        c.values.map(function (v) {
          return '<button type="button" class="vsi-btn ghost" data-cf="' + escapeHtml(key) + '" data-val="' + encodeURIComponent(v.value) + '">' + escapeHtml(faDigits(v.value)) + '</button>';
        }).join(' ') + '</div>';
    }
    var val = merged[key];
    return '<div><small>' + escapeHtml(label) + '</small><b>' + (val ? showNum(val) : '<span class="vsi-miss">—</span>') + '</b></div>';
  }

  function tapeLine() {
    var parts = [
      faDigits(merged.symbol || '—'),
      (merged.direction || '').toUpperCase() || '—',
      merged.volume ? faDigits(merged.volume) : '—',
      (merged.entryPrice ? faDigits(merged.entryPrice) : '—') + ' → ' + (merged.exitPrice ? faDigits(merged.exitPrice) : '—'),
      merged.profitLoss ? faDigits(merged.profitLoss) : ''
    ];
    return escapeHtml(parts.filter(Boolean).join('    '));
  }

  function renderReview() {
    var wrap = document.getElementById('vsiWrap');
    wrap.classList.add('review');
    panelMode('vsi-hero');
    var dir = merged.direction === 'sell' ? 'sell' : (merged.direction === 'buy' ? 'buy' : '');
    var pnl = parseFloat(merged.profitLoss);
    var pnlClass = !isFinite(pnl) ? 'flat' : (pnl < 0 ? 'neg' : 'pos');
    var warns = validateNow();
    var shotUrl = shots[0] ? safeDataImageUrl(shots[0].dataUrl) : '';
    var shot = shotUrl ? '<div class="vsi-ticket-visual"><img src="' + shotUrl + '" alt=""></div>' : '';
    document.getElementById('vsiBody').innerHTML =
      '<div class="vsi-ticket">' + shot +
        '<div class="vsi-ticket-body">' +
          '<div class="vsi-ticket-top">' +
            '<div class="vsi-sym">' + (window.VeloraSymbols && merged.symbol ? window.VeloraSymbols.icon(formSymbol(merged.symbol), 34) + ' ' : '') +
              '<span lang="en" dir="ltr">' + showNum(window.VeloraSymbols && merged.symbol ? window.VeloraSymbols.displayCode(merged.symbol) : (merged.symbol || '—')) + '</span>' +
              (window.VeloraSymbols && merged.symbol && window.VeloraSymbols.countryNameOf(merged.symbol) ? '<small style="display:block;font-weight:600;color:#8FA0C0;margin-top:2px;">' + escapeHtml(window.VeloraSymbols.countryNameOf(merged.symbol)) + '</small>' : '') +
            '</div>' +
            (dir ? '<div class="vsi-side ' + dir + '">' + dir.toUpperCase() + '</div>' : '') +
          '</div>' +
          '<div class="vsi-tape">' + tapeLine() + '</div>' +
          '<div class="vsi-pnl ' + pnlClass + '">' + (merged.profitLoss ? showNum(merged.profitLoss) : '—') + '</div>' +
          '<div class="vsi-meta">' +
            cell(t('حجم', 'Volume'), 'volume') +
            cell(t('ورود → خروج', 'Entry → Exit'), 'entryPrice') +
            cell(t('خروج', 'Exit'), 'exitPrice') +
            cell(t('حد ضرر', 'Stop loss'), 'stopLoss') +
            cell(t('حد سود', 'Take profit'), 'takeProfit') +
            cell(t('تیکت', 'Ticket'), 'ticketId') +
            cell(t('کمیسیون', 'Commission'), 'commission') +
            cell(t('سوآپ', 'Swap'), 'swap') +
            cell(t('زمان باز', 'Open time'), 'openTime') +
            cell(t('زمان بسته', 'Close time'), 'closeTime') +
          '</div>' +
          (warns.length ? '<div class="vsi-alert warn">' + warns.map(escapeHtml).join(' ') + '</div>' : '') +
          '<div class="vsi-toolbar">' +
            '<button type="button" class="vsi-btn gold" id="vsiApply">' + t('تأیید و ادامه ژورنال', 'Confirm & journal') + '</button>' +
            '<button type="button" class="vsi-btn edit" id="vsiEdit">' + t('ویرایش', 'Edit') + '</button>' +
            '<button type="button" class="vsi-btn ghost" id="vsiBack">' + t('اسکرین دیگر', 'Another shot') + '</button>' +
          '</div>' +
        '</div></div>';

    document.querySelectorAll('[data-cf]').forEach(function (b) {
      b.onclick = function () {
        merged[b.getAttribute('data-cf')] = decodeURIComponent(b.getAttribute('data-val'));
        conf[b.getAttribute('data-cf')] = 99;
        conflicts = conflicts.filter(function (c) { return c.field !== b.getAttribute('data-cf'); });
        renderReview();
      };
    });
    document.getElementById('vsiApply').onclick = applyToForm;
    document.getElementById('vsiEdit').onclick = function () {
      applyToForm();
      panelMode('vsi-journal');
    };
    document.getElementById('vsiBack').onclick = function () { setMode('ai'); };
    if (window.VeloraLatinDigits) window.VeloraLatinDigits.apply(document.getElementById('vsiBody'));
  }

  function validateNow() {
    var w = [];
    var dir = merged.direction;
    var en = parseFloat(merged.entryPrice);
    var ex = parseFloat(merged.exitPrice);
    var sl = parseFloat(merged.stopLoss);
    var tp = parseFloat(merged.takeProfit);
    if (dir === 'buy' && isFinite(en) && isFinite(sl) && sl >= en) w.push(t('برای خرید، حد ضرر معمولاً زیر ورود است.', 'For a Buy, SL is usually below entry.'));
    if (dir === 'sell' && isFinite(en) && isFinite(sl) && sl <= en) w.push(t('برای فروش، حد ضرر معمولاً بالای ورود است.', 'For a Sell, SL is usually above entry.'));
    if (dir === 'buy' && isFinite(en) && isFinite(tp) && tp <= en) w.push(t('برای خرید، حد سود معمولاً بالای ورود است.', 'For a Buy, TP is usually above entry.'));
    if (dir === 'sell' && isFinite(en) && isFinite(tp) && tp >= en) w.push(t('برای فروش، حد سود معمولاً زیر ورود است.', 'For a Sell, TP is usually below entry.'));
    return w;
  }

  function toLocal(v) {
    if (!v) return '';
    var d = new Date(String(v).replace(/\./g, '-').replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    function p(n) { return String(n).padStart(2, '0'); }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function setInput(id, value) {
    var node = document.getElementById(id);
    if (!node || value == null || value === '') return;
    node.value = value;
    node.dispatchEvent(new Event('input', { bubbles: true }));
    node.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function formSymbol(sym) {
    sym = String(sym || '').toUpperCase().replace(/\s+/g, '');
    if (/^[A-Z]{6}$/.test(sym)) return sym.slice(0, 3) + '/' + sym.slice(3);
    if (sym === 'XAUUSD') return 'XAU/USD';
    return sym;
  }

  function applySymbol(sym) {
    var value = formSymbol(sym);
    setInput('symbol', value);
    var txt = document.getElementById('symBtnText');
    var ico = document.getElementById('symBtnIcon');
    var label = window.VeloraSymbols && window.VeloraSymbols.displayCode ? window.VeloraSymbols.displayCode(value) : String(value || '').replace('/', '').toUpperCase();
    if (txt) {
      txt.textContent = label;
      txt.setAttribute('lang', 'en');
      txt.setAttribute('dir', 'ltr');
      txt.style.color = 'var(--txt)';
      txt.style.letterSpacing = '.04em';
    }
    var meta = document.getElementById('symBtnMeta');
    if (meta && window.VeloraSymbols && window.VeloraSymbols.nameOf) {
      try { meta.textContent = window.VeloraSymbols.nameOf(value) || ''; } catch (e) { meta.textContent = ''; }
    }
    function paint() {
      if (ico && window.VeloraSymbols) ico.innerHTML = window.VeloraSymbols.icon(value, 30);
    }
    paint();
    if (window.VeloraSymbols && window.VeloraSymbols.load) {
      window.VeloraSymbols.load().then(paint).catch(paint);
    }
  }

  function applyToForm() {
    Object.keys(merged).forEach(function (key) {
      merged[key] = faDigits(merged[key]);
    });
    if (merged.symbol) applySymbol(merged.symbol);
    if (merged.direction === 'sell') document.getElementById('optSell') && document.getElementById('optSell').click();
    else if (merged.direction === 'buy') document.getElementById('optBuy') && document.getElementById('optBuy').click();

    setInput('volume', merged.volume);
    setInput('entry', merged.entryPrice);
    setInput('exit', merged.exitPrice);
    setInput('sl', merged.stopLoss);
    setInput('tp', merged.takeProfit);
    setInput('commission', merged.commission);
    setInput('swap', merged.swap);
    setInput('openTime', toLocal(merged.openTime));
    setInput('closeTime', toLocal(merged.closeTime));

    var note = document.getElementById('notes');
    if (note && merged.ticketId && note.value.indexOf(merged.ticketId) === -1) {
      note.value = (note.value ? note.value + ' · ' : '') + 'Ticket ' + merged.ticketId;
      note.dispatchEvent(new Event('input', { bubbles: true }));
    }

    checkDuplicates();

    var wrap = document.getElementById('vsiWrap');
    wrap.classList.remove('ai');
    panelMode('vsi-journal');
    document.getElementById('vsiBody').innerHTML =
      '<div class="vsi-summary" id="vsiTape">' + tapeLine() + '</div>' +
      '<div id="vsiDup"></div>' +
      '<div class="vsi-insight" id="vsiInsight"></div>' +
      '<button type="button" class="vsi-manual-link" id="vsiAgain">' + t('اسکرین جدید', 'New screenshot') + '</button>';
    document.getElementById('vsiInsight').textContent =
      t('اعداد روی فرم نشست. احساس، استراتژی و درس را اضافه کنید، بعد با دکمه طلایی ذخیره کنید. هنوز چیزی ذخیره نشده.',
        'Numbers are on the form. Add emotion, strategy and notes, then save with the gold button. Nothing is stored yet.') +
      ' ' + insight();
    document.getElementById('vsiAgain').onclick = function () { setMode('ai'); };
    if (window.VeloraLatinDigits) window.VeloraLatinDigits.apply(document.querySelector('main .panel'));
    var grid = document.querySelector('.form-grid');
    if (grid) window.scrollTo({ top: grid.offsetTop - 72, behavior: 'smooth' });
  }

  function insight() {
    var bits = [];
    var en = parseFloat(merged.entryPrice);
    var sl = parseFloat(merged.stopLoss);
    var tp = parseFloat(merged.takeProfit);
    if (isFinite(en) && isFinite(sl) && isFinite(tp) && Math.abs(en - sl) > 0) {
      bits.push(t('ریسک به ریوارد تقریبی روی SL/TP حدود 1:', 'Planned R:R from SL/TP is about 1:') + (Math.abs(tp - en) / Math.abs(en - sl)).toFixed(2));
    } else if (!merged.stopLoss) {
      bits.push(t('حد ضرر در اسکرین دیده نشد. برای مرور بعدی بهتر است ثبت شود.', 'No stop loss was detected. Recording one later helps review risk, not just result.'));
    }
    bits.push(t('این متن آموزشی است؛ سیگنال خرید/فروش یا تضمین نتیجه نیست و جای خالی حدس زده نشده.',
      'Educational only. Not a buy/sell signal, not a verdict, and missing fields were not invented.'));
    return bits.join(' ');
  }

  function checkDuplicates() {
    if (!window.VeloraData || !merged.symbol) return;
    window.VeloraData.request('/api/v1/trades?limit=200').then(function (res) {
      var items = (res && (res.items || (res.data && res.data.items))) || [];
      var hits = items.filter(function (tr) {
        var score = 0;
        if (merged.ticketId && String(tr.notes || '').indexOf(merged.ticketId) !== -1) score += 80;
        if (String(tr.symbol || '').toUpperCase() === String(merged.symbol).toUpperCase()) score += 15;
        if (merged.entryPrice && String(tr.entryPrice) === String(merged.entryPrice)) score += 15;
        if (merged.volume && String(tr.volume) === String(merged.volume)) score += 10;
        return score >= 40;
      });
      if (!hits.length) return;
      var box = document.getElementById('vsiDup');
      if (box) box.innerHTML = '<div class="vsi-alert warn">' + t('ممکن است این معامله از قبل ثبت شده باشد. چیزی overwrite نمی‌شود — خودتان تصمیم بگیرید.',
        'This trade may already exist. Nothing will be overwritten.') + '</div>';
    }).catch(function () {});
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
  setTimeout(mount, 400);
})();
