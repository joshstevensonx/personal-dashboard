/* Browser-side OCR using tesseract.js.
 *
 * The server can't run the Tesseract binary (no SSH / shared hosting), so OCR
 * happens here in the page. The image never leaves the machine; only the
 * extracted text is posted back and indexed for search.
 *
 * tesseract.js is loaded lazily from a CDN the first time OCR is used, so the
 * rest of the app stays dependency-free and fast.
 */
(function () {
  'use strict';
  var status = document.getElementById('ocr-status');
  var buttons = document.querySelectorAll('.ocr-btn');
  if (!buttons.length) return;

  var CDN = 'https://cdnjs.cloudflare.com/ajax/libs/tesseract.js/5.1.0/tesseract.min.js';
  var loading = null;

  function say(msg) { if (status) status.textContent = msg; }

  function loadTesseract() {
    if (window.Tesseract) return Promise.resolve();
    if (loading) return loading;
    say('Loading OCR engine (first run only, ~2 MB)…');
    loading = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = CDN;
      s.onload = resolve;
      s.onerror = function () { reject(new Error('Could not load the OCR engine. Check your connection.')); };
      document.head.appendChild(s);
    });
    return loading;
  }

  function save(id, text) {
    var form = document.getElementById('ocr-form');
    document.getElementById('ocr-id').value = id;
    document.getElementById('ocr-text').value = text;
    var body = new URLSearchParams(new FormData(form));
    body.set('ajax', '1');
    return fetch('export.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    });
  }

  Array.prototype.forEach.call(buttons, function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.dataset.id, src = btn.dataset.src;
      btn.disabled = true;
      var original = btn.textContent;
      btn.textContent = 'Working…';

      loadTesseract()
        .then(function () {
          say('Recognising text…');
          return window.Tesseract.recognize(src, 'eng', {
            logger: function (m) {
              if (m.status === 'recognizing text') {
                say('Recognising text… ' + Math.round((m.progress || 0) * 100) + '%');
              }
            }
          });
        })
        .then(function (res) {
          var text = ((res && res.data && res.data.text) || '').trim();
          if (!text) { say('No readable text found in that image.'); btn.disabled = false; btn.textContent = original; return; }
          say('Found ' + text.length + ' characters — saving…');
          return save(id, text).then(function () {
            say('Saved and indexed. Reloading…');
            setTimeout(function () { location.reload(); }, 700);
          });
        })
        .catch(function (err) {
          say(err && err.message ? err.message : 'OCR failed.');
          btn.disabled = false;
          btn.textContent = original;
        });
    });
  });
})();
