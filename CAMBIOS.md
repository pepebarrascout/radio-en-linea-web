# Registro de correcciones — Que Chilero Radio

Fecha: 2026-09-14 (actualizado 2026-09-15) · Proyecto: Que Chilero Radio

## 🎯 Problema principal (reportado)

> "Debe actualizar la lista de canciones recientes de forma automática. Actualmente funciona hasta que algún usuario entra en la web."

**Causa raíz:** el historial lo registraba el NAVEGADOR de cada visitante (App.tsx detectaba el cambio de canción y hacía POST a la API). Si nadie tenía la web abierta, nadie registraba canciones: la lista quedaba congelada y las canciones emitidas mientras nadie visitaba el sitio se perdían para siempre.

**Solución:** el registro del historial se traslada al SERVIDOR con `api/cron-update.php`, un script PHP que se ejecuta cada minuto vía cron. Consulta el endpoint NowPlaying del plugin de Jellyfin y registra las canciones nuevas 24/7, haya o no visitantes. El navegador queda como respaldo idempotente (si el cron ya registró la canción, su envío es ignorado automáticamente).

## 🔧 Correcciones aplicadas

### Backend (`api/`)

| Archivo | Corrección |
|---|---|
| `history-lib.php` (NUEVO) | Lógica compartida: lectura/escritura con `flock` (bloqueo de archivos), zona horaria `America/Guatemala`, deduplicación consciente de la duración (misma canción repetida tras pasar su duración se registra como nuevo pase), escritura atómica, límite de 10 entradas, campo interno `ts`. |
| `history.php` | Reescrito: ahora es idempotente (POST registra solo si el cron no lo hizo), respuestas con `Cache-Control: no-store` (el navegador nunca muestra historial viejo cacheado), el campo interno `ts` no se expone, compatible con el formato anterior. |
| `cron-update.php` (NUEVO) | ⭐ Actualizador automático 24/7: consulta NowPlaying (cURL con timeout de 8 s y fallback a `file_get_contents`), registra canciones nuevas, idempotente, log opcional en `cron-update.log`, modo CLI (`--verbose`, `--url=` para pruebas) y modo HTTP opcional protegido por `CRON_SECRET_KEY`. Salida con código 1 si Jellyfin no responde. |
| `.htaccess` | Corregida sintaxis mixta de Apache 2.2 (podía causar error 500): ahora Apache 2.4 (`Require all denied`) con fallback a 2.2. `history.json` y `cron-update.log` ahora SÍ están protegidos contra acceso directo HTTP (antes el bloque "proteger" en realidad permitía el acceso). Corregido también el `RewriteRule` del preflight OPTIONS. |

### Frontend (`src/`, `index.html`)

| Archivo | Corrección |
|---|---|
| `App.tsx` | (1) Nueva lógica de historial: respaldo idempotente en vez de guardar la canción "anterior" al detectar cambios. (2) Portada: la URL con cache-busting se calcula una vez por canción con `useMemo` — antes se regeneraba en cada render (cada 10 s) y la portada parpadeaba/recargaba sin necesidad. (3) Todas las llamadas `fetch` con `cache: 'no-store'`. (4) El mensaje del panel de programación distingue "Cargando..." de "No hay programación para este día". (5) Año del copyright dinámico. |
| `data.ts` | Marca: artista por defecto "Que Chilero Radio" (antes "Radio Online"). |
| `index.html` | Título de la pestaña: "Que Chilero Radio - En Vivo". |
| Header/Footer | Marca actualizada a "Que Chilero Radio" en encabezado y pie. |

### Otros

| Archivo | Corrección |
|---|---|
| `README.md` | Actualizado: marca nueva, arquitectura del registro 24/7 e instrucciones de configuración del cron (cPanel y crontab). |
| `.gitignore` | Se excluyen los datos del servidor: `api/history.json` y `api/cron-update.log`. |

## ✅ Verificación realizada

- **Compilación**: `tsc --noEmit` y `vite build` sin errores (29 módulos).
- **Pruebas unitarias de la librería PHP**: 16/16 OK (deduplicación, repeticiones, zona horaria, límite de 10, formato de duración, validaciones).
- **Pruebas de integración**: 17/17 OK — endpoint GET/POST, HTTP 400/405, cache headers, 12 POSTs paralelos de la misma canción sin duplicados, 8 POSTs paralelos de canciones distintas sin corrupción del JSON, ciclo completo del cron (sin transmisión → registro → idempotencia → cambio de canción → error de red).
- **Prueba real**: el cron registró correctamente la canción en emisión en ese momento (Bananarama - Twisting) contra el endpoint real de Jellyfin, con hora de Guatemala y sin duplicar en la segunda ejecución.
- **Visual**: capturas en tema claro, tema oscuro y móvil (390px) confirmando que el diseño, colores y estructura se conservan intactos.

## 🚀 Puesta en marcha (importante)

1. Sube `index.html`, `assets/` y `api/` al servidor (`public_html/qc/`).
2. Visita `http://tu-dominio.com/qc/api/install.php` una vez y luego elimina ese archivo.
3. **Configura el cron** (esto es lo que mantiene la lista actualizada 24/7):
   - cPanel → Cron Jobs → cada 1 minuto:
     `php /home/usuario/public_html/qc/api/cron-update.php >/dev/null 2>&1`
   - VPS: `crontab -e` → `* * * * * php /var/www/tu-web/api/cron-update.php >/dev/null 2>&1`
