<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

/**
 * Read-only deployment aid. It writes no database rows and does not run a
 * migration or historical valuation backfill.
 *
 * @var App\Core\App $app
 */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$report = $app->valuationPreflight()->report();
foreach ($report as $key => $value) {
    fwrite(STDOUT, $key . ': ' . $value . PHP_EOL);
}

if ($report['movements_without_valuation'] > 0 || $report['products_with_qty_mismatch'] > 0) {
    fwrite(STDOUT, "Historical valuation review is required before Phase 3.2 or Phase 4.1 posting.\n");
    exit(1);
}

fwrite(STDOUT, "No historical receipt valuation mismatch was found.\n");
