<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || $argc!==5 || !in_array($argv[2],['confirm','link'],true)) { exit(2); }
require __DIR__.'/TestDatabase.php'; requireTestDatabase();
$app=require dirname(__DIR__).'/app/bootstrap.php';
try {
    $result=$argv[2]==='confirm'
        ?$app->purchaseReturnService()->confirm((int)$argv[1],(int)$argv[3],$argv[4])
        :$app->purchaseReturnService()->linkCredit((int)$argv[1],(int)$argv[3],json_decode($argv[4],true,flags:JSON_THROW_ON_ERROR));
    echo ($result['confirmed']??$result['linked'])?'posted':'duplicate';
} catch (DomainException) { echo 'rejected'; }
