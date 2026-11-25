<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 4 || !in_array($argv[3], ['approve','post'], true)) { exit(2); }
require __DIR__ . '/TestDatabase.php'; requireTestDatabase();
$app = require dirname(__DIR__) . '/app/bootstrap.php';
try {
    $method = $argv[3]; $result = $app->supplierPaymentService()->$method((int) $argv[1], (int) $argv[2]);
    $key = $method === 'post' ? 'posted' : 'approved'; echo $result[$key] ? $key : 'duplicate';
} catch (DomainException) { echo 'rejected'; }
