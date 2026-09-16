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
