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
 * (Opcional) Endpoint de metadatos del plugin RadioOnline si tu
 * Jellyfin vive en otra URL. Por defecto el código usa:
 *     https://jellyfin.blogsdeguatemala.com/RadioOnline/NowPlaying
 * Alternativa: variable de entorno QCR_NOWPLAYING_URL.
 */
// define('QCR_NOWPLAYING_URL_VALUE', 'https://tu-jellyfin/RadioOnline/NowPlaying');

/**
 * (Opcional) Clave para llamar al actualizador por HTTP
 * (api/cron-update.php?key=...). Vacía = solo CLI (recomendado).
 * Alternativa: variable de entorno QCR_CRON_SECRET.
 */
// define('QCR_CRON_SECRET_VALUE', 'una-clave-larga-aleatoria');
