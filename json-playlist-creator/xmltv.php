<?php
/**
 * Programme guide in XMLTV (see docs/API.md):
 *   xmltv.php?username=U&password=P[&days=1..14]
 * From the start of the longest catch-up window (at least a day back) through [days] ahead (default 2).
 */
require __DIR__ . '/app/core.php';

$doc = jp_catalog();
jp_require_account($_GET['username'] ?? '', $_GET['password'] ?? '');
$now = time();
$ahead = max(1, min(14, (int)($_GET['days'] ?? 2))) * 86400;
$x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$ts = fn($t) => gmdate('YmdHis', $t) . ' +0000';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: no-store');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tv generator-info-name=\"JSON Playlist Creator\">\n";
foreach ($doc['live'] as $c) {
    echo '  <channel id="' . $x(jp_channel_guide_id($c)) . '"><display-name>' . $x($c['name']) . '</display-name>'
        . ($c['logo'] !== '' ? '<icon src="' . $x(jp_asset($c['logo'])) . '"/>' : '') . "</channel>\n";
}
foreach ($doc['live'] as $c) {
    foreach (jp_guide($c, $now - max(1, $c['catchup_days']) * 86400, $now + $ahead) as $p) {
        echo '  <programme start="' . $ts($p['start']) . '" stop="' . $ts($p['end']) . '" channel="' . $x(jp_channel_guide_id($c)) . '">'
            . '<title>' . $x($p['title']) . '</title>' . ($p['description'] !== '' ? '<desc>' . $x($p['description']) . '</desc>' : '') . "</programme>\n";
    }
}
echo "</tv>\n";
