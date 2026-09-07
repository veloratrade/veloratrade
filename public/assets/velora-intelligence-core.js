/* VELORA — Trading Intelligence Core · Phase 1 controller + Phase 2 continuity beats (ES module, no bundler)
   Tier detection → HTML chapter state (works without WebGL) → lazy Three.js scene → chapter-5 handoff
   into the real #dashboard .db-shell. Native scroll only: no wheel capture, no snap, no page lock.
   All user-facing text is HTML (data-i18n). This file contains no copy. */
const VIC_VERSION = '2026.09.06.2';
const html = document.documentElement;
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
const clamp = (v, a = 0, b = 1) => Math.min(b, Math.max(a, v));
const smooth = t => t * t * (3 - 2 * t);
const S = (a, b, t) => a + (b - a) * clamp(t);
const BOUNDS = [0, .18, .40, .62, .82, 1.0001];

function main() {
  const core = $('#core');
  if (!core) return;
  const qs = new URLSearchParams(location.search);
  const vicParam = (qs.get('vic') || '').toLowerCase();
  if (vicParam === 'off') { html.setAttribute('data-vic', 'off'); return; }

  const rmq = matchMedia('(prefers-reduced-motion: reduce)');
  let reduced = rmq.matches || qs.get('vicrm') === '1';
  const dir = (html.getAttribute('dir') || 'ltr').toLowerCase();
  const dirSign = dir === 'rtl' ? -1 : 1;

  /* ---------- tier ---------- */
  function detectTier() {
    if (vicParam === 'a' || vicParam === 'b' || vicParam === 'c') return vicParam;
    if (reduced) return 'c';
    const nav = navigator;
    if (nav.connection && nav.connection.saveData) return 'c';
    if (nav.deviceMemory && nav.deviceMemory < 4) return 'c';
    if (nav.hardwareConcurrency && nav.hardwareConcurrency <= 2) return 'c';
    let gl = null;
    try { gl = document.createElement('canvas').getContext('webgl2'); } catch (e) { /* no WebGL2 */ }
    if (!gl) return 'c';
    const coarse = matchMedia('(pointer: coarse)').matches || innerWidth < 1024;
    return coarse ? 'b' : 'a';
  }
  let tier = detectTier();
  html.setAttribute('data-vic-tier', tier);
  html.setAttribute('data-vic-reduced', reduced ? '1' : '0');

  /* ---------- DOM refs ---------- */
  const els = {
    core, inner: $('.vic-inner', core), stage: $('#vic-stage'), leaders: $('#vic-leaders'), frame: $('#vic-frame'),
    stepsBox: $('.vic-steps', core), steps: $$('.vic-step', core), rail: $$('.vic-rail a', core), label: $('[data-vic-label]', core), posters: $$('.vic-poster img', core),
    dashboard: $('#dashboard'), real: $('#dashboard .db-shell'), header: $('#header'),
  };
  if (!els.stage || !els.inner) return;
  const sectionHead = els.dashboard ? $('.sec-head', els.dashboard) : null;

  /* ---------- Tier C: posters (requested only now) ---------- */
  function usePosters() {
    els.posters.forEach(img => { if (!img.getAttribute('src') && img.dataset.vicSrc) img.setAttribute('src', img.dataset.vicSrc); });
  }
  if (tier === 'c') usePosters();

  /* ---------- header height → sticky offset ---------- */
  let headerH = 70;
  function syncHeader() {
    const h = els.header ? Math.round(els.header.getBoundingClientRect().height) : 0;
    if (h && h !== headerH) { headerH = h; core.style.setProperty('--vic-header-h', h + 'px'); }
    else if (h && !core.style.getPropertyValue('--vic-header-h')) core.style.setProperty('--vic-header-h', h + 'px');
  }
  syncHeader();

  /* ---------- story progress from native scroll ---------- */
  function progress() {
    const r = core.getBoundingClientRect();
    const stickyH = innerHeight - headerH;
    const total = Math.max(1, r.height - stickyH);
    return (-r.top) / total;                 // <0 before, 0..1 pinned, >1 after
  }

  /* ---------- chapter state (HTML only) ---------- */
  let chapter = -1, counterVal = -1;
  const counter = $('[data-vic-count]', core);
  function setChapter(p) {
    let c = p < 0 ? 0 : p >= 1 ? 4 : BOUNDS.findIndex((b, i) => p >= b && p < BOUNDS[i + 1]);
    if (c < 0) c = 4;
    const local = clamp((p - BOUNDS[c]) / (BOUNDS[c + 1] - BOUNDS[c]));
    if (c !== chapter) {
      chapter = c;
      els.steps.forEach((s, i) => {
        const on = i === c;
        s.classList.toggle('is-active', on);
        $$('a,button', s).forEach(a => { a.tabIndex = on ? 0 : -1; });
      });
      els.rail.forEach((a, i) => { a.classList.toggle('on', i === c); if (i === c) a.setAttribute('aria-current', 'step'); else a.removeAttribute('aria-current'); });
      els.posters.forEach((img, i) => img.classList.toggle('on', i === c));
      if (els.label) els.label.textContent = (c + 1) + ' / 5';
    }
    const step = els.steps[c];
    if (c === 0 && counter) { const n = reduced ? 142 : Math.round(142 * smooth(clamp(local / .55))); if (n !== counterVal) { counterVal = n; counter.textContent = String(n); } }
    else if (counter && counterVal !== 142) { counterVal = 142; counter.textContent = '142'; }
    if (step) {
      $$('.vic-hud > div', step).forEach((d, i) => d.classList.toggle('on', local > .08 + i * .12));
      $$('.vic-tag', step).forEach((t, i) => t.classList.toggle('on', local > .3 + i * .16));
      if (c === 2) { const q = $('.vic-q', step), a = $('.vic-a', step); if (q) q.classList.toggle('on', local > .08); if (a) a.classList.toggle('on', local > .52); }   // question before the scan, answer after two patterns are labelled
      $$('.vic-ledger li', step).forEach((li, i) => li.classList.toggle('on', local > .70 + i * .054));   // 0.15 after the canvas row (ch4 beat 5)
    }
    return { c, local };
  }

  /* ---------- chapter 5 → runtime clone of the real dashboard ---------- */
  let mini = null, dockRects = null, revealed = false, framePx = null, frameSized = false, frameW = 0, frameH = 0;
  /* B1: a one-shot guard — any anchor navigation to #dashboard (nav link, hero CTA, skip link, deep link, back/forward)
     is a jump *past* the story, never a flight: the frame stays off and the real shell is revealed at once.
     Cleared when the user scrolls back into the story (p ≤ .78), so a later forward scroll gets the full handoff. */
  let anchored = false;
  function armAnchor() {
    anchored = true;
    if (els.frame && els.frame.classList.contains('on')) { els.frame.classList.remove('on'); if (els.dashboard) els.dashboard.classList.remove('vic-handoff'); }
    restoreSteps(); if (els.real) revealReal();
  }
  document.addEventListener('click', e => { const a = e.target.closest && e.target.closest('a[href]'); if (a && /#dashboard$/.test(a.getAttribute('href') || '') && !e.defaultPrevented) armAnchor(); });
  addEventListener('hashchange', () => { if (location.hash === '#dashboard') armAnchor(); });
  if (location.hash === '#dashboard') armAnchor();
  function buildClone() {
    if (!els.real || !els.frame || mini) return;
    const clone = els.real.cloneNode(true);
    clone.classList.remove('reveal', 'in');
    clone.classList.add('vic-mini');
    $$('[id]', clone).forEach(n => n.removeAttribute('id'));
    $$('*', clone).forEach(n => { Array.from(n.attributes).forEach(a => { if (a.name.indexOf('data-i18n') === 0 || a.name === 'data-format' || a.name === 'data-content-type') n.removeAttribute(a.name); }); });
    $$('a,button,input,select,textarea,[tabindex]', clone).forEach(n => { n.setAttribute('tabindex', '-1'); if (n.tagName === 'A') n.removeAttribute('href'); });
    clone.setAttribute('aria-hidden', 'true');
    clone.setAttribute('inert', '');
    // freeze the live animation end-state in the replica (gauges, bars, equity curve)
    $$('.k-gauge .fill', clone).forEach(f => { const pct = parseFloat(f.dataset.pct) || 0; f.style.strokeDashoffset = (163.4 * (1 - pct / 100)).toFixed(1); });
    $$('.k-bar i, .psy-track i', clone).forEach(b => { b.style.width = (b.dataset.w || 0) + '%'; });
    $$('.cons-bars .cb i', clone).forEach(b => { b.style.height = (b.dataset.h || 0) + '%'; });
    $$('.eq-path', clone).forEach(p => { p.style.strokeDashoffset = '0'; });
    els.frame.appendChild(clone);
    mini = clone;
  }
  function measureDock() {
    if (!els.real) return;
    const rr = els.real.getBoundingClientRect();
    if (rr.width < 10) return;
    const kpi = $$('.db-kpis .kpi', els.real).slice(0, 4).map(k => {
      const r = k.getBoundingClientRect();
      return { cx: r.left - rr.left + r.width / 2, cy: r.top - rr.top + r.height / 2, hw: r.width / 2, hh: r.height / 2 };
    });
    const eq = [];
    const path = $('.eq-chart path.eq-path', els.real);
    if (path && path.getTotalLength) {
      try {
        const svg = path.ownerSVGElement, L = path.getTotalLength(), vb = svg.viewBox.baseVal, sr = svg.getBoundingClientRect();
        for (let i = 0; i < 24; i++) {
          const pt = path.getPointAtLength(L * i / 23);
          eq.push({ x: sr.left - rr.left + (pt.x - vb.x) / vb.width * sr.width, y: sr.top - rr.top + (pt.y - vb.y) / vb.height * sr.height });
        }
      } catch (e) { /* keep frame-only docking */ }
    }
    while (kpi.length < 4) kpi.push({ cx: rr.width / 2, cy: rr.height / 2, hw: 0, hh: 0 });
    dockRects = { kpi, eq: eq.length === 24 ? eq : Array.from({ length: 24 }, () => ({ x: rr.width / 2, y: rr.height / 2 })), w: rr.width, h: rr.height };
    if (scene) scene.setDock(dockRects);
  }
  function revealReal() {
    els.real.style.visibility = '';
    if (sectionHead) { sectionHead.style.opacity = ''; sectionHead.classList.add('in'); }   // fades in via the live .reveal transition
    if (revealed) return;
    revealed = true;
    els.real.classList.add('in');
  }

  /* frame rect = lerp(stage-fit → real rect); every opacity is a function of scroll only (no CSS transitions) */
  function layoutFrame(p) {
    if (!els.real || !els.frame || tier === 'c' || reduced) { framePx = null; return; }
    if (p <= .78) { framePx = null; anchored = false; if (els.frame.classList.contains('on')) { els.frame.classList.remove('on'); els.dashboard.classList.remove('vic-handoff'); } els.real.style.visibility = ''; if (sectionHead) sectionHead.style.opacity = ''; restoreSteps(); return; }
    const real = els.real.getBoundingClientRect(), st = els.stage.getBoundingClientRect();
    const total = Math.max(1, core.getBoundingClientRect().height - (innerHeight - headerH));
    /* B1 (PR #117 review): the flight must be over wherever browser navigation can come to rest. Anchor navigation to
       #dashboard stops with the section under the header (scroll-margin-top), i.e. with the real shell at `landTop`;
       the reveal tick therefore happens at whichever comes first: 45% of the viewport or that anchored rest position. */
    const anchorTop = parseFloat(getComputedStyle(els.dashboard).scrollMarginTop) || headerH;
    const landTop = real.top - (els.dashboard.getBoundingClientRect().top - anchorTop);
    const target = Math.max(innerHeight * .45, landTop);
    const pEnd = clamp(p + (real.top - target) / total, 1.02, 1.6);
    const cons = clamp((p - .82) / .18), h = clamp((p - .97) / (pEnd - .97));
    const on = p > .86 && h < .96 && !anchored;
    if (on && !mini) buildClone();
    els.frame.classList.toggle('on', on);
    els.dashboard.classList.toggle('vic-handoff', on);
    const asp = real.width / Math.max(1, real.height);
    let fw = st.width * .86, fh = fw / asp, tall = false;
    if (fh > st.height * .86) {
      if (tier === 'b') tall = true;                                   // mobile: width-fit, top-aligned, clipped to the stage while inside it
      else { fh = st.height * .86; fw = fh * asp; }
    }
    const inStage = { left: st.left + (st.width - fw) / 2, top: tall ? st.top + st.height * .07 : st.top + (st.height - fh) / 2, width: fw, height: fh };
    const e = smooth(clamp(h / .96));                 // reaches exactly 1 at h = .96 → frame rect == real rect on the reveal tick
    const r = { left: S(inStage.left, real.left, e), top: S(inStage.top, real.top, e), width: S(fw, real.width, e), height: S(fh, real.height, e) };
    framePx = { left: r.left - st.left, top: r.top - st.top, width: r.width, height: r.height };
    if (!on) { restoreSteps(); if (h >= .96 || anchored) revealReal(); else { els.real.style.visibility = ''; if (sectionHead) sectionHead.style.opacity = ''; } return; }
    // Tier B: the frame's flight crosses the story column (stage sits above the text) → the story recedes as the dashboard leaves the stage
    if (tall && els.stepsBox) { els.stepsBox.style.willChange = 'opacity'; els.stepsBox.style.opacity = (1 - smooth(clamp(e / .18))).toFixed(3); }
    // the frame keeps the real shell's size and is placed/scaled with a transform only (compositor-only, no layout shift)
    const k = r.width / Math.max(1, real.width);
    if (!frameSized) { frameSized = true; els.frame.style.left = '0px'; els.frame.style.top = '0px'; }
    if (Math.abs(frameW - real.width) > .5 || Math.abs(frameH - real.height) > .5) { frameW = real.width; frameH = real.height; els.frame.style.width = frameW + 'px'; els.frame.style.height = frameH + 'px'; if (mini) mini.style.width = frameW + 'px'; }
    els.frame.style.transformOrigin = '0 0';
    els.frame.style.transform = 'translate3d(' + r.left.toFixed(2) + 'px,' + r.top.toFixed(2) + 'px,0) scale(' + k.toFixed(5) + ')';
    const cut = tall ? Math.max(0, ((r.top + r.height) - (st.top + st.height)) * (1 - smooth(clamp((e - .12) / .28)))) / k : 0;   // Tier B only: the clip releases once the story has mostly faded
    els.frame.style.clipPath = cut > 0 ? 'inset(0 0 ' + cut.toFixed(1) + 'px 0)' : '';
    els.frame.style.opacity = smooth(clamp((cons - .25) / .35)).toFixed(3);
    if (mini) mini.style.opacity = smooth(clamp((cons - .7) / .2)).toFixed(3);
    els.real.style.visibility = 'hidden';          // the frame owns the pixels until the single reveal tick at h ≥ .96
    if (sectionHead) sectionHead.style.opacity = '0';   // the caption appears after the dashboard has landed
  }

  function restoreSteps() { if (els.stepsBox && els.stepsBox.style.opacity !== '') { els.stepsBox.style.opacity = ''; els.stepsBox.style.willChange = ''; } }

  /* ---------- pointer parallax (fine pointer only, ≤3°) ---------- */
  let scene = null, px = 0, py = 0;
  if (matchMedia('(pointer: fine)').matches && !reduced) {
    addEventListener('pointermove', e => { px = (e.clientX / innerWidth - .5) * 2; py = (e.clientY / innerHeight - .5) * 2; }, { passive: true });
  }

  /* ---------- Phase 2 continuity beats (outside the pin): custom properties from scroll position only ----------
     Two beats, both DOM-only and compositor-only (transform/opacity via CSS custom properties):
       #ai       — the *answer* to the question asked in core chapter 3 settles into place as the panel enters
                   (replaces the timed .reveal fade of the panel with a scroll-driven one; briefing stats follow in order)
       #roadmap  — the timeline draws with scroll and nodes light in order
     Off under reduced motion (properties cleared → CSS defaults == baseline). Cost: 2 rect reads per scrolled frame. */
  const beats = (() => {
    if (reduced) return null;
    const aiPanel = $('#ai .ai-panel'), briefKids = $$('#ai .ai-briefing > *'), road = $('#roadmap .road'), roadLine = $('#roadmap .road-line'), dots = $$('#roadmap .road-node .dot');
    const last = { va: -1, vr: -1 };
    const set = (el, name, v) => { if (el) el.style.setProperty(name, v); };
    return function update() {
      if (reduced) return;
      const vh = innerHeight;
      /* #ai arrival: the answer settles as the panel enters (0 → 1 while its top travels from 92% to 45% of the viewport) */
      if (aiPanel) {
        const r = aiPanel.getBoundingClientRect();
        const va = +smooth(clamp((vh * .92 - r.top) / (vh * .47))).toFixed(3);
        if (va !== last.va) {
          last.va = va; set(aiPanel, '--va', va);
          briefKids.forEach((k, i) => set(k, '--va', smooth(clamp((va - .3 - i * .08) / .38)).toFixed(3)));   // all settled by va = 1
        }
      }
      /* roadmap: the line draws with scroll, nodes light in order */
      if (road && roadLine) {
        const r = road.getBoundingClientRect();
        const vr = +smooth(clamp((vh * .9 - r.top) / (vh * .55))).toFixed(3);
        if (vr !== last.vr) { last.vr = vr; set(roadLine, '--vr', vr); dots.forEach((d, i) => set(d, '--vn', (vr > (i + .5) / dots.length ? 1 : 0))); }
      }
    };
  })();

  /* ---------- rAF loop (reads scroll, never writes it) ---------- */
  let running = true, sp = 0, lastNow = 0, dbgT = 0, lastP = -9, frames = 0, lastY = -1, lastSH = -1;
  const debug = qs.get('vicdebug') === '1';
  let dbg = null;
  if (debug) { dbg = document.createElement('div'); dbg.className = 'vic-dbg'; dbg.setAttribute('aria-hidden', 'true'); document.body.appendChild(dbg); }
  function loop(now) {
    if (!running) return;
    const p = progress();
    const near = p > -1.5 && p < 1.3;
    if (beats) { const y = scrollY | 0, sh = html.scrollHeight; if (y !== lastY || sh !== lastSH) { lastY = y; lastSH = sh; beats(); } }
    if (near || Math.abs(p - lastP) > 1e-4 || Math.abs(p - sp) > 1e-4) {
      lastP = p;
      if ((++frames & 31) === 0) syncHeader();
      const dt = Math.min(1, (now - lastNow) / 1000); lastNow = now;   // real elapsed time → identical feel at any frame rate
      const k = tier === 'a' && !reduced ? 1 - Math.exp(-dt / .22) : 1;   // desktop scrub lag (~0.6 s settle); none on mobile / reduced
      sp += (p - sp) * k; if (Math.abs(p - sp) < 0.0005) sp = p;
      const st = setChapter(sp);
      layoutFrame(sp);
      if (scene && near) {
        scene.setPointer(px, py);
        let s = null;
        try { s = scene.render(now, sp, framePx); }
        catch (err) { downgrade('render-error'); }   // e.g. GL calls on a context lost before the contextlost event fired → posters, loop stays alive
        if (s && dbg && now - dbgT > 250) { dbgT = now; dbg.textContent = 'tier ' + tier + ' · p ' + p.toFixed(3) + ' · ch ' + (st.c + 1) + ' ' + ((st.local * 100) | 0) + '% · fps ' + s.fps + ' · draws ' + s.draws + ' · tris ' + s.tris + ' · pts ' + s.points + ' · dpr ' + s.dpr + ' · ms ' + s.ms.toFixed(2); }
      } else if (dbg) dbg.textContent = 'tier ' + tier + ' (static) · p ' + p.toFixed(3) + ' · ch ' + (st.c + 1);
    }
    requestAnimationFrame(loop);
  }
  requestAnimationFrame(loop);
  document.addEventListener('visibilitychange', () => { running = document.visibilityState === 'visible'; if (running) requestAnimationFrame(loop); });

  /* ---------- lazy Three.js (tiers a/b): when #core approaches, after load, never on the LCP path ---------- */
  let booting = false;
  function downgrade(reason) {
    if (scene) { try { scene.dispose(); } catch (e) { /* ignore */ } }
    scene = null; tier = 'c';
    html.setAttribute('data-vic-tier', 'c'); html.setAttribute('data-vic-downgrade', reason);
    if (els.frame) els.frame.classList.remove('on');
    restoreSteps();
    if (els.dashboard) els.dashboard.classList.remove('vic-handoff');
    if (els.real) els.real.style.visibility = '';
    if (sectionHead) sectionHead.style.opacity = '';
    usePosters();
  }
  async function boot3D() {
    if (tier === 'c' || booting || scene) return;
    booting = true;
    try {
      const canvas = document.createElement('canvas');
      canvas.setAttribute('aria-hidden', 'true');
      els.stage.insertBefore(canvas, els.stage.firstChild);
      const mod = await import('/public/assets/velora-intelligence-core.scene.js?v=' + VIC_VERSION);
      scene = mod.createScene({ canvas, tier, reduced, dirSign, els });
      html.setAttribute('data-vic-three', String(mod.THREE_REVISION || scene.three));
      measureDock();
      addEventListener('resize', () => { syncHeader(); if (scene) scene.resize(); measureDock(); }, { passive: true });
      canvas.addEventListener('webglcontextlost', e => { e.preventDefault(); downgrade('context-lost'); });
      // runtime downgrade: sustained slow frames in the first seconds → Tier C posters
      let slow = 0; const t0 = performance.now();
      const probe = () => { if (!scene) return; if (scene.stats.fps && scene.stats.fps < 20) slow++; if (performance.now() - t0 < 6000) setTimeout(probe, 800); else if (slow >= 5) downgrade('slow'); };
      if (!debug && !vicParam) setTimeout(probe, 1500);   // explicit ?vic= / ?vicdebug=1 keep the requested tier
    } catch (e) {
      downgrade('load-failed');
    }
  }
  if (tier !== 'c') {
    const start = () => {
      if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver(es => { if (es.some(e => e.isIntersecting)) { io.disconnect(); boot3D(); } }, { rootMargin: '900px 0px' });
        io.observe(core);
      } else boot3D();
    };
    if (document.readyState === 'complete') start();
    else addEventListener('load', () => ('requestIdleCallback' in window ? requestIdleCallback(start, { timeout: 1500 }) : setTimeout(start, 200)));
  }
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => measureDock());   // fonts settle after load → re-measure once

  /* ---------- live reduced-motion change → static, immediately ---------- */
  function clearBeats() { $$('#ai .ai-panel,#ai .ai-briefing > *,#roadmap .road-line,#roadmap .road-node .dot').forEach(el => { ['--va', '--vr', '--vn'].forEach(n => el.style.removeProperty(n)); }); }
  rmq.addEventListener('change', e => {
    if (e.matches) clearBeats();
    reduced = e.matches || qs.get('vicrm') === '1';
    html.setAttribute('data-vic-reduced', reduced ? '1' : '0');
    if (reduced) downgrade('reduced-motion');
  });

  /* ---------- rail / chapter anchors: native scroll to the chapter start (no hijack) ---------- */
  function scrollToChapter(i) {
    const r = core.getBoundingClientRect(), stickyH = innerHeight - headerH;
    scrollTo({ top: scrollY + r.top + (BOUNDS[i] + 0.02) * (r.height - stickyH), behavior: reduced ? 'auto' : 'smooth' });
  }
  els.rail.forEach((a, i) => a.addEventListener('click', e => { e.preventDefault(); scrollToChapter(i); }));
  addEventListener('hashchange', () => { const m = /^#core-ch([1-5])$/.exec(location.hash); if (m) scrollToChapter(+m[1] - 1); });
}

main();
