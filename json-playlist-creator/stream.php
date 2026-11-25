<?php
/**
 * Media URLs (the web server rewrites the path forms below to this script; see docs/API.md):
 *   live/{user}/{pass}/{id}[.m3u8|.ts]                          a live channel
 *   movie/{user}/{pass}/{id}.{ext}                              a movie
 *   series/{user}/{pass}/{episode_id}.{ext}                     an episode
 *   timeshift/{user}/{pass}/{minutes}/{YYYY-MM-DD:HH-MM}/{id}.ts catch-up of a live channel
 */
require __DIR__ . '/app/core.php';

jp_catalog();
jp_require_account($_GET['u'] ?? '', $_GET['p'] ?? '');
$id = (int)($_GET['id'] ?? 0);

switch ((string)($_GET['type'] ?? '')) {
    case 'live':
        $c = jp_find('live', $id);
        if (!$c) break;
        // Continuous HLS when every video is prepared for live.
        $tl = jp_timeline($c);
        if ($tl && $tl['ready']) {
            // A .ts request gets the same channel's playlist URL (players detect HLS from the reply).
            if (strtolower((string)($_GET['ext'] ?? 'm3u8')) !== 'm3u8') {
                jp_redirect(jp_base_url() . '/live/' . rawurlencode($_GET['u']) . '/' . rawurlencode($_GET['p']) . "/$id.m3u8");
            }
            $text = jp_live_playlist($c);
            if ($text === null) jp_text('This channel is off air right now.', 404);
            jp_send_playlist($text);
        }
        $url = jp_live_fallback_url($c, time());
        if (!$url) jp_text('This channel is off air right now.', 404);
        jp_redirect($url);

    case 'timeshift':
        $c = jp_find('live', $id);
        if (!$c) break;
        jp_timeshift($c, (string)($_GET['start'] ?? ''), (int)($_GET['duration'] ?? 60));

    case 'movie':
        $v = jp_find('vod', $id);
        if ($v && ($url = jp_media_url($v['media'])) !== '') jp_redirect($url);
        break;

    case 'series':
        $e = jp_episode($id);
        if ($e && ($url = jp_media_url($e['media'])) !== '') jp_redirect($url);
        break;
}
jp_text('Not found.', 404);

/** Shared by both catch-up URL forms (path and query). */
function jp_timeshift(array $c, $start, $minutes)
{
    if ($c['catchup_days'] <= 0) jp_text('This channel has no catch-up.', 404);
    $t = jp_parse_timeshift_start($start);
    if ($t === null) jp_text('Bad start time. Use YYYY-MM-DD:HH-MM.', 400);
    $now = time();
    if ($t > $now || $t < $now - $c['catchup_days'] * 86400) jp_text('That time is outside the catch-up window.', 404);
    // A programme with its own recording plays that.
    $p = jp_programme_at($c, $t + 30);
    if ($p && $p['archive'] !== '' && ($url = jp_archive_url($p['archive'])) !== '') jp_redirect($url);
    if ($c['source'] === 'files') {
        $text = jp_timeshift_playlist($c, $t, $minutes);
        if ($text !== null) jp_send_playlist($text);
        $tl = jp_timeline($c);
        $pos = $tl ? jp_timeline_locate($tl, $t) : null;
        if ($pos) jp_redirect(jp_media_url($tl['items'][$pos['index']]['media']));
    }
    jp_text('Nothing to replay at that time.', 404);
}

/** "YYYY-MM-DD:HH-MM" (also "YYYY-MM-DD HH:MM") in the playlist's time zone → unix time, or null. */
function jp_parse_timeshift_start($s)
{
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})[: _T](\d{2})[-:](\d{2})/', (string)$s, $m)) return null;
    return jp_local_ts("{$m[1]} {$m[2]}:{$m[3]}");
}
