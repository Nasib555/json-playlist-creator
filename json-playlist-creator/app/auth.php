<?php
/**
 * Admin login. One admin password, stored only as a hash in data/credentials.json. Sessions use their own cookie
 * (HttpOnly, SameSite=Strict, Secure on HTTPS), get a new id at login and expire after 4 idle hours. Wrong
 * passwords: 5 per 15 minutes per address, then a 15-minute lock-out.
 *
 * Forgot the password? On the server:  php tools/set-password.php 'new password'
 */

const JP_SESSION_IDLE = 4 * 3600;
const JP_MAX_FAILS = 5;
const JP_LOCK_SECONDS = 15 * 60;
const JP_MIN_PASSWORD = 10;

function jp_session_start()
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('jpcadmin');
    session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/', 'secure' => jp_https(),
                               'httponly' => true, 'samesite' => 'Strict']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
    if (!empty($_SESSION['jp_admin']) && time() - ($_SESSION['jp_seen'] ?? 0) > JP_SESSION_IDLE) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    if (!empty($_SESSION['jp_admin'])) $_SESSION['jp_seen'] = time();
    if (empty($_SESSION['jp_csrf'])) $_SESSION['jp_csrf'] = bin2hex(random_bytes(16));
}

function jp_logged_in()
{
    return !empty($_SESSION['jp_admin']) && jp_is_set_up();
}

function jp_credentials()
{
    $c = jp_read_json(jp_data_dir() . '/credentials.json');
    return is_array($c) && !empty($c['hash']) ? $c : null;
}

function jp_password_problem($password)
{
    if (strlen((string)$password) < JP_MIN_PASSWORD) return 'Use at least ' . JP_MIN_PASSWORD . ' characters for the admin password.';
    if (strlen((string)$password) > 200) return 'That password is too long.';
    return null;
}

function jp_set_password($password)
{
    if ($p = jp_password_problem($password)) throw new RuntimeException($p);
    jp_write_atomic(jp_data_dir() . '/credentials.json', json_encode(['hash' => password_hash((string)$password, PASSWORD_DEFAULT), 'changed_at' => time()]));
}

function jp_client_ip()
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}

/** [allowed, seconds until unlocked]. [record]: 'fail' or 'ok'. Per-address counters in data/throttle.json. */
function jp_throttle($record = null)
{
    $fh = fopen(jp_data_dir() . '/throttle.json', 'c+');
    if (!$fh) return [true, 0];
    flock($fh, LOCK_EX);
    $all = json_decode((string)stream_get_contents($fh), true) ?: [];
    $now = time();
    foreach ($all as $k => $v) if ($now - ($v['last'] ?? 0) > JP_LOCK_SECONDS) unset($all[$k]);
    $key = hash('sha256', jp_client_ip());
    $e = $all[$key] ?? ['fails' => 0, 'last' => 0];
    if ($record === 'fail') { $e['fails']++; $e['last'] = $now; $all[$key] = $e; }
    if ($record === 'ok') unset($all[$key]);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($all));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $locked = $e['fails'] >= JP_MAX_FAILS && $now - $e['last'] < JP_LOCK_SECONDS;
    return [!$locked, $locked ? JP_LOCK_SECONDS - ($now - $e['last']) : 0];
}

/** Null when logged in, else why not. */
function jp_login($password)
{
    [$allowed, $wait] = jp_throttle();
    if (!$allowed) return 'Too many wrong passwords. Try again in ' . ceil($wait / 60) . ' minutes.';
    $c = jp_credentials();
    if (!$c) return 'No admin password is set. On the server, run: php tools/set-password.php \'your password\'';
    if (!password_verify((string)$password, $c['hash'])) {
        jp_throttle('fail');
        usleep(300000);
        return 'Wrong password.';
    }
    jp_throttle('ok');
    if (password_needs_rehash($c['hash'], PASSWORD_DEFAULT)) jp_set_password($password);
    session_regenerate_id(true);
    $_SESSION['jp_admin'] = true;
    $_SESSION['jp_seen'] = time();
    $_SESSION['jp_csrf'] = bin2hex(random_bytes(16));
    return null;
}

