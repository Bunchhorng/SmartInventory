<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 3) { exit(2); }
require __DIR__ . '/TestDatabase.php';
requireTestDatabase();
$app = require dirname(__DIR__) . '/app/bootstrap.php';
try {
    $result = $app->supplierInvoiceService()->post((int) $argv[1], (int) $argv[2]);
    echo $result['posted'] ? 'posted' : 'duplicate';
} catch (DomainException) { echo 'rejected'; }
