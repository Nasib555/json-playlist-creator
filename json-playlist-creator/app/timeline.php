<?php
/**
 * Live channels.
 *
 * A "files" channel plays its videos back to back from its schedule start, looping (or once). Every viewer sees the
 * same moment, like broadcast TV. When every video has live segments (media/hls/<key>/, made by tools/build-hls.php),
 * the channel is served as one continuous HLS stream, the guide is generated from the schedule (or entered by hand),
 * and catch-up replays any past moment. A "link" channel relays a stream from elsewhere; its guide is entered by
 * hand, and catch-up plays the recording attached to a programme.
 */

/** Segments in a live playlist window. */
const JP_LIVE_WINDOW = 6;
/** Safety limit for generated guide entries per request. */
const JP_MAX_ENTRIES = 4000;

/** Live segments of a library item: {segments: [{f, d, at}], total}, or null when not made. Cached per request. */
function jp_hls_info($key)
{
    static $cache = [];
    $key = (string)$key;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $j = preg_match(JP_KEY_RE, $key) ? jp_read_json(JP_ROOT . "/media/hls/$key/segments.json") : null;
    if (!is_array($j) || empty($j['segments'])) return $cache[$key] = null;
    $at = 0.0;
    $segs = [];
    foreach ($j['segments'] as $s) {
        $d = (float)($s['d'] ?? 0);
        $f = (string)($s['f'] ?? '');
        if ($d <= 0 || !preg_match('/^[A-Za-z0-9._-]+\.(ts|m4s|mp4|aac)$/', $f)) return $cache[$key] = null;
        $segs[] = ['f' => $f, 'd' => $d, 'at' => $at];
        $at += $d;
    }
    return $cache[$key] = ['segments' => $segs, 'total' => $at];
}

/** Length of a library item in seconds: exact from its live segments, else what the library says (0 = unknown). */
function jp_item_seconds($key)
{
    $h = jp_hls_info($key);
    if ($h) return $h['total'];
    $m = jp_media($key);
    return $m ? (float)$m['seconds'] : 0.0;
}

/**
 * Schedule of a files channel: {items: [{media, seconds, at, hls}], total, start, loop, ok, ready, problems}.
 * ok = every video exists and has a length; ready = every video also has live segments (continuous HLS).
 */
function jp_timeline(array $c)
{
    if ($c['source'] !== 'files') return null;
    $items = [];
    $at = 0.0;
    $problems = [];
    foreach ($c['items'] as $i => $it) {
        $m = jp_media($it['media']);
        $sec = $m ? jp_item_seconds($it['media']) : 0.0;
        if (!$m) $problems[] = 'Video ' . ($i + 1) . ' is not in the library.';
        elseif ($sec <= 0) $problems[] = "\"{$m['title']}\" has no length: set it in the library, or prepare it for live.";
        $items[] = ['media' => $it['media'], 'seconds' => $sec, 'at' => $at, 'hls' => (bool)jp_hls_info($it['media'])];
        $at += max(0, $sec);
    }
    $start = jp_local_ts($c['start']);
    if ($start === null) $problems[] = 'The schedule start is not a valid date and time.';
    $ok = $items && !$problems && $at > 0;
    return ['items' => $items, 'total' => $at, 'start' => (int)$start, 'loop' => (bool)$c['loop'], 'ok' => $ok,
            'ready' => $ok && !in_array(false, array_column($items, 'hls'), true), 'problems' => $problems];
}

/** Item playing at unix time [t]: {cycle, index, offset}, or null before the start / after the end of a one-time run. */
function jp_timeline_locate(array $tl, $t)
{
    if (!$tl['ok']) return null;
    $elapsed = $t - $tl['start'];
    if ($elapsed < 0) return null;
    $L = $tl['total'];
    $cycle = (int)floor($elapsed / $L);
    if (!$tl['loop'] && $cycle > 0) return null;
    $o = $elapsed - $cycle * $L;
    $items = $tl['items'];
    $lo = 0;
    $hi = count($items) - 1;
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi + 1, 2);
        if ($items[$mid]['at'] <= $o) $lo = $mid; else $hi = $mid - 1;
    }
    // Skip zero-length entries.
    while ($items[$lo]['seconds'] <= 0 && $lo < count($items) - 1) $lo++;
    return ['cycle' => $cycle, 'index' => $lo, 'offset' => $o - $items[$lo]['at']];
}

