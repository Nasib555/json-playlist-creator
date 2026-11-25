<?php
/**
 * First-run setup from the command line, instead of the web page (recommended on public servers):
 *   php tools/setup.php --password='admin password' --accept-terms [--name='My Playlist'] [--timezone=Europe/London]
 * Read TERMS.md first: --accept-terms records that you accept it.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/media.php';
require dirname(__DIR__) . '/app/auth.php';

$o = getopt('', ['password:', 'accept-terms', 'name::', 'timezone::']);
if (empty($o['password']) || !isset($o['accept-terms'])) {
    fwrite(STDERR, "Usage: php tools/setup.php --password='…' --accept-terms [--name='My Playlist'] [--timezone=UTC]\n");
    exit(1);
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
try {
    jp_run_setup($o['password'], $o['name'] ?? 'My Playlist', $o['timezone'] ?? 'UTC', true);
    $doc = jp_read_catalog();
    $a = $doc['accounts'][0] ?? null;
    echo "Set up. Open the admin panel (admin/) and log in with your password.\n";
    if ($a) echo "First playlist account: username {$a['username']}, password {$a['password']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
