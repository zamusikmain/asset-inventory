// Service Worker: кэш оболочки PWA (Cache API). Данные кампаний — в IndexedDB (pwa.js).
const CACHE = 'inv-shell-v2';
const SHELL = ['/m', '/offline', '/css/app.css', '/css/pwa.css', '/js/app.js', '/js/pwa.js', '/manifest.webmanifest',
  '/icons/icon.svg', '/icons/icon-192.png', '/icons/icon-512.png'];
const OPTIONAL = ['/js/vendor/zxing.min.js'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(async c => {
    await c.addAll(SHELL);
    for (const u of OPTIONAL) { try { await c.add(u); } catch (err) { /* необязательный файл */ } }
  }).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin) return;
  // API — только сеть (offline-логика в приложении)
  if (url.pathname.startsWith('/api/')) return;
  // /m — сначала сеть (актуальная сессия), при отсутствии сети — кэш
  if (url.pathname === '/m') {
    e.respondWith(fetch(e.request).then(r => {
      if (r.ok && !r.redirected) { const copy = r.clone(); caches.open(CACHE).then(c => c.put('/m', copy)); }
      return r;
    }).catch(() => caches.match('/m')));
    return;
  }
  // Оболочка: сначала сеть (обновления приходят сразу), без сети — кэш
  if (SHELL.includes(url.pathname) || OPTIONAL.includes(url.pathname)) {
    e.respondWith(fetch(e.request).then(r => {
      if (r.ok) { const copy = r.clone(); caches.open(CACHE).then(c => c.put(url.pathname, copy)); }
      return r;
    }).catch(() => caches.match(url.pathname)));
    return;
  }
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request).catch(() => caches.match('/offline')));
  }
});
