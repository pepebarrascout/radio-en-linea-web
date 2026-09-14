<?php
/**
 * ============================================================
 *  Que Chilero Radio — Librería de votos (me gusta / no me gusta)
 * ============================================================
 *  Almacenamiento: JSON puro (sin base de datos).
 *
 *    api/data/votes.json   → conteos agregados por canción
 *        { "clave": { artist, title, likes, dislikes, updated } }
 *
 *    api/data/voters.json  → voto de cada visitante (anti-duplicado)
 *        { "idVisitante": { "clave": "like" | "dislike" } }
 *
 *  Un visitante = una cookie anónima (qc_vid) de 1 año.
 *  NO hay registro de usuario. El voto ES CAMBIABLE: se puede
 *  pasar de 👍 a 👎, o anularlo, siempre 1 voto activo por
 *  visitante y canción.
 *
 *  Jellyfin consume los totales vía api/votes.php UNA VEZ A LA
 *  SEMANA con su token secreto (lectura consumidora): recibe TODO
 *  lo acumulado, se guarda una copia de respaldo y el contador
 *  vuelve a cero (nuevo ciclo: todos pueden volver a votar).
 * ============================================================
 */

require_once __DIR__ . '/history-lib.php';

// ── Configuración ────────────────────────────────────────────

// Carpeta de datos (votes.json + voters.json).
// QCR_DATA_DIR permite redirigirla (p. ej. fuera del webroot o en pruebas).
define('DATA_DIR', getenv('QCR_DATA_DIR') ?: __DIR__ . '/data');
define('VOTES_FILE', DATA_DIR . '/votes.json');
define('VOTERS_FILE', DATA_DIR . '/voters.json');

// Cookie anónima del votante
define('VOTER_COOKIE', 'qc_vid');
define('VOTER_COOKIE_DAYS', 365);

// Límite de canciones recordadas por visitante (acota el archivo)
define('MAX_VOTES_PER_VOTER', 500);

// Límites de entrada
define('MAX_TEXT_LENGTH', 300);

// ── Ciclo de consumo (Opción C: lectura consumidora) ─────────
// Respaldos en api/data/ (carpeta protegida, NO accesible por web)
define('VOTES_ARCHIVE_PREFIX', 'votes-archivo-');
// Respaldos conservados (12 ciclos semanales ≈ 3 meses)
define('VOTES_ARCHIVE_KEEP', 12);
// Registro interno de consumos (auditoría ligera)
define('VOTES_LOG_FILE', DATA_DIR . '/votes-log.txt');

// ── Utilidades de almacenamiento ─────────────────────────────

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR) && !@mkdir(DATA_DIR, 0755, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('No se pudo crear el directorio de datos');
    }
}

/**
 * Lee un JSON bajo lock compartido. Devuelve [] si no existe o
 * está corrupto (nunca lanza error hacia el usuario).
 */
function readJsonFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $fp = fopen($path, 'r');
    if (!$fp) {
        return [];
    }
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return [];
    }
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    $data = json_decode((string)$content, true);
    return is_array($data) ? $data : [];
}

/**
 * Escritura atómica (tmp + rename + fflush) bajo lock exclusivo.
 */
