<?php
/**
 * JSON Playlist Creator: shared core of the public endpoints, the admin panel and the command-line tools.
 *
 * Everything lives in files: data/catalog.json (the playlist), data/credentials.json (admin login),
 * media/ (uploaded videos and their live segments), images/ (uploaded artwork). No database.
 * Public replies never contain PHP warnings: those go to the error log.
 */
ini_set('display_errors', '0');

const JP_VERSION = '1.0.2';
const JP_SCHEMA = 1;
/** Version of TERMS.md the admin accepts at setup. Bump when the terms change (the admin is asked again). */
const JP_TERMS_VERSION = 1;

define('JP_ROOT', dirname(__DIR__));
define('JP_DATA', JP_ROOT . '/data');

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/timeline.php';

/* ------------------------------------------------------------------ request / response */

function jp_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Public base URL of this install, as the client reached it (works at a sub-path or a domain root). */
function jp_base_url()
{
    static $base = null;
    if ($base !== null) return $base;
    $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    // Every entry script is in the root, admin/ or streaming/: the install root is the part before those.
    $dir = rtrim(dirname($script), '/');
    foreach (['/admin', '/streaming'] as $sub) {
        if (substr($dir, -strlen($sub)) === $sub) { $dir = substr($dir, 0, -strlen($sub)); break; }
    }
    return $base = (jp_https() ? 'https' : 'http') . "://$host$dir";
}

/** Absolute URL of a stored file ("images/…", "media/…"); links are returned as they are. */
function jp_asset($path)
{
    $path = (string)$path;
    if ($path === '' || strpos($path, '://') !== false) return $path;
    return jp_base_url() . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
}

function jp_json($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

function jp_text($text, $code)
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $text;
    exit;
}

function jp_redirect($url)
{
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    header('Location: ' . $url, true, 302);
    exit;
}

function jp_send_playlist($text)
{
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-cache, no-store');
    header('Access-Control-Allow-Origin: *');
    header('Content-Length: ' . strlen($text));
    echo $text;
    exit;
}

/* ------------------------------------------------------------------ the catalog, as the public endpoints see it */

/**
 * The published catalog (read once per request). Falls back to the newest good backup if the file is damaged.
 * The admin passes an unsaved draft to preview it ([use]); the public endpoints never do.
 */
function jp_catalog(?array $use = null)
{
    static $doc = null;
    if ($use !== null) $doc = jp_normalize($use);
    if ($doc === null) $doc = jp_read_catalog();
    date_default_timezone_set(jp_timezone($doc));
    return $doc;
}

function jp_timezone(array $doc)
{
    $tz = (string)($doc['settings']['timezone'] ?? 'UTC');
    return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
}

/** The enabled, unexpired account with these credentials, or null. Compared in constant time. */
function jp_account($user, $pass)
{
    $user = (string)$user;
    $pass = (string)$pass;
    if ($user === '' || $pass === '') return null;
    $found = null;
    foreach (jp_catalog()['accounts'] as $a) {
        $ok = hash_equals((string)$a['username'], $user) & hash_equals((string)$a['password'], $pass);
        if ($ok && $found === null) $found = $a;
    }
    if (!$found || empty($found['enabled'])) return null;
    if (($found['expires'] ?? '') !== '' && jp_local_ts($found['expires'] . ' 23:59') < time()) return null;
    return $found;
}

/** The account of a media / guide request, or a 403 reply. */
function jp_require_account($user, $pass)
{
    $a = jp_account($user, $pass);
    if (!$a) jp_text('Wrong username or password.', 403);
    return $a;
}

/** "YYYY-MM-DD HH:MM" in the playlist's time zone → unix time (null when not a valid time). */
function jp_local_ts($s)
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', trim((string)$s), $m)) return null;
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59) return null;
    return mktime((int)$m[4], (int)$m[5], (int)($m[6] ?? 0), (int)$m[2], (int)$m[3], (int)$m[1]);
}

/* ------------------------------------------------------------------ lookups */

function jp_media($key)
{
    $lib = jp_catalog()['library'];
    return isset($lib[$key]) ? $lib[$key] : null;
}

/** Playable URL of a library item: a link as it is, an uploaded video on this server, or a name inside the media base URL. */
function jp_media_url($key)
{
    $m = jp_media($key);
    if (!$m) return '';
    $f = (string)$m['file'];
    if ($f === '' || strpos($f, '://') !== false) return $f;
    if (strpos($f, 'media/') === 0) return jp_asset($f);
    return (string)jp_catalog()['settings']['media_base'] . $f;
}

function jp_find($section, $id)
{
    foreach (jp_catalog()[$section] as $x) if ((int)$x['id'] === (int)$id) return $x;
    return null;
}

/** Episode id = series id × 1000 + season × 100 + episode number (unique, stable while the order doesn't change). */
function jp_episode($id)
{
    $id = (int)$id;
    $s = jp_find('series', intdiv($id, 1000));
    $season = intdiv($id % 1000, 100);
    $num = $id % 100;
    if (!$s) return null;
    $e = $s['seasons'][(string)$season]['episodes'][$num - 1] ?? null;
    return $e ? ['series' => $s, 'season' => $season, 'num' => $num, 'media' => $e['media'], 'thumb' => $e['thumb'] ?? ''] : null;
}

function jp_channel_guide_id(array $c)
{
    return 'ch' . (int)$c['id'];
}
