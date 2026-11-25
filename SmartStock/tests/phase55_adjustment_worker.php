<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || $argc !== 4 || !in_array($argv[2],['credit','correction'],true)) { exit(2); }
require __DIR__ . '/TestDatabase.php'; requireTestDatabase();
$app = require dirname(__DIR__) . '/app/bootstrap.php';
try { $r = $app->supplierAdjustmentService()->post((int) $argv[1],$argv[2],(int) $argv[3]); echo $r['posted'] ? 'posted' : 'duplicate'; }
catch (DomainException) { echo 'rejected'; }
