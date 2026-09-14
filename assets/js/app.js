/* ============================================================
 *  Que Chilero Radio — app.js (JS vanilla, sin frameworks)
 * ============================================================
 *  Migrado desde React manteniendo el comportamiento 1:1:
 *   - Polling de NowPlaying cada 10s + respaldo idempotente
 *     del historial (el cron del servidor es el principal)
 *   - Programación semanal (recarga cada 30 min)
 *   - Tema claro/oscuro persistente
 *   - Portadas del historial (caché local del servidor)
 *   - Votos 👍/👎 (1 voto activo por visitante y canción,
 *     cambiable; cookie anónima de 1 año gestionada por el
 *     servidor en api/vote.php)
 *   - PWA: service worker + MediaSession (portada en pantalla
 *     de bloqueo)
 * ============================================================ */
(function () {
  'use strict';

  // ── Configuración ──────────────────────────────────────────
  var NOW_PLAYING_URL = 'https://jellyfin.blogsdeguatemala.com/RadioOnline/NowPlaying';
  var SCHEDULE_URL = './programacion.json';
  var RADIO_STREAM_URL = 'https://quechilero.com/radio';
  var HISTORY_API_URL = './api/history.php';
  var VOTES_API_URL = './api/vote.php';
  var ARTWORK_FALLBACK_URL = 'https://jellyfin.blogsdeguatemala.com/RadioOnline/NowPlaying/Artwork';
  var PLACEHOLDER_TITLE = 'Esperando transmisión...';

  var DEFAULT_NOW_PLAYING = {
    isPlaying: false,
    artist: 'Que Chilero Radio',
    title: PLACEHOLDER_TITLE,
    album: '',
    genre: '',
    year: '',
    duration: '',
    artworkUrl: '',
  };

  // ── Programación: días, iconos y utilidades ────────────────
  var dayOrder = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
  var dayIcons = ['🌙', '🎵', '🎶', '🎧', '🎉', '🌟', '☀️'];

  function getCurrentDayIndex() {
    var day = new Date().getDay(); // 0=Dom … 6=Sáb
    return day === 0 ? 6 : day - 1; // 0=Lun … 6=Dom
  }

  function isTimeSlotActive(horaInicio, horaFin) {
    var now = new Date();
    var currentMinutes = now.getHours() * 60 + now.getMinutes();

    var partsS = horaInicio.split(':');
    var partsE = horaFin.split(':');
    var startMinutes = parseInt(partsS[0], 10) * 60 + parseInt(partsS[1], 10);
    var endMinutes = parseInt(partsE[0], 10) * 60 + parseInt(partsE[1], 10);

    if (endMinutes === 0) endMinutes = 24 * 60;

    if (endMinutes <= startMinutes) {
      endMinutes += 24 * 60;
      if (currentMinutes < startMinutes) {
        return (currentMinutes + 24 * 60) >= startMinutes && (currentMinutes + 24 * 60) < endMinutes;
      }
    }
    return currentMinutes >= startMinutes && currentMinutes < endMinutes;
  }

  function getProgramIcon(programa) {
    var lower = (programa || '').toLowerCase();
    if (lower.includes('noche') || lower.includes('nocturn')) return '🌙';
    if (lower.includes('madrugada') || lower.includes('amanecer') || lower.includes('despertar')) return '🌅';
    if (lower.includes('mañana') || lower.includes('buenos días')) return '☀️';
    if (lower.includes('tarde')) return '🌇';
    if (lower.includes('rock')) return '🎸';
    if (lower.includes('deporte')) return '⚽';
    if (lower.includes('cultura') || lower.includes('cine') || lower.includes('películ')) return '🎬';
    if (lower.includes('familia')) return '👨‍👩‍👧‍👦';
    if (lower.includes('fiesta') || lower.includes('celebración')) return '🎊';
    if (lower.includes('relaj') || lower.includes('tranquil') || lower.includes('somnífero')) return '😴';
    if (lower.includes('energ') || lower.includes('motivación')) return '⚡';
    if (lower.includes('espiritual') || lower.includes('reflexión') || lower.includes('religios')) return '🙏';
    if (lower.includes('histo') || lower.includes('cuento') || lower.includes('leyenda')) return '📖';
    if (lower.includes('receta') || lower.includes('hogar') || lower.includes('casa')) return '🏠';
    if (lower.includes('romántic') || lower.includes('balada')) return '💕';
    if (lower.includes('reggaeton') || lower.includes('urbana') || lower.includes('latina')) return '💃';
    if (lower.includes('jazz')) return '🎷';
    if (lower.includes('clásic')) return '🎻';
    if (lower.includes('conversación') || lower.includes('entrevista')) return '🎙️';
    if (lower.includes('noticia')) return '📰';
    if (lower.includes('juego') || lower.includes('concurso') || lower.includes('sorpres')) return '🎲';
    if (lower.includes('internacional') || lower.includes('pop')) return '🌍';
    return '🎵';
  }

  // ── Utilidades ─────────────────────────────────────────────
  // Marcadores de posición: imagen de marca (el chile) en vez de
  // un genérico. Son archivos locales ya precacheados por el SW.
  var PLACEHOLDER_IMG = 'assets/img/icon-512.png';

  var PLACEHOLDER_IMG_SMALL = 'assets/img/icon-192.png';

  function esc(text) {
    return String(text == null ? '' : text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function $(id) { return document.getElementById(id); }

  function pairOf(artist, title) {
    return String(artist || '').trim().toLowerCase() + '||' + String(title || '').trim().toLowerCase();
  }

  // ── Estado ─────────────────────────────────────────────────
  var state = {
    theme: (document.documentElement.classList.contains('dark') ? 'dark' : 'light'),
    nowPlaying: DEFAULT_NOW_PLAYING,
    schedule: [],
    scheduleLoaded: false,
    activeDay: getCurrentDayIndex(),
    isPlaying: false,
    isLoading: true,
    history: [],
    artworkKey: '',
    artworkSrc: '',
  };

  // Votos: SOLO el estado del visitante (los conteos NO son públicos)
  var votes = {
    myVoteByPair: {}, // "artista||título" → 'like' | 'dislike'
  };

  // ── Persistencia local del estado de voto ────────────────
  // Al recargar o reabrir el navegador la indicación "ya voté"
  // debe seguir ahí SIN esperar al servidor. La copia en
  // localStorage se muestra al instante y se sincroniza en
  // segundo plano con la verdad del servidor (cookie anónima).
  var MY_VOTES_LS_KEY = 'qcr-myvotes';
  var MY_VOTES_LS_LIMIT = 500;   // misma cota que MAX_VOTES_PER_VOTER

  /** Guarda/elimina UN voto local (v = 'like'|'dislike'|null). */
  function markVoteLocal(pair, v) {
    try {
      var obj = null;
      var raw = localStorage.getItem(MY_VOTES_LS_KEY);
      if (raw) { var parsed = JSON.parse(raw); if (parsed && typeof parsed === 'object') obj = parsed; }
      if (!obj) obj = {};
      if (v === 'like' || v === 'dislike') {
        obj[pair] = { v: v, t: Date.now() };
      } else {
        delete obj[pair];
      }
      // Acotar: si hay demasiadas canciones, conservar las más recientes
      var keys = Object.keys(obj);
      if (keys.length > MY_VOTES_LS_LIMIT) {
        keys.sort(function (a, b) { return (obj[b].t || 0) - (obj[a].t || 0); });
        keys.slice(MY_VOTES_LS_LIMIT).forEach(function (k) { delete obj[k]; });
      }
      localStorage.setItem(MY_VOTES_LS_KEY, JSON.stringify(obj));
    } catch (e) { /* almacenamiento privado o lleno */ }
  }

  /** Carga los votos recordados (pinta la marca sin esperar red). */
  function loadMyVotesLocal() {
    try {
      var raw = localStorage.getItem(MY_VOTES_LS_KEY);
      if (!raw) return;
      var obj = JSON.parse(raw);
      if (!obj || typeof obj !== 'object') return;
      Object.keys(obj).forEach(function (pair) {
        var v = obj[pair] && obj[pair].v;
        if (v === 'like' || v === 'dislike') votes.myVoteByPair[pair] = v;
      });
    } catch (e) { /* corrupto o privado: se ignora */ }
  }

  var lastSongTitleRef = '';

  // ── Tema ───────────────────────────────────────────────────
  function applyTheme() {
    var root = document.documentElement;
    root.classList.remove('light', 'dark');
    root.classList.add(state.theme);
    root.setAttribute('data-theme', state.theme);

    var icon = $('theme-toggle-icon');
    if (icon) {
      // oscuro → sol (volver a claro), claro → luna
      icon.className = 'fas ' + (state.theme === 'dark' ? 'fa-sun' : 'fa-moon') + ' text-lg';
    }
    try { localStorage.setItem('qcr-theme', state.theme); } catch (e) { /* privado */ }
  }

  // ── Portada del reproductor ────────────────────────────────
  function buildPlayerArtworkUrl(np, cacheKey) {
    var url = (np && np.artworkUrl ? String(np.artworkUrl) : '').trim();
    if (!url) url = ARTWORK_FALLBACK_URL;
    url = url.replace(/^http:\/\//i, 'https://');
    try {
      var u = new URL(url);
      u.searchParams.set('maxWidth', '720');
      // ⭐ La URL del artwork de Jellyfin es SIEMPRE la misma (devuelve la
      // portada de la canción actual): sin este cache-busting el navegador
      // mostraría la portada de la canción anterior desde su caché.
      u.searchParams.set('v', cacheKey || String(Date.now()));
      url = u.href;
    } catch (e) { /* URL relativa o inválida: se usa tal cual */ }
    return url;
  }

  // ── Votos ──────────────────────────────────────────────────
  /**
   * Sincroniza el estado propio con la respuesta del servidor
   * (GET api/vote.php → { myVotes } según la cookie anónima).
   * REEMPLAZO COMPLETO: si Jellyfin consumió el ciclo semanal,
   * aquí se limpia también la marca local (todos pueden revotar).
   */
  function ingestMyVotes(data) {
    var myVotes = (data && data.myVotes) || {};
    votes.myVoteByPair = {};
    Object.keys(myVotes).forEach(function (pair) {
      if (myVotes[pair] === 'like' || myVotes[pair] === 'dislike') {
        votes.myVoteByPair[pair] = myVotes[pair];
      }
    });
    // Copiar el estado sincronizado a la persistencia local
    try {
      var obj = {};
      Object.keys(votes.myVoteByPair).forEach(function (pair) {
        obj[pair] = { v: votes.myVoteByPair[pair], t: Date.now() };
      });
      localStorage.setItem(MY_VOTES_LS_KEY, JSON.stringify(obj));
    } catch (e) { /* privado */ }
  }

  function loadVotes() {
    return fetch(VOTES_API_URL, { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('votes HTTP ' + r.status); return r.json(); })
      .then(function (data) {
        ingestMyVotes(data);   // ← fuente de verdad: mi cookie en el servidor
        renderPlayerVotes();
        renderHistory();
      })
      .catch(function (err) { console.error('Error cargando votos:', err); });
  }

  function castVote(artist, title, action, itemId) {
    var pair = pairOf(artist, title);
    var prev = votes.myVoteByPair[pair] || null;

    // Actualización optimista del estado propio (persistida al instante:
    // si el usuario recarga antes de la respuesta del servidor, la marca
    // del voto sigue visible)
    if (action === 'remove') delete votes.myVoteByPair[pair];
    else votes.myVoteByPair[pair] = action;
    markVoteLocal(pair, action === 'remove' ? null : action);
    renderPlayerVotes();
    renderHistory();

    fetch(VOTES_API_URL, {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        artist: artist,
        title: title,
        vote: action,
        itemId: itemId || '',
      }),
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
      .then(function (res) {
        if (!res.ok || !res.json.success) throw new Error(res.json.error || 'vote error');
        var mv = res.json.myVote;
        if (mv === 'like' || mv === 'dislike') votes.myVoteByPair[pair] = mv;
        else delete votes.myVoteByPair[pair];
        markVoteLocal(pair, mv);   // confirmado por el servidor
        renderPlayerVotes();
        renderHistory();
      })
      .catch(function (err) {
        console.error('Error votando:', err);
        // Revertir al estado anterior
        if (prev) votes.myVoteByPair[pair] = prev;
        else delete votes.myVoteByPair[pair];
        markVoteLocal(pair, prev);
        renderPlayerVotes();
        renderHistory();
      });
  }

  function voteButtonsHtml(artist, title, itemId, size) {
    var mv = votes.myVoteByPair[pairOf(artist, title)] || null;
    var cls = size === 'xs' ? 'px-2 py-1 text-xs' : 'px-3 py-2 text-sm';
    // SIN números de votos: no son públicos. Solo el estado propio.
    var itemAttr = itemId ? ' data-itemid="' + esc(itemId) + '"' : '';

    var likeClass = 'vote-btn ' + (mv === 'like' ? 'vote-btn-like' : 'vote-btn-inactive') + ' ' + cls;
    var dislikeClass = 'vote-btn ' + (mv === 'dislike' ? 'vote-btn-dislike' : 'vote-btn-inactive') + ' ' + cls;
    var likeIcon = (mv === 'like' ? 'fas' : 'far') + ' fa-thumbs-up';
    var dislikeIcon = (mv === 'dislike' ? 'fas' : 'far') + ' fa-thumbs-down';

    return '' +
      '<button type="button" class="' + likeClass + '" data-vote="like"' +
      ' data-artist="' + esc(artist) + '" data-title="' + esc(title) + '"' + itemAttr + ' aria-label="Me gusta">' +
      '<i class="' + likeIcon + '"></i></button>' +
      '<button type="button" class="' + dislikeClass + '" data-vote="dislike"' +
      ' data-artist="' + esc(artist) + '" data-title="' + esc(title) + '"' + itemAttr + ' aria-label="No me gusta">' +
      '<i class="' + dislikeIcon + '"></i></button>';
  }

  function renderPlayerVotes() {
    var np = state.nowPlaying;
    var realSong = np && np.title && np.title !== PLACEHOLDER_TITLE && np.artist;
    var box = $('player-votes');
    if (!box) return;
    if (!realSong) {
      box.classList.add('hidden');
      return;
    }
    box.classList.remove('hidden');

    ['like', 'dislike'].forEach(function (kind) {
      var btn = $('player-vote-' + kind);
      if (!btn) return;
      btn.setAttribute('data-artist', np.artist);
      btn.setAttribute('data-title', np.title);
      if (np.itemId) btn.setAttribute('data-itemid', np.itemId);
      else btn.removeAttribute('data-itemid');

      var mv = votes.myVoteByPair[pairOf(np.artist, np.title)] || null;

      btn.className = 'vote-btn ' + (mv === kind ? (kind === 'like' ? 'vote-btn-like' : 'vote-btn-dislike') : 'vote-btn-inactive') + ' px-3 py-2 text-sm';
      // SIN números: solo el icono con el estado propio
      btn.innerHTML = '<i class="' + (mv === kind ? 'fas' : 'far') + ' fa-thumbs-' + (kind === 'like' ? 'up' : 'down') + '"></i>';
    });
  }

  // ── Historial ──────────────────────────────────────────────
  /** ¿Es esta fila la canción que suena AHORA? */
  function songMatchesNowPlaying(song) {
    var np = state.nowPlaying;
    if (!np || !np.title || np.title === PLACEHOLDER_TITLE || !np.artist) return false;
    if (song.itemId && np.itemId) return song.itemId === np.itemId;
    return song.title === np.title && song.artist === np.artist;
  }

  /** ¿Es esta fila la canción que suena AHORA? */
  function songMatchesNowPlaying(song) {
    var np = state.nowPlaying;
    if (!np || !np.title || np.title === PLACEHOLDER_TITLE || !np.artist) return false;
    if (song.itemId && np.itemId) return song.itemId === np.itemId;
    return song.title === np.title && song.artist === np.artist;
  }

  function coverHtmlFor(song) {
    var src = '';
    if (song.cover) {
      // Portada en caché del servidor (descargada por el cron)
      src = song.cover;
    } else if (songMatchesNowPlaying(song) && state.artworkSrc) {
      // ⭐ Portada INMEDIATA para la canción actual: es la MISMA imagen
      // que el reproductor ya descargó de Jellyfin (está en la caché
      // del navegador) — no hay que esperar al cron del servidor.
      src = state.artworkSrc;
    }
    if (src) {
      return '<img src="' + esc(src) + '" alt="" loading="lazy"' +
        ' class="w-10 h-10 md:w-12 md:h-12 rounded-lg object-cover flex-shrink-0 shadow-sm cover-thumb"' +
        ' onerror="this.onerror=null;this.src=\'' + PLACEHOLDER_IMG_SMALL + '\'">';
    }
    return '<div class="cover-thumb w-10 h-10 md:w-12 md:h-12 rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm">' +
      '<i class="fas fa-music text-white text-xs opacity-80"></i></div>';
  }

  function renderHistory() {
    var section = $('history-section');
    var rows = $('history-rows');
    if (!section || !rows) return;

    if (!state.history.length) {
      section.classList.add('hidden');
      rows.innerHTML = '';
      return;
    }
    section.classList.remove('hidden');

    rows.innerHTML = state.history.map(function (song) {
      var pills = '';
      if (song.genre) {
        pills += '<span class="text-xs px-2 py-0.5 rounded-full hidden md:inline-block pill-soft">' + esc(song.genre) + '</span>';
      }
      if (song.duration) {
        pills += '<span class="text-xs px-2 py-0.5 rounded-full hidden md:inline pill-soft"><i class="fas fa-clock mr-1 text-[10px]"></i>' + esc(song.duration) + '</span>';
      }
      return '' +
        '<div class="row flex items-center gap-4 p-3 md:p-4 transition-colors">' +
        // La hora se oculta en móvil: da más espacio al nombre de la canción
        '<span class="hidden md:inline-block text-sm font-mono w-14 text-center flex-shrink-0 text-faint">' + esc(song.time) + '</span>' +
        coverHtmlFor(song) +
        '<div class="flex-1 min-w-0">' +
        '<p class="font-medium truncate">' + esc(song.title) + '</p>' +
        '<p class="text-sm truncate text-subtle">' + esc(song.artist) + '</p>' +
        '</div>' +
        '<div class="flex items-center gap-2 flex-shrink-0">' +
        pills +
        voteButtonsHtml(song.artist, song.title, song.itemId, 'xs') +
        '</div>' +
        '</div>';
    }).join('');
  }

  var lastHistoryJson = '';

  function loadHistory() {
    return fetch(HISTORY_API_URL, { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('historial HTTP ' + r.status); return r.json(); })
      .then(function (data) {
        if (data.history && Array.isArray(data.history)) {
          // Evitar re-pintar (y perder toques en botones) si no cambió nada
          var json = JSON.stringify(data.history);
          if (json === lastHistoryJson) return;
          lastHistoryJson = json;

          state.history = data.history;
          if (data.history.length > 0) {
            lastSongTitleRef = data.history[0].title;
          }
          renderHistory();
          loadVotes(); // refresca mi estado de votos de las canciones visibles
        }
      })
      .catch(function (err) { console.error('Error cargando historial:', err); });
  }

  // Respaldo idempotente del navegador (el cron es el principal)
  function saveSongToServer(song) {
    return fetch(HISTORY_API_URL, {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(song),
    }).catch(function (err) { console.error('Error respaldando canción:', err); });
  }

  // ── Reproductor / Now Playing ──────────────────────────────
  function showPlayerLoading(loading) {
    var loadingBox = $('player-loading');
    var loadedBox = $('player-loaded');
    if (!loadingBox || !loadedBox) return;
    if (loading) {
      loadingBox.classList.remove('hidden');
      loadedBox.classList.add('hidden');
    } else {
      loadingBox.classList.add('hidden');
      loadedBox.classList.remove('hidden');
    }
  }

  function updateMediaSession(np, coverUrl) {
    if (!('mediaSession' in navigator)) return;
    try {
      navigator.mediaSession.metadata = new window.MediaMetadata({
        title: np.title || 'Que Chilero Radio',
        artist: np.artist || '',
        album: np.album || 'Que Chilero Radio',
        artwork: [{ src: coverUrl || PLACEHOLDER_IMG, sizes: '720x720', type: 'image/jpeg' }],
      });
    } catch (e) { /* sin soporte */ }
  }

  function renderPlayer(np) {
    state.nowPlaying = np;

    // Portada: reconstruida SOLO cuando cambia la canción (sin parpadeos).
    // La clave incluye título/artista: al cambiar, el parámetro v= del URL
    // cambia y el navegador descarga la portada nueva (no la cacheada).
    var key = (np.title || '') + '|' + (np.artist || '') + '|' + (np.itemId || '');
    if (key !== state.artworkKey) {
      state.artworkKey = key;
      state.artworkSrc = buildPlayerArtworkUrl(np, key);
      var img = $('player-cover');
      if (img) {
        img.onerror = function () {
          img.onerror = null;
          img.src = PLACEHOLDER_IMG;
        };
        img.src = state.artworkSrc;
        img.alt = (np.title || '') + ' - ' + (np.artist || '');
      }
      updateMediaSession(np, state.artworkSrc);
    }

    // Indicador EN VIVO
    var live = $('live-badge');
    if (live) live.classList.toggle('hidden', !np.isPlaying);

    var realSong = np.title && np.title !== PLACEHOLDER_TITLE && np.artist;

    if (realSong) {
      $('player-title').textContent = np.title;
      $('player-artist').textContent = np.artist;

      var albumRow = $('player-album');
      if (np.album) {
        $('player-album-text').textContent = np.album + (np.year ? ' (' + np.year + ')' : '');
        albumRow.classList.remove('hidden');
      } else {
        albumRow.classList.add('hidden');
      }

      var genreRow = $('player-genre');
      if (np.genre) {
        $('player-genre-text').textContent = np.genre;
        genreRow.classList.remove('hidden');
      } else {
        genreRow.classList.add('hidden');
      }

      var durationRow = $('player-duration');
      if (np.duration) {
        $('player-duration-text').textContent = np.duration;
        durationRow.classList.remove('hidden');
      } else {
        durationRow.classList.add('hidden');
      }
    } else {
      $('player-title').textContent = np.title || DEFAULT_NOW_PLAYING.title;
      $('player-artist').textContent = np.artist || DEFAULT_NOW_PLAYING.artist;
      $('player-album').classList.add('hidden');
      $('player-genre').classList.add('hidden');
      $('player-duration').classList.add('hidden');
    }

    renderPlayerVotes();
  }

  function fetchNowPlaying() {
    return fetch(NOW_PLAYING_URL, { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('nowplaying HTTP ' + r.status); return r.json(); })
      .then(function (data) {
        // RESPALDO idempotente: registrar en el servidor solo si el
        // cron aún no lo hizo (history.php deduplica; nunca duplica)
        if (
          data.isPlaying && data.title && data.title !== PLACEHOLDER_TITLE &&
          data.artist && lastSongTitleRef !== data.title
        ) {
          saveSongToServer({
            title: data.title,
            artist: data.artist,
            itemId: data.itemId || '',
            duration: data.duration,
            genre: data.genre,
            artworkUrl: data.artworkUrl || '',   // ⭐ el servidor baja la portada AL INSTANTE (sin esperar al cron)
          }).then(loadHistory);
        }

        lastSongTitleRef = data.title;
        renderPlayer(data);
      })
      .catch(function (err) {
        console.error('Error consultando NowPlaying:', err);
      })
      .then(function () {
        state.isLoading = false;
        showPlayerLoading(false);
      });
  }

  // ── Programación ───────────────────────────────────────────
  function renderDayTabs() {
    var box = $('day-tabs');
    if (!box) return;
    box.innerHTML = dayOrder.map(function (day, index) {
      var cls = 'tab-btn flex-shrink-0 px-4 py-2.5 rounded-xl text-sm font-medium transition-all duration-200' +
        (state.activeDay === index ? ' tab-selected' : '');
      return '<button type="button" class="' + cls + '" data-day="' + index + '">' +
        '<span class="mr-1">' + dayIcons[index] + '</span>' + esc(day) + '</button>';
    }).join('');
  }

  var lastScheduleKey = '';

  function renderSchedule(force) {
    var empty = $('schedule-empty');
    var emptyText = $('schedule-empty-text');
    var rowsBox = $('schedule-rows');
    if (!empty || !rowsBox) return;

    var daySchedule = state.schedule.filter(function (item) {
      return item.dia === dayOrder[state.activeDay];
    });

    // ¿Cambió el bloque activo (o el día/cantidad)? Si no, no re-pintar.
    // Esto hace que el badge AHORA salte solo al programa nuevo cada minuto.
    var activeSlots = '';
    if (state.activeDay === getCurrentDayIndex()) {
      daySchedule.forEach(function (item) {
        if (isTimeSlotActive(item.hora_inicio, item.hora_fin)) {
          activeSlots += item.hora_inicio + ',';
        }
      });
    }
    var sk = state.activeDay + '|' + (state.scheduleLoaded ? state.schedule.length : -1) + '|' + activeSlots;
    if (!force && sk === lastScheduleKey) return;
    lastScheduleKey = sk;

    if (daySchedule.length === 0) {
      empty.classList.remove('hidden');
      rowsBox.classList.add('hidden');
      rowsBox.innerHTML = '';
      if (emptyText) {
        emptyText.textContent = state.scheduleLoaded && state.schedule.length === 0
          ? 'No hay programación para este día'
          : (state.scheduleLoaded
            ? 'No hay programación para este día'
            : 'Cargando programación...');
      }
      return;
    }

    empty.classList.add('hidden');
    rowsBox.classList.remove('hidden');

    rowsBox.innerHTML = daySchedule.map(function (item) {
      var timeRange = item.hora_inicio + ' - ' + item.hora_fin;
      var isActive = state.activeDay === getCurrentDayIndex() && isTimeSlotActive(item.hora_inicio, item.hora_fin);
      var icon = getProgramIcon(item.programa);
      var descripcion = item['descripción'] || item.descripcion || '';

      var rowClass = isActive ? 'program-active' : 'row';
      var titleClass = isActive ? 'font-semibold text-brand-accent' : 'font-semibold';
      var timeClass = isActive
        ? 'text-sm font-mono flex-shrink-0 text-right text-brand-accent font-semibold'
        : 'text-sm font-mono flex-shrink-0 text-right text-faint';

      return '' +
        '<div class="' + rowClass + ' flex items-start gap-4 p-4 md:p-5 transition-all duration-300 border-l-4 border-l-transparent">' +
        '<div class="text-2xl flex-shrink-0 mt-0.5">' + icon + '</div>' +
        '<div class="flex-1 min-w-0">' +
        '<div class="flex items-center gap-2 flex-wrap">' +
        '<h4 class="' + titleClass + '">' + esc(item.programa) + '</h4>' +
        (isActive
          ? '<span class="flex items-center gap-1 text-xs font-bold text-red-500 bg-red-500/10 px-2 py-0.5 rounded-full">' +
            '<span class="w-1.5 h-1.5 bg-red-500 rounded-full animate-pulse-live"></span>AHORA</span>'
          : '') +
        '</div>' +
        '<p class="text-sm mt-1 text-subtle">' + esc(descripcion) + '</p>' +
        '</div>' +
        '<div class="' + timeClass + '"><i class="fas fa-clock mr-1 text-xs"></i>' + esc(timeRange) + '</div>' +
        '</div>';
    }).join('');
  }

  function loadSchedule() {
    // Cache-busting: si el usuario edita programacion.json a mano, el
    // cambio se ve al recargar o a los pocos minutos (también el service
    // worker deja de cachear este archivo).
    return fetch(SCHEDULE_URL + (SCHEDULE_URL.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now(), { cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error('programación HTTP ' + r.status); return r.json(); })
      .then(function (data) {
        var next = data.programacion_radio || [];
        var changed = JSON.stringify(next) !== JSON.stringify(state.schedule);
        state.schedule = next;
        state.scheduleLoaded = true;
        renderSchedule(changed);
      })
      .catch(function (err) {
        console.error('Error cargando programación:', err);
        state.scheduleLoaded = true;
        renderSchedule();
      });
  }

  // ── Reproducción ───────────────────────────────────────────
  var audio = null;

  function togglePlay() {
    if (!audio) return;
    if (state.isPlaying) {
      audio.pause();
      state.isPlaying = false;
    } else {
      audio.play().then(function () {
        state.isPlaying = true;
      }).catch(function (err) {
        console.error('Error reproduciendo:', err);
        state.isPlaying = false;
      });
    }
    updatePlayButton();
  }

  function updatePlayButton() {
    var btn = $('play-btn');
    var icon = $('play-icon');
    if (!btn || !icon) return;
    btn.classList.toggle('playing', state.isPlaying);
    icon.className = 'fas ' + (state.isPlaying ? 'fa-pause' : 'fa-play');
  }

  // ── Eventos globales ───────────────────────────────────────
  function bindEvents() {
    // Tema
    var themeBtn = $('theme-toggle');
    if (themeBtn) {
      themeBtn.addEventListener('click', function () {
        state.theme = state.theme === 'light' ? 'dark' : 'light';
        applyTheme();
      });
    }

    // Play / pausa
    var playBtn = $('play-btn');
    if (playBtn) playBtn.addEventListener('click', togglePlay);

    // Pestañas de días
    var tabs = $('day-tabs');
    if (tabs) {
      tabs.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-day]');
        if (!btn) return;
        state.activeDay = parseInt(btn.getAttribute('data-day'), 10);
        renderDayTabs();
        renderSchedule(true);
      });
    }

    // Votos (delegación: reproductor + filas del historial)
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-vote]');
      if (!btn) return;
      var artist = btn.getAttribute('data-artist') || '';
      var title = btn.getAttribute('data-title') || '';
      if (!artist || !title) return;
      var kind = btn.getAttribute('data-vote'); // like | dislike
      var current = votes.myVoteByPair[pairOf(artist, title)] || null;
      var action = current === kind ? 'remove' : kind; // clic repetido = anular
      castVote(artist, title, action, btn.getAttribute('data-itemid') || '');
    });

    // Audio
    if (audio) {
      audio.addEventListener('pause', function () { state.isPlaying = false; updatePlayButton(); });
      audio.addEventListener('playing', function () { state.isPlaying = true; updatePlayButton(); });
    }
  }

  // ── Service Worker (PWA) ───────────────────────────────────
  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') return;
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('./sw.js').catch(function (err) {
        console.error('Error registrando el service worker:', err);
      });
    });
  }

  // ── Instalación de la app (PWA) ──────────────────────────
  var installPromptEvent = null;

  function isIosDevice() {
    return /iphone|ipad|ipod/i.test(navigator.userAgent) ||
      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }

  function isStandalone() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
      window.navigator.standalone === true;
  }

  function showInstallBanner(mode) {
    if (isStandalone()) return;   // ya está instalada
    try {
      if (localStorage.getItem('qcr-install-dismissed') === '1') return;
    } catch (e) { /* privado */ }

    var banner = $('install-banner');
    if (!banner) return;
    var text = $('install-banner-text');
    var accept = $('install-accept');

    if (mode === 'ios') {
      // iOS no dispara beforeinstallprompt: instrucciones manuales
      if (text) {
        text.innerHTML = 'En iPhone/iPad: toca <i class="fas fa-share"></i> Compartir y luego «Añadir a pantalla de inicio».';
      }
      if (accept) accept.classList.add('hidden');
    } else {
      if (text) text.textContent = 'Lleva la radio a tu pantalla de inicio, como una app.';
      if (accept) accept.classList.remove('hidden');
    }
    banner.classList.remove('hidden');
  }

  function hideInstallBanner(persist) {
    var banner = $('install-banner');
    if (banner) banner.classList.add('hidden');
    if (persist) {
      try { localStorage.setItem('qcr-install-dismissed', '1'); } catch (e) { /* privado */ }
    }
  }

  function bindInstall() {
    // Android/Chrome: el navegador avisa que la app es instalable
    window.addEventListener('beforeinstallprompt', function (e) {
      e.preventDefault();
      installPromptEvent = e;
      showInstallBanner('native');
    });

    window.addEventListener('appinstalled', function () {
      hideInstallBanner(false);
    });

    var accept = $('install-accept');
    if (accept) {
      accept.addEventListener('click', function () {
        if (!installPromptEvent) return;
        installPromptEvent.prompt();
        installPromptEvent.userChoice.then(function () {
          installPromptEvent = null;
          hideInstallBanner(false);
        }).catch(function () { /* cancelado */ });
      });
    }

    var dismiss = $('install-dismiss');
    if (dismiss) dismiss.addEventListener('click', function () { hideInstallBanner(true); });
    var close = $('install-close');
    if (close) close.addEventListener('click', function () { hideInstallBanner(true); });

    // iOS: mostrar instrucciones a los pocos segundos de entrar
    if (isIosDevice()) {
      setTimeout(function () { showInstallBanner('ios'); }, 2500);
    }
  }

  function init() {
    audio = $('audio');
    $('footer-year').textContent = String(new Date().getFullYear());

    applyTheme();
    loadMyVotesLocal();   // marca "ya voté" visible desde el primer render
    renderDayTabs();
    renderSchedule();
    renderPlayer(state.nowPlaying);
    showPlayerLoading(true);
    bindEvents();
    bindInstall();
    registerServiceWorker();

    loadHistory();
    loadSchedule();
    fetchNowPlaying();

    setInterval(fetchNowPlaying, 10000);        // canción actual cada 10s
    setInterval(renderSchedule, 60000);         // badge AHORA al minuto exacto
    setInterval(loadHistory, 60000);            // portadas/hora sin recargar
    setInterval(loadSchedule, 5 * 60 * 1000);   // programación cada 5 min
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
