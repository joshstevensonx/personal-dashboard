/* Kanban drag-and-drop. Saves each move via a background POST. */
(function () {
  'use strict';
  var board = document.getElementById('board');
  if (!board) return;

  var dragged = null;

  function csrf() {
    var el = document.querySelector('input[name="csrf"]');
    return el ? el.value : '';
  }

  board.addEventListener('dragstart', function (ev) {
    var card = ev.target.closest('.kcard');
    if (!card) return;
    dragged = card;
    card.classList.add('dragging');
    ev.dataTransfer.effectAllowed = 'move';
    try { ev.dataTransfer.setData('text/plain', card.dataset.id); } catch (e) {}
  });

  board.addEventListener('dragend', function () {
    if (dragged) dragged.classList.remove('dragging');
    Array.prototype.forEach.call(board.querySelectorAll('.dropzone'),
      function (z) { z.classList.remove('over'); });
    dragged = null;
  });

  board.addEventListener('dragover', function (ev) {
    var zone = ev.target.closest('.dropzone');
    if (!zone) return;
    ev.preventDefault();
    zone.classList.add('over');
    ev.dataTransfer.dropEffect = 'move';
  });

  board.addEventListener('dragleave', function (ev) {
    var zone = ev.target.closest('.dropzone');
    if (zone) zone.classList.remove('over');
  });

  board.addEventListener('drop', function (ev) {
    var zone = ev.target.closest('.dropzone');
    if (!zone || !dragged) return;
    ev.preventDefault();
    zone.classList.remove('over');

    // Insert before the card we dropped onto, otherwise append.
    var target = ev.target.closest('.kcard');
    if (target && target !== dragged) zone.insertBefore(dragged, target);
    else zone.appendChild(dragged);

    var position = Array.prototype.indexOf.call(zone.children, dragged);
    var body = new URLSearchParams();
    body.set('csrf', csrf());
    body.set('action', 'move');
    body.set('id', dragged.dataset.id);
    body.set('column_id', zone.dataset.col);
    body.set('position', position);
    body.set('ajax', '1');

    fetch('tasks.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (r) {
      if (!r.ok && r.status !== 204) location.reload();
    }).catch(function () { /* offline: the DOM already reflects the move */ });
  });
})();
