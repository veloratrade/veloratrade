/* VELORA UI Sound System — opt-in, minimal, and action-driven. */
(function (window, document) {
  'use strict';
  var KEY = 'velora_ui_sounds';
  function enabled() { try { return localStorage.getItem(KEY) !== 'off'; } catch (_) { return true; } }
  function setEnabled(value) {
    try { localStorage.setItem(KEY, value ? 'on' : 'off'); } catch (_) {}
    document.documentElement.dataset.veloraSounds = value ? 'on' : 'off';
    document.dispatchEvent(new CustomEvent('velora:sound-setting', { detail: { enabled: value } }));
  }
  function tone(ctx, frequency, start, duration, volume, type) {
    var oscillator = ctx.createOscillator(), gain = ctx.createGain();
    oscillator.type = type || 'sine';
    oscillator.frequency.setValueAtTime(frequency, start);
    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(volume, start + 0.012);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
    oscillator.connect(gain).connect(ctx.destination);
    oscillator.start(start); oscillator.stop(start + duration + 0.02);
  }
  function play(kind) {
    if (!enabled()) return;
    try {
      var Audio = window.AudioContext || window.webkitAudioContext;
      if (!Audio) return;
      var ctx = new Audio(), now = ctx.currentTime;
      if (kind === 'input') { tone(ctx, 740, now, .055, .010); }
      else if (kind === 'success') { tone(ctx, 659.25, now, .24, .055); tone(ctx, 987.77, now + .09, .38, .06); }
      else if (kind === 'warning') { tone(ctx, 294, now, .16, .045, 'triangle'); tone(ctx, 233, now + .12, .23, .04, 'triangle'); }
      else { tone(ctx, 659.25, now, .2, .042); tone(ctx, 880, now + .075, .31, .05); }
      window.setTimeout(function () { try { ctx.close(); } catch (_) {} }, 850);
    } catch (_) { /* Sound is enhancement only. */ }
  }
  window.VeloraSound = { play: play, enabled: enabled, setEnabled: setEnabled };
  document.documentElement.dataset.veloraSounds = enabled() ? 'on' : 'off';
  document.addEventListener('click', function (event) {
    var target = event.target.closest && event.target.closest('[data-sound]');
    if (target && !target.disabled) play(target.getAttribute('data-sound') || 'modal');
  });
})(window, document);
