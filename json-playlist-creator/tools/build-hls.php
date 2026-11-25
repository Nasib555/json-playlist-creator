<?php
/**
 * Prepares library items for live channels: cuts each video into ~6-second segments (media/hls/<key>/) with ffmpeg,
 * so channels can play videos back to back as one continuous stream and replay any past moment (catch-up).
 *
 *   php tools/build-hls.php <key> [<key> …]           these items
 *   php tools/build-hls.php --all [--force]            every item without segments (--force: every item, again)
 *   php tools/build-hls.php <key> --from video.mp4     from a local copy of the item's video (no download)
 *
 * The admin panel starts this by itself when the server has ffmpeg. Without ffmpeg on the server, run it on any
 * computer with PHP and ffmpeg (with a copy of this folder and its data/catalog.json), then upload media/hls/<key>/.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/media.php';
set_time_limit(0);

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$all = in_array('--all', $args, true);
$from = null;
if (($i = array_search('--from', $args, true)) !== false) {
    $from = $args[$i + 1] ?? '';
    if (!is_file($from)) { fwrite(STDERR, "--from: no such file: $from\n"); exit(1); }
    array_splice($args, $i, 2);
}
$keys = array_values(array_filter($args, fn($a) => $a !== '' && $a[0] !== '-'));
if ($from !== null && count($keys) !== 1) { fwrite(STDERR, "--from needs exactly one key.\n"); exit(1); }

$ffmpeg = jp_find_tool('ffmpeg');
if (!$ffmpeg) { fwrite(STDERR, "ffmpeg was not found. Install it and try again.\n"); exit(1); }
$doc = jp_read_catalog();
if ($all) $keys = array_keys($doc['library']);
if (!$keys) { fwrite(STDERR, "Usage: php tools/build-hls.php <key> … | --all [--force]\n"); exit(1); }

$failed = 0;
foreach ($keys as $key) {
    $item = $doc['library'][$key] ?? null;
    if (!$item) { fwrite(STDERR, "$key: not in the library.\n"); $failed++; continue; }
    if ($all && !$force && jp_hls_summary($key)) { echo "$key: already prepared, skipped (--force redoes it)\n"; continue; }
    echo "$key: preparing \"{$item['title']}\" for live…\n";
    try {
        $r = jp_build_hls($key, $item, $doc['settings'], $ffmpeg, $from);
        printf("%s: done, %d segments, %s%s\n", $key, $r['count'], gmdate('H:i:s', (int)$r['total']), $r['encoded'] ? ' (re-encoded)' : '');
    } catch (Throwable $e) {
        fwrite(STDERR, "$key: FAILED: " . $e->getMessage() . "\n");
        $failed++;
    }
}
exit($failed ? 1 : 0);
