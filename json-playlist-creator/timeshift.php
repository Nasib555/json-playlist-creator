<?php
/**
 * Catch-up, query form (see docs/API.md):
 *   timeshift.php?username=U&password=P&stream=ID&start=YYYY-MM-DD:HH-MM&duration=MINUTES
 * Also reachable as streaming/timeshift.php, the path many players use.
 */
$_GET['u'] = $_GET['username'] ?? '';
$_GET['p'] = $_GET['password'] ?? '';
$_GET['type'] = 'timeshift';
$_GET['id'] = $_GET['stream'] ?? 0;
require __DIR__ . '/stream.php';
