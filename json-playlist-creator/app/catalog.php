<?php
/**
 * The catalog (data/catalog.json): settings, accounts, the media library, categories, live channels, movies, series.
 * jp_normalize() makes any stored or edited catalog well-typed; jp_validate() lists what would break playback.
 */

const JP_KEY_RE = '/^[a-z0-9][a-z0-9_-]{0,47}$/';
const JP_MAX_CATCHUP_DAYS = 30;

function jp_default_catalog()
{
    return [
        'schema' => JP_SCHEMA,
        'settings' => ['name' => 'My Playlist', 'timezone' => 'UTC', 'message' => '', 'media_base' => ''],
        'accounts' => [],
        'library' => [],
        'categories' => ['live' => [], 'vod' => [], 'series' => []],
        'live' => [],
        'vod' => [],
        'series' => [],
    ];
}

/** Random account credentials that are easy to type on a TV remote (no look-alike characters). */
function jp_new_account($note = '')
{
    $pick = function ($n) {
        $chars = 'abcdefghjkmnpqrstuvwxyz23456789';
        $s = '';
        for ($i = 0; $i < $n; $i++) $s .= $chars[random_int(0, strlen($chars) - 1)];
        return $s;
    };
    return ['username' => 'user' . $pick(4), 'password' => $pick(10), 'enabled' => true, 'max_connections' => 1, 'expires' => '', 'note' => $note];
}

function jp_str($v, $max = 2000)
{
    return mb_substr(trim((string)$v), 0, $max);
}

function jp_youtube_id($v)
{
    $v = trim((string)$v);
    if (preg_match('~(?:v=|youtu\.be/|embed/|shorts/)([A-Za-z0-9_-]{11})~', $v, $m)) return $m[1];
    return preg_match('/^[A-Za-z0-9_-]{11}$/', $v) ? $v : '';
}

/** "YYYY-MM-DD HH:MM" (accepts a "T" or seconds), or '' when it isn't one. */
function jp_norm_time($v)
{
    return preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/', trim((string)$v), $m) ? "{$m[1]} {$m[2]}" : '';
}

