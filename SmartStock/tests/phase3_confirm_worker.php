<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    fwrite(STDERR, "Usage: php phase3_confirm_worker.php OWNER_ID RECEIPT_ID\n");
    exit(2);
}

require dirname(__DIR__) . '/tests/TestDatabase.php';
requireTestDatabase();

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $result = $app->inventory()->confirmReceipt((int) $argv[1], (int) $argv[2]);
    fwrite(STDOUT, $result['confirmed'] ? 'confirmed' : 'already-confirmed');
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage());
    exit(1);
}
