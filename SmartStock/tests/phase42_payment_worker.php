<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 4) {
    exit(2);
}

require dirname(__DIR__) . '/tests/TestDatabase.php';
requireTestDatabase();

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $result = $app->paymentService()->recordPayment((int) $argv[1], [
        'payment_key' => $argv[3],
        'payment_method' => 'cash',
        'currency' => 'KHR',
        'amount_original' => '70.0000',
        'exchange_rate_to_khr' => '1.000000',
        'allocations' => [['sale_id' => (int) $argv[2], 'amount_khr' => '70.0000']],
    ]);
    fwrite(STDOUT, $result['recorded'] ? 'recorded' : 'duplicate');
} catch (DomainException) {
    fwrite(STDOUT, 'rejected');
}
