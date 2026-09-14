/* ============================================================
 *  Que Chilero Radio — Service Worker (PWA instalable)
 * ============================================================
 *  Estrategias:
 *   - Navegación (página): red primero, caché como respaldo
 *   - Estáticos (css/js/img/manifest): stale-while-revalidate
 *   - APIs (api/*) y stream de audio: SIEMPRE red (datos vivos)
 * ============================================================ */

'use strict';

var CACHE_NAME = 'qcr-static-v4';

var PRECACHE = [
  './',
  './assets/css/app.css',
  './assets/js/app.js',
  './manifest.webmanifest',
  './assets/img/favicon.svg',
  './assets/img/favicon-16.png',
  './assets/img/favicon-32.png',
  './assets/img/icon-192.png',
  './assets/img/icon-512.png',
  './assets/img/icon-maskable-192.png',
  './assets/img/icon-maskable-512.png',
  './assets/img/apple-touch-icon.png',
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(function (cache) { return cache.addAll(PRECACHE); })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys()
      .then(function (names) {
        return Promise.all(
          names.filter(function (name) { return name !== CACHE_NAME; })
            .map(function (name) { return caches.delete(name); })
        );
      })
      .then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  var request = event.request;
  var url = new URL(request.url);

  // ⛔ No interceptar peticiones que no son GET
  if (request.method !== 'GET') return;

  // ⛔ No interceptar el stream de audio (el elemento <audio> lo gestiona)
  if (request.destination === 'audio' || request.headers.has('range')) return;

  // ⛔ API: siempre red (datos en vivo, nunca cachear)
  if (url.pathname.includes('/api/')) return;

  // ⛔ Programación: siempre red (el usuario puede editarla a mano;
  //    que se vea el cambio al recargar o a los pocos minutos)
  if (url.pathname.includes('programacion.json')) return;

  // ⛔ Artwork de Jellyfin: siempre red (cambia con cada canción)
  if (url.hostname.includes('jellyfin')) return;

  // Navegación: red primero, caché de respaldo (offline)
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then(function (response) {
          var copy = response.clone();
          caches.open(CACHE_NAME).then(function (cache) { cache.put('./', copy); });
          return response;
        })
        .catch(function () {
          return caches.match('./').then(function (cached) {
            return cached || caches.match(request);
          });
        })
    );
    return;
  }

  // Estáticos (mismo origen y CDNs de fuentes): stale-while-revalidate
  event.respondWith(
    caches.open(CACHE_NAME).then(function (cache) {
      return cache.match(request).then(function (cached) {
        var network = fetch(request)
          .then(function (response) {
            if (response && (response.ok || response.type === 'opaque')) {
              cache.put(request, response.clone());
            }
            return response;
          })
          .catch(function () { return cached; });
        return cached || network;
      });
    })
  );
});
