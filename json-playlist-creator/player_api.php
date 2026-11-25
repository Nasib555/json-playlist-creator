<?php
/**
 * Open JSON Playlist API (see docs/API.md).
 *   player_api.php?username=U&password=P                 account and server info
 *   player_api.php?username=U&password=P&action=…        categories, streams, details, guide
 */
require __DIR__ . '/app/core.php';

$doc = jp_catalog();
$account = jp_account($_GET['username'] ?? '', $_GET['password'] ?? '');
if (!$account) jp_json(['user_info' => ['auth' => 0]]);

$action = (string)($_GET['action'] ?? '');
$now = time();
$cat = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (string)$_GET['category_id'] : null;

/** Unix time of a catalog "added" date. */
$added = fn($s) => (string)(jp_local_ts($s) ?? $now);
$backdrops = fn($p) => $p === '' ? [] : [jp_asset($p)];
$tech = fn($m) => [
    'video' => ['codec_name' => 'h264', 'width' => $m['width'] ?: 1920, 'height' => $m['height'] ?: 1080],
    'audio' => ['codec_name' => 'aac', 'channels' => 2, 'channel_layout' => 'stereo'],
];
$ext = function ($key) {
    $m = jp_media($key);
    $e = strtolower(pathinfo(parse_url((string)($m['file'] ?? ''), PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    return in_array($e, ['mp4', 'm4v', 'mkv', 'mov', 'webm', 'avi', 'ts', 'm3u8'], true) ? $e : 'mp4';
};
$listing = function (array $c, array $p) use ($now) {
    return [
        'id' => (string)($c['id'] * 1000000 + intdiv($p['start'], 60) % 1000000),
        'epg_id' => (string)$c['id'],
        'title' => base64_encode($p['title']),
        'lang' => 'en',
        'start' => date('Y-m-d H:i:s', $p['start']),
        'end' => date('Y-m-d H:i:s', $p['end']),
        'description' => base64_encode($p['description']),
        'channel_id' => jp_channel_guide_id($c),
        'start_timestamp' => (string)$p['start'],
        'stop_timestamp' => (string)$p['end'],
        'now_playing' => $p['start'] <= $now && $now < $p['end'] ? 1 : 0,
        'has_archive' => jp_has_archive($c, $p, $now) ? 1 : 0,
    ];
};

switch ($action) {
    case '':
        $base = parse_url(jp_base_url());
        $scheme = $base['scheme'] ?? 'http';
        jp_json([
            'user_info' => [
                'username' => $account['username'],
                'password' => $account['password'],
                'message' => $doc['settings']['message'],
                'auth' => 1,
                'status' => 'Active',
                'exp_date' => $account['expires'] !== '' ? (string)jp_local_ts($account['expires'] . ' 23:59') : null,
                'is_trial' => '0',
                'active_cons' => '0',
                'created_at' => (string)(jp_local_ts('2025-01-01 00:00') ?? 0),
                'max_connections' => (string)$account['max_connections'],
                'allowed_output_formats' => ['m3u8', 'ts'],
            ],
            'server_info' => [
                'url' => $base['host'] ?? 'localhost',
                'port' => (string)($base['port'] ?? ($scheme === 'https' ? 443 : 80)),
                'https_port' => (string)($scheme === 'https' ? ($base['port'] ?? 443) : 443),
                'server_protocol' => $scheme,
                'rtmp_port' => '0',
                'timezone' => jp_timezone($doc),
                'timestamp_now' => $now,
                'time_now' => date('Y-m-d H:i:s', $now),
                'name' => $doc['settings']['name'],
            ],
        ]);

    case 'get_live_categories':
    case 'get_vod_categories':
    case 'get_series_categories':
        $type = ['get_live_categories' => 'live', 'get_vod_categories' => 'vod', 'get_series_categories' => 'series'][$action];
        $out = [];
        foreach ($doc['categories'][$type] as $id => $name) $out[] = ['category_id' => (string)$id, 'category_name' => $name, 'parent_id' => 0];
        jp_json($out);

    case 'get_live_streams':
        $out = [];
        foreach ($doc['live'] as $i => $c) {
            if ($cat !== null && $c['cat'] !== $cat) continue;
            $out[] = [
                'num' => $c['num'] ?: $i + 1, 'name' => $c['name'], 'stream_type' => 'live', 'stream_id' => $c['id'],
                'stream_icon' => jp_asset($c['logo']), 'epg_channel_id' => jp_channel_guide_id($c), 'added' => '0',
                'category_id' => $c['cat'], 'custom_sid' => '', 'tv_archive' => $c['catchup_days'] > 0 ? 1 : 0,
                'direct_source' => '', 'tv_archive_duration' => $c['catchup_days'],
            ];
        }
        jp_json($out);

    case 'get_vod_streams':
        $out = [];
        foreach ($doc['vod'] as $i => $v) {
            if ($cat !== null && $v['cat'] !== $cat) continue;
            $m = jp_media($v['media']);
            if (!$m) continue;
            $rating = $v['rating'] !== '' ? $v['rating'] : $m['rating'];
            $out[] = [
                'num' => $i + 1, 'name' => $m['title'], 'stream_type' => 'movie', 'stream_id' => $v['id'],
                'stream_icon' => jp_asset($m['poster']), 'rating' => $rating, 'rating_5based' => round((float)$rating / 2, 1),
                'added' => $added($v['added']), 'category_id' => $v['cat'], 'container_extension' => $ext($v['media']),
                'custom_sid' => '', 'direct_source' => '', 'tmdb' => $m['tmdb'],
            ];
        }
        jp_json($out);

    case 'get_vod_info':
        $v = jp_find('vod', $_GET['vod_id'] ?? 0);
        $m = $v ? jp_media($v['media']) : null;
        if (!$m) jp_json([]);
        $rating = $v['rating'] !== '' ? $v['rating'] : $m['rating'];
        $t = $tech($m);
        $date = $m['year'] !== '' ? $m['year'] . '-01-01' : '';
        $plot = $m['plot'] . ($m['license'] !== '' ? " ({$m['license']})" : '');
        jp_json([
            'info' => [
                'tmdb_id' => $m['tmdb'], 'name' => $m['title'], 'o_name' => $m['title'], 'movie_image' => jp_asset($m['poster']),
                'cover_big' => jp_asset($m['poster']), 'backdrop_path' => $backdrops($m['backdrop']), 'youtube_trailer' => $m['trailer'],
                'genre' => $m['genre'], 'plot' => $plot, 'description' => $plot, 'cast' => $m['cast'], 'actors' => $m['cast'],
                'director' => $m['director'], 'rating' => $rating, 'rating_5based' => round((float)$rating / 2, 1),
                'releasedate' => $date, 'release_date' => $date, 'year' => $m['year'],
                'duration' => gmdate('H:i:s', $m['seconds']), 'duration_secs' => $m['seconds'], 'episode_run_time' => (int)round($m['seconds'] / 60),
                'country' => $m['country'], 'language' => $m['language'], 'age' => $m['age_rating'], 'mpaa_rating' => $m['age_rating'],
                'video' => $t['video'], 'audio' => $t['audio'], 'bitrate' => $m['bitrate'],
            ],
            'movie_data' => ['stream_id' => $v['id'], 'name' => $m['title'], 'added' => $added($v['added']), 'category_id' => $v['cat'],
                             'container_extension' => $ext($v['media']), 'custom_sid' => '', 'direct_source' => ''],
        ]);

    case 'get_series':
        $out = [];
        foreach ($doc['series'] as $i => $s) {
            if ($cat !== null && $s['cat'] !== $cat) continue;
            $out[] = [
                'num' => $i + 1, 'name' => $s['name'], 'series_id' => $s['id'], 'cover' => jp_asset($s['cover']), 'plot' => $s['plot'],
                'cast' => $s['cast'], 'director' => $s['director'], 'genre' => $s['genre'], 'releaseDate' => $s['year'] !== '' ? $s['year'] . '-01-01' : '',
                'last_modified' => $added($s['added']), 'rating' => $s['rating'], 'rating_5based' => round((float)$s['rating'] / 2, 1),
                'backdrop_path' => $backdrops($s['backdrop']), 'youtube_trailer' => $s['trailer'],
                'episode_run_time' => (string)jp_series_runtime($s), 'category_id' => $s['cat'],
            ];
        }
        jp_json($out);

    case 'get_series_info':
        $s = jp_find('series', $_GET['series_id'] ?? 0);
        if (!$s) jp_json([]);
        $seasons = [];
        $episodes = [];
        foreach ($s['seasons'] as $no => $se) {
            $no = (int)$no;
            $seasons[] = ['season_number' => $no, 'name' => $se['title'] !== '' ? $se['title'] : "Season $no", 'episode_count' => count($se['episodes']),
                          'cover' => jp_asset($se['thumb']), 'air_date' => ''];
            foreach ($se['episodes'] as $k => $e) {
                $m = jp_media($e['media']);
                if (!$m) continue;
                $n = $k + 1;
                $t = $tech($m);
                $episodes[(string)$no][] = [
                    'id' => (string)($s['id'] * 1000 + $no * 100 + $n), 'episode_num' => $n, 'title' => $m['title'],
                    'container_extension' => $ext($e['media']), 'season' => $no, 'custom_sid' => '', 'added' => $added($s['added']), 'direct_source' => '',
                    'info' => ['movie_image' => jp_asset($e['thumb'] !== '' ? $e['thumb'] : $m['poster']), 'name' => $m['title'],
                               'plot' => $m['plot'] . ($m['license'] !== '' ? " ({$m['license']})" : ''),
                               'duration' => gmdate('H:i:s', $m['seconds']), 'duration_secs' => $m['seconds'], 'rating' => $m['rating'] !== '' ? $m['rating'] : $s['rating'],
                               'releasedate' => $m['year'] !== '' ? $m['year'] . '-01-01' : '', 'director' => $m['director'], 'cast' => $m['cast'],
                               'genre' => $m['genre'], 'tmdb_id' => $m['tmdb'], 'backdrop_path' => $backdrops($m['backdrop']),
                               'video' => $t['video'], 'audio' => $t['audio']],
                ];
            }
        }
        $date = $s['year'] !== '' ? $s['year'] . '-01-01' : '';
        jp_json([
            'seasons' => $seasons,
            'info' => ['name' => $s['name'], 'cover' => jp_asset($s['cover']), 'plot' => $s['plot'], 'cast' => $s['cast'], 'director' => $s['director'],
                       'genre' => $s['genre'], 'releaseDate' => $date, 'release_date' => $date, 'rating' => $s['rating'],
                       'rating_5based' => round((float)$s['rating'] / 2, 1), 'backdrop_path' => $backdrops($s['backdrop']),
                       'youtube_trailer' => $s['trailer'], 'episode_run_time' => (string)jp_series_runtime($s), 'category_id' => $s['cat']],
            'episodes' => (object)$episodes,
        ]);

    case 'get_short_epg':
        $c = jp_find('live', $_GET['stream_id'] ?? 0);
        if (!$c) jp_json(['epg_listings' => []]);
        $limit = max(1, min(50, (int)($_GET['limit'] ?? 4)));
        $list = array_values(array_filter(jp_guide($c, $now, $now + 3 * 86400), fn($p) => $p['end'] > $now));
        jp_json(['epg_listings' => array_map(fn($p) => $listing($c, $p), array_slice($list, 0, $limit))]);

    case 'get_simple_data_table':
        $c = jp_find('live', $_GET['stream_id'] ?? 0);
        if (!$c) jp_json(['epg_listings' => []]);
        // The catch-up window (at least a day back) through two days ahead.
        $list = jp_guide($c, $now - max(1, $c['catchup_days']) * 86400, $now + 2 * 86400);
        jp_json(['epg_listings' => array_map(fn($p) => $listing($c, $p), $list)]);

    default:
        jp_json([]);
}

/** Average episode length of a series, in minutes. */
function jp_series_runtime(array $s)
{
    $secs = [];
    foreach ($s['seasons'] as $se) foreach ($se['episodes'] as $e) if ($m = jp_media($e['media'])) $secs[] = $m['seconds'];
    return $secs ? (int)round(array_sum($secs) / count($secs) / 60) : 0;
}
