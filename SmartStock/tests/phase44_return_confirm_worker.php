<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    exit(2);
}

require dirname(__DIR__) . '/tests/TestDatabase.php';
requireTestDatabase();

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $result = $app->salesReturnService()->confirm((int) $argv[1], (int) $argv[2]);
    fwrite(STDOUT, $result['confirmed'] ? 'confirmed' : 'duplicate');
} catch (DomainException) {
    fwrite(STDOUT, 'rejected');
}