4. Comprueba: `php api/cron-update.php --verbose` debe mostrar `REGISTRADA: ...`.

---

# FASE 2 — Migración a PHP + JS vanilla, portadas, votos, favicon y PWA

Fecha: 2026-09-14

## 🔄 Migración completa (sin rastro de React)

| Antes (React) | Ahora (PHP + vanilla) |
|---|---|
| `index.html` + `src/App.tsx` + `src/data.ts` | `index.php` (HTML completo, editable en cPanel) |
| Lógica en React (bundle JS de ~159 KB) | `assets/js/app.js` (~29 KB, sin framework) |
| Build con Vite/Tailwind en cada cambio | CSS compilado una vez (`assets/css/app.css`, 32 KB) |
| `package.json`, `node_modules`, `tsconfig`, `vite.config.js` | Eliminados — no se necesita Node en producción |

- Mismo diseño, colores, tipografías (Inter/Poppins), iconos (Font Awesome),
  animaciones y comportamiento responsivo — verificado con capturas
  claro/oscuro y móvil 390 px.
- El tema claro/oscuro se aplica ANTES de pintar (script en `<head>` con
  `localStorage`), eliminando el destello de tema al cargar.
- Las diferencias de color claro/oscuro que en React eran condicionales
  `isDark ? … : …` ahora son clases semánticas CSS (`.text-brand`,
  `.player-card`, `.pill`, `.tab-btn`, …) con valores idénticos a los de
  Tailwind v4 (oklch). Resultado visual 1:1, HTML más limpio.
- La programación semanal se carga con ruta relativa (`./programacion.json`),
  eliminando cualquier problema de CORS también en desarrollo local.
- Portada del reproductor: se fuerza `https` y se normaliza `maxWidth=720`
  (antes, si Jellyfin devolvía la URL sin `?`, el cache-busting `&t=` la
  rompía y la portada no cargaba).

## 🖼️ Portadas en el historial (nuevo)

- `api/covers-lib.php`: cuando el cron registra una canción, descarga su
  portada redimensionada (`maxWidth=150`) desde Jellyfin y la guarda como
  `api/covers/{md5(artista|título)}.jpg` (escritura atómica, validación de
  magic-bytes JPEG/PNG/WebP, idempotente).
- `gcCovers()`: en cada pasada del cron se borran las portadas de canciones
  que ya salieron del top 10 y los `.tmp` huérfanos (+1 h).
- `api/history.php` devuelve el campo `cover` (URL local o `null`); la web
  muestra la miniatura o un placeholder con la marca.
- `QCR_COVERS_DIR` / `QCR_DATA_DIR` / `QCR_HISTORY_FILE` (env) permiten
  redirigir las rutas (útil para pruebas y para sacar datos del webroot).

## 👍 Votos "me gusta / no me gusta" (nuevo)

- Botones en la canción actual y en cada fila del historial (iconos
  Font Awesome, estado activo de marca). En móvil los botones del
  historial muestran solo el icono para no comprimir el título.
- Sin registro: cookie anónima `qc_vid` (1 año, HttpOnly, SameSite=Lax,
  Secure en HTTPS) que identifica al visitante.
- **Un voto activo por visitante y canción, CAMBIABLE**: votar el otro botón
  cambia el voto; pulsar el mismo lo anula (`vote: "remove"`).
- Almacenamiento **JSON puro** (elección del propietario): `api/data/votes.json`
  (conteos por canción) y `api/data/voters.json` (votos por visitante,
  acotado a 500 canciones por votante y con purga de votantes sin votos).
  Escrituras atómicas con `flock` en orden fijo (sin interbloqueos).
- `api/vote.php`: GET (conteos + mis votos) / POST (votar, con validaciones).
- `api/votes.php`: **export público para Jellyfin** — canciones ordenadas por
  más "me gusta" (`artist`, `title`, `likes`, `dislikes`, `score`). Sin
  exportación periódica: siempre está al día. Nunca expone votantes.
- `api/data/.htaccess` bloquea el acceso directo a los JSON privados.

## 🌐 Favicon y PWA instalable (nuevo)

