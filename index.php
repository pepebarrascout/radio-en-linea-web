<?php
/**
 * ============================================================
 *  Que Chilero Radio — Página principal (PHP + JS vanilla)
 * ============================================================
 *  Migrada desde React a PHP + JavaScript vanilla:
 *   - Mismo diseño, colores, tipografías e imagen gráfica
 *   - Editable directamente desde cPanel (no requiere Node)
 *   - PWA instalable (manifest + service worker)
 *   - Portadas del historial + votos 👍/👎 (api/vote.php)
 *
 *  La lógica dinámica vive en assets/js/app.js
 * ============================================================
 */
?>
<!doctype html>
<html lang="es">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Que Chilero Radio - En Vivo</title>
    <meta name="description" content="Que Chilero Radio — Tu música, tu onda. Radio en vivo 24/7." />

    <!-- PWA -->
    <link rel="manifest" href="manifest.webmanifest" />
    <meta name="theme-color" content="#000080" />
    <meta name="mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="default" />
    <meta name="apple-mobile-web-app-title" content="Que Chilero" />
    <link rel="icon" type="image/svg+xml" href="assets/img/favicon.svg" />
    <link rel="icon" type="image/png" sizes="32x32" href="assets/img/favicon-32.png" />
    <link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png" />

    <!-- Tema guardado ANTES de pintar (evita destello) -->
    <script>
      (function () {
        try {
          var t = localStorage.getItem('qcr-theme');
          if (t !== 'light' && t !== 'dark') t = 'light';
          document.documentElement.classList.add(t);
          document.documentElement.setAttribute('data-theme', t);
        } catch (e) {
          document.documentElement.classList.add('light');
        }
      })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
      html, body { margin: 0; padding: 0; width: 100%; min-height: 100%; }
      html.light, html.light body { background-color: #ffffff; color: #1a1a1a; }
      html.dark, html.dark body { background-color: #0a0a1a; color: #ffffff; }
    </style>
    <link rel="stylesheet" href="assets/css/app.css" />
  </head>
  <body>
    <div id="app" class="min-h-screen theme-transition app-root">
      <!-- Elemento de audio (stream en vivo) -->
      <audio id="audio" src="https://quechilero.com/radio" preload="none"></audio>

      <!-- ═══════════ Header ═══════════ -->
      <header class="app-header sticky top-0 z-50 backdrop-blur-md border-b theme-transition">
        <div class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between">
          <div class="flex items-center gap-3">
            <img src="assets/img/icon-192.png" alt="Que Chilero Radio" class="w-12 h-12 rounded-full shadow-lg" />
            <div>
              <h1 class="text-xl font-bold font-['Poppins'] text-brand">Que Chilero Radio</h1>
              <p class="text-xs text-subtle">Tu música, tu onda 🎶</p>
            </div>
          </div>

          <button id="theme-toggle" class="theme-toggle p-2.5 rounded-full transition-all duration-300 hover:scale-110" aria-label="Cambiar tema">
            <i id="theme-toggle-icon" class="fas fa-moon text-lg"></i>
          </button>
        </div>
      </header>

      <main class="max-w-7xl mx-auto px-4 py-6 space-y-8">
        <!-- ═══════════ Hero / Reproductor ═══════════ -->
        <section class="player-card rounded-2xl p-6 md:p-8 theme-transition">
          <div class="flex flex-col md:flex-row items-center gap-6 md:gap-8">
            <div class="relative">
              <div class="w-48 h-48 md:w-56 md:h-56 rounded-2xl overflow-hidden shadow-2xl">
                <img
                  id="player-cover"
                  alt="Cargando..."
                  class="w-full h-full object-cover"
                />
              </div>
              <div id="live-badge" class="hidden absolute -top-2 -right-2 flex items-center gap-1 bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full">
                <span class="w-2 h-2 bg-white rounded-full animate-pulse-live"></span>
                EN VIVO
              </div>
            </div>

            <div class="flex-1 text-center md:text-left">
              <p class="text-sm font-medium mb-1 text-brand-accent">
                <i class="fas fa-broadcast-tower mr-1"></i>
                Sonando ahora
              </p>

              <div id="player-loading" class="flex items-center gap-2">
                <div class="spinner w-5 h-5 border-2 border-t-transparent rounded-full animate-spin"></div>
                <p class="text-subtle">Cargando...</p>
              </div>

              <div id="player-loaded" class="hidden">
                <h2 id="player-title" class="text-2xl md:text-3xl font-bold font-['Poppins'] mb-1"></h2>
                <p id="player-artist" class="text-lg text-muted"></p>
                <p id="player-album" class="hidden text-sm mt-1 text-subtle">
                  <i class="fas fa-compact-disc mr-1"></i>
                  <span id="player-album-text"></span>
                </p>
                <!-- Píldoras en fila flex (no inline): el espaciado bajo
                     ellas queda determinista y la barra de progreso
                     puede centrarse de verdad entre esta fila y los
                     botones de voto -->
                <div class="mt-2 flex flex-wrap items-center justify-center md:justify-start gap-2">
                  <p id="player-genre" class="hidden text-sm inline-flex items-center gap-1 px-2 py-0.5 rounded-full pill">
                    <i class="fas fa-music text-xs"></i>
                    <span id="player-genre-text"></span>
                  </p>
                  <p id="player-duration" class="hidden text-sm inline-flex items-center gap-1 px-2 py-0.5 rounded-full pill">
                    <i class="fas fa-clock text-xs"></i>
                    <span id="player-duration-text"></span>
                  </p>
                </div>

                <!-- Progreso ilustrativo de la canción (solo la barra:
                     el total ya se muestra en la píldora de duración).
                     mt-4 = mismo margen que aplican los botones de voto
                     (mt-4): la barra queda centrada entre las píldoras
                     de género/duración y los botones me gusta / no me
                     gusta. -->
                <div id="song-progress" class="hidden mt-4">
                  <div class="song-progress-track">
                    <div id="song-progress-fill" class="song-progress-fill"></div>
                  </div>
                </div>

                <!-- Votos de la canción actual (sin conteos: no son públicos) -->
                <div id="player-votes" class="mt-4 flex items-center justify-center md:justify-start gap-2">
                  <button id="player-vote-like" data-vote="like" class="vote-btn vote-btn-inactive px-3 py-2 text-sm" aria-label="Me gusta">
                    <i class="far fa-thumbs-up"></i>
                  </button>
                  <button id="player-vote-dislike" data-vote="dislike" class="vote-btn vote-btn-inactive px-3 py-2 text-sm" aria-label="No me gusta">
                    <i class="far fa-thumbs-down"></i>
                  </button>
                </div>
              </div>

              <!-- Play Button + Waveform decorativa -->
              <div class="mt-6 flex items-center justify-center md:justify-start gap-4">
                <button id="play-btn" class="play-btn w-16 h-16 rounded-full flex items-center justify-center text-white text-2xl transition-all duration-300 hover:scale-105 shadow-lg" aria-label="Reproducir radio en vivo">
                  <i id="play-icon" class="fas fa-play"></i>
                </button>
                <!-- Waveform decorativa: se anima al reproducir y queda
                     plana al pausar (JS genera las barras; CSS las anima) -->
                <div id="waveform" class="waveform" aria-hidden="true"></div>
              </div>
            </div>
          </div>
        </section>

        <!-- ═══════════ Últimas canciones ═══════════ -->
        <section id="history-section" class="hidden">
          <h3 class="text-xl font-bold font-['Poppins'] mb-4 flex items-center gap-2 text-brand">
            <i class="fas fa-history"></i>
            Últimas canciones
          </h3>
          <div class="list-card rounded-2xl overflow-hidden border theme-transition">
            <div id="history-rows" class="divide-y divide-gray-200/50"></div>
          </div>
        </section>

        <!-- ═══════════ Programación semanal ═══════════ -->
        <section>
          <h3 class="text-xl font-bold font-['Poppins'] mb-4 flex items-center gap-2 text-brand">
            <i class="fas fa-calendar-alt"></i>
            Programación semanal
          </h3>

          <div id="day-tabs" class="flex overflow-x-auto gap-1 mb-4 pb-2 scrollbar-hide"></div>

          <div class="list-card rounded-2xl overflow-hidden border theme-transition">
            <div id="schedule-empty" class="hidden p-8 text-center text-faint">
              <i class="fas fa-calendar-times text-3xl mb-2"></i>
              <p id="schedule-empty-text">Cargando programación...</p>
            </div>
            <div id="schedule-rows" class="divide-y divide-gray-200/30 hidden"></div>
          </div>

          <!-- ═══════════ Avisos de programas (push, opt-in voluntario) ═══════════ -->
          <div id="push-block" class="push-block theme-transition">
            <button id="push-toggle" class="push-btn" type="button" aria-pressed="false">
              <i class="fas fa-bell mr-1 text-xs"></i><span id="push-toggle-text">Activar avisos de programas</span>
            </button>
            <div id="push-chooser" class="hidden push-chooser">
              <p class="text-xs text-subtle mb-2">
                Te avisamos <strong>10 minutos antes</strong> de cada programa. ¿Cuáles quieres recibir?
              </p>
              <label class="push-option">
                <input type="radio" name="qcr-push-mode" value="all" checked>
                <span>Todos los programas</span>
              </label>
              <label class="push-option">
                <input type="radio" name="qcr-push-mode" value="evening">
                <span>Solo tarde y noche (desde las 14:00)</span>
              </label>
              <div class="flex items-center gap-2 mt-3">
                <button id="push-accept" class="push-btn push-btn-accept" type="button">
                  <i class="fas fa-check mr-1 text-xs"></i>Activar
                </button>
                <button id="push-cancel" class="push-btn-ghost" type="button">Cancelar</button>
              </div>
            </div>
            <p id="push-hint" class="hidden text-xs text-faint mt-2"></p>
          </div>
        </section>

        <!-- ═══════════ Imagen del estudio ═══════════ -->
        <section class="studio-section rounded-2xl overflow-hidden relative">
          <div class="relative h-48 md:h-64">
            <img
              src="https://images.unsplash.com/photo-1598488035139-bdbb2231ce04?w=1200&h=400&fit=crop"
              alt="Radio studio"
              class="w-full h-full object-cover"
            />
            <div class="studio-overlay absolute inset-0"></div>
            <div class="absolute bottom-4 left-4 right-4">
              <p class="text-lg font-bold font-['Poppins'] text-brand">
                🎙️ Transmitiendo desde nuestro estudio
              </p>
              <p class="text-sm text-muted">
                Las 24 horas del día, los 7 días de la semana
              </p>
            </div>
          </div>
        </section>
      </main>

      <!-- ═══════════ Footer ═══════════ -->
      <footer class="app-footer mt-8 border-t theme-transition">
        <div class="max-w-7xl mx-auto px-4 py-6">
          <div class="flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-3">
              <img src="assets/img/icon-192.png" alt="Que Chilero Radio" class="w-8 h-8 rounded-full" />
              <span class="font-semibold font-['Poppins'] text-brand">Que Chilero Radio</span>
            </div>
            <div class="flex items-center gap-4">
              <a href="#" class="social-link transition-colors" aria-label="Facebook"><i class="fab fa-facebook text-xl"></i></a>
              <a href="#" class="social-link transition-colors" aria-label="Instagram"><i class="fab fa-instagram text-xl"></i></a>
              <a href="#" class="social-link transition-colors" aria-label="Twitter"><i class="fab fa-twitter text-xl"></i></a>
              <a href="#" class="social-link transition-colors" aria-label="YouTube"><i class="fab fa-youtube text-xl"></i></a>
            </div>
            <p class="text-sm text-faint">
              © <span id="footer-year"></span> Que Chilero Radio.
            </p>
          </div>
        </div>
      </footer>
    </div>

    <!-- ═══════════ Banner de instalación de la app (PWA) ═══════════ -->
    <div id="install-banner" class="hidden install-banner" role="dialog" aria-label="Instalar aplicación">
      <div class="flex items-start gap-3">
        <img src="assets/img/icon-192.png" alt="" class="w-10 h-10 rounded-xl flex-shrink-0 shadow-lg" />
        <div class="flex-1 min-w-0">
          <p class="font-semibold text-sm text-brand">Instalar Que Chilero Radio</p>
          <p id="install-banner-text" class="text-xs text-subtle mt-1">
            Lleva la radio a tu pantalla de inicio, como una app.
          </p>
          <div class="flex items-center gap-2 mt-2">
            <button id="install-accept" class="install-btn" type="button">
              <i class="fas fa-download mr-1 text-xs"></i>Instalar app
            </button>
            <button id="install-dismiss" class="install-btn-ghost" type="button">Ahora no</button>
          </div>
        </div>
        <button id="install-close" class="install-close" type="button" aria-label="Cerrar">✕</button>
      </div>
    </div>

    <script src="assets/js/app.js" defer></script>
    <script defer src="https://stats.blogsdeguatemala.com/script.js" data-website-id="b844201b-c1e8-4e88-9b67-20a6109583ce"></script>
  </body>
</html>
