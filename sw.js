/* Orrville C&MA - service worker.
   Purpose: lets the installed app open offline (shows the Sunday times/address page) and
   re-opens pages the visitor has already seen. It never serves old content while online:
   every request goes to the network first and only falls back to the saved copy if that fails.
   When you publish changes you do not need to touch this file (the browser re-checks it by itself);
   change VERSION only if you want every visitor's saved copies cleared. */
const VERSION = 'cma-v1-2026-10-30';
const CACHE = VERSION;
const OFFLINE_URL = 'offline.html';
const PRECACHE = [OFFLINE_URL, 'images/orrville-cma-logo-transparent.png', 'favicon.ico'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;                 // YouTube, Google, etc. go straight to the network
  if (/\.(php|mp4)$/i.test(url.pathname) || req.headers.has('range')) return; // live feeds and video: never intercept

  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res && res.ok && res.type === 'basic' && !res.redirected) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
        }
        return res;
      })
      .catch(() =>
        caches.match(req, { ignoreSearch: true }).then((hit) => {
          if (hit) return hit;
          if (req.mode === 'navigate') return caches.match(OFFLINE_URL);
          return Response.error();
        })
      )
  );
});
