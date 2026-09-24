/* ============================================================
 *  Que Chilero Radio — Service Worker (PWA instalable)
 * ============================================================
 *  Estrategias:
 *   - Navegación (página): red primero, caché como respaldo
 *   - Estáticos (css/js/img/manifest): stale-while-revalidate
 *   - APIs (api/*) y stream de audio: SIEMPRE red (datos vivos)
 *  v0.1.4 — Notificaciones push (Web Push + VAPID):
 *   - push: aviso de programa (o anuncio manual) con regla
 *     anti-spam: si el oyente ya está escuchando, se silencia
 *   - notificationclick: abrir/re-enfocar la web y reproducir;
 *     botón «Silenciar avisos» = baja en 1 toque
 *   - pushsubscriptionchange: re-suscripción automática
 * ============================================================ */

'use strict';

var CACHE_NAME = 'qcr-static-v8';

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

  // Nota: el NowPlaying y las portadas llegan vía api/* (proxys del
  // propio hosting) y ya quedan excluidos por la regla de api/ de
  // arriba: siempre red, nunca caché.

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

// ════════════════════════════════════════════════════════════
//  v0.1.4 — Notificaciones push (Web Push + VAPID)
// ════════════════════════════════════════════════════════════

/**
 * ¿Hay alguna pestaña/PWA abierta con la radio SONANDO?
 * Pregunta a cada cliente por MessageChannel con un tope de 700 ms;
 * si nadie responde a tiempo se muestra la notificación (fallo
 * hacia «avisar»: perder un aviso es peor que duplicar un silencio).
 */
function anyClientPlaying() {
  return new Promise(function (resolve) {
    var settled = false;
    function finish(value) {
      if (!settled) { settled = true; resolve(value); }
    }
    var timer = setTimeout(function () { finish(false); }, 700);

    self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(function (list) {
        if (!list || !list.length) { clearTimeout(timer); finish(false); return; }
        var pending = list.length;
        list.forEach(function (client) {
          var channel = new MessageChannel();
          channel.port1.onmessage = function (ev) {
            if (ev.data && ev.data.type === 'qcr-status' && ev.data.playing) {
              finish(true);
            }
            pending -= 1;
            if (pending <= 0) { clearTimeout(timer); finish(false); }
          };
          try {
            client.postMessage({ type: 'qcr-status-query' }, [channel.port2]);
          } catch (e) {
            pending -= 1;
            if (pending <= 0) { clearTimeout(timer); finish(false); }
          }
        });
      })
      .catch(function () { clearTimeout(timer); finish(false); });
  });
}

self.addEventListener('push', function (event) {
  var data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { body: event.data ? event.data.text() : '' };
  }

  var options = {
    body: data.body || '',
    icon: './assets/img/icon-192.png',
    badge: './assets/img/favicon-32.png',
    // Mismo tag → cada aviso reemplaza al anterior (nunca se apilan)
    tag: data.tag || 'qcr-aviso',
    data: { url: data.url || './' },
    requireInteraction: false,
    // Baja en 1 toque (donde el sistema soporte acciones)
    actions: [{ action: 'mute', title: 'Silenciar avisos' }],
  };

  event.waitUntil(
    anyClientPlaying().then(function (playing) {
      // Regla anti-spam: ya está escuchando → silencio
      if (playing) return;
      return self.registration.showNotification(data.title || 'Que Chilero Radio', options);
    })
  );
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();

  if (event.action === 'mute') {
    // Baja en 1 toque: borra la suscripción en el servidor y en el navegador
    event.waitUntil(muteFromNotification());
    return;
  }

  // Cuerpo de la notificación: abrir la web/app y arrancar la radio
  // (si el navegador bloquea el arranque automático, la web queda
  // lista con el botón de play — comportamiento degradado suave)
  event.waitUntil(openAndPlay((event.notification.data || {}).url || './'));
});

function openAndPlay(url) {
  return self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    var sameOrigin = (list || []).filter(function (c) {
      return c.url && c.url.indexOf(self.location.origin) === 0;
    });
    if (sameOrigin.length) {
      // Ya hay una ventana: enfocarla y reproducir sin recargar
      try { sameOrigin[0].postMessage({ type: 'qcr-autoplay' }); } catch (e) { /* sin severidad */ }
      return sameOrigin[0].focus();
    }
    // Sin ventana abierta: abrir con autoplay en la URL
    var sep = url.indexOf('?') >= 0 ? '&' : '?';
    return self.clients.openWindow(url + sep + 'autoplay=1');
  });
}

function muteFromNotification() {
  return self.registration.pushManager.getSubscription().then(function (subscription) {
    var jobs = [];
    if (subscription) {
      jobs.push(
        fetch('./api/push-subscribe.php', {
          method: 'DELETE',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ endpoint: subscription.endpoint }),
        }).catch(function () { /* el servidor lo limpiará por edad */ })
      );
      jobs.push(subscription.unsubscribe().catch(function () {}));
    }
    return Promise.all(jobs);
  });
}

/** Re-suscripción automática cuando el servicio push rota la suscripción. */
self.addEventListener('pushsubscriptionchange', function (event) {
  event.waitUntil(
    fetch('./api/push-config.php')
      .then(function (r) { return r.json(); })
      .then(function (cfg) {
        if (!cfg || !cfg.publicKey) return;
        return self.registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlB64ToUint8Array(cfg.publicKey),
        });
      })
      .then(function (subscription) {
        if (!subscription) return;
        return fetch('./api/push-subscribe.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            endpoint: subscription.endpoint,
            keys: subscription.toJSON().keys || {},
            prefs: { mode: 'all' },
          }),
        });
      })
      .catch(function () { /* se resuscribirá en la próxima visita */ })
  );
});

function urlB64ToUint8Array(base64String) {
  var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  var raw = atob(base64);
  var output = new Uint8Array(raw.length);
  for (var i = 0; i < raw.length; i += 1) {
    output[i] = raw.charCodeAt(i);
  }
  return output;
}
