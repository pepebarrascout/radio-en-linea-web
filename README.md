# Que Chilero Radio — Web en Línea
<div align="center">
    <p>
        <img alt="Logo" src="assets/img/icon-512.png" height="180"/><br />
        <a href="https://github.com/pepebarrascout/radio-en-linea-web/releases"><img alt="GitHub Downloads" src="https://img.shields.io/github/downloads/pepebarrascout/radio-en-linea-web/total?color=000080&label=descargas"/></a>
        <a href="https://github.com/pepebarrascout/radio-en-linea-web/issues"><img alt="GitHub Issues" src="https://img.shields.io/github/issues/pepebarrascout/radio-en-linea-web?color=000080"/></a>
        <a href="https://www.php.net/"><img alt="PHP" src="https://img.shields.io/badge/PHP-%3E%3D%207.4-777BB4?logo=php&logoColor=white"/></a>
        <a href="https://developer.mozilla.org/es/docs/Web/Progressive_web_apps"><img alt="PWA" src="https://img.shields.io/badge/PWA-Instalable-5A0FC8?logo=pwa&logoColor=white"/></a>
        <a href="https://www.jellyfin.org/"><img alt="Jellyfin" src="https://img.shields.io/badge/Jellyfin-12.1.x-blue.svg"/></a>
    </p>
</div>

> **Sitio web oficial de Que Chilero Radio**: radio en vivo 24/7 con historial automático de canciones, portadas de discos, votos de los oyentes (👍/👎) y app instalable (PWA). 100% **PHP + JavaScript vanilla**: sin frameworks, sin Node y sin base de datos. Se instala en cualquier hosting con PHP descomprimiendo un ZIP.