- Iconos provisionales generados con la marca (monograma "QC" sobre degradado
  navy #000080→#0000b3 con ondas de radio): `favicon.svg`, `favicon.ico`,
  `favicon-32.png`, `icon-192/512.png`, `icon-maskable-192/512.png`,
  `apple-touch-icon.png`. Para usar el logo oficial solo hay que
  sobrescribir esos archivos (misma ruta/tamaño) y subir la versión de
  `CACHE_NAME` en `sw.js`.
- `manifest.webmanifest`: nombre, `start_url`/`scope` relativos (funciona en
  `/qc/`), standalone, theme color de marca, iconos normal + maskable.
- `sw.js`: precache del shell, *stale-while-revalidate* para estáticos,
  red-primero para navegación y **sin cachear** `api/*`, artwork de Jellyfin
  ni el stream de audio (los datos en vivo nunca quedan obsoletos).
- `meta` iOS (apple-touch-icon, apple-mobile-web-app-*) y `theme-color`.
- Media Session API: título, artista y portada en la pantalla de bloqueo;
  botones play/pause del sistema conectados.

## 🧪 Verificación

- 41 pruebas unitarias (votos, claves de canción, portadas, GC) — OK.
- 46 pruebas de integración HTTP (página, estáticos, manifest, API de
  historial y votos con cookies, ciclo completo del cron con mock de
  Jellyfin: descarga de portada 150 px, idempotencia, GC del top 10,
  persistencia de votos) — OK.
- Capturas con navegador real: tema claro, oscuro, móvil 390 px, voto con
  cambio de estado, badge AHORA en programación, persistencia del tema
  tras recargar — diseño idéntico al original.

---

# Fase 3 — Ciclo semanal de votos (Opción C) y paquete limpio

Fecha: 2026-09-15

## 🔄 Consumo semanal de votos con token (opción elegida: lectura consumidora)

| Archivo | Cambio |
|---|---|
| `votes.php` | Dos modos: **sin token** = solo lectura (no toca nada, útil para mirar); **con `?token=`** = consumo semanal. Token constante `VOTES_CONSUME_TOKEN` (o env `QCR_VOTES_TOKEN`), comparado con `hash_equals`. Error 403 con token inválido. La respuesta del consumo incluye `consumido: true` y el nombre del respaldo. |
| `vote-lib.php` | Nueva `consumeAllVotes()`: bajo lock en orden fijo (voters→votes, mismo que `applyVote` → sin deadlocks), lee todo, **guarda respaldo** `api/data/votes-archivo-AAAA MMDD-HHMMSS.json` y SOLO si el respaldo triunfa **vacía `votes.json` Y `voters.json`** (contador a cero y todos pueden volver a votar: ciclo nuevo). Si el respaldo falla → error y NO se resetea (cero pérdida). Nueva `pruneVoteArchives()` conserva los últimos 12 respaldos (≈ 3 meses semanales). Nueva `buildJellyfinSongs()` (refactor compartido). Registro de auditoría en `api/data/votes-log.txt`. |
| `install.php` | Verifica también `data/.htaccess` y `covers/.htaccess`; añade el paso del consumo semanal de Jellyfin. |
| `sw.js` | `CACHE_NAME` sube a `qcr-static-v2` (refresco garantizado de caché en la próxima visita). |

**Consumo de Jellyfin (1 vez/semana):** `GET /qc/api/votes.php?token=TOKEN`. Con la semana sin votos responde `count: 0` sin crear respaldos inútiles. Los respaldos viven en `api/data/` (protegido por .htaccess, no accesible por web).

## 🧹 Paquete de producción limpio

- El ZIP entregado contiene **solo lo estrictamente necesario**: `index.php`,
  `manifest.webmanifest`, `sw.js`, `programacion.json.example`, `assets/`
  (css/js/img) y `api/` (php + .htaccess). Sin `README.md`, `CAMBIOS.md`,
  `LICENSE`, `.gitignore` ni `cssbuild/` (quedan fuera del paquete).
- Retirados del proyecto los archivos orientados a repositorio
  (`LICENSE`, `.gitignore`) y la referencia al repositorio en la
  documentación, hasta que se decida subir a GitHub.

## 🧪 Verificación (fase 3)

- 21 pruebas unitarias del ciclo de consumo (`scripts/test-consume.php`) — OK.
- 16 pruebas HTTP del ciclo completo: solo lectura no toca datos, token
  inválido → 403 sin tocar nada, consumo con token entrega/respalda/reinicia,
  segundo consumo → 0, re-voto posible en el ciclo nuevo — OK.
- Regresión: 28 unitarias + 46 de integración — OK (111 aserciones verdes).

---

# Fase 4 — UX móvil, privacidad de votos, itemId de Jellyfin y refrescos

Fecha: 2026-09-15

## 📱 Frontend (`index.php`, `assets/js/app.js`, `assets/css/app.css`)

| Cambio | Detalle |
|---|---|
| Hora oculta en móvil | En «Últimas canciones» la columna de hora se oculta en pantallas pequeñas (`hidden md:inline-block`): más espacio para el nombre de la canción. En escritorio se mantiene. |
| Votos sin números | Los conteos ya NO se muestran (ni reproductor ni historial): solo el estado propio (👍/👎 activo). `vote.php` GET devuelve solo `myVotes` mapeado por `artista||título`; el POST ya no responde conteos. |
| itemId al votar | Los botones llevan `data-itemid` (GUID de Jellyfin) y el POST lo envía; el servidor lo usa como clave del voto. |
| Banner de instalación | Al entrar aparece un banner fijo inferior «Instalar Que Chilero Radio» (Android: diálogo nativo vía `beforeinstallprompt`; iOS: instrucciones Compartir → Añadir a inicio; cierrable con «Ahora no», se recuerda en localStorage; oculto si ya está instalada). CSS propio añadido a `app.css` (no requiere regenerar Tailwind). |
| Portada del player | ⭐ El artwork de Jellyfin tiene SIEMPRE la misma URL (devuelve la portada de la canción actual) y el navegador la servía cacheada: ahora lleva parámetro `v=` con la clave de la canción → se descarga la portada nueva al cambiar la canción. |
| Miniaturas del historial | `loadHistory()` corre cada 60 s (antes solo al cambiar de canción): las portadas que el cron descargó unos segundos tarde aparecen solas. Si el JSON no cambió, no re-pinta (no interfiere con toques). |
| Badge AHORA / programación | `renderSchedule()` se evalúa cada 60 s: el badge AHORA salta al programa nuevo solo. `programacion.json` se re-lee cada 5 min con cache-busting y el service worker dejó de cachearlo → los cambios manuales se ven al recargar (y a los 5 min sin recargar). |
| Analítica | Script de Umami (`stats.blogsdeguatemala.com`) añadido antes de `</body>`. |

## ⚙️ Backend (`api/`)

| Archivo | Cambio |
|---|---|
| `history-lib.php` | `registerSong()` guarda `itemId` (sanitizado `sanitizeItemId()`); se expone en el GET/POST del historial. |
| `cron-update.php` | Pasa el `itemId` de NowPlaying al historial. |
| `vote-lib.php` | `applyVote()` acepta `$itemId`: si es válido es la CLAVE del voto (antes md5(artista|título); sin itemId sigue funcionando con md5). `votes.json` guarda el itemId. Nueva `getMyVotesPublic()` (une voters+votes y devuelve pares `artista||título`, nunca conteos). Export incluye `itemId`. |
| `vote.php` | GET → solo `{myVotes}`; POST → acepta itemId, responde `{success,key,myVote}` SIN conteos. |
| `votes.php` | Ahora SIEMPRE requiere token (403 sin él). Modo `&ver=1` para mirar sin consumir. Export incluye `itemId` de cada canción. |
| ~~`install.php`~~ | **ELIMINADO** (petición del usuario): todo se auto-crea (`history.json`/`votes.json`/`voters.json` en la primera escritura; `data/` y `covers/` vienen en el ZIP con su `.htaccess`). Verificación documentada en README. |
| `sw.js` | `CACHE_NAME` → `qcr-static-v3`; excluye `programacion.json` de la caché (siempre red). |

## 🧪 Verificación (fase 4)

- Unitarias ciclo: 26 OK · HTTP ciclo: 23 OK · Integración: 50 OK · Unitarias votos/portadas: 28 OK.
- Nuevas coberturas: itemId como clave, export con itemId, 403 sin token, ver=1 no consume, POST/GET sin conteos, myVotes mapeado por artista||título, historial expone itemId.
- `node --check app.js` OK; sintaxis PHP de todos los endpoints OK.
- TLS verificado contra el servidor real de la radio (entonces el cliente accedía directo; desde v0.1.1 el navegador ya no contacta con ese servidor: lo hacen los proxys del propio hosting).

---

# FASE 5 — Miniatura instantánea, voto persistente, identidad visual y release v0.1.0

Fecha: 2026-09-15

## 🐞 Bugs corregidos

| Bug | Causa raíz | Solución |
|---|---|---|
| La marca "ya voté" desaparecía al recargar o reabrir el navegador | `loadVotes()` llamaba a `ingestVoteCounts()` (eliminada en fase 4 con los conteos públicos): `ReferenceError` → los `myVotes` del servidor nunca se aplicaban | `loadVotes()` usa `ingestMyVotes()` (reemplazo completo desde la cookie anónima) **+ persistencia en `localStorage`** (`qcr-myvotes`, máx. 500): la marca se pinta al instante al cargar, sobrevive recargas/cierres, se confirma con el servidor y se limpia sola cuando el ciclo semanal se consume (todos pueden revotar) |
| La miniatura de la última canción no aparecía hasta cambiar de canción o recargar | El historial solo mostraba la portada del archivo local que baja el cron (hasta ~1 min); la imagen del artwork que el player YA había descargado no se reutilizaba | ⭐ `coverHtmlFor()` reutiliza **la misma URL del artwork ya descargada por el reproductor** para la fila de la canción actual (caché del navegador: instantáneo, cero red extra). Además el respaldo del navegador envía `artworkUrl` y `api/history.php` baja la portada local **al instante** (no espera al cron); el cron sigue siendo el respaldo 24/7 |

## 🎨 Identidad visual (quechilero.png)

| Elemento | Cambio |
|---|---|
| Iconos PWA | Regenerados desde el logo oficial (chile sobre círculo navy): `icon-192/512`, `icon-maskable-192/512` (fondo navy + contenido al 90% en zona segura), halo translúcido del original eliminado con máscara circular exacta |
| Favicons | `favicon-16/32.png`, `favicon.ico` (16+32+48) y `favicon.svg` regenerados |
| Logo en la web | Header, footer y banner de instalación usan ahora el logo real (antes emoji 📻) |
| Marcadores | Los placeholders de portada usan el icono de la marca (antes SVG genérico con 🎵) |
| `sw.js` | `CACHE_NAME` → `qcr-static-v4` + precache de los iconos nuevos |

## 🔒 Seguridad / repositorio

| Cambio | Detalle |
|---|---|
| Token fuera del código | `VOTES_CONSUME_TOKEN` ya NO está hardcodeado: se lee de la variable de entorno `QCR_VOTES_TOKEN` o de `api/config.php` (NO versionado; plantilla `api/config.example.php`). Sin token configurado el consumo responde **503 `token_no_configurado`** con instrucciones. `cron-update.php` admite también overrides por config/env (`QCR_NOWPLAYING_URL`, `QCR_CRON_SECRET`) |
| Repositorio GitHub | Publicado en `pepebarrascout/radio-en-linea-web` con `README.md` al estilo del plugin Radio Online (badges, características, instalación wget+unzip, API, solución de problemas), `LICENSE` MIT y `.gitignore` (config.php, datos runtime, programacion.json) |
| Release v0.1.0 | ZIP de producción adjunto a la release: solo archivos de la web, listo para `wget` + `unzip` en el servidor |

## 🧪 Verificación (fase 5)

- Unitarias ciclo: OK · HTTP ciclo: OK · Integración v2: OK · Unitarias votos/portadas: OK (suites adaptadas al token por entorno).
- `node --check app.js` OK; sintaxis PHP de todos los endpoints OK.
- Smoke: banner de instalación, Umami, iconos nuevos, miniatura instantánea y persistencia de voto validados en navegador.

---

# v0.1.1 — Programación legible, pie limpio y cero dominios en el código

## 📐 Interfaz

| Cambio | Detalle |
|---|---|
| Programación semanal a 2 columnas | Cada fila ahora es **ícono → (título / horario / descripción)** en tres líneas apiladas: mucho más legible en pantallas grandes y pequeñas. Se conservan tipografías, colores, borde de acento, resaltado del programa al aire y el badge AHORA |
| Pie de página | Eliminada la frase "Todos los derechos reservados." (queda `© AÑO Que Chilero Radio.`) |

## 🛡️ Privacidad: el dominio del servidor de la radio desaparece del código

| Cambio | Detalle |
|---|---|
| `api/nowplaying.php` (NUEVO) | Proxy JSON de "Ahora suena": el navegador consulta el propio hosting (mismo origen, sin CORS). Caché de 5 s con `flock` (miles de oyentes no multiplican consultas), sirve la última respuesta válida si el upstream falla y **reescribe `artworkUrl` al proxy local** |
| `api/artwork.php` (NUEVO) | Proxy de portadas del reproductor (`maxWidth` saneado 16–2048). Valida magic bytes JPEG/PNG/WebP antes de servir: una página de error del upstream nunca llega al `<img>` |
| `api/np-lib.php` (NUEVO) | Punto único de resolución de la URL del servidor (env `QCR_NOWPLAYING_URL` → `api/config.php`). Descarga HTTP compartida (cURL + fallback) y "limpiador" que borra el host real de cualquier campo de texto de la respuesta |
| `app.js` | `NOW_PLAYING_URL` y `ARTWORK_FALLBACK_URL` apuntan a los proxys locales; el dominio real ya no existe en el código del cliente. Cache-busting de portada compatible con rutas relativas. Duplicado de `songMatchesNowPlaying()` eliminado |
| `covers-lib.php` | `ARTWORK_FALLBACK_URL` hardcodeado eliminado: usa el endpoint configurado. `buildCoverDownloadUrl()` admite `maxWidth` como parámetro (lo usa `artwork.php`); sin configuración devuelve `''` |
| `cron-update.php` | Sin valor por defecto del dominio: si falta configuración termina en error con la instrucción exacta (CLI) o 503 JSON (HTTP). `--url=` sigue disponible para pruebas |
| `history.php` | El `artworkUrl` relativa que envía el navegador (`api/artwork.php?...`) se traduce en el servidor a la URL real para bajar la portada al instante |
| `config.example.php` | `QCR_NOWPLAYING_URL_VALUE` pasa a ser el Paso 1 (obligatorio) con placeholder genérico; sin dominios reales en el repositorio |
| `sw.js` | Regla "hostname incluye jellyfin" eliminada (ya no hay tráfico directo); regla `/api/` cubre los proxys. `CACHE_NAME` → `qcr-static-v5` |
| Degradación elegante | Sin URL configurada, `nowplaying.php` responde un placeholder "Esperando transmisión…" → la web funciona nada más descomprimir el ZIP, sin errores en consola |

## 🧪 Verificación (v0.1.1)

- Nueva suite `scripts/test-fase6.sh`: **20/20** (proxy JSON sin host real, artwork validado, caché, traducción del artwork del navegador, placeholder sin configurar, cron exit 1, escaneo anti-hardcodeo del repo completo).
- Regresión completa: integración v2 **50/50**, ciclo **26/26**, HTTP ciclo **23/23**, votos/portadas **29/29**. `php -l` y `node --check` limpios.
- Escaneo: cero apariciones del dominio real, del token antiguo o de tokens de GitHub en el repositorio.

---

# v0.1.2 — Waveform, progreso de la canción y día siempre visible

## 🎛️ Interfaz

| Cambio | Detalle |
|---|---|
| **Waveform animada** junto al play | 24 barras verticales redondeadas (navy / azul en oscuro) donde antes estaba el texto "Escucha en vivo". Al reproducir, cada barra «baila» con su propio pico, ciclo y retardo aleatorios (efecto ecualizador); al pausar vuelven a quedar **planas** con transición suave. Es decorativa: no analiza el audio real (eso exigiría Web Audio API + CORS y gasta batería). Respeta `prefers-reduced-motion` y se desincroniza el arranque con retardos negativos |
| **Barra de progreso de la canción** | Barra fina ilustrativa bajo la píldora de duración: muestra cuánto avanzó la canción en emisión. **Sin etiquetas de tiempo** (el total ya se ve en la píldora, que queda intacta). Se oculta sola si no hay duración conocida o si la canción aún no está en el historial — nunca muestra un avance inventado. Se clampa al 100 % si la detección tardó más que la duración |
| **Pestaña del día siempre visible** | En móvil el contenedor de días se desliza horizontalmente: el día activo ahora se **auto-centra** al cargar la web, **al tocar** cualquier día (desplazamiento suave, sin re-pintar el HTML para no perder la posición) y **al cambiar el ancho** de la ventana (rotación, barra de URL del navegador) |

## ⚙️ Técnico

| Cambio | Detalle |
|---|---|
| `api/nowplaying.php` | Nuevos campos `elapsedSec` (segundos transcurridos de la canción) y `serverTime`. El cálculo se hace **en el servidor** restando `time()` − `ts` de `history[0]` cuando coincide con la canción en emisión: el navegador no depende del reloj del teléfono del oyente. `elapsedSec: null` → sin dato (el cliente oculta la barra). Retrocompatible: ningún campo cambió |
| `assets/js/app.js` | `initWaveform()` genera las barras con variables CSS aleatorias; `updatePlayButton()` alterna la clase `playing`; `durationStringToSeconds()` + `updateProgressFromNp()` + `renderProgress()` mantienen la barra (avance local de 1 s entre sondeos de 10 s, resincronizado en cada respuesta); `centerDayTab()` centra el día (`auto` al cargar/redimensionar, `smooth` al tocar); el clic de día ya no re-pinta el innerHTML (evita el salto brusco) |
| `assets/css/app.css` | Bloque propio al final (mismo patrón que el banner de instalación): `.waveform`, `@keyframes qcr-wave`, `.song-progress-track` / `.song-progress-fill`, variantes `.dark` y `prefers-reduced-motion` |
| `sw.js` | `CACHE_NAME` → `qcr-static-v6` (los visitantes reciben la nueva versión al recargar) |
| PWA | Instalación intacta: manifest, iconos, banner Android/iOS y registro del service worker sin cambios |

## 🧪 Verificación (v0.1.2)

- Nueva suite `scripts/test-fase7.sh`: **37/37** (elapsedSec ≈ esperado desde el historial, null sin coincidencia, placeholder con campos nuevos, HTML con waveform/progreso, CSS y JS nuevos, SW v6, PWA intacta, escaneo anti-secretos).
- Verificación en navegador real (viewport 390 px y escritorio): 24 barras generadas, reposo plano (5 px) → animación con picos al reproducir → planas al pausar; pestaña del día centrada al cargar (`scrollLeft` 303), al tocar Domingo (403) y tras redimensionar (321); barra de progreso visible con color de marca en claro y oscuro.
- Regresión completa: integración v2 **50/50**, ciclo **26/26**, HTTP ciclo **23/23**, votos/portadas **29/29**, fase 6 **20/20**. Total **185 aserciones** verdes. `php -l` + `node --check` limpios.
- Escaneo: cero tokens de GitHub, cero dominios reales del servidor de la radio y cero token de consumo en el repositorio.

---

# v0.1.3 — Ajustes tras la prueba en vivo (pausa real, progreso centrado, waveform viva, día siempre azul)

## 🎛️ Interfaz

| Cambio | Detalle |
|---|---|
| **Barra de progreso centrada** | Las píldoras de género/duración pasan a una **fila flex** (antes eran `inline-flex` alineadas a la línea base de texto, lo que dejaba un colchón invisible que «pegaba» la barra a las píldoras). La barra ahora usa el mismo margen arriba que hacia los botones de voto: queda **en medio exacto** entre la información de género y los botones me gusta / no me gusta |
| **El botón detiene de verdad** | En un stream en vivo «pausar» solo congela la reproducción: el navegador mantiene la conexión y **sigue descargando en segundo plano**; al reanudar continuaba donde se quedó (música del pasado) aunque el vivo ya cambiara de canción. Ahora el botón **corta la conexión y vacía el búfer** (`stopStream()`), y play **reconecta al borde del vivo** con un cache-buster (`startStream()`). Los botones de la pantalla de bloqueo / notificación (Media Session) usan la misma lógica |
| **Waveform más viva** | Movimiento tipo ecualizador más notorio: ciclos de **0.35–0.75 s** (antes 0.55–1.15 s) y picos de **14–40 px** (antes 10–28 px) en un contenedor más alto (**44 px** vs 32 px). Sigue siendo decorativa, plana al detener y respeta `prefers-reduced-motion` |
| **Día marcado siempre azul** | En móvil el estado `:hover` queda «pegado» al último botón tocado y, en tema oscuro, su fondo tenía mayor especificidad que el azul del día seleccionado: al tocar otro día «nadie» parecía marcado. Nueva regla `.tab-btn.tab-selected:hover` (claro y oscuro): el día elegido conserva **el mismo azul navy** del marcado inicial |

## ⚙️ Técnico

| Archivo | Cambio |
|---|---|
| `index.php` | Píldoras de género/duración envueltas en `flex flex-wrap gap-2` (el espaciado bajo ellas queda determinista); `#song-progress` con `mt-4` (igual al `mt-4` de los votos). Sin cambios de IDs: el JS no se entera |
| `assets/js/app.js` | `togglePlay()` dividido en `stopStream()` (pause + removeAttribute('src') + load) y `startStream()` (src con `?t=Date.now()` + load + play); handlers de `navigator.mediaSession` play/pause conectados a la misma lógica; `initWaveform()` con los rangos nuevos de pico/ciclo |
| `assets/css/app.css` | `.waveform` altura 44 px y reposo 6 px; `@keyframes qcr-wave` hasta `var(--h,40px)`; reduced-motion a 20 px; regla nueva `.tab-btn.tab-selected:hover` + variante `.dark` |
| `sw.js` | `CACHE_NAME` → `qcr-static-v7` (imprescindible para que los móviles reciban el CSS/JS nuevos) |

## 🧪 Verificación (v0.1.3)

- Nueva suite `scripts/test-fase8.sh`: **28/28** (markup con fila flex y `mt-4`, detención real con corte de stream, cache-buster de reconexión, Media Session sincronizada, rangos nuevos de la waveform, regla hover del día marcado, SW v7, PWA intacta, lint y escaneo anti-secretos).
- Verificación en navegador real (viewport móvil 390 px, claro y oscuro): márgenes de la barra medidos **16 px arriba / 16 px abajo** (centrada exacta); waveform a 44 px animando con ciclos ≈0.39 s; tras detener, el elemento de audio queda **sin src** (conexión cortada) y las barras planas; al tocar «Viernes» con el hover pegado el botón mantiene `rgb(0,0,128)` con texto blanco.
- Regresión completa: fase 7 **37/37**, fase 6 **20/20**, integración v2 **50/50**, ciclo **26/26**, HTTP ciclo **23/23**, votos/portadas **29/29**. Total **213 aserciones** verdes. `php -l` + `node --check` limpios.
- Escaneo: cero tokens de GitHub, cero dominios reales del servidor de la radio y cero token de consumo en el repositorio.

---

# v0.1.4 — Salud del historial/portadas y avisos push de programas

## 🩺 Historial y portadas (temas 2, 3 y 4 pospuestos)

| Cambio | Detalle |
|---|---|
| **Deduplicación robusta del historial** | Antes solo se comparaba contra `history[0]`: el rebote de metadatos A→B→A entre dos registradores (cron 1 min + navegador 10 s) registraba la misma canción DOS veces (caso real: *Sexercize* ×2 con *Three Imaginary Boys* en medio). Ahora `isSamePlayRecent()` escanea TODAS las entradas recientes: si la canción coincide con cualquiera cuya emisión aún no cumple `duración + margen`, es la misma emisión y no se registra. Comparación por clave normalizada (`songKey`, inmune a mayúsculas/espacios) + `itemId` de Jellyfin (manda cuando el título cambia por corrección de metadatos). La repetición GENUINA (tras su duración completa) sigue registrándose como pase nuevo |
| **Retro-relleno de portadas nulas** | Las filas del historial con `cover:null` ya no quedan huérfanas: si configuras `QCR_JELLYFIN_IMAGES_URL` (plantilla `https://TU-JELLYFIN/Items/{itemId}/Images/Primary`), el cron repara cada fila por su itemId (máx. 2 por minuto; la misma portada espera 6 h entre intentos para no martillar un item sin imagen). Sin plantilla, la vía oportunista de siempre sigue operando: cuando la canción vuelve a sonar, su portada se descarga al instante |
| **GC de portadas endurecido** | `gcCovers()` también elimina archivos con nombre inválido (una portada válida SIEMPRE es 32 hex + `.jpg`), `.jpg` de 0 bytes (se re-descargan solas) y cualquier resto con otra extensión |
| **CLI de mantenimiento** | Nuevo `api/maintenance.php`: `covers:audit` (informe sin borrar nada: válidos, inválidos, vacíos, duplicados por contenido y filas sin portada), `covers:clean` (borra inválidas/vacías/.tmp viejos y duplicadas por hash de contenido), `covers:backfill` (fuerza el retro-relleno, `--force` ignora la espera) e `history:dedupe` (colapsa en el propio history.json las emisiones duplicadas del rebote) |

## 🔔 Avisos push de programas (Web Push puro, sin Firebase)

| Cambio | Detalle |
|---|---|
| **Mensaje** | «Te invitamos a escuchar «[programa]», que empieza a las [hora]. ¡Te esperamos!» — 10 minutos antes de cada inicio, con enlace que abre la web/PWA y arranca la radio (autoplay mejor esfuerzo; si el navegador lo bloquea, la radio queda lista con el botón de play) |
| **Fuente de datos** | `programacion.json` — la MISMA parrilla que muestra la web; zona horaria America/Guatemala. Editar la parrilla cambia los avisos sin tocar código |
| **Arquitectura** | `api/push-lib.php` implementa RFC 8291 (cifrado aes128gcm) + RFC 8292 (VAPID con JWT ES256) en PHP puro con openssl — sin Composer, sin Firebase, sin dependencias. `api/push-subscribe.php` (alta/baja con preferencias), `api/push-config.php` (clave pública), `api/push-trigger.php` (disparador del cron cada minuto + CLI: `--anuncio`, `--test-send`, `--dry-run`, `--generate-keys`) |
| **El oyente elige** | Botón «🔔 Activar avisos» en el panel de Programación (opt-in voluntario, nunca popups): dos modos — *todos los programas* o *solo tarde y noche (inicio ≥ 14:00)* — guardados por suscriptor en `api/data/subscribers.json`. Cambiar de modo = volver a activar; desactivar = un toque |
| **Anti-spam por diseño** | Máximo 1 aviso por programa y día (estado idempotente en `api/data/push-state.json`); nunca avisos atrasados (ventana `[inicio−10, inicio)`; si el servidor estuvo caído, se salta); el service worker SILENCIA el aviso si el oyente ya está escuchando (consulta por MessageChannel con tope de 700 ms); las notificaciones no se apilan (mismo `tag`); TTL 300 s (nunca llega un «en 10 minutos» tarde); suscripciones muertas (404/410) se limpian solas; onda completa se reintenta solo si NO llegó nada a nadie (máx. 3) → jamás se duplica un aviso |
| **Baja en 1 toque** | Botón «Silenciar avisos» dentro de la propia notificación (donde el sistema soporte acciones) + el botón de la web como interruptor activo/inactivo |
| **Re-suscripción automática** | El service worker gestiona `pushsubscriptionchange` (rotación de suscripción del servicio push) re-suscribiéndose con la clave pública de `push-config.php` |
| **Compatibilidad** | Android: navegador y PWA. iOS ≥ 16.4: solo con la PWA instalada en pantalla de inicio. Sin soporte, el bloque de avisos ni siquiera se muestra |

## ⚙️ Técnico

| Archivo | Cambio |
|---|---|
| `api/history-lib.php` | `isSamePlayAsLast()` → `isSamePlayRecent()` (escaneo completo + `songKey` + `itemId`); `registerSong()` usa la nueva regla |
| `api/covers-lib.php` | `jellyfinItemImagesUrl()` (plantilla validada; http solo para hosts privados), `storeCoverBytes()` + `ensureCoverFromUrl()` (refactor del guardado atómico), `backfillMissingCovers()` con memoria de intentos en `api/data/backfill-state.json`, `gcCovers()` endurecido |
| `api/cron-update.php` | Llama al retro-relleno (2 por pasada) y lo reporta en el log/verbose |
| `api/push-lib.php` (NUEVO) | Web Push completo: claves VAPID (generación y carga), JWT ES256 (DER→raw), HKDF, cifrado aes128gcm, POST con cURL/fallback, almacén de suscriptores compartido; distingue fallo PERMANENTE (`false` → limpiar suscripción) de fallo TRANSITORIO (`null` → reintentar, nunca borrar) |
| `api/push-config.php` (NUEVO) | Clave pública VAPID para el navegador; 503 si no está configurado (la web oculta el botón) |
| `api/push-subscribe.php` (NUEVO) | POST (alta/actualización de modo) y DELETE (baja) con validación estricta (endpoint https — http solo loopback/privado para pruebas—, `p256dh` de 65 bytes, `auth` 8–48) y escritura atómica con `flock` |
| `api/push-trigger.php` (NUEVO) | Disparador cada minuto: ventana de 10 minutos, estado del día, filtro por preferencia, ondas de envío con limpieza, CLI completa y log en `api/data/push-trigger.log` |
| `api/maintenance.php` (NUEVO) | CLI de mantenimiento (audit/clean/backfill/dedupe), solo por CLI |
| `api/config.example.php` | Nuevas claves documentadas: `QCR_JELLYFIN_IMAGES_URL_VALUE`, `QCR_VAPID_PUBLIC_KEY_VALUE`, `QCR_VAPID_PRIVATE_PEM_VALUE`, `QCR_VAPID_SUBJECT_VALUE` |
| `sw.js` | `CACHE_NAME` → `qcr-static-v8`; handlers `push` (con silencio si ya escucha), `notificationclick` (abrir/re-enfocar + autoplay, y acción de silenciar) y `pushsubscriptionchange` |
| `assets/js/app.js` | Flujo de suscripción completo (`bindPush`, `subscribePush`, `unsubscribePush`, `refreshPushUi`), reporte del estado del reproductor al SW (`notifySwPlayState`), respuestas a consultas del SW y autoplay (`tryAutoplay`, `?autoplay=1`, mensaje `qcr-autoplay`) |
| `index.php` / `assets/css/app.css` | Bloque de avisos en el panel de Programación (botón + selector de alcance + pista) con estilos propios (claro/oscuro) |

## 🧪 Verificación (v0.1.4)

- Nueva suite `scripts/test-pkg-historial.php`: **39/39** (rebote A→B→A, repetición genuina, itemId, normalización, retro-relleno con servidor de imágenes real + memoria de intentos, GC endurecido, CLI completa).
- Nueva suite `scripts/test-push.php`: **35/35** end-to-end con servicio push simulado (claves VAPID reales, JWT ES256 verificado contra la pública, cifrado aes128gcm descifrado byte a byte de forma independiente, endpoints de suscripción, ventana de 10 min, idempotencia, limpieza de suscripciones muertas, cabeceras `Authorization: vapid t=…,k=…` + `TTL: 300` + `Content-Encoding: aes128gcm` recibidas por el servicio, aviso manual y prueba).
- Nueva suite `scripts/test-fase9.sh`: **27 grupos** (lint, estructura SW/HTML/JS/CSS/PHP, ambas suites y escaneo anti-secretos).
- Regresión completa: historial **16/16**, votos/portadas **29/29**, consumo **26/26**, HTTP consumo **23/23**, smoke fase 5 **5/5**, fase 6 **20/20**, fase 7 **37/37**, fase 8 **28/28** (SW v8+), integración v1 **17/17**, integración v2 **50/50**. Total **~325 aserciones** verdes. `php -l` y `node --check` limpios.
- Escaneo: cero tokens de GitHub, cero dominios reales del servidor de la radio, cero claves privadas en el repositorio.
