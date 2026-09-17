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
| 📡 **En vivo 24/7** | Reproductor del stream de Icecast con indicador EN VIVO |
| 🎵 **Historial automático** | El servidor registra las canciones cada minuto vía cron, aunque nadie esté navegando |
| 🖼️ **Portadas de discos** | Miniaturas en caché local servidas desde el propio hosting |
| 📅 **Programación semanal** | Programas por día y horario editando un simple `programacion.json` |
| 🗳️ **Votos de oyentes** | Like/dislike anónimo (cookie, 1 voto activo por canción, cambiable) |
| 🔄 **Ciclo semanal de votos** | Jellyfin consume los totales 1 vez/semana con token; el contador vuelve a cero |
| 🪪 **itemId de Jellyfin** | Cada voto viaja con el GUID del ítem para que el plugin consulte su base de datos |
| 🛡️ **Sin dominios expuestos** | El navegador solo habla con tu hosting (proxys de NowPlaying y portadas); el dominio del servidor de la radio nunca aparece en el código ni en la red del cliente |
| 🔒 **Privacidad** | Los conteos nunca son públicos; los votantes nunca se exponen |
| 📲 **PWA instalable** | Manifest + service worker + banner de instalación con detección Android/iOS |
| 🌗 **Tema claro/oscuro** | Persistente, aplicado antes de pintar (sin destellos) |
| 📱 **Media Session** | Portada y metadatos en la pantalla de bloqueo del móvil |

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
2. En tu servidor (o por SSH en cPanel), dentro de la carpeta pública destino (p. ej. `public_html/qc/`):

   ```bash
   cd ~/public_html/qc
   wget https://github.com/pepebarrascout/radio-en-linea-web/releases/download/v0.1.1/radio-en-linea-web-v0.1.1.zip
   unzip radio-en-linea-web-v0.1.1.zip && rm radio-en-linea-web-v0.1.1.zip
   ```