**Diseñada para trabajar junto al plugin [Jellyfin Radio Online](https://github.com/pepebarrascout/jellyfin-plugin-radio-online)** (Jellyfin → Liquidsoap → Icecast).

---

## ✨ Caracteristicas

| Caracteristica | Descripcion |
|---|---|
| 📡 **En vivo 24/7** | Reproductor del stream de Icecast con indicador EN VIVO. El botón **detiene de verdad** la transmisión (cierra la conexión y vacía el búfer) y play reconecta siempre al borde del vivo — nunca se queda escuchando música «del pasado» |
| 🎵 **Historial automático** | El servidor registra las canciones cada minuto vía cron, aunque nadie esté navegando |
| 📶 **Waveform animada** | Barras junto al botón play, tipo ecualizador, que «bailan» al reproducir y quedan planas al detener (decorativa, sin coste de batería) |
| 📊 **Progreso de la canción** | Barra ilustrativa del avance de la canción en emisión, calculada en el servidor con la hora de inicio del historial |
| 📆 **Día siempre visible** | La pestaña del día actual se auto-centra al cargar, al tocarla y al rotar el móvil; el día marcado conserva su azul aunque el toque deje el botón en estado hover (tema oscuro incluido) |
| 🖼️ **Portadas de discos** | Miniaturas en caché local servidas desde el propio hosting |
| 📅 **Programación semanal** | Programas por día y horario editando un simple `programacion.json` |
| 🗳️ **Votos de oyentes** | Like/dislike anónimo (cookie, 1 voto activo por canción, cambiable) |
| 🔄 **Ciclo semanal de votos** | Jellyfin consume los totales 1 vez/semana con token; el contador vuelve a cero |
| 🪪 **itemId de Jellyfin** | Cada voto viaja con el GUID del ítem para que el plugin consulte su base de datos |
| 🛡️ **Sin dominios expuestos** | El navegador solo habla con tu hosting (proxys de NowPlaying y portadas); el dominio del servidor de la radio nunca aparece en el código ni en la red del cliente |
| 🔒 **Privacidad** | Los conteos nunca son públicos; los votantes nunca se exponen |
| 📲 **PWA instalable** | Manifest + service worker + banner de instalación con detección Android/iOS |
| 🔔 **Avisos de programas** | Notificación push 10 minutos antes de cada programa (Web Push + VAPID, sin Firebase). El oyente elige UNA o VARIAS franjas: **noche** (22:00–05:00), **mañana** (05:00–14:00) y/o **tarde** (14:00–21:00), o los excluyentes **todos** / **ninguno** (v0.1.7); se pueden cambiar sin darse de baja («Cambiar franjas»); silencio automático si ya está escuchando y baja en 1 toque |
| 📻 **Reproducción resiliente** (v0.1.7) | Si otra pestaña/app «roba» el foco de audio o la red congela el stream, la radio se reconecta sola al borde del vivo (reintentos con backoff 1→30 s + vigilante de stream congelado); tras pausas largas basta volver a la pestaña o tocar la pantalla |
| 🌗 **Tema claro/oscuro** | Persistente, aplicado antes de pintar (sin destellos) |
| 📱 **Media Session** | Portada y metadatos en la pantalla de bloqueo del móvil |
| 🧹 **Mantenimiento** | CLI `api/maintenance.php`: auditoría/limpieza de portadas, retro-relleno por itemId y deduplicación del historial |

---

## 📋 Requisitos

1. **Hosting con PHP 7.4+** (Apache con soporte `.htaccess`; probado en cPanel)
2. **Cron cada 1 minuto** para el actualizador del historial
3. Cadena de radio funcionando con el plugin **[Jellyfin Radio Online](https://github.com/pepebarrascout/jellyfin-plugin-radio-online)**:
   Jellyfin → Liquidsoap → Icecast (`https://tu-dominio.com/radio`)

> Sin base de datos: todo el estado vive en archivos JSON en `api/` (protegidos con `.htaccess`).

---

## 🚀 Instalacion

### Metodo 1: Descarga desde Releases (wget + unzip) ⭐ Recomendado

1. Copia la URL del ZIP de la última release desde [Releases](https://github.com/pepebarrascout/radio-en-linea-web/releases)
2. En tu servidor (o por SSH en cPanel), dentro de la carpeta pública. **En producción la web vive en la RAÍZ de `public_html/`** — si la instalas en una subcarpeta, ajusta las rutas de todos los pasos:

   ```bash
   cd ~/public_html
   wget https://github.com/pepebarrascout/radio-en-linea-web/releases/download/v0.1.7/radio-en-linea-web-v0.1.7.zip
   unzip radio-en-linea-web-v0.1.7.zip && rm radio-en-linea-web-v0.1.7.zip
   ```

3. Crea la configuración privada (Pasos 1 y 2 de [Configuración](#️-configuracion))
4. Programa el cron cada 1 minuto (Pasos 3 y 5)
5. (Opcional) Claves VAPID para los avisos push (Paso 6)
6. Abre tu web y pulsa play 🎶

> El ZIP trae ya la estructura completa (incluidas las carpetas `api/data/` y `api/covers/` con su `.htaccess` de protección). Los archivos de datos se crean solos la primera vez que se usa la web — y si tu hosting no deja a PHP crear archivos, se crean a mano en 1 minuto (ver [Archivos de datos y permisos](#-archivos-de-datos-y-permisos-importante)).

### Metodo 2: Clonar el repositorio

```bash
cd ~/public_html
git clone https://github.com/pepebarrascout/radio-en-linea-web.git .
```

> Si `public_html` no está vacío, clona en una subcarpeta (`git clone ... qc`), mueve el contenido a la raíz… o usa el ZIP del Método 1, que es lo más simple.

Después, los mismos pasos 3-5 del Método 1.

---

## ⚙️ Configuracion

### Paso 1: URL del servidor de la radio (obligatorio)

El dominio de tu servidor (endpoint NowPlaying del plugin RadioOnline) **nunca va escrito en el código**: el navegador consulta los proxys del propio hosting (`api/nowplaying.php` y `api/artwork.php`) y solo el PHP del servidor conoce la URL real.

```bash
cd ~/public_html/api
cp config.example.php config.php
nano config.php   # pega tu URL en QCR_NOWPLAYING_URL_VALUE
```

- Formato: `https://tu-servidor-de-radio/RadioOnline/NowPlaying`
- Alternativa: variable de entorno `QCR_NOWPLAYING_URL`
- Sin esta URL la web muestra "Esperando transmisión…" y el cron no registra canciones

### Paso 2: Token secreto de consumo (obligatorio)

Los votos se consumen desde Jellyfin **una vez a la semana** con un token secreto que **nunca va hardcodeado en el código**:

```bash
cd ~/public_html/api
cp config.example.php config.php   # si ya lo creaste en el Paso 1, solo añade el token
# Genera un token aleatorio y edítalo:
php -r "echo bin2hex(random_bytes(24)) . PHP_EOL;"
nano config.php   # pega el token en QCR_VOTES_TOKEN_VALUE
```

- La URL que configura el plugin de Jellyfin es:
  `https://tu-dominio.com/api/votes.php?token=TU_TOKEN`
- Para mirar los votos **sin consumirlos**: añade `&ver=1`
- Alternativa sin `config.php`: define la variable de entorno `QCR_VOTES_TOKEN`

### Paso 3: Cron del historial (obligatorio)

En cPanel → **Cron Jobs** (o crontab):

```
* * * * * php /home/USUARIO/public_html/api/cron-update.php >/dev/null 2>&1
```

Consulta el historial de Jellyfin cada minuto, registra las canciones nuevas y baja sus portadas. Es idempotente: puede ejecutarse las veces que haga falta sin duplicados.

### Paso 4: Programación y personalización

| Archivo | Descripción |
|---|---|
| `programacion.json` | Crea una copia de `programacion.json.example` y edítala: programas por día con `dia`, `hora_inicio`, `hora_fin`, `programa`, `descripción` |
| `index.php` | Textos, estructura del header/footer/hero, fuentes (Google Fonts) y el stream (`<audio src="https://tu-dominio.com/radio">`) |
| `assets/css/app.css` | Todos los estilos: paleta de colores (variables al inicio del archivo), tipografías, botones, tarjetas, tema claro/oscuro. Bloques propios al final: banner de instalación, `.waveform` (colores y animación de las barras), `.song-progress-*` (barra de progreso) y el azul del día marcado bajo hover (`.tab-btn.tab-selected:hover`) |
| `assets/js/app.js` | Bloque de configuración al inicio (URLs locales, stream) + render dinámico: filas de programación (`renderSchedule`), íconos de programas (`getProgramIcon`), historial (`renderHistory`), barras de la waveform (`initWaveform`: número y tamaño), progreso (`renderProgress`) y centrado de días (`centerDayTab`) |
| `api/config.php` | URL del servidor de la radio, token de consumo, clave HTTP del cron, plantilla de imágenes Jellyfin y claves VAPID |

### Paso 5: Retro-relleno de portadas por itemId (opcional)

La API de arte del plugin solo expone la portada de la canción que suena AHORA. Si una fila del historial quedó con portada nula (p. ej. por una falla momentánea), con esta plantilla el cron la repara sola por el itemId de Jellyfin, sin esperar a que la canción vuelva a sonar:

```bash
nano api/config.php
define('QCR_JELLYFIN_IMAGES_URL_VALUE', 'https://TU-JELLYFIN/Items/{itemId}/Images/Primary');
```

- El placeholder `{itemId}` es obligatorio; debe ser https (o http si tu Jellyfin está en la red local: 192.168.x.x, 10.x.x.x…)
- Sin plantilla el retro-relleno queda deshabilitado y las portadas se reparan por la vía oportunista (cuando la canción repite)
- Máximo 2 reparaciones por minuto y 6 horas de espera entre intentos de la misma portada: un item sin imagen nunca se martilla
- Diagnóstico manual: `php api/maintenance.php covers:audit` y `php api/maintenance.php covers:backfill`

### Paso 6: Avisos push de programas (opcional)

Notificación «En 10 minutos empieza…» con Web Push estándar (VAPID + cifrado aes128gcm, 100% PHP, sin Firebase ni Composer):

```bash
# 1) Genera las claves VAPID UNA vez y pégalas en api/config.php:
php api/push-trigger.php --generate-keys

# 2) Añade UNA línea más al cron (cada minuto):
#    * * * * * php /home/USUARIO/public_html/api/push-trigger.php >/dev/null 2>&1

# 3) Verifica que todo quedó vivo:
php api/push-trigger.php --verbose
```

- La fuente de datos es `programacion.json`: si editas la parrilla, los avisos la siguen; zona horaria America/Guatemala
- Anti-spam por diseño: el oyente se suscribe VOLUNTARIAMENTE desde el panel de Programación (botón «🔔 Activar avisos»), hay máximo 1 aviso por programa y día, nunca avisos atrasados, no se apilan (mismo tag), el aviso se silencia si el oyente ya está escuchando y la baja es en 1 toque (botón en la web o «Silenciar avisos» dentro de la propia notificación)
- **El oyente elige sus franjas** (v0.1.7, multi-selección): puede marcar **Noche** (inicio 22:00 a 05:00), **Mañana** (05:00 a 14:00) y/o **Tarde** (14:00 a 21:00) a la vez, o los excluyentes **Todos los programas** y **Ninguno** (solo anuncios manuales). La franja se evalúa sobre la hora de INICIO del programa (nota: entre 21:00 y 21:59 solo reciben avisos los de «Todos»). Con «Cambiar franjas» las modifica sin darse de baja. Suscripciones anteriores siguen funcionando: `morning`→mañana, `afternoon`/`evening`→tarde, `day`→mañana+tarde
- Al tocar la notificación se abre la web/PWA y arranca la radio (si el navegador bloquea el autoplay, queda lista con el botón de play)
- Las suscripciones muertas (404/410) se limpian solas; almacenadas en `api/data/subscribers.json` (carpeta protegida, sin acceso web)
- iOS: los avisos requieren la PWA instalada en pantalla de inicio (iOS ≥ 16.4); en Android funcionan en el navegador y en la PWA

#### Enviar avisos push manualmente

Todos los comandos se ejecutan desde la raíz de la web (`~/public_html`). Los avisos manuales llegan a **TODOS** los suscriptores, sin importar su franja (la franja solo filtra los recordatorios automáticos de programas):

| Comando | Qué hace |
|---|---|
| `php api/push-trigger.php --anuncio="Hoy a las 20:00, programa especial"` | Envía TU texto como aviso a todos los suscriptores |
| `php api/push-trigger.php --test-send` | Notificación de prueba a todos («si lees esto, todo funciona») |
| `php api/push-trigger.php --verbose` | Ciclo normal con detalle: **Suscriptores: N**, programas por avisar ahora y resultado de cada envío |
| `php api/push-trigger.php --dry-run --verbose` | Muestra qué aviso enviaría AHORA mismo, sin enviar nada ni marcar estado |

Consejos rápidos:

- Antes de un evento: `--test-send` para confirmar que llega, y luego `--anuncio="..."` con tu mensaje real
- Si `--verbose` dice `Suscriptores: 0` pese a que ya hay gente activada, casi siempre es el archivo `subscribers.json` que falta o no es escribible (ver la sección siguiente)
- Cada envío deja rastro en `api/data/push-trigger.log`

---

## 📦 Archivos de datos y permisos (importante)

Todo el estado vive en archivos JSON (sin base de datos). Normalmente **PHP los crea solo** la primera vez que se usan; pero en algunos hostings el usuario con el que corre la web (p. ej. `php-web`) NO puede crear archivos dentro de carpetas que subiste tú por FTP/ZIP (dueño distinto). Si al activar los avisos, votar o usar la web todo «funciona pero no guarda nada», crealos tú a mano:

```bash
cd ~/public_html

# Historial de canciones (vive en la raíz de api/):
echo '[]' > api/history.json

# Carpeta protegida api/data/ — votos, avisos push y caché:
echo '[]' > api/data/votes.json
echo '{}' > api/data/voters.json
echo '[]' > api/data/subscribers.json
echo '{}' > api/data/np-cache.json

# Permisos de escritura para el usuario de PHP:
chmod 666 api/history.json api/data/*.json
chmod 777 api/covers   # portadas que descarga el cron
```

| Archivo | Qué guarda | Contenido inicial |
|---|---|---|
| `api/history.json` | Historial de canciones | `[]` |
| `api/data/votes.json` | Conteos de votos por canción | `[]` o `{}` |
| `api/data/voters.json` | Voto anónimo por visitante (cookie) | `{}` |
| `api/data/subscribers.json` | Suscriptores de los avisos push (endpoint, claves y franjas en CSV) | `[]` |
| `api/data/np-cache.json` | Caché de 5 s de «Ahora suena» | `{}` |
| `api/data/push-state.json` | Avisos ya enviados hoy (idempotencia) | se crea solo al primer aviso |
| `api/data/push-trigger.log` / `api/cron-update.log` | Logs acumulativos (opcionales) | se crean solos |

Notas:

- Si tienes dudas, `[]` también funciona como contenido inicial de todos (las APIs lo reinterpretan); lo importante es que el archivo EXISTA y PHP pueda escribirlo
- Si el archivo ya existe pero PHP no puede ESCRIBIRLO, los votos/avisos fallan en silencio: `chmod 666` lo resuelve
- Comprueba el dueño con `ls -l api/data/`: si tu usuario de FTP creó la carpeta, PHP (otro usuario) necesita permiso de escritura para el grupo u «otros» en la carpeta y 666 en los archivos
- Tras crear `subscribers.json`, el oyente debe volver a pulsar «🔔 Activar avisos» en la web (desactivar y activar si ya estaba activado)

---

## 🔄 Como Funciona

```
┌───────────────────────────────────────────────────────────┐
│                    Tu hosting (PHP)                       │
│                                                           │
│  ┌──────────────┐  cron 1 min   ┌──────────────────────┐  │
│  │   index.php  │─────────────▶│  api/cron-update.php │  │
│  │  + app.js    │               │  (historial 24/7)    │  │
│  └──────┬───────┘               └──────────┬───────────┘  │
│         │                                  │              │
│         ▼                                  ▼              │
│  ┌──────────────┐               ┌──────────────────────┐  │
│  │ api/history. │               │ api/covers/ (150px)  │  │
│  │ php (JSON)   │               │ portadas en caché    │  │
│  └──────────────┘               └──────────────────────┘  │
│         │                                  ▲              │
│         ▼                                  │              │
│  ┌──────────────┐                          │              │
│  │ api/vote.php │  votos anónimos con      │              │
│  │ api/votes.php│  itemId de Jellyfin ─────┘              │
│  └──────────────┘                                         │
└────────────────────────┬──────────────────────────────────┘
                         │ NowPlaying / artwork / consumo semanal
                         ▼
┌────────────────────────────────────────────────────────────┐
│  Jellyfin (plugin Radio Online) → Liquidsoap → Icecast     │
└────────────────────────────────────────────────────────────┘
```

### Flujo de Operacion

1. **Ahora suena**: el reproductor consulta `api/nowplaying.php` (proxy del propio hosting que oculta el servidor de la radio) cada 10 s y pinta portada, metadatos, votos y barra de progreso al instante.
2. **Historial 24/7**: el cron del servidor registra cada canción (con su `itemId`) y baja la portada a `api/covers/`. Si un visitante detecta el cambio antes, el navegador hace un respaldo idempotente que además dispara la descarga de la portada en ese mismo momento.
3. **Votos**: cada oyente vota 👍/👎 una vez por canción (cookie anónima `qc_vid`, cambio y anulación permitidos). El voto se guarda con el `itemId` de Jellyfin.
4. **Ciclo semanal**: el plugin llama `api/votes.php?token=...` 1 vez/semana → recibe todo lo acumulado ordenado por popularidad, se guarda respaldo y el contador vuelve a cero (todos pueden volver a votar).

---

## 🎛️ API

| Endpoint | Método | Descripción |
|---|---|---|
| `api/history.php` | GET | Historial público (últimas 10 canciones + portadas, sin campos internos) |
| `api/history.php` | POST | Respaldo idempotente desde el navegador (incluye `artworkUrl` para bajar la portada al instante) |
| `api/nowplaying.php` | GET | Proxy local de "Ahora suena" (caché breve de 5 s; nunca expone el dominio del upstream). Incluye `elapsedSec`/`serverTime` para la barra de progreso |
| `api/artwork.php` | GET | Proxy de portadas del reproductor (`maxWidth` 16–2048; solo imágenes validadas) |
| `api/vote.php` | GET | Mis votos según cookie anónima (sin conteos: no son públicos) |
| `api/vote.php` | POST | Emitir / cambiar / anular voto `{ artist, title, vote, itemId }` |
| `api/votes.php?token=...` | GET | ⚠️ Privado: **consume** los votos (lista ordenada + respaldo + reset del ciclo) |
| `api/votes.php?token=...&ver=1` | GET | Privado: solo lectura (no toca nada) |
| `api/cron-update.php` | CLI | Actualizador 24/7 del historial (`--verbose` para detalle) |
| `api/push-config.php` | GET | Clave pública VAPID para el navegador (503 si no está configurada) |
| `api/push-subscribe.php` | GET/POST/DELETE | Alta, cambio de franjas y baja de suscriptores push (`GET ?endpoint=` lee las franjas guardadas) |
| `api/push-trigger.php` | CLI / HTTP | Cron de avisos + manuales (`--anuncio`, `--test-send`, `--dry-run`) |
| `api/maintenance.php` | CLI | Auditoría/limpieza de portadas y deduplicación del historial |

Carpetas protegidas con `.htaccess` (Apache 2.2/2.4): `api/data/` (votos, votantes, suscriptores push y respaldos) y `api/covers/`.

---

## 🔧 Solucion de Problemas

### Falta el CSS o algún asset (404)
- El ZIP debe extraerse **dentro** de la carpeta destino (`~/public_html/` en producción): comprueba que exista `assets/css/app.css`
- Tras actualizar archivos, haz **Ctrl+F5** (el service worker renueva la caché con el nuevo `CACHE_NAME`)

### El historial no se actualiza
- Verifica que el cron esté programado y apunte a `api/cron-update.php`
- Prueba a mano por CLI: `php api/cron-update.php --verbose` (sin URL configurada termina en error con la instrucción exacta)
- Comprueba que `api/history.json` y `api/covers/` tienen permiso de escritura (755/644)

### La web queda en "Esperando transmisión…"
- Falta `api/config.php` con la URL del servidor de la radio (Paso 1 de Configuración)
- Comprueba el proxy: `curl https://tu-dominio.com/api/nowplaying.php` → debe devolver el JSON con la canción en emisión

### El consumo de votos responde 503 `token_no_configurado`
- Falta definir `QCR_VOTES_TOKEN_VALUE` en `api/config.php` (Paso 2 de Configuración)

### La programación no se ve
- `programacion.json` debe ser JSON válido; usa `programacion.json.example` como plantilla
- Los cambios manuales se reflejan solos (la web la reconsulta cada 5 minutos y al recargar)

### Los avisos push dicen «Suscriptores: 0» o no se activan
- Casi siempre es el archivo `subscribers.json` que falta o que PHP no puede escribir: créalo a mano y dale permisos (ver [Archivos de datos y permisos](#-archivos-de-datos-y-permisos-importante))
- Tras crearlo, el oyente debe pulsar de nuevo «🔔 Activar avisos» (desactivar y activar si ya estaba)
- Comprueba las claves VAPID: `php api/push-trigger.php --verbose` NO debe decir «faltan las claves»
- Detalle de cada envío: `php api/push-trigger.php --verbose` y el log `api/data/push-trigger.log`

### Los votos o el historial no se guardan
- Suele ser permisos: PHP no puede crear/escribir los JSON de datos (ver [Archivos de datos y permisos](#-archivos-de-datos-y-permisos-importante))
- Diagnóstico rápido: `ls -l api/ api/data/` — los archivos deben ser escribibles por el usuario de PHP

---

## 🏗️ Estructura

| Archivo / Carpeta | Responsabilidad |
|---|---|
| `index.php` | Página principal (HTML editable en cPanel) |
| `assets/js/app.js` | Toda la lógica del navegador: polling, votos, programación, PWA |
| `assets/css/app.css` | Estilos (tema claro/oscuro incluido) |
| `assets/img/` | Iconos de la marca (favicon, PWA, maskable) |
| `manifest.webmanifest` | Manifiesto de la PWA |
| `sw.js` | Service worker (precache del shell, red-primero en APIs) |
| `api/history.php` | API del historial (GET/POST idempotente) |
| `api/history-lib.php` | Registro idempotente, deduplicación por duración, zona horaria |
| `api/nowplaying.php` | Proxy JSON de "Ahora suena" (caché breve con `flock`, sin exponer el upstream) |
| `api/artwork.php` | Proxy de portadas del reproductor (validación de imagen real) |
| `api/np-lib.php` | Resolución de la URL del servidor de la radio (env/config) y descarga HTTP compartida |
| `api/cron-update.php` | Actualizador automático 24/7 (cron) |
| `api/push-lib.php` | Motor Web Push: VAPID ES256 (RFC 8292), cifrado aes128gcm (RFC 8291), franjas horarias multi-selección y almacén de suscriptores |
| `api/push-config.php` | Entrega la clave pública VAPID al navegador |
| `api/push-subscribe.php` | Alta / cambio de franjas / lectura / baja de suscriptores (JSON puro) |
| `api/push-trigger.php` | Cron de avisos cada minuto + avisos manuales y pruebas (CLI/HTTP) |
| `api/covers-lib.php` | Caché de portadas (descarga, validación y recolector de basura) |
| `api/vote.php` | Votos individuales anónimos (cookie `qc_vid`) |
| `api/vote-lib.php` | Lógica de votos, ciclo de consumo atómico con `flock` |
| `api/votes.php` | Export/consumo privado para Jellyfin (token) |
| `api/config.example.php` | Plantilla de configuración privada (copiar a `config.php`) |
| `api/data/` | `votes.json`, `voters.json`, `subscribers.json`, `push-state.json`, `np-cache.json` y respaldos semanales (protegido) |
| `programacion.json.example` | Plantilla de la programación semanal |

---

## 💬 Soporte y Contribuciones

- **Reportes de bugs y sugerencias**: Usa la seccion de [Issues](https://github.com/pepebarrascout/radio-en-linea-web/issues)
- **Contribuciones**: Las contribuciones son bienvenidas. No dudes en enviar un Pull Request

---

## ⚠️ Disclaimer

Este proyecto es un proyecto independiente y no esta afiliado, respaldado ni patrocinado por Jellyfin o Liquidsoap.

---

## 📄 Licencia

Este proyecto esta bajo la licencia [MIT](LICENSE).
