/* =============================================================================
   Notion-style block editor.

   - contenteditable blocks with debounced autosave
   - "/" slash menu to insert or convert block types
   - Enter creates the next block, Backspace on empty merges upward
   - Tab / Shift+Tab converts between list levels (bulleted <-> paragraph)
   - drag handle reorders blocks
   - checkbox + toggle state persists
   All writes go through api.php?action=block_*  (session + CSRF protected).
   ========================================================================== */
(function () {
  'use strict';

  var wrap = document.getElementById('blocks');
  var savingEl = document.getElementById('saving');
  var PAGE = window.PAGE_ID;
  var TYPES = window.BLOCK_TYPES || [];

  /* --------------------------------------------------------------- plumbing */
  var pending = 0;
  function busy(on) {
    pending += on ? 1 : -1;
    if (pending < 0) pending = 0;
    if (savingEl) savingEl.hidden = pending === 0;
  }

  function api(action, data) {
    busy(true);
    return fetch('api.php?action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(Object.assign({ csrf: window.CSRF, page_id: PAGE }, data || {}))
    }).then(function (r) { return r.json().catch(function () { return {}; }); })
      .finally(function () { busy(false); });
  }

  if (!wrap) return;   // database pages have no block editor

  /* ------------------------------------------------------------- autosave  */
  var timers = {};
  function queueSave(blockEl) {
    var id = blockEl.dataset.id;
    clearTimeout(timers[id]);
    timers[id] = setTimeout(function () { saveBlock(blockEl); }, 550);
  }
  function textOf(blockEl) {
    var t = blockEl.querySelector('.bk-text');
    return t ? t.innerText.replace(/ /g, ' ') : '';
  }
  function saveBlock(blockEl) {
    return api('block_update', {
      id: parseInt(blockEl.dataset.id, 10),
      content: textOf(blockEl),
      type: blockEl.dataset.type
    });
  }

  /* ---------------------------------------------------------- block making */
  function blockHTML(id, type) {
    var ph = type === 'paragraph' ? "Type '/' for commands" : '';
    var editable = "<div class='bk-text' contenteditable='true' data-ph=\"" + ph + "\"></div>";
    var inner;
    switch (type) {
      case 'heading1': inner = "<h1 class='bk-h1'>" + editable + "</h1>"; break;
      case 'heading2': inner = "<h2 class='bk-h2'>" + editable + "</h2>"; break;
      case 'heading3': inner = "<h3 class='bk-h3'>" + editable + "</h3>"; break;
      case 'bulleted': inner = "<div class='bk-li'><span class='bk-bullet'>•</span>" + editable + "</div>"; break;
      case 'numbered': inner = "<div class='bk-li'><span class='bk-num'></span>" + editable + "</div>"; break;
      case 'todo':     inner = "<div class='bk-li'><input type='checkbox' class='bk-check'>" + editable + "</div>"; break;
      case 'toggle':   inner = "<div class='bk-toggle'><button class='bk-arrow' type='button'>▸</button>" + editable + "</div>"; break;
      case 'quote':    inner = "<blockquote class='bk-quote'>" + editable + "</blockquote>"; break;
      case 'callout':  inner = "<div class='bk-callout'><span class='bk-emoji'>💡</span>" + editable + "</div>"; break;
      case 'code':     inner = "<pre class='bk-code'><code class='bk-text' contenteditable='true'></code></pre>"; break;
      case 'divider':  inner = "<hr class='bk-divider'>"; break;
      case 'image':    inner = "<div class='bk-image-empty'><div class='bk-text' contenteditable='true' data-ph='Paste an image URL…'></div></div>"; break;
      default:         inner = "<div class='bk-p'>" + editable + "</div>";
    }
    var el = document.createElement('div');
    el.className = 'bk';
    el.dataset.id = id;
    el.dataset.type = type;
    el.innerHTML = "<div class='bk-handles'>"
      + "<button class='bk-add' type='button' title='Add block below'>+</button>"
      + "<button class='bk-drag' type='button' draggable='true' title='Drag to move'>⠿</button>"
      + "</div><div class='bk-body'>" + inner + "</div>";
    return el;
  }

  function focusBlock(el, atEnd) {
    var t = el.querySelector('.bk-text');
    if (!t) return;
    t.focus();
    var r = document.createRange();
    r.selectNodeContents(t);
    r.collapse(!atEnd);
    var s = window.getSelection();
    s.removeAllRanges();
    s.addRange(r);
  }

  function addBlockAfter(afterEl, type) {
    type = type || 'paragraph';
    return api('block_add', {
      after: afterEl ? parseInt(afterEl.dataset.id, 10) : null,
      type: type
    }).then(function (res) {
      if (!res || !res.id) return null;
      var el = blockHTML(res.id, type);
      if (afterEl) afterEl.after(el); else wrap.appendChild(el);
      renumber();
      if (type !== 'divider') focusBlock(el, false);
      return el;
    });
  }

  function convert(blockEl, type) {
    var text = textOf(blockEl);
    var fresh = blockHTML(blockEl.dataset.id, type);
    var t = fresh.querySelector('.bk-text');
    if (t) t.innerText = text;
    blockEl.replaceWith(fresh);
    fresh.dataset.type = type;
    renumber();
    if (type !== 'divider') focusBlock(fresh, true);
    api('block_update', { id: parseInt(fresh.dataset.id, 10), content: text, type: type });
    return fresh;
  }

  function removeBlock(blockEl) {
    var id = parseInt(blockEl.dataset.id, 10);
    var prev = blockEl.previousElementSibling;
    blockEl.remove();
    renumber();
    api('block_delete', { id: id });
    if (prev) focusBlock(prev, true);
  }

  /** Numbered lists show their running index. */
  function renumber() {
    var n = 0;
    Array.prototype.forEach.call(wrap.children, function (el) {
      if (el.dataset.type === 'numbered') {
        n++;
        var s = el.querySelector('.bk-num');
        if (s) s.textContent = n + '.';
      } else { n = 0; }
    });
  }
  renumber();

  /* ------------------------------------------------------------ slash menu */
  var slash = null, slashFor = null, slashIdx = 0, slashItems = [];

  function openSlash(blockEl) {
    closeSlash();
    slashFor = blockEl;
    slashIdx = 0;
    slash = document.createElement('div');
    slash.className = 'slash';
    slash.innerHTML = "<input class='slash-q' placeholder='Filter…' autocomplete='off'><ul class='slash-list'></ul>";
    document.body.appendChild(slash);

    var r = blockEl.getBoundingClientRect();
    slash.style.left = Math.min(r.left, window.innerWidth - 330) + 'px';
    slash.style.top = (r.bottom + window.scrollY + 6) + 'px';

    var q = slash.querySelector('.slash-q');
    renderSlash('');
    q.addEventListener('input', function () { slashIdx = 0; renderSlash(q.value); });
    q.addEventListener('keydown', slashKeys);
    setTimeout(function () { q.focus(); }, 0);
  }

  function renderSlash(query) {
    var ul = slash.querySelector('.slash-list');
    var ql = (query || '').toLowerCase();
    slashItems = TYPES.filter(function (t) {
      return !ql || t.label.toLowerCase().indexOf(ql) !== -1 || t.type.indexOf(ql) !== -1;
    });
    ul.innerHTML = '';
    if (!slashItems.length) { ul.innerHTML = "<li class='slash-none'>No matching blocks</li>"; return; }
    slashItems.forEach(function (t, i) {
      var li = document.createElement('li');
      li.className = 'slash-item' + (i === slashIdx ? ' on' : '');
      li.innerHTML = "<span class='slash-ico'>" + t.icon + "</span>"
        + "<span><span class='slash-label'>" + t.label + "</span>"
        + "<span class='slash-hint'>" + t.hint + "</span></span>";
      li.addEventListener('mousedown', function (ev) { ev.preventDefault(); pickSlash(i); });
      ul.appendChild(li);
    });
  }

  function slashKeys(ev) {
    if (ev.key === 'ArrowDown') { ev.preventDefault(); slashIdx = Math.min(slashIdx + 1, slashItems.length - 1); renderSlash(ev.target.value); }
    else if (ev.key === 'ArrowUp') { ev.preventDefault(); slashIdx = Math.max(slashIdx - 1, 0); renderSlash(ev.target.value); }
    else if (ev.key === 'Enter') { ev.preventDefault(); pickSlash(slashIdx); }
    else if (ev.key === 'Escape') { ev.preventDefault(); var b = slashFor; closeSlash(); if (b) focusBlock(b, true); }
  }

  function pickSlash(i) {
    var t = slashItems[i];
    var target = slashFor;
    closeSlash();
    if (!t || !target) return;
    // Strip the "/" the user typed.
    var te = target.querySelector('.bk-text');
    if (te) te.innerText = te.innerText.replace(/\/$/, '');
    convert(target, t.type);
  }

  function closeSlash() {
    if (slash) { slash.remove(); slash = null; }
    slashFor = null;
  }
  document.addEventListener('click', function (ev) {
    if (slash && !slash.contains(ev.target)) closeSlash();
  });

  /* -------------------------------------------------------------- editing  */
  wrap.addEventListener('input', function (ev) {
    var t = ev.target.closest('.bk-text');
    if (!t) return;
    var blockEl = t.closest('.bk');
    // "/" on an otherwise empty line opens the block menu.
    if (t.innerText.trim() === '/' && !slash) { openSlash(blockEl); }
    // Markdown-ish shortcuts at the start of a line.
    var txt = t.innerText;
    var shortcuts = [
      [/^#\s/, 'heading1'], [/^##\s/, 'heading2'], [/^###\s/, 'heading3'],
      [/^[-*]\s/, 'bulleted'], [/^1\.\s/, 'numbered'], [/^\[\]\s/, 'todo'],
      [/^>\s/, 'quote'], [/^```$/, 'code'], [/^---$/, 'divider']
    ];
    for (var i = 0; i < shortcuts.length; i++) {
      if (shortcuts[i][0].test(txt)) {
        t.innerText = txt.replace(shortcuts[i][0], '');
        convert(blockEl, shortcuts[i][1]);
        return;
      }
    }
    queueSave(blockEl);
  });

  wrap.addEventListener('keydown', function (ev) {
    var t = ev.target.closest('.bk-text');
    if (!t) return;
    var blockEl = t.closest('.bk');

    if (ev.key === 'Enter' && !ev.shiftKey && blockEl.dataset.type !== 'code') {
      ev.preventDefault();
      clearTimeout(timers[blockEl.dataset.id]);
      saveBlock(blockEl).then(function () {
        // Lists continue as the same type; everything else drops to a paragraph.
        var cont = ['bulleted', 'numbered', 'todo'].indexOf(blockEl.dataset.type) !== -1;
        addBlockAfter(blockEl, cont ? blockEl.dataset.type : 'paragraph');
      });
      return;
    }

    if (ev.key === 'Backspace' && t.innerText === '') {
      var prev = blockEl.previousElementSibling;
      if (prev) { ev.preventDefault(); removeBlock(blockEl); }
      else if (blockEl.dataset.type !== 'paragraph') { ev.preventDefault(); convert(blockEl, 'paragraph'); }
      return;
    }

    if (ev.key === 'Tab') {
      ev.preventDefault();
      if (blockEl.dataset.type === 'paragraph') convert(blockEl, 'bulleted');
      else if (ev.shiftKey && blockEl.dataset.type === 'bulleted') convert(blockEl, 'paragraph');
      return;
    }

    if (ev.key === 'ArrowUp' || ev.key === 'ArrowDown') {
      var sib = ev.key === 'ArrowUp' ? blockEl.previousElementSibling : blockEl.nextElementSibling;
      var sel = window.getSelection();
      // Only jump blocks when the caret is already at the edge of this one.
      if (sib && sel && sel.isCollapsed) {
        var atStart = sel.anchorOffset === 0;
        var atEnd = sel.anchorOffset === (sel.anchorNode ? (sel.anchorNode.textContent || '').length : 0);
        if ((ev.key === 'ArrowUp' && atStart) || (ev.key === 'ArrowDown' && atEnd)) {
          ev.preventDefault();
          focusBlock(sib, ev.key === 'ArrowUp');
        }
      }
    }
  });

  wrap.addEventListener('blur', function (ev) {
    var t = ev.target.closest && ev.target.closest('.bk-text');
    if (t) { clearTimeout(timers[t.closest('.bk').dataset.id]); saveBlock(t.closest('.bk')); }
  }, true);

  /* --------------------------------------------------------- click actions */
  wrap.addEventListener('click', function (ev) {
    var add = ev.target.closest('.bk-add');
    if (add) { addBlockAfter(add.closest('.bk'), 'paragraph'); return; }

    var chk = ev.target.closest('.bk-check');
    if (chk) {
      var b = chk.closest('.bk');
      var te = b.querySelector('.bk-text');
      if (te) {
        te.style.opacity = chk.checked ? '.45' : '';
        te.style.textDecoration = chk.checked ? 'line-through' : '';
      }
      api('block_props', { id: parseInt(b.dataset.id, 10), props: { checked: chk.checked } });
      return;
    }

    var arrow = ev.target.closest('.bk-arrow');
    if (arrow) {
      var tg = arrow.closest('.bk-toggle');
      tg.classList.toggle('open');
      arrow.textContent = tg.classList.contains('open') ? '▾' : '▸';
      api('block_props', {
        id: parseInt(arrow.closest('.bk').dataset.id, 10),
        props: { open: tg.classList.contains('open') }
      });
    }
  });

  var addBtn = document.getElementById('addblock');
  if (addBtn) addBtn.addEventListener('click', function () {
    addBlockAfter(wrap.lastElementChild, 'paragraph');
  });

  /* ------------------------------------------------------------------ drag */
  var dragEl = null;
  wrap.addEventListener('dragstart', function (ev) {
    var h = ev.target.closest('.bk-drag');
    if (!h) { ev.preventDefault(); return; }
    dragEl = h.closest('.bk');
    dragEl.classList.add('dragging');
    ev.dataTransfer.effectAllowed = 'move';
    try { ev.dataTransfer.setData('text/plain', dragEl.dataset.id); } catch (e) {}
  });
  wrap.addEventListener('dragover', function (ev) {
    if (!dragEl) return;
    ev.preventDefault();
    var over = ev.target.closest('.bk');
    if (!over || over === dragEl) return;
    var r = over.getBoundingClientRect();
    if (ev.clientY < r.top + r.height / 2) over.before(dragEl);
    else over.after(dragEl);
  });
  wrap.addEventListener('dragend', function () {
    if (!dragEl) return;
    dragEl.classList.remove('dragging');
    dragEl = null;
    renumber();
    var order = Array.prototype.map.call(wrap.children, function (el) { return parseInt(el.dataset.id, 10); });
    api('block_reorder', { order: order });
  });

  /* ----------------------------------------------------- icon/cover pickers */
  function toggle(btnId, panelId) {
    var b = document.getElementById(btnId), p = document.getElementById(panelId);
    if (!b || !p) return;
    b.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var r = b.getBoundingClientRect();
      p.style.left = r.left + 'px';
      p.style.top = (r.bottom + window.scrollY + 6) + 'px';
      p.hidden = !p.hidden;
    });
    document.addEventListener('click', function (ev) {
      if (!p.hidden && !p.contains(ev.target) && ev.target !== b) p.hidden = true;
    });
  }
  toggle('btn-icon', 'iconpicker');
  toggle('btn-cover', 'coverpicker');

  document.addEventListener('click', function (ev) {
    var em = ev.target.closest('[data-em]');
    if (!em) return;
    var f = document.getElementById('iconform');
    var v = document.getElementById('iconval');
    if (f && v) { v.value = em.dataset.em; f.submit(); }
  });

  /* title autosave (submit on blur / Enter) */
  var titleInput = document.querySelector('.page-title');
  if (titleInput) {
    titleInput.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter') { ev.preventDefault(); titleInput.blur(); }
    });
    var orig = titleInput.value;
    titleInput.addEventListener('blur', function () {
      if (titleInput.value !== orig) document.getElementById('titleform').submit();
    });
  }
})();

/* =============================================================================
   Database view helpers — inline cell editing (table view).
   ========================================================================== */
(function () {
  'use strict';
  function save(row, key, value) {
    return fetch('api.php?action=value_set', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ csrf: window.CSRF, row_id: row, key: key, value: value })
    });
  }

  document.addEventListener('blur', function (ev) {
    var c = ev.target.closest && ev.target.closest('.db-cell');
    if (c) save(c.dataset.row, c.dataset.key, c.innerText.trim());
  }, true);

  document.addEventListener('change', function (ev) {
    var s = ev.target.closest('.db-editselect, .db-editinput');
    if (s) { save(s.dataset.row, s.dataset.key, s.value); return; }
    var c = ev.target.closest('.db-editcheck');
    if (c) save(c.dataset.row, c.dataset.key, c.checked ? '1' : '');
  });

  document.addEventListener('keydown', function (ev) {
    var c = ev.target.closest && ev.target.closest('.db-cell');
    if (c && ev.key === 'Enter') { ev.preventDefault(); c.blur(); }
  });

  // "+ Property" panel
  var addProp = document.getElementById('addprop');
  var panel = document.getElementById('proppicker');
  if (addProp && panel) {
    addProp.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var r = addProp.getBoundingClientRect();
      panel.style.left = Math.min(r.left, window.innerWidth - 300) + 'px';
      panel.style.top = (r.bottom + window.scrollY + 6) + 'px';
      panel.hidden = !panel.hidden;
    });
    document.addEventListener('click', function (ev) {
      if (!panel.hidden && !panel.contains(ev.target) && ev.target !== addProp) panel.hidden = true;
    });
    var typeSel = document.getElementById('proptype');
    if (typeSel) {
      // Only show the fields that belong to the chosen property type.
      var groups = {
        propopts:    ['select', 'multi'],
        proprel:     ['relation'],
        proprollup:  ['rollup'],
        propformula: ['formula']
      };
      var sync = function () {
        Object.keys(groups).forEach(function (id) {
          var el = document.getElementById(id);
          if (el) el.style.display = groups[id].indexOf(typeSel.value) !== -1 ? '' : 'none';
        });
      };
      typeSel.addEventListener('change', sync);
      sync();
    }
  }

  /* panel toggles: move page, add view, configure view */
  function panelToggle(btnId, panelId, width) {
    var b = document.getElementById(btnId), p = document.getElementById(panelId);
    if (!b || !p) return;
    b.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var r = b.getBoundingClientRect();
      p.style.left = Math.max(8, Math.min(r.left, window.innerWidth - (width || 300))) + 'px';
      p.style.top = (r.bottom + window.scrollY + 6) + 'px';
      p.hidden = !p.hidden;
    });
    document.addEventListener('click', function (ev) {
      if (!p.hidden && !p.contains(ev.target) && ev.target !== b) p.hidden = true;
    });
  }
  panelToggle('btn-move', 'movepicker', 300);
  panelToggle('addview', 'viewpicker', 300);
  panelToggle('editview', 'viewconfig', 290);

  // Submitting the "+ New row" input
  document.addEventListener('keydown', function (ev) {
    var i = ev.target.closest && ev.target.closest('.db-newinput');
    if (i && ev.key === 'Enter') { ev.preventDefault(); if (i.value.trim()) i.form.submit(); }
  });
})();