function jp_logout()
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['jp_csrf'] = bin2hex(random_bytes(16));
}

/* ------------------------------------------------------------------ first-run setup */

/** Requirements: [{label, ok, required, detail}]. */
function jp_requirements()
{
    $writable = function ($dir) { if (!is_dir($dir)) @mkdir($dir, 0775, true); return is_dir($dir) && is_writable($dir); };
    $ffmpeg = jp_can_exec() ? jp_find_tool('ffmpeg') : null;
    return [
        ['label' => 'PHP 8.0 or newer', 'ok' => PHP_VERSION_ID >= 80000, 'required' => true, 'detail' => 'This server has PHP ' . PHP_VERSION . '.'],
        ['label' => 'JSON and multibyte string support', 'ok' => function_exists('json_encode') && function_exists('mb_substr'), 'required' => true, 'detail' => 'PHP extensions json and mbstring.'],
        ['label' => 'data/ is writable', 'ok' => $writable(JP_ROOT . '/data'), 'required' => true, 'detail' => 'The playlist, logins and backups are saved there.'],
        ['label' => 'media/ is writable', 'ok' => $writable(JP_ROOT . '/media'), 'required' => true, 'detail' => 'Uploaded videos and live segments go there.'],
        ['label' => 'images/ is writable', 'ok' => $writable(JP_ROOT . '/images'), 'required' => true, 'detail' => 'Uploaded artwork goes there.'],
        ['label' => 'ffmpeg (optional)', 'ok' => (bool)$ffmpeg, 'required' => false,
         'detail' => $ffmpeg ? 'Found: videos can be prepared for live channels right here.' : 'Not found. Channels can still relay stream links; to play your own videos as live channels, install ffmpeg, or prepare them on another computer with tools/build-hls.php.'],
        ['label' => 'Private folders closed to the web', 'ok' => null, 'required' => false,
         'detail' => 'Apache uses the included .htaccess files. On nginx, add the rules in docs/nginx.conf. Check that /data/catalog.json is not reachable in a browser.'],
    ];
}

/** First-run setup: admin password, terms acceptance, playlist name / time zone, a first account. */
function jp_run_setup($password, $name, $timezone, $acceptedTerms)
{
    if (jp_is_set_up()) throw new RuntimeException('Setup is already done.');
    if (!$acceptedTerms) throw new RuntimeException('Please read and accept the terms of use to continue.');
    foreach (jp_requirements() as $r) if ($r['required'] && !$r['ok']) throw new RuntimeException('Fix this first: ' . $r['label'] . '. ' . $r['detail']);
    if ($p = jp_password_problem($password)) throw new RuntimeException($p);
    jp_set_password($password);
    if (!is_file(jp_catalog_path())) {
        $doc = jp_default_catalog();
        $doc['settings']['name'] = jp_str($name, 80) ?: 'My Playlist';
        $doc['settings']['timezone'] = in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
        $doc['accounts'][] = jp_new_account('First account');
        $doc['categories'] = ['live' => ['1' => 'Channels'], 'vod' => ['1' => 'Movies'], 'series' => ['1' => 'Series']];
        jp_save_catalog($doc);
    }
    jp_record_terms();
}

/** Remembers that the admin accepted the current terms (version, time, hashed address). */
function jp_record_terms()
{
    jp_write_atomic(jp_data_dir() . '/setup.json', json_encode([
        'terms_version' => JP_TERMS_VERSION,
        'accepted_at' => gmdate('c'),
        'accepted_from' => hash('sha256', jp_client_ip()),
        'software_version' => JP_VERSION,
    ], JSON_PRETTY_PRINT));
}

function jp_terms_current()
{
    $r = jp_setup_record();
    return $r && (int)($r['terms_version'] ?? 0) >= JP_TERMS_VERSION;
}

function jp_terms_text()
{
    return (string)@file_get_contents(JP_ROOT . '/TERMS.md');
}