function jp_normalize($d)
{
    $d = is_array($d) ? $d : [];
    $out = jp_default_catalog();

    $s = (array)($d['settings'] ?? []);
    $out['settings'] = [
        'name' => jp_str($s['name'] ?? 'My Playlist', 80) ?: 'My Playlist',
        'timezone' => in_array($s['timezone'] ?? '', timezone_identifiers_list(), true) ? $s['timezone'] : 'UTC',
        'message' => jp_str($s['message'] ?? '', 300),
        'media_base' => jp_str($s['media_base'] ?? '', 500),
    ];

    foreach ((array)($d['accounts'] ?? []) as $a) {
        $a = (array)$a;
        $out['accounts'][] = [
            'username' => jp_str($a['username'] ?? '', 64),
            'password' => jp_str($a['password'] ?? '', 64),
            'enabled' => !empty($a['enabled']),
            'max_connections' => max(1, min(100, (int)($a['max_connections'] ?? 1))),
            'expires' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($a['expires'] ?? '')) ? $a['expires'] : '',
            'note' => jp_str($a['note'] ?? '', 200),
        ];
    }

    foreach ((array)($d['library'] ?? []) as $key => $m) {
        $m = (array)$m;
        $item = ['seconds' => max(0, (int)($m['seconds'] ?? 0)), 'rating' => jp_str($m['rating'] ?? '', 4)];
        foreach (['title' => 200, 'file' => 1000, 'year' => 4, 'poster' => 1000, 'backdrop' => 1000, 'genre' => 200, 'director' => 200,
                  'cast' => 500, 'plot' => 4000, 'license' => 200, 'country' => 100, 'language' => 100, 'age_rating' => 20, 'tmdb' => 20] as $f => $max) {
            $item[$f] = jp_str($m[$f] ?? '', $max);
        }
        foreach (['width', 'height', 'bitrate'] as $f) $item[$f] = max(0, (int)($m[$f] ?? 0));
        $item['trailer'] = jp_youtube_id($m['trailer'] ?? '');
        $out['library'][(string)$key] = $item;
    }

    foreach (['live', 'vod', 'series'] as $t) {
        foreach ((array)($d['categories'][$t] ?? []) as $id => $name) {
            if (jp_str($name, 100) !== '') $out['categories'][$t][(string)(int)$id] = jp_str($name, 100);
        }
    }

    foreach ((array)($d['live'] ?? []) as $i => $c) {
        $c = (array)$c;
        $source = ($c['source'] ?? '') === 'link' ? 'link' : 'files';
        $programmes = [];
        foreach ((array)($c['programmes'] ?? []) as $p) {
            $p = (array)$p;
            $programmes[] = ['start' => jp_norm_time($p['start'] ?? ''), 'end' => jp_norm_time($p['end'] ?? ''), 'title' => jp_str($p['title'] ?? '', 200),
                             'description' => jp_str($p['description'] ?? '', 2000), 'archive' => jp_str($p['archive'] ?? '', 1000)];
        }
        usort($programmes, fn($a, $b) => strcmp($a['start'], $b['start']));
        $out['live'][] = [
            'id' => (int)($c['id'] ?? 0),
            'num' => max(0, (int)($c['num'] ?? $i + 1)),
            'name' => jp_str($c['name'] ?? '', 120),
            'cat' => (string)($c['cat'] ?? ''),
            'logo' => jp_str($c['logo'] ?? '', 1000),
            'source' => $source,
            'link' => jp_str($c['link'] ?? '', 1000),
            'items' => array_values(array_map(fn($x) => ['media' => (string)(((array)$x)['media'] ?? '')], (array)($c['items'] ?? []))),
            'start' => jp_norm_time($c['start'] ?? '') ?: '2025-01-01 00:00',
            'loop' => !array_key_exists('loop', $c) || !empty($c['loop']),
            'guide' => $source === 'files' && ($c['guide'] ?? 'auto') !== 'manual' ? 'auto' : 'manual',
            'programmes' => $programmes,
            'catchup_days' => max(0, min(JP_MAX_CATCHUP_DAYS, (int)($c['catchup_days'] ?? 0))),
        ];
    }

    foreach ((array)($d['vod'] ?? []) as $m) {
        $m = (array)$m;
        $out['vod'][] = ['id' => (int)($m['id'] ?? 0), 'cat' => (string)($m['cat'] ?? ''), 'media' => (string)($m['media'] ?? ''),
                         'rating' => jp_str($m['rating'] ?? '', 4), 'added' => jp_norm_time($m['added'] ?? '') ?: date('Y-m-d H:i')];
    }

    foreach ((array)($d['series'] ?? []) as $s) {
        $s = (array)$s;
        $seasons = [];
        foreach ((array)($s['seasons'] ?? []) as $no => $se) {
            $se = (array)$se;
            $eps = [];
            foreach ((array)($se['episodes'] ?? []) as $e) {
                $e = (array)$e;
                $eps[] = ['media' => (string)($e['media'] ?? ''), 'thumb' => jp_str($e['thumb'] ?? '', 1000)];
            }
            $seasons[(string)(int)$no] = ['title' => jp_str($se['title'] ?? '', 120), 'thumb' => jp_str($se['thumb'] ?? '', 1000), 'episodes' => $eps];
        }
        ksort($seasons, SORT_NUMERIC);
        $item = ['id' => (int)($s['id'] ?? 0), 'cat' => (string)($s['cat'] ?? ''), 'seasons' => $seasons, 'trailer' => jp_youtube_id($s['trailer'] ?? ''),
                 'added' => jp_norm_time($s['added'] ?? '') ?: date('Y-m-d H:i')];
        foreach (['name' => 200, 'cover' => 1000, 'backdrop' => 1000, 'plot' => 4000, 'cast' => 500, 'director' => 200, 'genre' => 200, 'year' => 4, 'rating' => 4] as $f => $max) {
            $item[$f] = jp_str($s[$f] ?? '', $max);
        }
        $out['series'][] = $item;
    }
    return $out;
}

