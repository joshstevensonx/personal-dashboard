/* =============================================================================
   Personal Dashboard — app shell behaviour (Phase 1)
   Command palette, keyboard navigation, theme toggle, copy buttons, PWA.
   Zero dependencies.
   ========================================================================== */
(function () {
  'use strict';

  var dataEl = document.getElementById('app-data');
  var APP = dataEl ? JSON.parse(dataEl.textContent || '{}') : {};
  var COMMANDS = APP.commands || [];
  var CUSTOM = APP.shortcuts || {};

  /* ---------------------------------------------------------------- helpers */
  function $(id) { return document.getElementById(id); }
  function isTyping(el) {
    if (!el) return false;
    var t = (el.tagName || '').toLowerCase();
    return t === 'input' || t === 'textarea' || t === 'select' || el.isContentEditable;
  }

  /* ------------------------------------------------------------------ theme */
  // data-theme on <html>: auto | dark | light. 'auto' follows the OS.
  function effectiveTheme() {
    var t = document.documentElement.getAttribute('data-theme');
    if (t === 'dark' || t === 'light') return t;
    return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
  }
  function applyAutoInversion() {
    // The chosen preset supplies colours. For 'auto'/'light' on a dark preset we
    // flip a readable light palette so the app is usable either way.
    var mode = effectiveTheme();
    document.documentElement.setAttribute('data-mode', mode);
    if (mode === 'light' && document.documentElement.dataset.presetDark !== 'false') {
      var s = document.documentElement.style;
      if (!s.getPropertyValue('--bg-dark-cache')) {
        s.setProperty('--bg-dark-cache', s.getPropertyValue('--bg') || '#0f1115');
        s.setProperty('--panel-dark-cache', s.getPropertyValue('--panel') || '#181b22');
        s.setProperty('--panel2-dark-cache', s.getPropertyValue('--panel2') || '#1f232c');
        s.setProperty('--line-dark-cache', s.getPropertyValue('--line') || '#2a2f3a');
        s.setProperty('--text-dark-cache', s.getPropertyValue('--text') || '#e7e9ee');
        s.setProperty('--muted-dark-cache', s.getPropertyValue('--muted') || '#9aa3b2');
      }
      s.setProperty('--bg', '#f6f7f9'); s.setProperty('--panel', '#ffffff');
      s.setProperty('--panel2', '#f0f2f5'); s.setProperty('--line', '#dfe3e8');
      s.setProperty('--text', '#1c1f24'); s.setProperty('--muted', '#61697a');
    } else {
      var st = document.documentElement.style;
      if (st.getPropertyValue('--bg-dark-cache')) {
        st.setProperty('--bg', st.getPropertyValue('--bg-dark-cache'));
        st.setProperty('--panel', st.getPropertyValue('--panel-dark-cache'));
        st.setProperty('--panel2', st.getPropertyValue('--panel2-dark-cache'));
        st.setProperty('--line', st.getPropertyValue('--line-dark-cache'));
        st.setProperty('--text', st.getPropertyValue('--text-dark-cache'));
        st.setProperty('--muted', st.getPropertyValue('--muted-dark-cache'));
      }
    }
  }
  function toggleTheme() {
    var cur = document.documentElement.getAttribute('data-theme');
    var next = effectiveTheme() === 'dark' ? 'light' : 'dark';
    if (cur === next) next = (next === 'dark') ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    applyAutoInversion();
    fetch('api.php?action=set_setting', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ key: 'theme', value: next })
    }).catch(function () { /* offline is fine */ });
  }
  applyAutoInversion();
  window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', applyAutoInversion);

  /* -------------------------------------------------------------- overlays */
  function openEl(el) { if (el) el.classList.add('open'); }
  function closeEl(el) { if (el) el.classList.remove('open'); }
  function anyOpen() { return document.querySelector('.palette-wrap.open, .sheet-wrap.open'); }

  /* -------------------------------------------------------- command palette */
  var pw = $('palette-wrap'), pin = $('palette-input'), plist = $('palette-list');
  var results = [], sel = 0;

  function score(cmd, q) {
    if (!q) return 1;
    var hay = (cmd.label + ' ' + (cmd.group || '')).toLowerCase();
    var needle = q.toLowerCase();
    if (hay.indexOf(needle) !== -1) return 100 - hay.indexOf(needle);
    // subsequence (fuzzy) match
    var i = 0;
    for (var c = 0; c < hay.length && i < needle.length; c++) {
      if (hay[c] === needle[i]) i++;
    }
    return i === needle.length ? 10 : 0;
  }

  function render() {
    if (!plist) return;
    plist.innerHTML = '';
    if (!results.length) {
      plist.innerHTML = '<div class="none">No matching commands</div>';
      return;
    }
    results.forEach(function (cmd, i) {
      var li = document.createElement('li');
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', i === sel ? 'true' : 'false');
      li.innerHTML = '<span class="ic">' + (cmd.icon || '›') + '</span>'
        + '<span>' + cmd.label + '</span>'
        + '<span class="hint">' + (cmd.group || '') + '</span>';
      li.addEventListener('click', function () { runCommand(cmd); });
      li.addEventListener('mousemove', function () {
        if (sel !== i) { sel = i; paintSelection(); }
      });
      plist.appendChild(li);
    });
  }
  function paintSelection() {
    Array.prototype.forEach.call(plist.children, function (li, i) {
      if (li.setAttribute) li.setAttribute('aria-selected', i === sel ? 'true' : 'false');
    });
    var cur = plist.children[sel];
    if (cur && cur.scrollIntoView) cur.scrollIntoView({ block: 'nearest' });
  }
  function filter(q) {
    results = COMMANDS
      .map(function (c) { return { c: c, s: score(c, q) }; })
      .filter(function (x) { return x.s > 0; })
      .sort(function (a, b) { return b.s - a.s; })
      .slice(0, 40)
      .map(function (x) { return x.c; });
    sel = 0;
    render();
  }
  function runCommand(cmd) {
    closePalette();
    if (cmd.action === 'toggleTheme') return toggleTheme();
    if (cmd.action === 'showKeys') return openEl($('sheet-wrap'));
    if (cmd.href) window.location.href = cmd.href;
  }
  function openPalette() {
    if (!pw) return;
    openEl(pw);
    pin.value = '';
    filter('');
    setTimeout(function () { pin.focus(); }, 10);
  }
  function closePalette() { closeEl(pw); }

  if (pin) {
    pin.addEventListener('input', function () { filter(pin.value.trim()); });
    pin.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowDown') { ev.preventDefault(); sel = Math.min(sel + 1, results.length - 1); paintSelection(); }
      else if (ev.key === 'ArrowUp') { ev.preventDefault(); sel = Math.max(sel - 1, 0); paintSelection(); }
      else if (ev.key === 'Enter') { ev.preventDefault(); if (results[sel]) runCommand(results[sel]); }
      else if (ev.key === 'Escape') { closePalette(); }
    });
  }
  if (pw) pw.addEventListener('click', function (ev) { if (ev.target === pw) closePalette(); });
  var pbtn = $('palettebtn'); if (pbtn) pbtn.addEventListener('click', openPalette);
  var tbtn = $('themebtn'); if (tbtn) tbtn.addEventListener('click', toggleTheme);
  var mbtn = $('menubtn');
  if (mbtn) mbtn.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
  var sw = $('sheet-wrap');
  if (sw) sw.addEventListener('click', function (ev) { if (ev.target === sw) closeEl(sw); });

  /* ------------------------------------------------------ keyboard shortcuts */
  var GOTO = {
    d: 'index.php', i: 'inbox.php', s: 'subscriptions.php',
    t: 'dates.php', b: 'bookmarks.php', r: 'remote.php', ',': 'settings.php',
    k: 'tasks.php', c: 'calendar.php',
    p: 'planner.php', f: 'focus.php', h: 'habits.php', o: 'goals.php',
    n: 'notes.php', j: 'notes.php?daily=1',
    e: 'export.php', w: 'review.php', a: 'reports.php'
  };
  Object.keys(CUSTOM).forEach(function (k) { GOTO[k] = CUSTOM[k]; });

  var awaitingG = false, gTimer = null;
  document.addEventListener('keydown', function (ev) {
    var meta = ev.metaKey || ev.ctrlKey;

    // Command palette: Cmd/Ctrl+K works even while typing.
    if (meta && ev.key.toLowerCase() === 'k') { ev.preventDefault(); openPalette(); return; }

    if (ev.key === 'Escape') {
      closePalette(); closeEl($('sheet-wrap'));
      document.body.classList.remove('nav-open');
      return;
    }
    if (isTyping(document.activeElement) || meta || ev.altKey) return;

    if (ev.key === '/') { ev.preventDefault(); openPalette(); return; }
    if (ev.key === '?') { ev.preventDefault(); var s = $('sheet-wrap'); s.classList.contains('open') ? closeEl(s) : openEl(s); return; }
    if (ev.key === 'D' && ev.shiftKey) { ev.preventDefault(); toggleTheme(); return; }

    if (awaitingG) {
      awaitingG = false;
      clearTimeout(gTimer);
      var dest = GOTO[ev.key];
      if (dest) { ev.preventDefault(); window.location.href = dest; }
      return;
    }
    if (ev.key === 'g') {
      awaitingG = true;
      gTimer = setTimeout(function () { awaitingG = false; }, 1200);
    }
  });

  /* ------------------------------------------------------------ copy buttons */
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-copy]');
    if (!b) return;
    var text = b.getAttribute('data-copy');
    var done = function () {
      var t = b.textContent; b.textContent = 'Copied!';
      setTimeout(function () { b.textContent = t; }, 1200);
    };
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, done);
    else done();
  });

  /* --------------------------------------------------- focus #new form field */
  if (location.hash === '#new') {
    var first = document.querySelector('form.row input, form.row textarea');
    if (first) { first.focus(); first.scrollIntoView({ block: 'center' }); }
  }

  /* --------------------------------------------------------------------- PWA */
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () { /* non-fatal */ });
    });
  }
})();
