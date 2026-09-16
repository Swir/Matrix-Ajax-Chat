const CACHE = 'matrix-chat-v7-static';
const STATIC = [
  './assets/app.css',
  './assets/app.js',
  './assets/matrix-chat.svg',
  './assets/icon-192.png',
  './assets/icon-512.png',
  './manifest.webmanifest'
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(STATIC)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;
  const url = new URL(event.request.url);
  if (!STATIC.some(path => url.pathname.endsWith(path.replace('./', '/')))) return;
  event.respondWith(caches.match(event.request).then(hit => hit || fetch(event.request)));
});