/** Schedule entries overlapping [from, to): [{start, end, media, index}] (unix seconds). */
function jp_timeline_entries(array $tl, $from, $to)
{
    if (!$tl['ok'] || $to <= $from) return [];
    // Very short videos over a long window would exceed the limit before reaching the present: keep the part
    // around now (half before, half after) instead of only the oldest entries.
    $avg = $tl['total'] / max(1, count($tl['items']));
    if (($to - $from) / $avg > JP_MAX_ENTRIES) {
        $span = JP_MAX_ENTRIES * $avg;
        $from = max($from, min(time(), $to) - $span / 2);
        $to = min($to, $from + $span);
    }
    $out = [];
    $t = max($from, $tl['start']);
    $pos = jp_timeline_locate($tl, $t);
    if (!$pos) return [];
    [$cycle, $i] = [$pos['cycle'], $pos['index']];
    $n = count($tl['items']);
    while (count($out) < JP_MAX_ENTRIES) {
        $it = $tl['items'][$i];
        $s = $tl['start'] + $cycle * $tl['total'] + $it['at'];
        if ($s >= $to) break;
        if ($it['seconds'] > 0) $out[] = ['start' => (int)round($s), 'end' => (int)round($s + $it['seconds']), 'media' => $it['media'], 'index' => $i];
        if (++$i >= $n) {
            $i = 0;
            $cycle++;
            if (!$tl['loop']) break;
        }
    }
    return $out;
}

/**
 * Guide of a channel between [from, to): [{start, end, title, description, archive}] where archive is the
 * programme's recording ('' = none; files channels replay their schedule instead).
 */
function jp_guide(array $c, $from, $to)
{
    $out = [];
    if ($c['guide'] === 'auto') {
        $tl = jp_timeline($c);
        foreach ($tl ? jp_timeline_entries($tl, $from, $to) : [] as $e) {
            $m = jp_media($e['media']);
            $out[] = ['start' => $e['start'], 'end' => $e['end'], 'title' => $m['title'] ?? '', 'description' => $m['plot'] ?? '', 'archive' => ''];
        }
        return $out;
    }
    foreach ($c['programmes'] as $p) {
        $s = jp_local_ts($p['start']);
        $e = jp_local_ts($p['end']);
        if ($s === null || $e === null || $e <= $from || $s >= $to) continue;
        $out[] = ['start' => $s, 'end' => $e, 'title' => $p['title'], 'description' => $p['description'], 'archive' => $p['archive']];
    }
    return $out;
}

/** Whether a past programme can be replayed now (inside the catch-up window, with something to play). */
function jp_has_archive(array $c, array $p, $now)
{
    if ($c['catchup_days'] <= 0 || $p['end'] > $now || $p['start'] < $now - $c['catchup_days'] * 86400) return false;
    if ($p['archive'] !== '') return true;
    $tl = $c['source'] === 'files' ? jp_timeline($c) : null;
    return $tl && $tl['ok'];
}

/* ------------------------------------------------------------------ continuous HLS for files channels */

/** One cycle of the channel as a flat segment list: [{k, f, d, at, first, item}], plus count of videos. */
function jp_stitch(array $tl)
{
    $segs = [];
    $at = 0.0;
    foreach ($tl['items'] as $i => $it) {
        $h = jp_hls_info($it['media']);
        foreach ($h['segments'] as $j => $s) {
            $segs[] = ['k' => $it['media'], 'f' => $s['f'], 'd' => $s['d'], 'at' => $at, 'first' => $j === 0, 'item' => $i];
            $at += $s['d'];
        }
    }
    return ['segments' => $segs, 'total' => $at, 'videos' => count($tl['items'])];
}

/** Global segment number playing at [t] (cycle × count + index), or null. */
function jp_stitch_locate(array $st, array $tl, $t)
{
    $elapsed = $t - $tl['start'];
    if ($elapsed < 0) return null;
    $cycle = (int)floor($elapsed / $st['total']);
    if (!$tl['loop'] && $cycle > 0) return null;
    $o = $elapsed - $cycle * $st['total'];
    $segs = $st['segments'];
    $lo = 0;
    $hi = count($segs) - 1;
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi + 1, 2);
        if ($segs[$mid]['at'] <= $o) $lo = $mid; else $hi = $mid - 1;
    }
    return $cycle * count($segs) + $lo;
}