function writeJsonFileAtomic(string $path, array $data): bool
{
    ensureDataDir();
    $tmp = $path . '.' . getmypid() . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

// ── Identidad anónima del votante ────────────────────────────

/**
 * Devuelve el ID anónimo del visitante desde la cookie.
 * Si no existe, genera uno y lo envía como cookie nueva
 * (1 año, HttpOnly, SameSite=Lax, Secure si hay HTTPS).
 * Debe llamarse ANTES de cualquier echo.
 */
function getOrCreateVoterId(): string
{
    $vid = trim((string)($_COOKIE[VOTER_COOKIE] ?? ''));
    if (preg_match('/^[a-f0-9]{32}$/', $vid)) {
        return $vid;
    }

    $vid = bin2hex(random_bytes(16));

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    if (!headers_sent()) {
        setcookie(VOTER_COOKIE, $vid, [
            'expires'  => time() + VOTER_COOKIE_DAYS * 86400,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    return $vid;
}

// ── Lectura de votos ─────────────────────────────────────────

/**
 * Conteos agregados por canción:
 * { clave: { artist, title, likes, dislikes, updated } }
 */
function getVoteCounts(): array
{
    return readJsonFile(VOTES_FILE);
}

/**
 * Votos emitidos por un visitante: { clave: 'like'|'dislike' }
 */
function getMyVotes(string $vid): array
{
    $voters = readJsonFile(VOTERS_FILE);
    return is_array($voters[$vid] ?? null) ? $voters[$vid] : [];
}

/**
 * Votos emitidos por un visitante, MAPEADOS A PAREJA PÚBLICA:
 * { "artista||título": 'like'|'dislike' }
 * (une voters.json con votes.json; los conteos NUNCA se exponen).
 */
function getMyVotesPublic(string $vid): array
{
    $voters = readJsonFile(VOTERS_FILE);
    $myKeys = is_array($voters[$vid] ?? null) ? $voters[$vid] : [];
    if ($myKeys === []) {
        return [];
    }

    $votes = readJsonFile(VOTES_FILE);
    $out = [];
    foreach ($myKeys as $key => $vote) {
        if (($vote !== 'like' && $vote !== 'dislike') || !is_array($votes[$key] ?? null)) {
            continue;
        }
        $pair = normalizeForKey((string)($votes[$key]['artist'] ?? ''))
              . '||' . normalizeForKey((string)($votes[$key]['title'] ?? ''));
        $out[$pair] = $vote;
    }
    return $out;
}

// ── Emisión de voto ──────────────────────────────────────────

/**
 * Registra / cambia / anula el voto de un visitante.
 *
 * $action: 'like' | 'dislike' | 'remove'
 * $itemId: ID de Jellyfin (de NowPlaying). Si es válido, es la CLAVE
 *          del voto (para que Jellyfin pueda consultar su base de
 *          datos); si no, se usa md5(artista|título).
 *
 * Devuelve: [bool $ok, string|null $error, array $result]
 *   $result = { key, likes, dislikes, myVote }   (solo uso interno:
 *   vote.php no expone los conteos al navegador)
 */
function applyVote(string $vid, string $artist, string $title, string $action, string $itemId = ''): array
{
    $artist = trim($artist);
    $title  = trim($title);

    if ($artist === '' || $title === '') {
        return [false, 'missing_song', []];
    }
    if (mb_strlen($artist) > MAX_TEXT_LENGTH || mb_strlen($title) > MAX_TEXT_LENGTH) {
        return [false, 'song_too_long', []];
    }
    if (!in_array($action, ['like', 'dislike', 'remove'], true)) {
        return [false, 'invalid_action', []];
    }

    $itemId = sanitizeItemId($itemId);
    $key = $itemId !== '' ? $itemId : songKey($artist, $title);

    ensureDataDir();

    // ── Bloqueo de AMBOS archivos en orden fijo (sin deadlocks) ──
    $fpVoters = fopen(VOTERS_FILE, 'c+');
    $fpVotes  = fopen(VOTES_FILE, 'c+');
    if (!$fpVoters || !$fpVotes) {
        if ($fpVoters) fclose($fpVoters);
        if ($fpVotes)  fclose($fpVotes);
        return [false, 'storage_error', []];
    }
    if (!flock($fpVoters, LOCK_EX) || !flock($fpVotes, LOCK_EX)) {
        flock($fpVoters, LOCK_UN);
        flock($fpVotes, LOCK_UN);
        fclose($fpVoters);
        fclose($fpVotes);
        return [false, 'storage_error', []];
    }

    // Leer estado actual
    $votersRaw = json_decode((string)stream_get_contents($fpVoters), true);
    $votesRaw  = json_decode((string)stream_get_contents($fpVotes), true);
    $voters    = is_array($votersRaw) ? $votersRaw : [];
    $votes     = is_array($votesRaw) ? $votesRaw : [];

    $myVotes = is_array($voters[$vid] ?? null) ? $voters[$vid] : [];
    $current = $myVotes[$key] ?? null;   // 'like' | 'dislike' | null

    $song = is_array($votes[$key] ?? null) ? $votes[$key] : [
        'artist'   => $artist,
        'title'    => $title,
        'itemId'   => $itemId,
        'likes'    => 0,
        'dislikes' => 0,
        'updated'  => 0,
    ];

    // ── Aplicar la acción ──
    if ($action === 'remove') {
        if ($current === 'like')       $song['likes']    = max(0, $song['likes'] - 1);
        if ($current === 'dislike')    $song['dislikes'] = max(0, $song['dislikes'] - 1);
        unset($myVotes[$key]);
        $newVote = null;

    } elseif ($action === $current) {
        // Mismo voto otra vez: no-op (el frontend usa 'remove' para anular)
        $newVote = $current;

    } else {
        // Cambio de voto (like ⇄ dislike) o voto nuevo
        if ($current === 'like')       $song['likes']    = max(0, $song['likes'] - 1);
        if ($current === 'dislike')    $song['dislikes'] = max(0, $song['dislikes'] - 1);

        if ($action === 'like')        $song['likes']++;
        if ($action === 'dislike')     $song['dislikes']++;

        $myVotes[$key] = $action;
        $newVote = $action;
    }

    $song['artist']  = $artist;
    $song['title']   = $title;
    $song['itemId']  = $itemId;
    $song['updated'] = time();

    // Canción sin votos restantes y sin voto del visitante → limpiar
    if ($song['likes'] === 0 && $song['dislikes'] === 0 && $newVote === null) {
        unset($votes[$key]);
    } else {
        $votes[$key] = $song;
    }

    // Acotar memoria por visitante (conserva los votos más recientes)
    if (count($myVotes) > MAX_VOTES_PER_VOTER) {
        $myVotes = array_slice($myVotes, -MAX_VOTES_PER_VOTER, null, true);
    }

    // Visitante sin votos activos → fuera del archivo (higiene)
    if (count($myVotes) === 0) {
        unset($voters[$vid]);
    } else {
        $voters[$vid] = $myVotes;
    }

    // ── Escribir ambos archivos ──
    $okVoters = writeVotersLocked($fpVoters, $voters);
    $okVotes  = writeVotesLocked($fpVotes, $votes);

    flock($fpVoters, LOCK_UN);
    flock($fpVotes, LOCK_UN);
    fclose($fpVoters);
    fclose($fpVotes);

    if (!$okVoters || !$okVotes) {
        return [false, 'storage_error', []];
    }

    return [true, null, [
        'key'      => $key,
        'likes'    => (int)$song['likes'],
        'dislikes' => (int)$song['dislikes'],
        'myVote'   => $newVote,
    ]];
}

/**
 * Escritura del archivo de votantes REUSANDO el lock ya tomado.
 */
function writeVotersLocked($fp, array $voters): bool
{
    $json = json_encode($voters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, $json);
    return fflush($fp);
}

/**
 * Escritura del archivo de conteos REUSANDO el lock ya tomado.
 */
function writeVotesLocked($fp, array $votes): bool
{
    $json = json_encode($votes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, $json);
    return fflush($fp);
}

/**
 * Lista pública para Jellyfin a partir de unos conteos YA leídos.
 * Ordenada por popularidad (más "me gusta" primero; a igualdad,
 * menos "no me gusta" y más recientes). Sin datos de votantes.
 */
function buildJellyfinSongs(array $counts): array
{
    $songs = [];
    foreach ($counts as $key => $song) {
        if (!is_array($song)) {
            continue;
        }
        $songs[] = [
            'key'      => (string)$key,
            'itemId'   => (string)($song['itemId'] ?? ''),
            'artist'   => (string)($song['artist'] ?? ''),
            'title'    => (string)($song['title'] ?? ''),
            'likes'    => (int)($song['likes'] ?? 0),
            'dislikes' => (int)($song['dislikes'] ?? 0),
            'score'    => (int)($song['likes'] ?? 0) - (int)($song['dislikes'] ?? 0),
            'updated'  => (int)($song['updated'] ?? 0),
        ];
    }

    usort($songs, static function ($a, $b) {
        if ($a['likes'] !== $b['likes'])     return $b['likes'] <=> $a['likes'];
        if ($a['dislikes'] !== $b['dislikes']) return $a['dislikes'] <=> $b['dislikes'];
        return $b['updated'] <=> $a['updated'];
    });

    return $songs;
}

/**
 * Lista pública para Jellyfin (solo lectura, sin consumir).
 */
function getVotesForJellyfin(): array
{
    return buildJellyfinSongs(getVoteCounts());
}

// ── Consumo semanal (Opción C: lectura consumidora) ──────────

/**
 * Consume TODO lo acumulado en una sola operación atómica:
 *
 *   1. Bloquea voters.json y votes.json (mismo orden fijo que
 *      applyVote → imposible el deadlock con un voto en curso).
 *   2. Lee todo y construye la lista ordenada por popularidad.
 *   3. Guarda una copia de respaldo en
 *      api/data/votes-archivo-AAAA MMDD-HHMMSS.json
 *      (si el respaldo FALLA, NO se resetea nada: cero pérdida).
 *   4. Vacía votes.json Y voters.json → contador a cero y todos
 *      pueden volver a votar en el nuevo ciclo.
 *   5. Conserva solo los VOTES_ARCHIVE_KEEP respaldos más recientes.
 *   6. Deja una línea de auditoría en api/data/votes-log.txt.
 *
 * Devuelve: ['songs' => [...], 'count' => N, 'backup' => nombre|null]
 *           o false si hubo error de almacenamiento.
 */
function consumeAllVotes()
{
    ensureDataDir();

    $fpVoters = fopen(VOTERS_FILE, 'c+');
    $fpVotes  = fopen(VOTES_FILE, 'c+');
    if (!$fpVoters || !$fpVotes) {
        if ($fpVoters) fclose($fpVoters);
        if ($fpVotes)  fclose($fpVotes);
        return false;
    }
    if (!flock($fpVoters, LOCK_EX) || !flock($fpVotes, LOCK_EX)) {
        flock($fpVoters, LOCK_UN);
        flock($fpVotes, LOCK_UN);
        fclose($fpVoters);
        fclose($fpVotes);
        return false;
    }

    // Leer estado actual bajo lock
    $votersRaw = json_decode((string)stream_get_contents($fpVoters), true);
    $votesRaw  = json_decode((string)stream_get_contents($fpVotes), true);
    $votes     = is_array($votesRaw) ? $votesRaw : [];

    $songs  = buildJellyfinSongs($votes);
    $count  = count($songs);
    $backup = null;

    // Respaldo SOLO si hay algo que conservar; si falla, abortar sin resetear
    if ($count > 0) {
        $backup  = VOTES_ARCHIVE_PREFIX . date('Ymd-His') . '.json';
        $payload = json_encode([
            'generated' => date('Y-m-d H:i:s'),
            'count'     => $count,
            'songs'     => $songs,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $tmp    = DATA_DIR . '/' . $backup . '.' . getmypid() . '.tmp';
        $final  = DATA_DIR . '/' . $backup;
        $okBack = ($payload !== false)
               && (file_put_contents($tmp, $payload, LOCK_EX) !== false)
               && rename($tmp, $final);

        if (!$okBack) {
            @unlink($tmp);
            flock($fpVoters, LOCK_UN);
            flock($fpVotes, LOCK_UN);
            fclose($fpVoters);
            fclose($fpVotes);
            return false;   // no se perdió ni un voto: no se reseteó
        }

        pruneVoteArchives();
    }

    // Reset completo: conteos a cero + votantes limpios (nuevo ciclo)
    $okVotes  = writeVotesLocked($fpVotes, []);
    $okVoters = writeVotersLocked($fpVoters, []);

    flock($fpVoters, LOCK_UN);
    flock($fpVotes, LOCK_UN);
    fclose($fpVoters);
    fclose($fpVotes);

    if (!$okVotes || !$okVoters) {
        return false;
    }

    @file_put_contents(
        VOTES_LOG_FILE,
        sprintf(
            "[%s] Consumo: %d canciones · respaldo: %s · nuevo ciclo iniciado\n",
            date('Y-m-d H:i:s'),
            $count,
            $backup ?? '—'
        ),
        FILE_APPEND | LOCK_EX
    );

    return ['songs' => $songs, 'count' => $count, 'backup' => $backup];
}

/**
 * Conserva solo los VOTES_ARCHIVE_KEEP respaldos más recientes.
 */
function pruneVoteArchives(): void
{
    $files = glob(DATA_DIR . '/' . VOTES_ARCHIVE_PREFIX . '*.json');
    if (!is_array($files) || count($files) <= VOTES_ARCHIVE_KEEP) {
        return;
    }
    usort($files, static function ($a, $b) {
        return filemtime($b) <=> filemtime($a);   // más nuevos primero
    });
    foreach (array_slice($files, VOTES_ARCHIVE_KEEP) as $old) {
        @unlink($old);
    }
}
