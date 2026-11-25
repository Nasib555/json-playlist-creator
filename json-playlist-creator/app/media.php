<?php
/**
 * Uploads and video processing: chunked video uploads into media/, artwork into images/, lengths with ffprobe,
 * and live segments (media/hls/<key>/) with ffmpeg. ffmpeg / ffprobe are optional: without them, lengths are
 * typed in or read by the browser, and live preparation runs on any computer that has ffmpeg (tools/build-hls.php).
 */

const JP_VIDEO_EXT = ['mp4', 'm4v', 'mov', 'mkv', 'webm', 'ts'];
const JP_MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const JP_CHUNK_MAX = 8 * 1024 * 1024;
const JP_MAX_VIDEO_BYTES = 20 * 1024 * 1024 * 1024;
/** Disk space always left free after an upload. */
const JP_DISK_RESERVE = 512 * 1024 * 1024;
/** A live preparation job is considered dead after this long without finishing. */
const JP_JOB_TIMEOUT = 8 * 3600;

function jp_media_dir()
{
    $d = JP_ROOT . '/media';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

/* ------------------------------------------------------------------ images */

/** Stores an uploaded image and returns its catalog path ("images/…"). Same picture twice = same file. */
function jp_store_image(array $file)
{
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('The image upload failed (' . ($file['error'] ?? '?') . ').');
    if ($file['size'] > JP_MAX_IMAGE_BYTES) throw new RuntimeException('Images can be up to 5 MB.');
    $info = @getimagesize($file['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
    if (!$ext) throw new RuntimeException('Use a JPEG, PNG, WebP or GIF image.');
    $dir = JP_ROOT . '/images';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = substr(sha1_file($file['tmp_name']), 0, 20) . ".$ext";
    if (!is_file("$dir/$name") && !move_uploaded_file($file['tmp_name'], "$dir/$name")) {
        throw new RuntimeException('Could not save the image (is images/ writable by PHP?).');
    }
    @chmod("$dir/$name", 0664);
    return "images/$name";
}

/* ------------------------------------------------------------------ videos (chunked upload) */

/** php.ini size ("2M", "1G") in bytes; 0 = unlimited / unknown. */
function jp_ini_bytes($v)
{
    $v = trim((string)$v);
    if ($v === '' || $v === '-1') return 0;
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return (int)$n;
}

/** Bytes per upload request that this PHP accepts. */
function jp_chunk_bytes()
{
    $limit = JP_CHUNK_MAX;
    $up = jp_ini_bytes(ini_get('upload_max_filesize'));
    $post = jp_ini_bytes(ini_get('post_max_size'));
    if ($up > 0) $limit = min($limit, (int)($up * 0.9));
    if ($post > 0) $limit = min($limit, (int)($post * 0.9) - 65536);
    return max(262144, intdiv($limit, 1024) * 1024);
}

function jp_upload_dir()
{
    $d = jp_data_dir('uploads');
    foreach (glob("$d/*.part") ?: [] as $f) if (filemtime($f) < time() - 86400) @unlink($f); // abandoned
    return $d;
}

/**
 * One chunk of a video upload. Chunks arrive in order (the browser retries a failed one); a repeated chunk is
 * ignored. Returns ['done' => false, 'received' => n] or, after the last one, ['done' => true, 'path' => 'media/…', 'seconds' => n].
 */
function jp_upload_chunk(array $req, array $chunk)
{
    $id = (string)($req['upload_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{16,40}$/', $id)) throw new RuntimeException('Bad upload id.');
    $index = (int)($req['index'] ?? -1);
    $total = (int)($req['total'] ?? 0);
    $size = (int)($req['size'] ?? 0);
    $name = (string)($req['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, JP_VIDEO_EXT, true)) throw new RuntimeException('Upload an MP4, M4V, MOV, MKV, WebM or TS video.');
    if ($size <= 0 || $size > JP_MAX_VIDEO_BYTES) throw new RuntimeException('Videos can be up to 20 GB.');
    $chunkBytes = (int)($req['chunk_bytes'] ?? 0);
    if ($chunkBytes !== jp_chunk_bytes()) throw new RuntimeException('The server\'s upload size changed. Reload the page and upload again.');
    if ($total !== (int)ceil($size / $chunkBytes) || $index < 0 || $index >= $total) throw new RuntimeException('Bad chunk. Reload the page and upload again.');
    if (($chunk['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('A part of the upload failed (' . ($chunk['error'] ?? '?') . '). Retrying…');

    $part = jp_upload_dir() . "/$id.part";
    $fh = fopen($part, 'c+');
    if (!$fh) throw new RuntimeException('Could not store the upload (is data/ writable by PHP?).');
    flock($fh, LOCK_EX);
    try {
        clearstatcache(true, $part);
        $have = filesize($part);
        $expected = $index * $chunkBytes;
        if ($index === 0 && $have === 0) {
            $free = @disk_free_space(jp_media_dir());
            if ($free !== false && $free < $size + JP_DISK_RESERVE) throw new RuntimeException('Not enough free disk space on the server for this video.');
        }
        if ($have > $expected) return ['done' => false, 'received' => $index + 1]; // a retry of a part we already have
        if ($have < $expected) throw new RuntimeException('The upload arrived out of order. Start it again.');
        $data = file_get_contents($chunk['tmp_name']);
        $want = $index === $total - 1 ? $size - $expected : $chunkBytes;
        if (strlen($data) !== $want) throw new RuntimeException('A part of the upload arrived incomplete. Retrying…');
        fseek($fh, $expected);
        if (fwrite($fh, $data) !== strlen($data)) throw new RuntimeException('Could not write the upload (is the disk full?).');
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    if ($index < $total - 1) return ['done' => false, 'received' => $index + 1];

    clearstatcache(true, $part);
    if (filesize($part) !== $size) { @unlink($part); throw new RuntimeException('The upload size doesn\'t match. Please try again.'); }
    if (!jp_looks_like_video($part, $ext)) { @unlink($part); throw new RuntimeException('That file doesn\'t look like a ' . strtoupper($ext) . ' video.'); }
    $base = trim(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo($name, PATHINFO_FILENAME)), '-') ?: 'video';
    $final = substr(strtolower($base), 0, 80) . '-' . substr($id, 0, 6) . ".$ext";
    if (!@rename($part, jp_media_dir() . "/$final")) throw new RuntimeException('Could not move the video into media/.');
    @chmod(jp_media_dir() . "/$final", 0664);
    $file = jp_media_dir() . "/$final";
    $seconds = jp_probe_seconds($file);
    return ['done' => true, 'path' => "media/$final", 'seconds' => $seconds, 'poster' => jp_poster_from_video($file, $seconds)];
}

/** The file's first bytes match its type (a renamed script or image is refused). */
function jp_looks_like_video($path, $ext)
{
    $h = (string)@file_get_contents($path, false, null, 0, 4096);
    switch ($ext) {
        case 'mp4': case 'm4v': case 'mov':
            return strlen($h) >= 12 && in_array(substr($h, 4, 4), ['ftyp', 'moov', 'mdat', 'free', 'wide', 'skip', 'pnot'], true);
        case 'mkv': case 'webm':
            return substr($h, 0, 4) === "\x1A\x45\xDF\xA3";
        case 'ts':
            return strlen($h) >= 377 && $h[0] === "\x47" && $h[188] === "\x47" && $h[376] === "\x47";
    }
    return false;
}

/** Uploaded videos, newest first, with which library items use them. */
function jp_media_files(array $doc)
{
    $out = [];
    foreach (glob(jp_media_dir() . '/*') ?: [] as $f) {
        if (!is_file($f) || !in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), JP_VIDEO_EXT, true)) continue;
        $rel = 'media/' . basename($f);
        $users = [];
        foreach ($doc['library'] as $k => $m) if ($m['file'] === $rel) $users[] = $k;
        $out[] = ['path' => $rel, 'size' => filesize($f), 'time' => filemtime($f), 'items' => $users];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/** Keys with live segments on disk but no library item any more (deleted items). */
function jp_hls_orphans(array $doc)
{
    $out = [];
    foreach (glob(jp_media_dir() . '/hls/*', GLOB_ONLYDIR) ?: [] as $d) {
        $k = basename($d);
        if (!isset($doc['library'][$k]) && preg_match(JP_KEY_RE, $k)) $out[] = $k;
    }
    return $out;
}

/** Deletes an uploaded video that no item of the published catalog uses. */
function jp_media_file_delete($rel)
{
    $p = jp_media_file_path($rel);
    if (!$p) throw new RuntimeException('That video was not found.');
    foreach (jp_read_catalog()['library'] as $k => $m) {
        if ($m['file'] === $rel) throw new RuntimeException("The library item \"{$m['title']}\" still uses it (in the published catalog).");
    }
    if (!@unlink($p)) throw new RuntimeException('Could not delete it.');
}

/* ------------------------------------------------------------------ ffmpeg / ffprobe */

function jp_find_tool($name)
{
    foreach (["/usr/bin/$name", "/usr/local/bin/$name", "/opt/homebrew/bin/$name", "/bin/$name"] as $p) if (@is_executable($p)) return $p;
    if (jp_can_exec()) {
        $p = trim((string)@shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        if ($p !== '' && @is_executable($p)) return $p;
    }
    return null;
}

/** Whether PHP may run programs here. */
function jp_can_exec()
{
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return function_exists('exec') && !in_array('exec', $disabled, true) && function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
}

/** Length in whole seconds of a video file or link (ffprobe), or 0 when unknown. */
function jp_probe_seconds($src)
{
    $ffprobe = jp_can_exec() ? jp_find_tool('ffprobe') : null;
    if (!$ffprobe) return 0;
    $cmd = implode(' ', array_map('escapeshellarg', [$ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $src]));
    $out = trim((string)@shell_exec("timeout 60 $cmd 2>/dev/null") ?: (string)@shell_exec("$cmd 2>/dev/null"));
    return is_numeric($out) ? (int)round((float)$out) : 0;
}

/** Where ffmpeg reads a library item from: the uploaded file, its link, or base + name. */
function jp_media_source(array $item, array $settings)
{
    $f = $item['file'];
    if (strpos($f, 'media/') === 0) return jp_media_file_path($f) ?: throw new RuntimeException("The uploaded file $f is missing.");
    if (strpos($f, '://') !== false) return $f;
    if ($f !== '' && $settings['media_base'] !== '') return $settings['media_base'] . $f;
    throw new RuntimeException('It has no video.');
}

/**
 * A poster image from a frame of a video (a tenth of the way in, at most a minute), stored like an uploaded image.
 * Returns "images/…", or '' when ffmpeg isn't available or the frame can't be read.
 */
function jp_poster_from_video($src, $seconds = 0)
{
    $ffmpeg = jp_can_exec() ? jp_find_tool('ffmpeg') : null;
    if (!$ffmpeg || $src === '') return '';
    $at = $seconds > 0 ? min(60, max(1, $seconds / 10)) : 5;
    $tmp = tempnam(sys_get_temp_dir(), 'jpposter') . '.jpg';
    $args = [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-ss', (string)$at, '-i', $src, '-frames:v', '1',
             '-vf', 'scale=640:-2', '-q:v', '3', $tmp];
    exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
    if ($code !== 0 || !is_file($tmp) || filesize($tmp) < 500) { @unlink($tmp); return ''; }
    $dir = JP_ROOT . '/images';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = substr(sha1_file($tmp), 0, 20) . '.jpg';
    if (!@rename($tmp, "$dir/$name")) { @unlink($tmp); return ''; }
    @chmod("$dir/$name", 0664);
    return "images/$name";
}

/* ------------------------------------------------------------------ live segments */

function jp_hls_dir($key)
{
    return jp_media_dir() . '/hls/' . $key;
}

/** {count, total, built_at, encoded, source} of an item's live segments, or null. */
function jp_hls_summary($key)
{
    $j = preg_match(JP_KEY_RE, (string)$key) ? jp_read_json(jp_hls_dir($key) . '/segments.json') : null;
    if (!is_array($j) || empty($j['segments'])) return null;
    return ['count' => count($j['segments']), 'total' => (float)($j['total'] ?? 0), 'built_at' => (int)($j['built_at'] ?? 0),
            'encoded' => !empty($j['encoded']), 'source' => (string)($j['source'] ?? '')];
}

function jp_job_paths($key)
{
    $d = jp_data_dir('jobs');
    return ["$d/hls-$key.pid", "$d/hls-$key.log"];
}

/** Live preparation state per library item: {info, running, last (log line), stale (video changed since)}. */
function jp_hls_states(array $doc)
{
    $out = [];
    foreach ($doc['library'] as $key => $m) {
        [$pid, $log] = jp_job_paths($key);
        $running = is_file($pid) && filemtime($pid) > time() - JP_JOB_TIMEOUT;
        $last = '';
        if (is_file($log)) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", (string)@file_get_contents($log, false, null, max(0, filesize($log) - 2048))))));
            $last = (string)end($lines);
        }
        $info = jp_hls_summary($key);
        $out[$key] = ['info' => $info, 'running' => $running, 'last' => mb_substr($last, 0, 300),
                      'stale' => $info && $info['source'] !== '' && $info['source'] !== $m['file']];
    }
    return $out;
}

/** Starts tools/build-hls.php for [key] in the background (needs exec and ffmpeg here). */
function jp_hls_start($key)
{
    if (!preg_match(JP_KEY_RE, $key)) throw new RuntimeException('Bad library key.');
    if (!jp_can_exec()) throw new RuntimeException("This server doesn't let PHP run programs. Prepare it on a computer with ffmpeg: php tools/build-hls.php $key, then upload media/hls/$key/.");
    if (!jp_find_tool('ffmpeg')) throw new RuntimeException("ffmpeg isn't installed on this server. Install it, or prepare it on a computer with ffmpeg: php tools/build-hls.php $key, then upload media/hls/$key/.");
    [$pid, $log] = jp_job_paths($key);
    if (is_file($pid) && filemtime($pid) > time() - JP_JOB_TIMEOUT) return false; // already running
    $php = PHP_BINARY && @is_executable(PHP_BINARY) && stripos(PHP_BINARY, 'fpm') === false && stripos(PHP_BINARY, 'httpd') === false
        ? PHP_BINARY : (jp_find_tool('php') ?: 'php');
    $cmd = sprintf('(touch %s; %s %s %s; rm -f %s) > %s 2>&1 &', escapeshellarg($pid), escapeshellarg($php), escapeshellarg(JP_ROOT . '/tools/build-hls.php'),
                   escapeshellarg($key), escapeshellarg($pid), escapeshellarg($log));
    exec($cmd);
    return true;
}

function jp_hls_delete($key)
{
    $d = jp_hls_dir($key);
    if (!preg_match(JP_KEY_RE, (string)$key) || !is_dir($d)) return;
    foreach (glob("$d/*") ?: [] as $f) @unlink($f);
    @rmdir($d);
}

/**
 * Cuts a library item into ~6 s MPEG-TS segments (stream copy; re-encoded to H.264 / AAC only when copying fails),
 * then swaps them in, so channels never see half a set. Returns {count, total, encoded}.
 */
function jp_build_hls($key, array $item, array $settings, $ffmpeg, $from = null)
{
    $src = $from ?? jp_media_source($item, $settings);
    $final = jp_hls_dir($key);
    $parent = dirname($final);
    if (!is_dir($parent)) @mkdir($parent, 0775, true);
    $tmp = "$parent/.$key.building";
    $clean = function ($dir) { foreach (glob("$dir/*") ?: [] as $f) @unlink($f); @rmdir($dir); };
    $clean($tmp);
    if (!@mkdir($tmp, 0775, true)) throw new RuntimeException("Could not create $tmp (is media/ writable?).");

    $run = function (array $codec) use ($ffmpeg, $src, $tmp) {
        $args = array_merge([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostdin', '-y', '-i', $src, '-map', '0:v:0', '-map', '0:a:0?'], $codec,
            ['-f', 'hls', '-hls_time', '6', '-hls_list_size', '0', '-hls_playlist_type', 'vod', '-hls_segment_filename', "$tmp/seg_%05d.ts", "$tmp/index.m3u8"]);
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $out, $code);
        return [$code, implode(' ', array_slice($out, -3))];
    };
    [$code, $err] = $run(['-c', 'copy', '-bsf:v', 'h264_mp4toannexb']);
    $encoded = false;
    if ($code !== 0) {
        echo "Copying didn't work ($err); re-encoding to H.264 / AAC…\n";
        foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
        [$code, $err] = $run(['-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22', '-g', '150', '-sc_threshold', '0', '-c:a', 'aac', '-b:a', '128k', '-ac', '2']);
        $encoded = true;
        if ($code !== 0) { $clean($tmp); throw new RuntimeException("ffmpeg failed: $err"); }
    }

    $segments = [];
    $d = null;
    foreach (file("$tmp/index.m3u8", FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^#EXTINF:([\d.]+)/', $line, $m)) $d = (float)$m[1];
        elseif ($line !== '' && $line[0] !== '#' && $d !== null) { $segments[] = ['f' => basename($line), 'd' => round($d, 3)]; $d = null; }
    }
    if (count($segments) < 1) { $clean($tmp); throw new RuntimeException('ffmpeg made no segments.'); }
    $total = round(array_sum(array_column($segments, 'd')), 3);
    file_put_contents("$tmp/segments.json", json_encode(['segments' => $segments, 'total' => $total, 'built_at' => time(),
                                                          'encoded' => $encoded, 'source' => $item['file']], JSON_UNESCAPED_SLASHES));
    @unlink("$tmp/index.m3u8");

    $old = "$parent/.$key.old";
    $clean($old);
    if (is_dir($final) && !@rename($final, $old)) { $clean($tmp); throw new RuntimeException('Could not replace the old segments.'); }
    if (!@rename($tmp, $final)) { if (is_dir($old)) @rename($old, $final); throw new RuntimeException('Could not put the new segments in place.'); }
    $clean($old);
    return ['count' => count($segments), 'total' => $total, 'encoded' => $encoded];
}