function jp_segment_lines(array $st, $g0, $count, $loop)
{
    $n = count($st['segments']);
    $lines = [];
    for ($k = 0; $k < $count; $k++) {
        $g = $g0 + $k;
        if (!$loop && $g >= $n) break;
        $s = $st['segments'][$g % $n];
        if ($k > 0 && $s['first']) $lines[] = '#EXT-X-DISCONTINUITY';
        $lines[] = sprintf('#EXTINF:%.3f,', $s['d']);
        $lines[] = jp_asset("media/hls/{$s['k']}/{$s['f']}");
    }
    return $lines;
}

function jp_target_duration(array $st)
{
    return (int)ceil(max(array_column($st['segments'], 'd')));
}

/** Live playlist: a sliding window ending at the segment playing now. Null when the channel is off air. */
function jp_live_playlist(array $c, $now = null)
{
    $tl = jp_timeline($c);
    if (!$tl || !$tl['ready']) return null;
    $st = jp_stitch($tl);
    $g = jp_stitch_locate($st, $tl, $now ?? microtime(true));
    if ($g === null) return null;
    $n = count($st['segments']);
    $g0 = max(0, $g - (JP_LIVE_WINDOW - 1));
    // Discontinuities that have left the window: one per video start before or at the first segment (not the very first).
    $disc = intdiv($g0, $n) * $st['videos'] + $st['segments'][$g0 % $n]['item'];
    $lines = ['#EXTM3U', '#EXT-X-VERSION:3', '#EXT-X-TARGETDURATION:' . jp_target_duration($st), "#EXT-X-MEDIA-SEQUENCE:$g0",
              "#EXT-X-DISCONTINUITY-SEQUENCE:$disc"];
    $lines = array_merge($lines, jp_segment_lines($st, $g0, JP_LIVE_WINDOW, $tl['loop']));
    if (!$tl['loop'] && $g0 + JP_LIVE_WINDOW >= $n) $lines[] = '#EXT-X-ENDLIST';
    return implode("\n", $lines) . "\n";
}

/** What the channel played from [startTs] for [minutes], as a finished (seekable) playlist. Null when nothing played. */
function jp_timeshift_playlist(array $c, $startTs, $minutes)
{
    $tl = jp_timeline($c);
    if (!$tl || !$tl['ready']) return null;
    $st = jp_stitch($tl);
    $g = jp_stitch_locate($st, $tl, $startTs);
    if ($g === null) return null;
    $want = max(60, min(12 * 3600, $minutes * 60));
    $n = count($st['segments']);
    $count = 0;
    for ($sum = 0.0, $k = $g; $sum < $want && $count < 20000; $k++, $count++) {
        if (!$tl['loop'] && $k >= $n) break;
        $sum += $st['segments'][$k % $n]['d'];
    }
    $lines = ['#EXTM3U', '#EXT-X-VERSION:3', '#EXT-X-TARGETDURATION:' . jp_target_duration($st), '#EXT-X-MEDIA-SEQUENCE:0', '#EXT-X-PLAYLIST-TYPE:VOD'];
    return implode("\n", array_merge($lines, jp_segment_lines($st, $g, $count, $tl['loop']), ['#EXT-X-ENDLIST'])) . "\n";
}

/**
 * Where to send a viewer when the channel can't be served as continuous HLS: the relayed link, or the video playing
 * now (from its start: without live segments a position inside a file can't be given to every player). Null = off air.
 */
function jp_live_fallback_url(array $c, $t)
{
    if ($c['source'] === 'link') return $c['link'];
    $tl = jp_timeline($c);
    $pos = $tl ? jp_timeline_locate($tl, $t) : null;
    return $pos ? jp_media_url($tl['items'][$pos['index']]['media']) : null;
}

/** The programme of a manual guide that contains [t] (catch-up of a link channel), or null. */
function jp_programme_at(array $c, $t)
{
    foreach (jp_guide($c, $t - 86400, $t + 86400) as $p) if ($p['start'] <= $t && $t < $p['end']) return $p;
    return null;
}

/** Playable URL of a programme recording: a library item or a link. */
function jp_archive_url($archive)
{
    if (preg_match('#^https?://#i', $archive)) return $archive;
    return jp_media($archive) ? jp_media_url($archive) : '';
}
