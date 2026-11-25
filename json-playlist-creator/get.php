<?php
/**
 * The account's playlist as M3U (see docs/API.md):
 *   get.php?username=U&password=P[&type=m3u_plus][&output=ts|m3u8]
 */
require __DIR__ . '/app/core.php';

$doc = jp_catalog();
$account = jp_require_account($_GET['username'] ?? '', $_GET['password'] ?? '');
$base = jp_base_url();
$u = rawurlencode($account['username']);
$p = rawurlencode($account['password']);
$ext = in_array($_GET['output'] ?? '', ['m3u8', 'hls'], true) ? 'm3u8' : 'ts';
// Attribute values can't contain quotes or line breaks.
$a = fn($s) => str_replace(['"', "\r", "\n"], ["'", ' ', ' '], (string)$s);
$t = fn($s) => str_replace(["\r", "\n"], ' ', (string)$s);

header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Content-Disposition: inline; filename="playlist.m3u"');
header('Cache-Control: no-store');
echo "#EXTM3U url-tvg=\"$base/xmltv.php?username=$u&password=$p\"\n";
foreach ($doc['live'] as $c) {
    $archive = $c['catchup_days'] > 0
        ? " catchup=\"default\" catchup-days=\"{$c['catchup_days']}\" catchup-source=\"$base/timeshift/$u/$p/{duration:60}/{Y}-{m}-{d}:{H}-{M}/{$c['id']}.ts\""
        : '';
    echo '#EXTINF:-1 tvg-id="' . jp_channel_guide_id($c) . '" tvg-chno="' . (int)$c['num'] . '" tvg-name="' . $a($c['name']) . '" tvg-logo="' . $a(jp_asset($c['logo']))
        . '" group-title="' . $a($doc['categories']['live'][$c['cat']] ?? '') . "\"$archive," . $t($c['name']) . "\n";
    echo "$base/live/$u/$p/{$c['id']}.$ext\n";
}
foreach ($doc['vod'] as $v) {
    $m = jp_media($v['media']);
    if (!$m) continue;
    $e = strtolower(pathinfo(parse_url($m['file'], PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'mp4';
    echo '#EXTINF:-1 tvg-name="' . $a($m['title']) . '" tvg-logo="' . $a(jp_asset($m['poster'])) . '" group-title="' . $a($doc['categories']['vod'][$v['cat']] ?? '') . '",' . $t($m['title']) . "\n";
    echo "$base/movie/$u/$p/{$v['id']}.$e\n";
}
foreach ($doc['series'] as $s) {
    foreach ($s['seasons'] as $no => $se) {
        foreach ($se['episodes'] as $k => $ep) {
            $m = jp_media($ep['media']);
            if (!$m) continue;
            $n = $k + 1;
            $code = sprintf('S%02dE%02d', $no, $n);
            $e = strtolower(pathinfo(parse_url($m['file'], PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'mp4';
            echo '#EXTINF:-1 tvg-name="' . $a("{$s['name']} $code") . '" tvg-logo="' . $a(jp_asset($ep['thumb'] !== '' ? $ep['thumb'] : $s['cover']))
                . '" group-title="' . $a($s['name']) . '",' . $t("{$s['name']} $code {$m['title']}") . "\n";
            echo "$base/series/$u/$p/" . ($s['id'] * 1000 + $no * 100 + $n) . ".$e\n";
        }
    }
}
