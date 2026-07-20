/* Service worker — offline shell for the Personal Dashboard PWA.
 *
 * Strategy:
 *  - Static assets (css/js/icons/manifest): cache-first, refreshed in background.
 *  - PHP pages: network-first, falling back to cache, then to an offline notice.
 *  - Never caches POST requests or the API.
 */
var VERSION = 'pd-v1';
var STATIC_CACHE = VERSION + '-static';
var PAGE_CACHE = VERSION + '-pages';

var PRECACHE = [
  'assets/style.css',
  'assets/app.js',
  'manifest.webmanifest'
];

self.addEventListener('install', function (ev) {
  ev.waitUntil(
    caches.open(STATIC_CACHE)
      .then(function (c) { return c.addAll(PRECACHE); })
      .then(function () { return self.skipWaiting(); })
      .catch(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (ev) {
  ev.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.map(function (k) {
        if (k.indexOf(VERSION) !== 0) return caches.delete(k);
      }));
    }).then(function () { return self.clients.claim(); })
  );
});

function isStatic(url) {
  return /\.(css|js|png|svg|webmanifest|woff2?)$/i.test(url.pathname);
}

self.addEventListener('fetch', function (ev) {
  var req = ev.request;
  if (req.method !== 'GET') return;                       // never cache writes
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) return;        // third-party: passthrough
  if (url.pathname.indexOf('api.php') !== -1) return;     // always live
  if (url.pathname.indexOf('health.php') !== -1) return;

  if (isStatic(url)) {
    ev.respondWith(
      caches.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) {
          if (res && res.ok) {
            var copy = res.clone();
            caches.open(STATIC_CACHE).then(function (c) { c.put(req, copy); });
          }
          return res;
        }).catch(function () { return hit; });
        return hit || net;
      })
    );
    return;
  }

  // Pages: network first so data is fresh; cached copy when offline.
  ev.respondWith(
    fetch(req).then(function (res) {
      if (res && res.ok) {
        var copy = res.clone();
        caches.open(PAGE_CACHE).then(function (c) { c.put(req, copy); });
      }
      return res;
    }).catch(function () {
      return caches.match(req).then(function (hit) {
        return hit || new Response(
          '<!doctype html><meta charset="utf-8"><title>Offline</title>' +
          '<div style="font:16px/1.6 system-ui;max-width:520px;margin:15vh auto;padding:0 20px;text-align:center">' +
          '<h1>You are offline</h1><p>This page has not been cached yet. ' +
          'Reconnect and try again.</p></div>',
          { headers: { 'Content-Type': 'text/html; charset=UTF-8' }, status: 503 }
        );
      });
    })
  );
});
