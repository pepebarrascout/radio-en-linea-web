<?php
/**
 * ============================================================
 *  Que Chilero Radio — Configuración PRIVADA (no versionar)
 * ============================================================
 *  1. Copia este archivo como "config.php" (mismo directorio):
 *
 *         cp config.example.php config.php
 *
 *  2. Edita config.php con TUS valores. Ese archivo está en
 *     .gitignore y NUNCA debe subirse al repositorio ni hacerse
 *     público.
 *
 *  Todos los valores son OPCIONALES: si no existen, se usan los
 *  valores por defecto del código (documentados en cada caso).
 * ============================================================
 */

/**
 * Token secreto que consume los votos (1 vez/semana, llamado por
 * el plugin de Jellyfin). SIN token configurado el consumo queda
 * DESHABILITADO (api/votes.php responde 503).
 *
 * Genera uno aleatorio con:
 *     php -r "echo bin2hex(random_bytes(24));"
 *
 * Alternativa: definir la variable de entorno QCR_VOTES_TOKEN
 * (tiene prioridad sobre este archivo).
 */
define('QCR_VOTES_TOKEN_VALUE', 'PEGA_AQUI_UN_TOKEN_ALEATORIO_LARGO');

/**
 * ⭐ URL del endpoint NowPlaying del plugin RadioOnline en tu
 * servidor de la radio (OBLIGATORIA: sin ella la web muestra
 * "Esperando transmisión…" y el cron no puede registrar canciones).
 *
 * Ejemplo de formato (usa TU dominio; NUNCA va hardcodeada en el
 * código del repositorio, por eso se define aquí o en el entorno):
 *     https://tu-servidor-de-radio/RadioOnline/NowPlaying
 *
 * De esta URL el servidor deriva también el endpoint de portadas
 * (…/RadioOnline/NowPlaying/Artwork), que sirve el navegador vía
 * api/artwork.php sin exponer tu dominio.
 *
 * Alternativa: variable de entorno QCR_NOWPLAYING_URL
 * (tiene prioridad sobre este archivo).
 */
define('QCR_NOWPLAYING_URL_VALUE', 'PEGA_AQUI_LA_URL_DE_TU_SERVIDOR_DE_RADIO');

/**
 * (Opcional) Clave para llamar al actualizador por HTTP
 * (api/cron-update.php?key=...). Vacía = solo CLI (recomendado).
 * Alternativa: variable de entorno QCR_CRON_SECRET.
 */
// define('QCR_CRON_SECRET_VALUE', 'una-clave-larga-aleatoria');

/**
 * ⭐ v0.1.4 — Retro-relleno de portadas viejas (OPCIONAL).
 *
 * Plantilla de la URL de imágenes de TU servidor Jellyfin, para
 * reparar portadas nulas del historial por itemId (la API de arte
 * del plugin solo expone la canción ACTUAL). El placeholder
 * {itemId} es obligatorio y la URL debe ser https:
 *
 *     https://TU-SERVIDOR-JELLYFIN/Items/{itemId}/Images/Primary
 *
 * Sin esta plantilla el retro-relleno queda deshabilitado y las
 * portadas viejas se reparan por la vía oportunista de siempre
 * (cuando la canción vuelve a sonar). Dejarla vacía es 100% válido.
 *
 * Alternativa: variable de entorno QCR_JELLYFIN_IMAGES_URL
 * (tiene prioridad sobre este archivo).
 */
define('QCR_JELLYFIN_IMAGES_URL_VALUE', '');

/**
 * ⭐ v0.1.4 — Notificaciones push de programas (Web Push con VAPID).
 *
 * Se generan UNA SOLA VEZ con el comando:
 *
 *     php api/push-trigger.php --generate-keys
 *
 * y se pegan aquí tal cual muestra el comando. Sin claves el
 * botón de la web no aparece y el disparador no envía nada.
 *
 * La clave PÚBLICA no es un secreto (la recibe el navegador);
 * la clave PRIVADA sí: este archivo nunca se versiona.
 */
define('QCR_VAPID_PUBLIC_KEY_VALUE', 'PEGA_AQUI_LA_CLAVE_PUBLICA_BASE64URL');
define('QCR_VAPID_PRIVATE_PEM_VALUE', 'PEGA_AQUI_LA_CLAVE_PRIVADA_PEM_COMPLETA');

/**
 * (Opcional) Contacto que viaja en el token VAPID por si el
 * servicio push necesita avisarte (formato mailto:).
 * Alternativa: variable de entorno QCR_VAPID_SUBJECT.
 */
define('QCR_VAPID_SUBJECT_VALUE', 'mailto:contacto@quechilero.com');
