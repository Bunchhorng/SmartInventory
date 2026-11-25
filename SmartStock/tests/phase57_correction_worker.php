<?php
declare(strict_types=1);

use App\Core\Session;

if (PHP_SAPI!=='cli' || !isset($argv[1])) { exit(2); }
require __DIR__.'/TestDatabase.php'; requireTestDatabase();

if ($argv[1]==='post') {
    if ($argc!==5) { exit(2); }
    $actor=(int)$argv[2]; $correction=(int)$argv[3]; $barrier=$argv[4];
    if ($actor<1 || $correction<1 || !preg_match('/^'.preg_quote(sys_get_temp_dir(),'\/').'[\\\\\/]/',$barrier)) { exit(2); }
    try {
        if (file_put_contents($barrier.'.'.getmypid().'.ready','ready',LOCK_EX)===false) { throw new RuntimeException('Cannot signal correction barrier.'); }
        $deadline=microtime(true)+10;
        do {
            if (count(glob($barrier.'.*.ready') ?: [])>=2) { break; }
            usleep(10000);
        } while (microtime(true)<$deadline);
        if (count(glob($barrier.'.*.ready') ?: [])<2) { throw new RuntimeException('Correction barrier timed out.'); }
        $app=require dirname(__DIR__).'/app/bootstrap.php';
        $result=$app->purchaseReturnCorrectionService()->post($actor,$correction);
        echo $result['posted']?'posted':'duplicate';
    } catch (DomainException) {
        echo 'rejected';
    } catch (Throwable $e) {
        fwrite(STDERR,$e->getMessage()); exit(1);
    }
    exit(0);
}

if ($argv[1]==='unauthenticated' && $argc===3 && in_array($argv[2],['purchase-return-correction-create','purchase-return-correction-reconciliation'],true)) {
    $_GET=$_POST=$_SESSION=[]; http_response_code(200);
    register_shutdown_function(static function(): void { echo (string) http_response_code(); });
    $app=require dirname(__DIR__).'/app/bootstrap.php';
    $router=require dirname(__DIR__).'/routes/web.php';
    $router->dispatch($argv[2],$argv[2]==='purchase-return-correction-reconciliation'?'GET':'POST');
    exit(1);
}

exit(2);
