/* ==================================================================
   ASVIORA GAMES — service worker
   ------------------------------------------------------------------
   Deliberately conservative. The site is database-driven, so pages are
   never served stale: HTML always goes to the network first and only
   falls back to the cache when the phone is offline. Static assets
   (CSS, JS, images, fonts) are served from the cache and refreshed in
   the background.

   Bump CACHE_VERSION after a big asset change to force a clean sweep.
   ================================================================== */

const CACHE_VERSION = 'asviora-v1';
const SHELL_CACHE   = CACHE_VERSION + '-shell';
const ASSET_CACHE   = CACHE_VERSION + '-assets';
const PAGE_CACHE    = CACHE_VERSION + '-pages';

// scope-relative, so this works whether the site sits at the domain root
// or in an XAMPP subfolder
const BASE = new URL('./', self.registration.scope).pathname;

const SHELL = [
  BASE,
  BASE + 'index.php',
  BASE + 'assets/css/style.css',
  BASE + 'assets/js/main.js',
  BASE + 'assets/img/icon-192.png',
  BASE + 'assets/img/icon-512.png',
  BASE + 'offline.php'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE)
      // addAll rejects the whole batch if one file 404s — add individually
      .then((c) => Promise.all(SHELL.map((u) => c.add(u).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => !k.startsWith(CACHE_VERSION)).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') self.skipWaiting();
});

const isAsset = (url) =>
  /\.(css|js|png|jpe?g|webp|gif|svg|ico|woff2?|ttf)$/i.test(url.pathname);

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;          // let the CDN/fonts be
  if (url.pathname.includes('/admin/')) return;             // never cache the panel
  if (url.pathname.includes('/api/'))   return;             // never cache API calls
  if (url.pathname.endsWith('/logout.php')) return;

  // ---- static assets: cache first, refresh in the background ----
  if (isAsset(url)) {
    event.respondWith(
      caches.open(ASSET_CACHE).then((cache) =>
        cache.match(req).then((hit) => {
          const net = fetch(req)
            .then((res) => {
              if (res && res.ok) cache.put(req, res.clone());
              return res;
            })
            .catch(() => hit);
          return hit || net;
        })
      )
    );
    return;
  }

  // ---- pages: network first, cache as a fallback for offline ----
  if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
    event.respondWith(
      fetch(req)
        .then((res) => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(PAGE_CACHE).then((c) => c.put(req, copy));
          }
          return res;
        })
        .catch(() =>
          caches.match(req)
            .then((hit) => hit || caches.match(BASE + 'offline.php'))
            .then((hit) => hit || caches.match(BASE + 'index.php'))
            .then((hit) => hit || new Response(
              '<h1>You are offline</h1><p>Reconnect and try again.</p>',
              { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
            ))
        )
    );
  }
});
