<?php
// Keep local preview routing consistent with a dedicated public document root.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/', '/index.html', '/style.css', '/download.php', '/INSTALL.md', '/API.md'], true)) {
    return false;
}
http_response_code(404);
