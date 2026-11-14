/**
 * CivicFix v3.0 — Smart-City Disaster-Resilient PWA Service Worker (sw.js)
 * Purges legacy v2 caches and ensures failed image/tile requests do NOT return index.html,
 * allowing Leaflet's tileerror handler to substitute procedural Ludhiana GIS Canvas tiles cleanly.
 */

const CACHE_NAME = 'civicfix-v31-osm-esri-cache';
const OFFLINE_ASSETS = [
  './',
  './index.html',
  './css/style.css',
  './js/leaflet_offline.js',
  './js/map.js',
  './js/app.js',
  './loogo.jpeg'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(OFFLINE_ASSETS)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;

  const acceptHeader = event.request.headers.get('accept') || '';
  const isHtmlNav = event.request.mode === 'navigate' || acceptHeader.includes('text/html');

  event.respondWith(
    fetch(event.request)
      .then(response => {
        if (response && response.status === 200 && (event.request.url.startsWith(self.location.origin) || event.request.url.includes('unpkg.com'))) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone)).catch(() => {});
        }
        return response;
      })
      .catch(() =>
        caches.match(event.request).then(cached => {
          if (cached) return cached;
          if (isHtmlNav) return caches.match('./index.html');
          return new Response('', { status: 503, statusText: 'Offline' });
        })
      )
  );
});
