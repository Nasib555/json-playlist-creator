<?php
/**
 * Sets the admin password from the server's command line (when it's forgotten):
 *   php tools/set-password.php 'new password'
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/media.php';
require dirname(__DIR__) . '/app/auth.php';
try {
    jp_set_password($argv[1] ?? '');
    echo "Admin password set.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
