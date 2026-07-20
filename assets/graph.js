/* Force-directed note graph on a canvas. No dependencies. */
(function () {
  'use strict';
  var cv = document.getElementById('graph');
  if (!cv || !window.GRAPH) return;
  var ctx = cv.getContext('2d');
  var N = window.GRAPH.nodes.slice();
  var E = window.GRAPH.edges.slice();
  if (!N.length) {
    ctx.font = '15px system-ui';
    ctx.fillStyle = getComputedStyle(document.body).getPropertyValue('--muted');
    ctx.fillText('No notes yet — create some and link them with [[Title]].', 24, 40);
    return;
  }

  var css = getComputedStyle(document.documentElement);
  var accent = (css.getPropertyValue('--accent') || '#6ea8fe').trim();
  var lineCol = (css.getPropertyValue('--line') || '#2a2f3a').trim();
  var textCol = (css.getPropertyValue('--text') || '#e7e9ee').trim();

  var W = 0, H = 0, dpr = window.devicePixelRatio || 1;
  function resize() {
    W = cv.clientWidth; H = cv.clientHeight;
    cv.width = W * dpr; cv.height = H * dpr;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  resize();
  window.addEventListener('resize', function () { resize(); draw(); });

  var byId = {};
  N.forEach(function (n, i) {
    var a = (i / N.length) * Math.PI * 2;
    n.x = W / 2 + Math.cos(a) * Math.min(W, H) * 0.3 + (Math.random() - 0.5) * 30;
    n.y = H / 2 + Math.sin(a) * Math.min(W, H) * 0.3 + (Math.random() - 0.5) * 30;
    n.vx = 0; n.vy = 0;
    n.r = 4 + Math.min(9, n.deg * 1.6);
    byId[n.id] = n;
  });
  E = E.filter(function (e) { return byId[e.s] && byId[e.t]; });

  var view = { x: 0, y: 0, k: 1 };
  var alpha = 1;

  function step() {
    // repulsion
    for (var i = 0; i < N.length; i++) {
      for (var j = i + 1; j < N.length; j++) {
        var a = N[i], b = N[j];
        var dx = b.x - a.x, dy = b.y - a.y;
        var d2 = dx * dx + dy * dy || 0.01;
        var d = Math.sqrt(d2);
        var f = 900 / d2;
        var fx = (dx / d) * f, fy = (dy / d) * f;
        a.vx -= fx; a.vy -= fy; b.vx += fx; b.vy += fy;
      }
    }
    // springs
    E.forEach(function (e) {
      var a = byId[e.s], b = byId[e.t];
      var dx = b.x - a.x, dy = b.y - a.y;
      var d = Math.sqrt(dx * dx + dy * dy) || 0.01;
      var f = (d - 90) * 0.008;
      var fx = (dx / d) * f, fy = (dy / d) * f;
      a.vx += fx; a.vy += fy; b.vx -= fx; b.vy -= fy;
    });
    // centre pull + integrate
    N.forEach(function (n) {
      n.vx += (W / 2 - n.x) * 0.0015;
      n.vy += (H / 2 - n.y) * 0.0015;
      n.vx *= 0.82; n.vy *= 0.82;
      n.x += n.vx * alpha; n.y += n.vy * alpha;
    });
    alpha *= 0.994;
  }

  function draw() {
    ctx.clearRect(0, 0, W, H);
    ctx.save();
    ctx.translate(view.x, view.y);
    ctx.scale(view.k, view.k);

    ctx.strokeStyle = lineCol;
    ctx.lineWidth = 1;
    ctx.beginPath();
    E.forEach(function (e) {
      var a = byId[e.s], b = byId[e.t];
      ctx.moveTo(a.x, a.y); ctx.lineTo(b.x, b.y);
    });
    ctx.stroke();

    N.forEach(function (n) {
      ctx.beginPath();
      ctx.arc(n.x, n.y, n.r, 0, Math.PI * 2);
      ctx.fillStyle = n.deg ? accent : lineCol;
      ctx.fill();
      if (view.k > 0.55) {
        ctx.fillStyle = textCol;
        ctx.font = '11px system-ui';
        ctx.textAlign = 'center';
        var label = n.title.length > 22 ? n.title.slice(0, 21) + '…' : n.title;
        ctx.fillText(label, n.x, n.y - n.r - 5);
      }
    });
    ctx.restore();
  }

  var frames = 0;
  (function loop() {
    if (frames++ < 600 && alpha > 0.005) step();
    draw();
    requestAnimationFrame(loop);
  })();

  /* interaction */
  var dragging = false, last = null, moved = 0;
  cv.addEventListener('mousedown', function (ev) { dragging = true; moved = 0; last = { x: ev.clientX, y: ev.clientY }; cv.style.cursor = 'grabbing'; });
  window.addEventListener('mouseup', function () { dragging = false; cv.style.cursor = 'grab'; });
  window.addEventListener('mousemove', function (ev) {
    if (!dragging || !last) return;
    var dx = ev.clientX - last.x, dy = ev.clientY - last.y;
    moved += Math.abs(dx) + Math.abs(dy);
    view.x += dx; view.y += dy;
    last = { x: ev.clientX, y: ev.clientY };
  });
  cv.addEventListener('wheel', function (ev) {
    ev.preventDefault();
    var f = ev.deltaY < 0 ? 1.1 : 0.9;
    view.k = Math.max(0.2, Math.min(4, view.k * f));
  }, { passive: false });

  cv.addEventListener('click', function (ev) {
    if (moved > 6) return;
    var r = cv.getBoundingClientRect();
    var mx = (ev.clientX - r.left - view.x) / view.k;
    var my = (ev.clientY - r.top - view.y) / view.k;
    var hit = null;
    N.forEach(function (n) {
      var dx = n.x - mx, dy = n.y - my;
      if (dx * dx + dy * dy <= (n.r + 6) * (n.r + 6)) hit = n;
    });
    if (hit) window.location.href = 'notes.php?id=' + hit.id;
  });
})();
