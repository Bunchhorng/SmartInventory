<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php phase41_confirm_worker.php USER_ID SALE_ID\n");
    exit(2);
}

require dirname(__DIR__) . '/tests/TestDatabase.php';
requireTestDatabase();

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
try {
    $result = $app->salesService()->confirmSale((int) $argv[1], (int) $argv[2]);
    fwrite(STDOUT, $result['confirmed'] ? 'confirmed' : 'already-confirmed');
} catch (DomainException) {
    fwrite(STDOUT, 'rejected');
}
