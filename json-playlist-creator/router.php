<?php
/**
 * Router for PHP's built-in web server, to run JSON Playlist Creator on your own computer without Apache or nginx:
 *
 *   PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8080 router.php
 *
 * then open http://localhost:8080/admin/ (players on your network use http://<this computer's IP>:8080).
 * The workers let it serve several players (and the admin panel) at the same time; without them it answers one
 * request at a time.
 * It does what .htaccess does: keeps private folders closed and maps media URLs to stream.php.
 */
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if (preg_match('#^/(app|data|tools)(/|$)#', $path) || preg_match('#/\.#', $path) || preg_match('#(^/router\.php$|\.md$)#', $path)
    || (preg_match('#^/(media|images)/#', $path) && preg_match('/\.(php\d?|phtml|phar)$/i', $path))) {
    http_response_code(404);
    exit('Not found.');
}

$route = null;
if (preg_match('#^/(live|movie|series)/([^/]+)/([^/]+)/(\d+)(?:\.(\w+))?$#', $path, $m)) {
    $route = ['type' => $m[1], 'u' => $m[2], 'p' => $m[3], 'id' => $m[4], 'ext' => $m[5] ?? ''];
} elseif (preg_match('#^/timeshift/([^/]+)/([^/]+)/(\d+)/([^/]+)/(\d+)(?:\.\w+)?$#', $path, $m)) {
    $route = ['type' => 'timeshift', 'u' => $m[1], 'p' => $m[2], 'duration' => $m[3], 'start' => $m[4], 'id' => $m[5]];
}
if ($route) {
    $_GET = $route + $_GET;
    $_SERVER['SCRIPT_NAME'] = '/stream.php';
    require __DIR__ . '/stream.php';
    return true;
}
if (substr($path, -4) === '.m3u8' && is_file(__DIR__ . $path)) {
    header('Content-Type: application/vnd.apple.mpegurl');
    readfile(__DIR__ . $path);
    return true;
}
return false; // everything else (PHP scripts, files) as usual