3. Crea la configuración privada (Pasos 1 y 2 de [Configuración](#️-configuracion))
4. Programa el cron cada 1 minuto (Paso 3)
5. Abre tu web y pulsa play 🎶

> El ZIP trae ya la estructura completa (incluidas las carpetas `api/data/` y `api/covers/` con su `.htaccess` de protección). Los archivos de datos se crean solos la primera vez que se usa la web.

### Metodo 2: Clonar el repositorio

```bash
cd ~/public_html
git clone https://github.com/pepebarrascout/radio-en-linea-web.git qc
```

Después, los mismos pasos 3-5 del Método 1.

---

## ⚙️ Configuracion

### Paso 1: URL del servidor de la radio (obligatorio)

El dominio de tu servidor (endpoint NowPlaying del plugin RadioOnline) **nunca va escrito en el código**: el navegador consulta los proxys del propio hosting (`api/nowplaying.php` y `api/artwork.php`) y solo el PHP del servidor conoce la URL real.

```bash
cd ~/public_html/qc/api
cp config.example.php config.php
nano config.php   # pega tu URL en QCR_NOWPLAYING_URL_VALUE
```

- Formato: `https://tu-servidor-de-radio/RadioOnline/NowPlaying`
- Alternativa: variable de entorno `QCR_NOWPLAYING_URL`
- Sin esta URL la web muestra "Esperando transmisión…" y el cron no registra canciones

### Paso 2: Token secreto de consumo (obligatorio)

Los votos se consumen desde Jellyfin **una vez a la semana** con un token secreto que **nunca va hardcodeado en el código**:

```bash
cd ~/public_html/qc/api
cp config.example.php config.php   # si ya lo creaste en el Paso 1, solo añade el token
# Genera un token aleatorio y edítalo:
php -r "echo bin2hex(random_bytes(24)) . PHP_EOL;"
nano config.php   # pega el token en QCR_VOTES_TOKEN_VALUE
```

- La URL que configura el plugin de Jellyfin es:
  `https://tu-dominio.com/qc/api/votes.php?token=TU_TOKEN`
- Para mirar los votos **sin consumirlos**: añade `&ver=1`
- Alternativa sin `config.php`: define la variable de entorno `QCR_VOTES_TOKEN`

### Paso 3: Cron del historial (obligatorio)

En cPanel → **Cron Jobs** (o crontab):

```
* * * * * php /home/USUARIO/public_html/qc/api/cron-update.php >/dev/null 2>&1
```

Consulta el historial de Jellyfin cada minuto, registra las canciones nuevas y baja sus portadas. Es idempotente: puede ejecutarse las veces que haga falta sin duplicados.

### Paso 4: Programación y personalización

| Archivo | Descripción |
|---|---|
| `programacion.json` | Crea una copia de `programacion.json.example` y edítala: programas por día con `dia`, `hora_inicio`, `hora_fin`, `programa`, `descripción` |
| `index.php` | Textos, estructura del header/footer/hero, fuentes (Google Fonts) y el stream (`<audio src="https://tu-dominio.com/radio">`) |
| `assets/css/app.css` | Todos los estilos: paleta de colores (variables al inicio del archivo), tipografías, botones, tarjetas, tema claro/oscuro |
| `assets/js/app.js` | Bloque de configuración al inicio (URLs locales, stream) + render dinámico: filas de programación (`renderSchedule`), íconos de programas (`getProgramIcon`), historial (`renderHistory`) |
| `api/config.php` | URL del servidor de la radio, token de consumo y clave HTTP del cron |

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
└────────────────────────┬───────────────────────────────────┘
                         │ NowPlaying / artwork / consumo semanal
                         ▼
┌────────────────────────────────────────────────────────────┐
│  Jellyfin (plugin Radio Online) → Liquidsoap → Icecast     │
└────────────────────────────────────────────────────────────┘
```

### Flujo de Operacion

1. **Ahora suena**: el reproductor consulta `api/nowplaying.php` (proxy del propio hosting que oculta el servidor de la radio) cada 10 s y pinta portada, metadatos y votos al instante.
2. **Historial 24/7**: el cron del servidor registra cada canción (con su `itemId`) y baja la portada a `api/covers/`. Si un visitante detecta el cambio antes, el navegador hace un respaldo idempotente que además dispara la descarga de la portada en ese mismo momento.
3. **Votos**: cada oyente vota 👍/👎 una vez por canción (cookie anónima `qc_vid`, cambio y anulación permitidos). El voto se guarda con el `itemId` de Jellyfin.
4. **Ciclo semanal**: el plugin llama `api/votes.php?token=...` 1 vez/semana → recibe todo lo acumulado ordenado por popularidad, se guarda respaldo y el contador vuelve a cero (todos pueden volver a votar).

---

## 🎛️ API

| Endpoint | Método | Descripción |
|---|---|---|
| `api/history.php` | GET | Historial público (últimas 10 canciones + portadas, sin campos internos) |
| `api/history.php` | POST | Respaldo idempotente desde el navegador (incluye `artworkUrl` para bajar la portada al instante) |
| `api/nowplaying.php` | GET | Proxy local de "Ahora suena" (caché breve de 5 s; nunca expone el dominio del upstream) |
| `api/artwork.php` | GET | Proxy de portadas del reproductor (`maxWidth` 16–2048; solo imágenes validadas) |
| `api/vote.php` | GET | Mis votos según cookie anónima (sin conteos: no son públicos) |
| `api/vote.php` | POST | Emitir / cambiar / anular voto `{ artist, title, vote, itemId }` |
| `api/votes.php?token=...` | GET | ⚠️ Privado: **consume** los votos (lista ordenada + respaldo + reset del ciclo) |
| `api/votes.php?token=...&ver=1` | GET | Privado: solo lectura (no toca nada) |
| `api/cron-update.php` | CLI | Actualizador 24/7 del historial (`--verbose` para detalle) |

Carpetas protegidas con `.htaccess` (Apache 2.2/2.4): `api/data/` (votos, votantes y respaldos) y `api/covers/`.

---

## 🔧 Solucion de Problemas

### Falta el CSS o algún asset (404)
- El ZIP debe extraerse **dentro** de la carpeta destino (`public_html/qc/`): comprueba que exista `assets/css/app.css`
- Tras actualizar archivos, haz **Ctrl+F5** (el service worker renueva la caché con el nuevo `CACHE_NAME`)

### El historial no se actualiza
- Verifica que el cron esté programado y apunte a `api/cron-update.php`
- Prueba a mano por CLI: `php api/cron-update.php --verbose` (sin URL configurada termina en error con la instrucción exacta)
- Comprueba que `api/history.json` y `api/covers/` tienen permiso de escritura (755/644)

### La web queda en "Esperando transmisión…"
- Falta `api/config.php` con la URL del servidor de la radio (Paso 1 de Configuración)
- Comprueba el proxy: `curl https://tu-dominio.com/qc/api/nowplaying.php` → debe devolver el JSON con la canción en emisión

### El consumo de votos responde 503 `token_no_configurado`
- Falta definir `QCR_VOTES_TOKEN_VALUE` en `api/config.php` (Paso 2 de Configuración)

### La programación no se ve
- `programacion.json` debe ser JSON válido; usa `programacion.json.example` como plantilla
- Los cambios manuales se reflejan solos (la web la reconsulta cada 5 minutos y al recargar)

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
| `api/covers-lib.php` | Caché de portadas (descarga, validación y recolector de basura) |
| `api/vote.php` | Votos individuales anónimos (cookie `qc_vid`) |
| `api/vote-lib.php` | Lógica de votos, ciclo de consumo atómico con `flock` |
| `api/votes.php` | Export/consumo privado para Jellyfin (token) |
| `api/config.example.php` | Plantilla de configuración privada (copiar a `config.php`) |
| `api/data/` | `votes.json`, `voters.json`, respaldos semanales (protegido) |
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
