/* Pomodoro / deep-work timer.
 * Counts down, survives tab-throttling by using wall-clock time, logs the
 * session to the server when it completes, and beeps via WebAudio (no assets).
 */
(function () {
  'use strict';
  var clock = document.getElementById('clock');
  if (!clock) return;

  var phaseEl = document.getElementById('phase');
  var startBtn = document.getElementById('startbtn');
  var pauseBtn = document.getElementById('pausebtn');
  var resetBtn = document.getElementById('resetbtn');
  var intrBtn = document.getElementById('intrbtn');
  var intrEl = document.getElementById('intr');

  var totalSec = 25 * 60;
  var remaining = totalSec;
  var kind = 'pomodoro';
  var kindLabel = 'Pomodoro';
  var running = false;
  var endAt = null;        // wall-clock target
  var startedAt = null;
  var interruptions = 0;
  var ticker = null;

  function two(n) { return (n < 10 ? '0' : '') + n; }
  function paint() {
    var s = Math.max(0, Math.round(remaining));
    clock.textContent = two(Math.floor(s / 60)) + ':' + two(s % 60);
    document.title = (running ? clock.textContent + ' · ' : '') + 'Focus';
    phaseEl.textContent = kindLabel + ' · ' + (running ? 'running' : (remaining < totalSec ? 'paused' : 'ready'));
  }

  function beep() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      var ctx = new Ctx();
      var o = ctx.createOscillator(), g = ctx.createGain();
      o.connect(g); g.connect(ctx.destination);
      o.frequency.value = 660; o.type = 'sine';
      g.gain.setValueAtTime(0.0001, ctx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + 0.02);
      g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 1.1);
      o.start(); o.stop(ctx.currentTime + 1.15);
    } catch (e) { /* audio is a nicety */ }
  }

  function fmtSql(d) {
    return d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate()) + ' ' +
           two(d.getHours()) + ':' + two(d.getMinutes()) + ':' + two(d.getSeconds());
  }

  function logSession(elapsedSec) {
    if (elapsedSec < 30) return;   // ignore accidental taps
    var csrfEl = document.querySelector('input[name="csrf"]');
    var body = new URLSearchParams();
    body.set('csrf', csrfEl ? csrfEl.value : '');
    body.set('action', 'log');
    body.set('kind', kind);
    body.set('duration_sec', Math.round(elapsedSec));
    body.set('started_at', fmtSql(startedAt || new Date()));
    body.set('interruptions', interruptions);
    body.set('label', (document.getElementById('label') || {}).value || '');
    body.set('task_id', (document.getElementById('taskpick') || {}).value || '');
    body.set('ajax', '1');
    fetch('focus.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function () { setTimeout(function () { location.reload(); }, 400); })
      .catch(function () { /* offline — session lost, acceptable */ });
  }

  function tick() {
    if (!running) return;
    remaining = (endAt - Date.now()) / 1000;
    if (remaining <= 0) {
      remaining = 0;
      running = false;
      clearInterval(ticker);
      paint();
      beep();
      logSession(totalSec);
      return;
    }
    paint();
  }

  function start() {
    if (running) return;
    if (!startedAt || remaining >= totalSec) { startedAt = new Date(); interruptions = 0; intrEl.textContent = '0'; }
    endAt = Date.now() + remaining * 1000;
    running = true;
    clearInterval(ticker);
    ticker = setInterval(tick, 250);
    paint();
  }

  function pause() {
    if (!running) return;
    running = false;
    remaining = Math.max(0, (endAt - Date.now()) / 1000);
    clearInterval(ticker);
    paint();
  }

  function reset() {
    running = false;
    clearInterval(ticker);
    // If a real chunk was done, log it before clearing.
    var done = totalSec - remaining;
    if (startedAt && done > 60) logSession(done);
    remaining = totalSec;
    startedAt = null;
    interruptions = 0;
    intrEl.textContent = '0';
    paint();
  }

  startBtn.addEventListener('click', start);
  pauseBtn.addEventListener('click', pause);
  resetBtn.addEventListener('click', reset);
  intrBtn.addEventListener('click', function () {
    interruptions++; intrEl.textContent = String(interruptions);
  });

  Array.prototype.forEach.call(document.querySelectorAll('.preset'), function (b) {
    b.addEventListener('click', function () {
      running = false;
      clearInterval(ticker);
      totalSec = parseInt(b.dataset.min, 10) * 60;
      remaining = totalSec;
      kind = b.dataset.kind;
      kindLabel = b.textContent.replace(/^\d+m\s*/, '');
      startedAt = null;
      paint();
    });
  });

  // Warn before navigating away mid-session.
  window.addEventListener('beforeunload', function (ev) {
    if (running) { ev.preventDefault(); ev.returnValue = ''; }
  });

  paint();
})();
