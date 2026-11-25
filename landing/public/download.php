<?php
declare(strict_types=1);

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$root = dirname(__DIR__);
$filename = 'json-playlist-creator-1.0.0.zip';
$zip = fopen($root . '/' . $filename, 'rb');
$size = $zip ? fstat($zip)['size'] : 0;
if (!$size) {
    http_response_code(503);
    exit('The download is temporarily unavailable.');
}

// Only trust the address provided by the web server.
$address = inet_pton($_SERVER['REMOTE_ADDR'] ?? '');
if ($address === false) {
    http_response_code(503);
    exit;
}
// Group IPv6 clients by /64 to cover rotating privacy addresses.
$client = hash('sha256', strlen($address) === 16 ? substr($address, 0, 8) : $address);
$lock = fopen($root . '/state/downloads.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) {
    http_response_code(503);
    exit('The download is temporarily unavailable.');
}

$ledger = $root . '/state/downloads.json';
$history = is_file($ledger)
    ? json_decode((string) file_get_contents($ledger), true)
    : [];
if (!is_array($history)) {
    http_response_code(503);
    exit('The download is temporarily unavailable.');
}

$now = time();
$window = 600;
$limit = 5;
$recent = $history[$client] ?? [];
$recent = array_values(array_filter($recent, static fn ($at) => $at > $now - $window));
if (count($recent) >= $limit) {
    $retry = $recent[0] + $window - $now;
    flock($lock, LOCK_UN);
    header('Retry-After: ' . $retry);
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(429);
    exit('Download limit reached. Try again in ' . $retry . ' seconds.');
}

foreach ($history as $key => $timestamps) {
    if (end($timestamps) <= $now - $window) {
        unset($history[$key]);
    }
}
$recent[] = $now;
$history[$client] = $recent;
$payload = json_encode($history);
$temp = $ledger . '.tmp';
if (file_put_contents($temp, $payload) !== strlen($payload) || !rename($temp, $ledger)) {
    http_response_code(503);
    exit('The download is temporarily unavailable.');
}

flock($lock, LOCK_UN);
fclose($lock);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $size);
fpassthru($zip);
