<?php
/**
 * The admin panel's API. POST JSON {action, …} (uploads: multipart with action=upload_image / upload_chunk /
 * import_xmltv); every reply is JSON {ok, …}. Needs a logged-in session and its CSRF token (X-JP-Token header).
 */
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/media.php';
require dirname(__DIR__) . '/app/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(array $data)
{
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fail($message, array $extra = [])
{
    echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail('POST only.');
jp_session_start();
if (!jp_logged_in()) fail('Your session ended. Reload the page and log in again.', ['login' => true]);
if (!hash_equals($_SESSION['jp_csrf'], (string)($_SERVER['HTTP_X_JP_TOKEN'] ?? ($_POST['token'] ?? '')))) fail('Security token mismatch. Reload the page.');
// The session isn't written after this, so parallel requests (an upload while editing) don't wait for each other.
session_write_close();

$multipart = in_array($_POST['action'] ?? '', ['upload_image', 'upload_chunk', 'import_xmltv'], true);
$req = $multipart ? $_POST : (json_decode((string)file_get_contents('php://input'), true) ?: []);
$action = (string)($req['action'] ?? '');

/** The published catalog plus its revision. */
function published()
{
    [, $rev] = jp_catalog_revision();
    return [jp_read_catalog(), $rev];
}

function now_local()
{
    return date('Y-m-d H:i');
}

try {
    switch ($action) {
        case 'load':
            [$doc, $rev] = published();
            date_default_timezone_set(jp_timezone($doc));
            out(['doc' => $doc, 'rev' => $rev, 'base' => jp_base_url(), 'timezones' => timezone_identifiers_list(), 'backups' => jp_backups(),
                 'files' => jp_media_files($doc), 'orphans' => jp_hls_orphans($doc), 'hls' => jp_hls_states($doc), 'chunk_bytes' => jp_chunk_bytes(), 'video_ext' => JP_VIDEO_EXT,
                 'can_build' => jp_can_exec() && jp_find_tool('ffmpeg') !== null, 'can_probe' => jp_can_exec() && jp_find_tool('ffprobe') !== null,
                 'now' => now_local(), 'setup' => jp_setup_record(), 'version' => JP_VERSION, 'max_catchup' => JP_MAX_CATCHUP_DAYS]);

        case 'save':
            $doc = $req['doc'] ?? null;
            if (!is_array($doc) || strlen(json_encode($doc)) > 32_000_000) fail('The catalog sent is not valid.');
            if ($errors = jp_validate($doc)) fail('Not saved. Please fix:', ['errors' => $errors]);
            $r = jp_save_catalog($doc, (string)($req['rev'] ?? ''));
            if ($r['conflict']) fail('Not saved: the playlist changed since you opened it (another tab or person). Reload to see the latest.', ['conflict' => true]);
            $doc = jp_read_catalog();
            out(['rev' => $r['rev'], 'doc' => $doc, 'backups' => jp_backups(), 'files' => jp_media_files($doc), 'orphans' => jp_hls_orphans($doc), 'hls' => jp_hls_states($doc)]);

        case 'upload_image':
            out(['path' => jp_store_image($_FILES['image'] ?? [])]);

        case 'upload_chunk':
            out(jp_upload_chunk($req, $_FILES['chunk'] ?? []));

        case 'files':
            out(['files' => jp_media_files(jp_read_catalog())]);

        case 'file_delete':
            jp_media_file_delete((string)($req['path'] ?? ''));
            out(['files' => jp_media_files(jp_read_catalog())]);

        case 'probe':
            // Length of a link or an uploaded file, read by ffprobe on the server.
            $src = (string)($req['src'] ?? '');
            if (strpos($src, 'media/') === 0) $src = (string)jp_media_file_path($src);
            elseif (!preg_match('#^https?://#i', $src)) fail('Paste a link starting with http:// or https://.');
            if ($src === '') fail('That uploaded file is missing.');
            out(['seconds' => jp_probe_seconds($src)]);

        case 'poster':
            // A poster from a frame of a library item's video (uploaded file or link).
            $src = (string)($req['src'] ?? '');
            if (strpos($src, 'media/') === 0) $src = (string)jp_media_file_path($src);
            elseif (!preg_match('#^https?://#i', $src)) fail('Add the video first.');
            $path = jp_poster_from_video($src, (int)($req['seconds'] ?? 0));
            if ($path === '') fail('Could not take a picture from that video.');
            out(['path' => $path]);

        case 'hls':
            out(['hls' => jp_hls_states(jp_read_catalog())]);

        case 'hls_build':
            $doc = jp_read_catalog();
            $started = 0;
            foreach ((array)($req['keys'] ?? []) as $key) {
                $key = (string)$key;
                if (!isset($doc['library'][$key])) fail('Save first: videos are prepared from the published playlist.');
                if (jp_hls_start($key)) $started++;
            }
            out(['started' => $started, 'hls' => jp_hls_states($doc)]);

        case 'orphans_delete':
            $doc = jp_read_catalog();
            foreach (jp_hls_orphans($doc) as $k) jp_hls_delete($k);
            out(['orphans' => jp_hls_orphans($doc)]);

        case 'hls_delete':
            jp_hls_delete((string)($req['key'] ?? ''));
            out(['hls' => jp_hls_states(jp_read_catalog())]);

        case 'timeline':
            // Schedule preview of a channel being edited (the draft is not saved).
            $doc = jp_catalog(is_array($req['doc'] ?? null) ? $req['doc'] : jp_read_catalog());
            $c = $doc['live'][(int)($req['index'] ?? -1)] ?? null;
            if (!$c) fail('Channel not found.');
            $now = time();
            $hours = max(1, min(168, (int)($req['hours'] ?? 24)));
            $tl = jp_timeline($c);
            $pos = $tl ? jp_timeline_locate($tl, $now) : null;
            $fmt = fn($t) => date('Y-m-d H:i', $t);
            $guide = array_map(fn($p) => ['start' => $fmt($p['start']), 'end' => $fmt($p['end']), 'seconds' => $p['end'] - $p['start'], 'title' => $p['title'],
                                          'archive' => jp_has_archive($c, $p, $now), 'now' => $p['start'] <= $now && $now < $p['end'], 'past' => $p['end'] <= $now],
                               guide_window(jp_guide($c, $now - 3 * 3600, $now + $hours * 3600), $now));
            out([
                'now' => $fmt($now),
                'problems' => $tl ? $tl['problems'] : [],
                'ok' => $tl ? $tl['ok'] : $c['source'] === 'link',
                'ready' => $tl ? $tl['ready'] : true,
                'total' => $tl ? $tl['total'] : 0,
                'starts' => $tl ? $fmt($tl['start']) : '',
                'playing' => $pos ? ['index' => $pos['index'], 'offset' => (int)$pos['offset'], 'seconds' => (int)$tl['items'][$pos['index']]['seconds'],
                                     'title' => jp_media($tl['items'][$pos['index']]['media'])['title'] ?? ''] : null,
                'items' => $tl ? array_map(fn($it) => ['seconds' => (int)round($it['seconds']), 'hls' => $it['hls']], $tl['items']) : [],
                'guide' => $guide,
            ]);

        case 'import_xmltv':
            out(import_xmltv($_FILES['file'] ?? [], jp_timezone(jp_read_catalog())));

        case 'backups':
            out(['backups' => jp_backups()]);

        case 'restore':
            $name = basename((string)($req['name'] ?? ''));
            $doc = preg_match('/^catalog-\d{8}-\d{6}(-\d+)?\.json$/', $name) ? jp_read_json(jp_backup_dir() . "/$name") : null;
            if (!$doc) fail('That backup was not found.');
            if ($errors = jp_validate($doc)) fail('That backup can\'t be restored as it is:', ['errors' => $errors]);
            $r = jp_save_catalog($doc, (string)($req['rev'] ?? ''));
            if ($r['conflict']) fail('Not restored: the playlist changed since you opened it. Reload first.', ['conflict' => true]);
            out(['doc' => jp_read_catalog(), 'rev' => $r['rev'], 'backups' => jp_backups()]);

        case 'import_catalog':
            $doc = $req['doc'] ?? null;
            if (!is_array($doc) || !isset($doc['library'])) fail('That file is not a JSON Playlist Creator export.');
            if ($errors = jp_validate($doc)) fail('That file can\'t be imported as it is:', ['errors' => $errors]);
            $r = jp_save_catalog($doc, (string)($req['rev'] ?? ''));
            if ($r['conflict']) fail('Not imported: the playlist changed since you opened it. Reload first.', ['conflict' => true]);
            out(['doc' => jp_read_catalog(), 'rev' => $r['rev'], 'backups' => jp_backups()]);

        case 'test':
            out(['results' => self_test(jp_read_catalog())]);

        case 'change_password':
            $c = jp_credentials();
            if (!$c || !password_verify((string)($req['current'] ?? ''), $c['hash'])) fail('The current password is wrong.');
            jp_set_password((string)($req['new'] ?? ''));
            out([]);

        default:
            fail('Unknown action.');
    }
} catch (Throwable $e) {
    error_log('json-playlist-creator admin: ' . $e->getMessage());
    fail($e->getMessage());
}

/**
 * Reads an uploaded XMLTV guide: {channels: [{id, name, count}], programmes: {id: [{start, end, title, description}]}}
 * with times in the playlist's time zone ("YYYY-MM-DD HH:MM"). At most 20,000 programmes.
 */
function import_xmltv(array $file, $tz)
{
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('The upload failed.');
    if ($file['size'] > 50 * 1024 * 1024) throw new RuntimeException('Guide files can be up to 50 MB.');
    $path = $file['tmp_name'];
    $head = (string)file_get_contents($path, false, null, 0, 2);
    if ($head === "\x1f\x8b") { // gzip
        $raw = @gzdecode((string)file_get_contents($path));
        if ($raw === false) throw new RuntimeException('That .gz file could not be unpacked.');
        $path = tempnam(sys_get_temp_dir(), 'xmltv');
        file_put_contents($path, $raw);
    }
    $r = new XMLReader();
    // No network access and no entity expansion while parsing.
    if (!$r->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) throw new RuntimeException('That is not an XML file.');
    $zone = new DateTimeZone($tz);
    $parse = function ($s) use ($zone) {
        if (!preg_match('/^(\d{14})\s*([+-]\d{4})?/', trim((string)$s), $m)) return null;
        $d = DateTime::createFromFormat('YmdHis O', $m[1] . ' ' . ($m[2] ?? '+0000'));
        return $d ? $d->setTimezone($zone)->format('Y-m-d H:i') : null;
    };
    $channels = [];
    $programmes = [];
    $total = 0;
    while (@$r->read()) {
        if ($r->nodeType !== XMLReader::ELEMENT) continue;
        if ($r->name === 'channel') {
            $x = @simplexml_load_string($r->readOuterXml(), 'SimpleXMLElement', LIBXML_NONET);
            if (!$x) continue;
            $id = (string)$x['id'];
            $channels[$id] = ['id' => $id, 'name' => (string)($x->{'display-name'}[0] ?? $id), 'count' => 0];
        } elseif ($r->name === 'programme' && $total < 20000) {
            $x = @simplexml_load_string($r->readOuterXml(), 'SimpleXMLElement', LIBXML_NONET);
            if (!$x) continue;
            $id = (string)$x['channel'];
            $s = $parse($x['start']);
            $e = $parse($x['stop']);
            if ($s === null || $e === null || $e <= $s) continue;
            $programmes[$id][] = ['start' => $s, 'end' => $e, 'title' => mb_substr(trim((string)$x->title), 0, 200),
                                  'description' => mb_substr(trim((string)$x->desc), 0, 2000), 'archive' => ''];
            $total++;
            if (!isset($channels[$id])) $channels[$id] = ['id' => $id, 'name' => $id, 'count' => 0];
            $channels[$id]['count']++;
        }
    }
    $r->close();
    $channels = array_values(array_filter($channels, fn($c) => $c['count'] > 0));
    if (!$channels) throw new RuntimeException('No programmes were found in that file.');
    return ['channels' => $channels, 'programmes' => $programmes, 'truncated' => $total >= 20000];
}

/** At most 400 guide entries: a few before the one playing now, then what's next. */
function guide_window(array $list, $now)
{
    $at = count($list);
    foreach ($list as $i => $p) if ($p['end'] > $now) { $at = $i; break; }
    return array_slice($list, max(0, $at - 5), 400);
}

/** Calls this install's public API the way a player would, with the first enabled account. */
function self_test(array $doc)
{
    $acc = null;
    foreach ($doc['accounts'] as $a) if ($a['enabled']) { $acc = $a; break; }
    if (!$acc) return [['label' => 'Accounts', 'ok' => false, 'detail' => 'Add or enable an account first.']];
    if (!function_exists('curl_init')) return [['label' => 'Test', 'ok' => false, 'detail' => 'PHP has no curl here; open the links on the Overview page instead.']];
    // PHP's built-in server answers one request at a time unless it has workers: calling itself would wait forever.
    if (PHP_SAPI === 'cli-server' && (int)getenv('PHP_CLI_SERVER_WORKERS') < 2) {
        return [['label' => 'Test', 'ok' => false, 'detail' => 'Start the built-in server with workers so it can serve players and this test at once: PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8080 router.php']];
    }
    $url = jp_base_url() . '/player_api.php?username=' . rawurlencode($acc['username']) . '&password=' . rawurlencode($acc['password']);
    $out = [];
    foreach (['' => 'Sign-in', 'get_live_streams' => 'Live channels', 'get_vod_streams' => 'Movies', 'get_series' => 'Series'] as $a => $label) {
        $ch = curl_init($url . ($a !== '' ? "&action=$a" : ''));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $j = json_decode((string)$body, true);
        $ok = $code === 200 && is_array($j) && ($a !== '' || (int)($j['user_info']['auth'] ?? 0) === 1);
        $out[] = ['label' => $label, 'ok' => $ok, 'detail' => $ok ? ($a === '' ? 'works' : count($j) . ' items') : ($err ?: "HTTP $code")];
    }
    // The private data folder must not be reachable.
    $ch = curl_init(jp_base_url() . '/data/catalog.json');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_NOBODY => true, CURLOPT_SSL_VERIFYPEER => false]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $out[] = ['label' => 'Private data', 'ok' => $code >= 400, 'detail' => $code >= 400 ? 'closed to the web' : "REACHABLE (HTTP $code): add the web server rules (docs/INSTALL.md)"];
    return $out;
}