/** Problems that would break the playlist for players, in plain words (empty = fine to publish). */
function jp_validate(array $d)
{
    $d = jp_normalize($d);
    $e = [];
    $lib = $d['library'];

    $users = [];
    foreach ($d['accounts'] as $i => $a) {
        $n = $i + 1;
        if (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $a['username'])) $e[] = "Account $n: the username needs 3–64 letters, digits or . _ @ -";
        if (!preg_match('/^[A-Za-z0-9._@!$*+-]{4,64}$/', $a['password'])) $e[] = "Account $n: the password needs 4–64 letters, digits or . _ @ ! \$ * + -";
        if (isset($users[strtolower($a['username'])])) $e[] = "Account $n: the username \"{$a['username']}\" is used twice.";
        $users[strtolower($a['username'])] = true;
    }

    foreach ($lib as $key => $m) {
        $label = $m['title'] !== '' ? "\"{$m['title']}\"" : "\"$key\"";
        if (!preg_match(JP_KEY_RE, (string)$key)) $e[] = "Library item \"$key\": its key may only use a-z, 0-9, - and _.";
        if ($m['title'] === '') $e[] = "Library item \"$key\": a title is required.";
        $f = $m['file'];
        if ($f === '') $e[] = "Library item $label: add a video (upload one or paste a link).";
        elseif (strpos($f, 'media/') === 0) { if (!jp_media_file_path($f)) $e[] = "Library item $label: the uploaded file $f is missing."; }
        elseif (strpos($f, '://') !== false) { if (!preg_match('#^https?://#i', $f)) $e[] = "Library item $label: links must start with http:// or https://."; }
        elseif ($d['settings']['media_base'] === '') $e[] = "Library item $label: a bare file name needs the media base URL (Settings), or use a full link.";
    }

    $checkIds = function (array $list, $what) use (&$e) {
        $seen = [];
        foreach ($list as $i => $x) {
            if ($x['id'] <= 0 || $x['id'] > 2_000_000_000) $e[] = "$what #" . ($i + 1) . ': the id must be a positive number.';
            elseif (isset($seen[$x['id']])) $e[] = "$what: the id {$x['id']} is used twice.";
            $seen[$x['id']] = true;
        }
    };
    $checkIds($d['live'], 'Channel');
    $checkIds($d['vod'], 'Movie');
    $checkIds($d['series'], 'Series');

    foreach ($d['live'] as $c) {
        $n = $c['name'] !== '' ? "\"{$c['name']}\"" : "#{$c['id']}";
        if ($c['name'] === '') $e[] = "Channel {$c['id']}: a name is required.";
        if (!isset($d['categories']['live'][$c['cat']])) $e[] = "Channel $n: pick a category.";
        if ($c['source'] === 'link') {
            if (!preg_match('#^(https?|rtmps?|rtsp|udp|rtp)://#i', $c['link'])) $e[] = "Channel $n: paste the stream link (http://, https://, rtmp://, rtsp:// or udp://).";
        } else {
            if (!$c['items']) $e[] = "Channel $n: add at least one video to play.";
            foreach ($c['items'] as $k => $it) if (!isset($lib[$it['media']])) $e[] = "Channel $n, video " . ($k + 1) . ': pick a library item.';
            if (!jp_local_ts_any($c['start'])) $e[] = "Channel $n: the schedule start is not a valid date and time.";
        }
        $prev = '';
        foreach ($c['programmes'] as $k => $p) {
            $pn = "Channel $n, programme " . ($k + 1);
            if ($p['start'] === '' || $p['end'] === '') { $e[] = "$pn: start and end must be dates with times."; continue; }
            if ($p['end'] <= $p['start']) $e[] = "$pn: it must end after it starts.";
            if ($p['title'] === '') $e[] = "$pn: a title is required.";
            if ($prev !== '' && $p['start'] < $prev) $e[] = "$pn (\"{$p['title']}\"): it overlaps the programme before it.";
            if ($p['archive'] !== '' && !isset($lib[$p['archive']]) && !preg_match('#^https?://#i', $p['archive'])) {
                $e[] = "$pn: the recording must be a library item or an http(s) link.";
            }
            $prev = $p['end'];
        }
    }

    foreach ($d['vod'] as $m) {
        if (!isset($lib[$m['media']])) $e[] = "Movie {$m['id']}: pick a library item.";
        if (!isset($d['categories']['vod'][$m['cat']])) $e[] = "Movie {$m['id']}: pick a category.";
    }

    foreach ($d['series'] as $s) {
        $n = $s['name'] !== '' ? "\"{$s['name']}\"" : "#{$s['id']}";
        if ($s['name'] === '') $e[] = "Series {$s['id']}: a name is required.";
        if ($s['id'] > 2_000_000) $e[] = "Series $n: the id must be at most 2,000,000 (episode ids are built from it).";
        if (!isset($d['categories']['series'][$s['cat']])) $e[] = "Series $n: pick a category.";
        if (!$s['seasons']) $e[] = "Series $n: add a season.";
        foreach ($s['seasons'] as $no => $se) {
            if ((int)$no < 1 || (int)$no > 9) $e[] = "Series $n: season numbers must be 1–9.";
            if (!$se['episodes']) $e[] = "Series $n, season $no: add an episode.";
            if (count($se['episodes']) > 99) $e[] = "Series $n, season $no: at most 99 episodes.";
            foreach ($se['episodes'] as $k => $ep) if (!isset($lib[$ep['media']])) $e[] = "Series $n, S{$no}E" . ($k + 1) . ': pick a library item.';
        }
    }
    return array_values(array_unique($e));
}

/** Validation-only time check that doesn't depend on the request's time zone. */
function jp_local_ts_any($s)
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s) && strtotime($s . ':00 UTC') !== false;
}

/** Absolute path of an uploaded video ("media/<name>"), or null when it isn't one or is missing. */
function jp_media_file_path($rel)
{
    if (!preg_match('#^media/([A-Za-z0-9][A-Za-z0-9._-]{0,150})$#', (string)$rel, $m)) return null;
    $p = JP_ROOT . '/media/' . $m[1];
    return is_file($p) ? $p : null;
}
