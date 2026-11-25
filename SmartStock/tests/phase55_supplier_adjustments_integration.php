<?php
declare(strict_types=1);
use App\Core\{HttpException, Session};

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require __DIR__ . '/TestDatabase.php'; $testName = requireTestDatabase();
$app = require dirname(__DIR__) . '/app/bootstrap.php'; $pdo = $app->pdo();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $testName || $app->users()->countOwners() !== 0) { fwrite(STDERR,'Use an empty disposable _test database with no Owner.'); exit(2); }
if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='supplier_payable_activities'")->fetchColumn()) { fwrite(STDERR,'Apply Phase 5.5 on the disposable database first.'); exit(2); }
$directory = dirname(__DIR__) . '/storage/phase55-test-sessions'; if (!is_dir($directory)) { mkdir($directory,0700,true); } session_save_path($directory);
ob_start(); Session::start(); $tag = 'P55-' . strtoupper(bin2hex(random_bytes(5)));
$ownerId = $staffId = $categoryId = $productId = null; $supplierIds = $purchaseIds = $inventoryIds = $failures = []; $checks = 0;
$triggerName = 'p55_fail_' . strtolower(str_replace('-','_',$tag));

function check55(bool $pass, string $message): void { global $checks,$failures; ++$checks; if (!$pass) { $failures[]=$message; } echo ($pass?'PASS: ':'FAIL: ') . $message . PHP_EOL; }
function reject55(callable $fn, string $message): void { try { $fn(); check55(false,$message); } catch (DomainException) { check55(true,$message); } }
function key55(): string { global $tag; return $tag . '-' . bin2hex(random_bytes(8)); }
function invoice55(string $price, string $currency='KHR', string $rate='1.000000', int $supplierIndex=0, bool $post=true): int
{
    global $app,$ownerId,$supplierIds,$purchaseIds,$inventoryIds,$unitId,$locationId;
    $po = $app->purchasingService()->createDraft($ownerId,['purchase_key'=>key55(),'supplier_id'=>$supplierIds[$supplierIndex],
        'currency'=>$currency,'exchange_rate_to_khr'=>$rate,'items'=>[['product_unit_id'=>$unitId,'ordered_qty'=>'1','unit_purchase_price'=>$price]]]);
    $id=$po['purchase_id']; $purchaseIds[]=$id;
    $app->purchasingService()->submit($ownerId,$id); $app->purchasingService()->approve($ownerId,$id);
    $gr=$app->goodsReceiving()->createDraft($ownerId,['purchase_id'=>$id,'receipt_key'=>key55(),'reference_no'=>key55(),'location_id'=>$locationId,
        'items'=>[['purchase_item_id'=>$app->purchaseOrders()->items($id)[0]['id'],'quantity'=>'1']]]);
    $inventoryIds[]=(int) $app->goodsReceipts()->find($gr['receipt_id'])['inventory_receipt_id']; $app->goodsReceiving()->confirm($ownerId,$gr['receipt_id']);
    $note=$app->supplierInvoiceService()->createDraft($ownerId,['invoice_key'=>key55(),'supplier_invoice_no'=>key55(),'supplier_id'=>$supplierIds[$supplierIndex],
        'purchase_id'=>$id,'invoice_date'=>'2026-11-24','due_date'=>'2026-12-24','items'=>[['goods_receipt_item_id'=>$app->goodsReceipts()->items($gr['receipt_id'])[0]['id'],'quantity'=>'1']]]);
    $invoice=$note['invoice_id'];
    if ($post) { $app->supplierInvoiceService()->submit($ownerId,$invoice); $app->supplierInvoiceService()->approve($ownerId,$invoice); $app->supplierInvoiceService()->post($ownerId,$invoice); }
    return $invoice;
}
function pay55(array $lines, string $amount, string $currency='KHR', string $rate='1.000000', string $method='cash', bool $post=true): int
{
    global $app,$ownerId,$supplierIds;
    $allocations=[]; foreach($lines as $invoice=>$value) { $allocations[]=['invoice_id'=>$invoice,'invoice_amount_original'=>$value,'payment_amount_original'=>$value]; }
    $s=$app->supplierPaymentService(); $reference=key55();
    $id=$s->createDraft($ownerId,['payment_key'=>key55(),'supplier_id'=>$supplierIds[0],'payment_method'=>$method,'currency'=>$currency,'exchange_rate_to_khr'=>$rate,
        'amount_original'=>$amount,'reference_no'=>$reference,'paid_at'=>'2026-11-24 12:00:00','allocations'=>$allocations])['supplier_payment_id'];
    $s->submit($ownerId,$id); $s->approve($ownerId,$id);
    if ($post) { if($method!=='cash') { $s->verify($ownerId,$id,'Manually checked statement reference ' . $reference); } $s->post($ownerId,$id); } return $id;
}
function creditInput55(int $invoice, string $amount, array $extra=[]): array
{
    global $supplierIds; return array_replace(['document_key'=>key55(),'supplier_id'=>$supplierIds[0],'invoice_id'=>$invoice,'credit_no'=>key55(),'amount_original'=>$amount,'reason'=>'Supplier invoice price correction; no physical goods return'],$extra);
}
function credit55(int $invoice, string $amount, bool $post=true): int
{
    global $s,$ownerId; $id=$s->createCreditDraft($ownerId,creditInput55($invoice,$amount))['adjustment_id']; $s->submit($ownerId,'credit',$id); $s->approve($ownerId,'credit',$id);
    if($post) { $s->post($ownerId,'credit',$id); } return $id;
}
function correctionInput55(int $payment, string $resolution='funds_returned', array $extra=[]): array
{
    global $supplierIds; return array_replace(['document_key'=>key55(),'supplier_id'=>$supplierIds[0],'supplier_payment_id'=>$payment,'reason'=>'Erroneous payment confirmed reversed in full',
        'resolution'=>$resolution,'evidence_reference'=>key55(),'evidence'=>'Full original-currency amount and original transaction confirmed against statement or cash handover evidence.', 'confirm_full_reversal'=>'1'],$extra);
}
function correction55(int $payment, string $resolution='funds_returned', bool $post=true): int
{
    global $s,$ownerId; $id=$s->createCorrectionDraft($ownerId,correctionInput55($payment,$resolution))['adjustment_id']; $s->submit($ownerId,'correction',$id); $s->approve($ownerId,'correction',$id);
    if($post) { $s->post($ownerId,'correction',$id); } return $id;
}
function balance55(int $invoice): array { global $app; return $app->supplierPayableBalances()->read($app->payables()->forInvoice($invoice)); }
function race55(string $kind, int $a, int $b): array
{
    global $ownerId; $workers=[];
    foreach([$a,$b] as $id) {
        $process=proc_open([PHP_BINARY,__DIR__.'/phase55_adjustment_worker.php',(string)$ownerId,$kind,(string)$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
        if(!is_resource($process)) { throw new RuntimeException('Cannot launch adjustment worker.'); } fclose($pipes[0]); $workers[]=[$process,$pipes];
    }
    $results=[]; foreach($workers as [$process,$pipes]) { $results[]=trim(stream_get_contents($pipes[1])); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if(proc_close($process)!==0 || $error!=='') { throw new RuntimeException('Adjustment worker failure: '.$error); } }
    sort($results); return $results;
}
function invariant55(): array
{
    global $pdo,$app,$ownerId;
    return array_map(fn($table)=>$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(),
        ['product_costs','stock_balances','stock_movements','inventory_cost_entries','payables','supplier_invoice_matches','supplier_invoices','expenses','receivables','receivable_allocations','credit_notes']);
}
function cleanup55(): void
{
    global $pdo,$ownerId,$staffId,$categoryId,$productId,$supplierIds,$purchaseIds,$inventoryIds,$triggerName;
    $pdo->exec('DROP TRIGGER IF EXISTS '.$triggerName);
    foreach($supplierIds as $supplier) {
        foreach(['supplier_payable_activities','supplier_credit_reapplications','supplier_payment_correction_allocations','supplier_credit_allocations'] as $table) { $pdo->prepare('DELETE a FROM '.$table.' a JOIN payables p ON p.id=a.payable_id WHERE p.supplier_id=?')->execute([$supplier]); }
        $pdo->prepare('DELETE a FROM supplier_financial_approvals a LEFT JOIN supplier_credit_notes c ON c.id=a.credit_id LEFT JOIN supplier_payment_corrections r ON r.id=a.correction_id WHERE c.supplier_id=? OR r.supplier_id=?')->execute([$supplier,$supplier]);
        foreach(['supplier_credit_notes','supplier_payment_corrections'] as $table) { $pdo->prepare('DELETE FROM '.$table.' WHERE supplier_id=?')->execute([$supplier]); }
        $q=$pdo->prepare('SELECT id,payment_id FROM supplier_payments WHERE supplier_id=?'); $q->execute([$supplier]);
        foreach($q->fetchAll() as $doc) {
            $pdo->prepare('DELETE d FROM supplier_payment_differences d JOIN payable_allocations a ON a.id=d.allocation_id WHERE a.supplier_payment_id=?')->execute([$doc['id']]);
            foreach(['payable_allocations','supplier_payment_items','supplier_payment_approvals','supplier_payment_verifications'] as $table) { $pdo->prepare('DELETE FROM '.$table.' WHERE supplier_payment_id=?')->execute([$doc['id']]); }
            $pdo->prepare('DELETE FROM supplier_payments WHERE id=?')->execute([$doc['id']]); if($doc['payment_id']) { $pdo->prepare('DELETE FROM payments WHERE id=?')->execute([$doc['payment_id']]); }
        }
        $q=$pdo->prepare('SELECT id FROM supplier_invoices WHERE supplier_id=?'); $q->execute([$supplier]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $pdo->prepare('DELETE v FROM supplier_invoice_price_variances v JOIN supplier_invoice_matches m ON m.id=v.match_id WHERE m.invoice_id=?')->execute([$id]);
            foreach(['supplier_invoice_matches'=>'invoice_id','payables'=>'supplier_invoice_id','supplier_invoice_approvals'=>'invoice_id','supplier_invoice_items'=>'invoice_id','supplier_invoices'=>'id'] as $table=>$col) { $pdo->prepare('DELETE FROM '.$table.' WHERE '.$col.'=?')->execute([$id]); }
        }
    }
    foreach($purchaseIds as $id) {
        $pdo->prepare('DELETE v FROM goods_receipt_cost_variances v JOIN goods_receipt_items i ON i.id=v.goods_receipt_item_id JOIN goods_receipts g ON g.id=i.receipt_id WHERE g.purchase_id=?')->execute([$id]);
        $pdo->prepare('DELETE i FROM goods_receipt_items i JOIN goods_receipts g ON g.id=i.receipt_id WHERE g.purchase_id=?')->execute([$id]);
        foreach(['goods_receipts'=>'purchase_id','purchase_order_approval_snapshots'=>'purchase_id','purchase_items'=>'purchase_id','purchases'=>'id'] as $table=>$col) { $pdo->prepare('DELETE FROM '.$table.' WHERE '.$col.'=?')->execute([$id]); }
    }
    foreach($inventoryIds as $id) {
        foreach(['inventory_cost_entries','stock_movements'] as $table) { $pdo->prepare('DELETE FROM '.$table.' WHERE source_type=\'inventory_receipt\' AND source_id=?')->execute([$id]); }
        $pdo->prepare('DELETE FROM inventory_receipt_items WHERE receipt_id=?')->execute([$id]); $pdo->prepare('DELETE FROM inventory_receipts WHERE id=?')->execute([$id]);
    }
    if($productId) { foreach(['inventory_cost_entries','stock_movements','stock_balances','product_costs','product_units'] as $table) { $pdo->prepare('DELETE FROM '.$table.' WHERE product_id=?')->execute([$productId]); } $pdo->prepare('DELETE FROM products WHERE id=?')->execute([$productId]); }
    if($categoryId) { $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$categoryId]); }
    foreach($supplierIds as $id) { $pdo->prepare('DELETE FROM suppliers WHERE id=?')->execute([$id]); }
    foreach(array_filter([$ownerId,$staffId]) as $id) {
        foreach(['audit_logs'=>'actor_id','auth_sessions'=>'user_id','user_roles'=>'user_id'] as $table=>$col) { $pdo->prepare('DELETE FROM '.$table.' WHERE '.$col.'=?')->execute([$id]); } $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    }
}

try {
    $ownerName=strtolower($tag).'_owner'; $staffName=strtolower($tag).'_staff'; $password='SupplierAdjustments!'.$tag;
    $app->auth()->createInitialOwner($ownerName,'Supplier Adjustment Owner',$password); $ownerId=(int)$app->users()->findByUsername($ownerName)['id'];
    $app->auth()->createStaff($ownerId,$staffName,'Supplier Adjustment Staff',$password); $staffId=(int)$app->users()->findByUsername($staffName)['id'];
    foreach(['A','B'] as $code) { $supplierIds[]=$app->supplierService()->create($ownerId,['supplier_code'=>$tag.$code,'name'=>$tag.$code,'contact_person'=>'Contact','phone'=>'12345678','address'=>'Test address','payment_terms_days'=>'30']); }
    $categoryId=$app->productDomain()->createCategory($ownerId,$tag,null); $pcs=(int)$pdo->query("SELECT id FROM units WHERE code='pcs'")->fetchColumn(); $locationId=(int)$pdo->query("SELECT id FROM stock_locations WHERE code='MAIN'")->fetchColumn();
    $productId=$app->productDomain()->createProduct($ownerId,['sku'=>$tag,'name'=>$tag,'category_id'=>$categoryId,'base_unit_id'=>$pcs,'kind'=>'physical']); $unitId=(int)$app->products()->findProductUnitByProductAndUnit($productId,$pcs)['id'];
    $s=$app->supplierAdjustmentService();
    $unpaid=invoice55('100'); $partial=invoice55('100'); $settled=invoice55('100'); $mixed=invoice55('100'); $usd=invoice55('10','USD','4000.000000');
    $round=invoice55('0.3702','USD','1.234567'); $competing=invoice55('100'); $reserved=invoice55('100');
    $rollbackA=invoice55('11'); $rollbackB=invoice55('12'); $creditRollback=invoice55('25');
    $bankInvoice=invoice55('40'); $khqrInvoice=invoice55('40'); $legacy=invoice55('10'); $foreign=invoice55('10',supplierIndex:1);
    $draftInvoice=invoice55('10',post:false); $rejectedInvoice=invoice55('10',post:false); $cancelledInvoice=invoice55('10',post:false);
    $app->supplierInvoiceService()->submit($ownerId,$rejectedInvoice); $app->supplierInvoiceService()->reject($ownerId,$rejectedInvoice,'Supplier correction needed'); $app->supplierInvoiceService()->cancel($ownerId,$cancelledInvoice,'Invalid invoice');
    $paidPartial=pay55([$partial=>'40'],'40'); $paidSettled=pay55([$settled=>'100'],'100'); $paidMixed=pay55([$mixed=>'40'],'40');
    $paidUsd=pay55([$usd=>'10'],'10','USD','4100.000000'); $roundA=pay55([$round=>'0.1234'],'0.1234','USD','1.234567'); $roundB=pay55([$round=>'0.1234'],'0.1234','USD','1.234567');
    $paidRollback=pay55([$rollbackA=>'11',$rollbackB=>'12'],'23'); $paidBank=pay55([$bankInvoice=>'40'],'40',method:'bank'); $paidKhqr=pay55([$khqrInvoice=>'40'],'40',method:'khqr');
    $paidLegacy=pay55([$legacy=>'10'],'10');
    $legacyAllocation=(int)$app->supplierPayments()->allocations($paidLegacy)[0]['id'];
    $pdo->prepare('DELETE FROM supplier_payable_activities WHERE payment_allocation_id=?')->execute([$legacyAllocation]); $pdo->prepare('UPDATE payable_allocations SET posting_version=1 WHERE id=?')->execute([$legacyAllocation]);
    $unchanged=invariant55(); $cashRows=$pdo->query('SELECT * FROM payments ORDER BY id')->fetchAll(); $paymentDocs=$pdo->query('SELECT * FROM supplier_payments ORDER BY id')->fetchAll(); $allocRows=$pdo->query('SELECT * FROM payable_allocations ORDER BY id')->fetchAll();

    $input=creditInput55($unpaid,'25'); $credit=$s->createCreditDraft($ownerId,$input)['adjustment_id'];
    check55(!$s->createCreditDraft($ownerId,$input)['created'],'credit creation is idempotent for identical payload');
    reject55(fn()=>$s->createCreditDraft($ownerId,array_replace($input,['reason'=>'Changed reason'])),'credit key cannot be reused with different content');
    reject55(fn()=>$s->createCreditDraft($ownerId,creditInput55($unpaid,'1',['credit_no'=>'  '.strtolower($input['credit_no']).'  '])),'supplier credit numbers are normalized and unique');
    check55(balance55($unpaid)['outstanding_khr']==='100.0000','draft credit creates no payable reduction');
    reject55(fn()=>$s->post($ownerId,'credit',$credit),'draft credit cannot bypass submission and approval');
    $s->updateDraft($ownerId,'credit',$credit,['reason'=>'Approved invoice-price reduction; inventory unchanged']); $s->submit($ownerId,'credit',$credit);
    check55($s->approve($ownerId,'credit',$credit)['approved'] && !$s->approve($ownerId,'credit',$credit)['approved'],'credit approval is immutable and idempotent');
    check55($s->post($ownerId,'credit',$credit)['posted'] && !$s->post($ownerId,'credit',$credit)['posted'] && balance55($unpaid)['outstanding_khr']==='75.0000','partial credit reduces unpaid invoice once');
    credit55($unpaid,'75'); check55(balance55($unpaid)['outstanding_khr']==='0.0000' && balance55($unpaid)['recoverable_khr']==='0.0000','full unpaid credit settles invoice without a negative payable');
    reject55(fn()=>credit55($unpaid,'0.0001'),'credits cannot exceed original invoice less already-posted credits');
    foreach([$draftInvoice,$rejectedInvoice,$cancelledInvoice] as $id) { reject55(fn()=>$s->createCreditDraft($ownerId,creditInput55($id,'1')),'draft/rejected/cancelled invoice cannot receive credit'); }
    reject55(fn()=>$s->createCreditDraft($ownerId,creditInput55($foreign,'1')),'cross-supplier credit is rejected');
    reject55(fn()=>$s->createCreditDraft($ownerId,creditInput55($usd,'1',['currency'=>'KHR'])),'credit currency must match original invoice');
    reject55(fn()=>$s->createCreditDraft($ownerId,creditInput55($usd,'1',['exchange_rate_to_khr'=>'4100'])),'credit cannot alter historical invoice FX');
    $originalInvoice=$app->supplierInvoices()->find($mixed); $pdo->prepare("UPDATE supplier_invoices SET notes='Tampered source invoice approval' WHERE id=?")->execute([$mixed]);
    reject55(fn()=>$s->createCreditDraft($ownerId,creditInput55($mixed,'1')),'credit requires original invoice approval and match evidence to remain intact');
    $pdo->prepare('UPDATE supplier_invoices SET notes=?,updated_at=? WHERE id=?')->execute([$originalInvoice['notes'],$originalInvoice['updated_at'],$mixed]);
    foreach(['0','-1','1e2','0.00001'] as $amount) { reject55(fn()=>$s->createCreditDraft($ownerId,creditInput55($mixed,$amount)),'invalid credit amount is rejected: '.$amount); }
    $partialCredit=credit55($partial,'80'); $state=balance55($partial);
    check55($state['outstanding_khr']==='0.0000' && $state['credit_applied_khr']==='60.0000' && $state['recoverable_khr']==='20.0000','partially paid invoice uses credit against unpaid debt first, then supplier recoverable');
    credit55($partial,'20'); check55(balance55($partial)['recoverable_khr']==='40.0000','multiple credits preserve separate recoverable balances without over-credit');
    credit55($settled,'30'); check55(balance55($settled)['outstanding_khr']==='0.0000' && balance55($settled)['recoverable_khr']==='30.0000','credit on fully paid invoice becomes supplier recoverable, not negative AP or cash refund');
    credit55($mixed,'20'); credit55($usd,'3'); credit55($round,'0.1234');
    reject55(fn()=>$s->cancel($ownerId,'credit',$credit,'Erase posted credit'),'posted credit cannot be cancelled');
    reject55(fn()=>$s->updateDraft($ownerId,'credit',$credit,['amount_original'=>'1']),'posted credit cannot be edited');
    $cancel=$s->createCreditDraft($ownerId,creditInput55($mixed,'1'))['adjustment_id']; $s->cancel($ownerId,'credit',$cancel,'Unneeded draft');
    reject55(fn()=>$s->submit($ownerId,'credit',$cancel),'cancelled credit is terminal');
    $reject=$s->createCreditDraft($ownerId,creditInput55($mixed,'1'))['adjustment_id']; $s->submit($ownerId,'credit',$reject); $s->reject($ownerId,'credit',$reject,'Incorrect supplier document');
    reject55(fn()=>$s->approve($ownerId,'credit',$reject),'rejected credit cannot be approved');
    $a=credit55($competing,'70',false); $b=credit55($competing,'70',false);
    check55(race55('credit',$a,$b)===['posted','rejected'] && balance55($competing)['credited_original']==='70.0000','concurrent competing credits never exceed remaining creditable invoice');
    $winner=$app->supplierAdjustments()->find('credit',$a)['status']==='posted'?$a:$b; $loser=$winner===$a?$b:$a; $s->cancel($ownerId,'credit',$loser,'Competing request already posted');
    check55(race55('credit',$winner,$winner)===['duplicate','duplicate'],'concurrent duplicate credit posting creates no duplicate allocation');
    $reservation=pay55([$reserved=>'50'],'50',post:false); $reservedCredit=credit55($reserved,'75',false);
    reject55(fn()=>$s->post($ownerId,'credit',$reservedCredit),'credit cannot consume approved payment reservation');
    reject55(fn()=>$s->createCorrectionDraft($ownerId,correctionInput55($reservation)),'unposted payment cannot receive a posted-payment correction');
    $app->supplierPaymentService()->cancel($ownerId,$reservation,'Payment not executed'); $s->post($ownerId,'credit',$reservedCredit);
    check55(balance55($reserved)['outstanding_khr']==='25.0000','credit posts safely after unverified payment reservation is released');

    reject55(fn()=>$s->createCorrectionDraft($ownerId,correctionInput55($paidSettled,extra:['confirm_full_reversal'=>'0'])),'payment correction requires explicit full-reversal confirmation');
    reject55(fn()=>$s->createCorrectionDraft($ownerId,correctionInput55($paidSettled,'accounting_only')),'bookkeeping-only reversal cannot restore AP');
    reject55(fn()=>$s->createCorrectionDraft($ownerId,correctionInput55($paidSettled,extra:['evidence'=>''])),'payment correction requires evidence');
    reject55(fn()=>$s->createCorrectionDraft($ownerId,correctionInput55($paidSettled,extra:['amount_original'=>'50'])),'partial payment correction inputs are refused rather than silently expanded');
    reject55(fn()=>$s->createCorrectionDraft($ownerId,correctionInput55($paidSettled,extra:['supplier_id'=>$supplierIds[1]])),'cross-supplier payment correction is rejected');
    $input=correctionInput55($paidSettled); $correction=$s->createCorrectionDraft($ownerId,$input)['adjustment_id'];
    check55(!$s->createCorrectionDraft($ownerId,$input)['created'],'payment correction request is idempotent');
    reject55(fn()=>$s->createCorrectionDraft($ownerId,correctionInput55($paidSettled)),'duplicate full-payment correction is rejected');
    reject55(fn()=>$s->post($ownerId,'correction',$correction),'payment correction cannot post without submission and Owner approval');
    $s->submit($ownerId,'correction',$correction); $s->approve($ownerId,'correction',$correction);
    check55(balance55($settled)['recoverable_khr']==='30.0000','correction approval alone does not restore or reclassify debt');
    $s->post($ownerId,'correction',$correction); $state=balance55($settled);
    check55($state['outstanding_khr']==='70.0000' && $state['recoverable_khr']==='0.0000' && $state['reversed_khr']==='100.0000','proven full correction restores debt net of existing credit and reclassifies recoverable first');
    check55(!$s->post($ownerId,'correction',$correction)['posted'],'duplicate correction posting does not restore payable twice');
    reject55(fn()=>$s->cancel($ownerId,'correction',$correction,'Undo posted correction'),'posted correction cannot be cancelled');
    correction55($paidPartial); check55(balance55($partial)['outstanding_khr']==='0.0000' && balance55($partial)['recoverable_khr']==='0.0000','full supplier credit still extinguishes invoice after original partial payment is corrected');
    correction55($paidMixed); check55(balance55($mixed)['outstanding_khr']==='80.0000','payment correction restores unpaid balance while preserving earlier AP credit');
    correction55($paidUsd); check55(balance55($usd)['outstanding_original']==='7.0000' && balance55($usd)['outstanding_khr']==='28000.0000','USD payment correction restores original carrying value and preserves invoice FX');
    correction55($roundB); $state=balance55($round);
    check55($state['available'] && $state['issues']===[] && $state['outstanding_original']==='0.1234' && $state['correction_rounding_khr']==='0.0001','non-last payment correction preserves exact AP with explicit rounding adjustment');
    correction55($paidLegacy); check55(balance55($legacy)['outstanding_khr']==='10.0000','pre-5.5 supplier payment history can be corrected without rewriting or backfilling original allocations');
    $abandoned=$s->createCorrectionDraft($ownerId,correctionInput55($paidBank))['adjustment_id'];
    $s->updateDraft($ownerId,'correction',$abandoned,['reason'=>'Draft correction request to be withdrawn']);
    $s->cancel($ownerId,'correction',$abandoned,'Request withdrawn before execution');
    reject55(fn()=>$s->approve($ownerId,'correction',$abandoned),'cancelled correction cannot be approved');
    $abandoned=$s->createCorrectionDraft($ownerId,correctionInput55($paidBank))['adjustment_id']; $s->submit($ownerId,'correction',$abandoned);
    $s->reject($ownerId,'correction',$abandoned,'Evidence requires clarification');
    reject55(fn()=>$s->post($ownerId,'correction',$abandoned),'rejected correction cannot post');
    check55(balance55($bankInvoice)['outstanding_khr']==='0.0000','cancelled/rejected correction requests leave original payable settlement intact');
    foreach([[$paidBank,$bankInvoice,'funds_returned'],[$paidKhqr,$khqrInvoice,'proven_not_settled']] as [$payment,$invoice,$resolution]) {
        reject55(fn()=>$s->createCorrectionDraft($ownerId,correctionInput55($payment,$resolution,['evidence_reference'=>''])),'bank/KHQR correction requires independent evidence reference');
        $id=correction55($payment,$resolution,false); check55(balance55($invoice)['outstanding_khr']==='0.0000','bank/KHQR approval alone does not restore payable');
        $verification=$app->supplierPayments()->verification($payment);
        $pdo->prepare("UPDATE supplier_payment_verifications SET evidence='Altered original verification evidence' WHERE supplier_payment_id=?")->execute([$payment]);
        reject55(fn()=>$s->post($ownerId,'correction',$id),'correction approval cannot survive changed original bank/KHQR verification evidence');
        $pdo->prepare('UPDATE supplier_payment_verifications SET evidence=? WHERE supplier_payment_id=?')->execute([$verification['evidence'],$payment]);
        $correctionDoc=$app->supplierAdjustments()->find('correction',$id);
        $pdo->prepare("UPDATE supplier_payment_corrections SET evidence='Altered approved correction evidence' WHERE id=?")->execute([$id]);
        reject55(fn()=>$s->post($ownerId,'correction',$id),'approved correction evidence cannot be changed');
        check55(!$s->reconcile($ownerId,$supplierIds[0])['reconciled'],'read-only reconciliation identifies correction approval drift');
        $pdo->prepare('UPDATE supplier_payment_corrections SET evidence=? WHERE id=?')->execute([$correctionDoc['evidence'],$id]);
        check55(race55('correction',$id,$id)===['duplicate','posted'] && balance55($invoice)['outstanding_khr']==='40.0000','evidenced bank/KHQR correction posts exactly once without changing original verification');
    }
    $id=credit55($creditRollback,'10',false); $payable=(int)$app->payables()->forInvoice($creditRollback)['id'];
    $pdo->exec('CREATE TRIGGER '.$triggerName." BEFORE INSERT ON supplier_payable_activities FOR EACH ROW BEGIN IF NEW.payable_id=".$payable." AND NEW.credit_allocation_id IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phase 5.5 credit rollback'; END IF; END");
    reject55(fn()=>$s->post($ownerId,'credit',$id),'late credit activity failure rolls back entire posting');
    check55($app->supplierAdjustments()->find('credit',$id)['status']==='approved' && !$app->supplierAdjustments()->creditPosting($id) && balance55($creditRollback)['outstanding_khr']==='25.0000','failed credit retains approval but creates no reduction or recoverable');
    $pdo->exec('DROP TRIGGER '.$triggerName);
    $creditDoc=$app->supplierAdjustments()->find('credit',$id); $pdo->prepare("UPDATE supplier_credit_notes SET reason='Changed after approval' WHERE id=?")->execute([$id]);
    reject55(fn()=>$s->post($ownerId,'credit',$id),'credit approval protects immutable reason/content');
    check55(!$s->reconcile($ownerId,$supplierIds[0])['reconciled'],'read-only reconciliation identifies credit approval drift');
    $pdo->prepare('UPDATE supplier_credit_notes SET reason=? WHERE id=?')->execute([$creditDoc['reason'],$id]); $s->post($ownerId,'credit',$id);
    $id=correction55($paidRollback,post:false); $payable=(int)$app->payables()->forInvoice($rollbackB)['id'];
    $pdo->exec('CREATE TRIGGER '.$triggerName." BEFORE INSERT ON supplier_payment_correction_allocations FOR EACH ROW BEGIN IF NEW.payable_id=".$payable." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phase 5.5 correction rollback'; END IF; END");
    reject55(fn()=>$s->post($ownerId,'correction',$id),'later invoice correction failure aborts multi-invoice correction');
    check55($app->supplierAdjustments()->corrections($id)===[] && balance55($rollbackA)['outstanding_khr']==='0.0000' && $app->supplierAdjustments()->find('correction',$id)['status']==='approved','failed correction rolls back earlier restoration and all status/activity writes');
    $pdo->exec('DROP TRIGGER '.$triggerName); $s->post($ownerId,'correction',$id);
    check55(balance55($rollbackA)['outstanding_khr']==='11.0000' && balance55($rollbackB)['outstanding_khr']==='12.0000','full multi-invoice correction restores each original allocation');
    $report=$s->reconcile($ownerId,$supplierIds[0]); if(!$report['reconciled']) { echo json_encode($report['issues'],JSON_THROW_ON_ERROR).PHP_EOL; }
    check55($report['reconciled'] && $report['recoverable_khr']==='0.0000' && $report['proven_not_settled_cash_khr']==='40.0000','supplier financial reconciliation includes credits, corrections, recoverables and proven non-settlement');
    $posting=$app->supplierAdjustments()->creditPosting($credit);
    $q=$pdo->prepare('SELECT * FROM supplier_payable_activities WHERE credit_allocation_id=?'); $q->execute([$posting['id']]); $activity=$q->fetch();
    $pdo->prepare('DELETE FROM supplier_payable_activities WHERE id=?')->execute([$activity['id']]);
    $bad=$s->reconcile($ownerId,$supplierIds[0]); $q=$pdo->prepare('SELECT COUNT(*) FROM supplier_payable_activities WHERE id=?'); $q->execute([$activity['id']]);
    check55(!$bad['reconciled'] && $bad['outstanding_khr']===null && (int)$q->fetchColumn()===0,'missing immutable activity makes balance unavailable and reconciliation never silently repairs it');
    $pdo->prepare('INSERT INTO supplier_payable_activities (id,payable_id,credit_allocation_id) VALUES (?,?,?)')->execute([$activity['id'],$activity['payable_id'],$activity['credit_allocation_id']]);
    check55(count($s->reconcile($ownerId,$supplierIds[0])['recoverable_reapplications'])>=3,'reconciliation exposes immutable recoverable-to-AP reapplication provenance');
    $currentDocs=array_column($pdo->query('SELECT * FROM supplier_payments ORDER BY id')->fetchAll(),null,'id');
    $originalDocsUnchanged=true; foreach($paymentDocs as $originalDoc) { if(($currentDocs[$originalDoc['id']]??null)!==$originalDoc) { $originalDocsUnchanged=false; } }
    check55($pdo->query('SELECT * FROM payments ORDER BY id')->fetchAll()===$cashRows && $originalDocsUnchanged
        && $pdo->query('SELECT * FROM payable_allocations ORDER BY id')->fetchAll()===$allocRows,'credits and corrections preserve original cash, payment documents, allocations and bank verification');
    check55(invariant55()===$unchanged,'credits and corrections change no invoice totals, receipt matches, stock, moving average, expense or customer-credit ledger');
    pay55([$mixed=>'80'],'80'); pay55([$usd=>'7'],'7','USD','4200.000000'); pay55([$round=>'0.1234'],'0.1234','USD','1.234567');
    check55(balance55($mixed)['outstanding_khr']==='0.0000' && balance55($usd)['outstanding_khr']==='0.0000' && balance55($round)['outstanding_khr']==='0.0000','subsequent payments use net AP after credits/corrections and leave no FX/rounding debt dust');
    check55($s->reconcile($ownerId,$supplierIds[0])['reconciled'],'combined payment/credit/correction chronology reconciles after replacement payments');
    $pdo->prepare("UPDATE users SET status='disabled' WHERE id=?")->execute([$ownerId]);
    try { reject55(fn()=>$s->listForViewer($ownerId,'credit',$supplierIds[0]),'disabled Owner cannot access supplier financial data'); }
    finally { $pdo->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$ownerId]); }

    foreach(['credit','correction'] as $kind) {
        reject55(fn()=>$s->forViewer($staffId,$kind,$kind==='credit'?$credit:$correction),'Staff cannot read supplier '.$kind.' financial data');
        reject55(fn()=>$s->approve($staffId,$kind,$kind==='credit'?$credit:$correction),'Staff cannot approve supplier '.$kind);
    }
    reject55(fn()=>$s->reconcile($staffId,$supplierIds[0]),'Staff cannot access combined supplier reconciliation');
    Session::clearAuthentication(); $app->auth()->attemptLogin($ownerName,$password); $router=require dirname(__DIR__).'/routes/web.php';
    foreach(['supplier-credit','supplier-payment-correction'] as $prefix) {
        foreach(['create','update','submit','approve','post','reject','cancel'] as $action) {
            foreach(['bad-token',[]] as $token) { $_POST=['_csrf'=>$token]; try { $router->dispatch($prefix.'-'.$action,'POST'); check55(false,'CSRF '.$action); } catch(HttpException $e) { check55($e->statusCode()===419,$prefix.'-'.$action.' rejects invalid/array CSRF'); } }
        }
    }
    $_POST=['_csrf'=>Session::csrfToken(),'adjustment_id'=>$credit]; ob_start(); $router->dispatch('supplier-credit-post','POST'); $json=json_decode(ob_get_clean(),true,flags:JSON_THROW_ON_ERROR);
    check55($json['success'] && !$json['data']['posted'] && http_response_code()===200,'HTTP credit duplicate post returns idempotent JSON');
    $_POST=['_csrf'=>Session::csrfToken(),'invoice_id'=>[]]; ob_start(); $router->dispatch('supplier-credit-create','POST'); $json=json_decode(ob_get_clean(),true,flags:JSON_THROW_ON_ERROR);
    check55(!$json['success'] && http_response_code()===422,'malformed supplier financial input returns HTTP 422 JSON');
    Session::clearAuthentication(); $app->auth()->attemptLogin($staffName,$password);
    foreach(['supplier-credit-create','supplier-credit-approve','supplier-credit-post','supplier-payment-correction-create','supplier-payment-correction-approve','supplier-payment-correction-post'] as $route) {
        $_POST=['_csrf'=>Session::csrfToken()]; try { $router->dispatch($route,'POST'); check55(false,'Staff RBAC '.$route); } catch(HttpException $e) { check55($e->statusCode()===403,$route.' forbids Staff'); }
    }
    foreach(['supplier-credits','supplier-credit-detail','supplier-payment-corrections','supplier-payment-correction-detail','supplier-financial-reconciliation'] as $route) {
        $_GET=['supplier_id'=>$supplierIds[0],'adjustment_id'=>$credit]; try { $router->dispatch($route,'GET'); check55(false,'Staff privacy '.$route); } catch(HttpException $e) { check55($e->statusCode()===403,$route.' exposes no Staff financial data'); }
    }
    $q=$pdo->query("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.code='staff' AND (p.code LIKE 'supplier_credits.%' OR p.code LIKE 'supplier_payment_corrections.%')");
    check55((int)$q->fetchColumn()===0,'supplier financial permissions are not granted to Staff by default');
    try {
        $pdo->exec("INSERT INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='staff' AND (p.code LIKE 'supplier_credits.%' OR p.code LIKE 'supplier_payment_corrections.%')");
        check55($app->users()->hasPermission($staffId,'supplier_credits.approve'),'test temporarily grants explicit financial permissions to Staff');
        reject55(fn()=>$s->approve($staffId,'credit',$credit),'service still requires Owner after accidental Staff permission grant');
        reject55(fn()=>$s->post($staffId,'correction',$correction),'correction service still requires Owner after accidental Staff permission grant');
        $_GET=['adjustment_id'=>$credit]; try { $router->dispatch('supplier-credit-detail','GET'); check55(false,'explicit Owner HTTP guard'); } catch(HttpException $e) { check55($e->statusCode()===403,'HTTP Owner guard still prevents finance leakage after accidental Staff grant'); }
    } finally { $pdo->exec("DELETE rp FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.code='staff' AND (p.code LIKE 'supplier_credits.%' OR p.code LIKE 'supplier_payment_corrections.%')"); }
    foreach(['supplier_credit.approved','supplier_credit.posted','supplier_credit.rejected','supplier_credit.cancelled','supplier_correction.approved','supplier_correction.posted','supplier_correction.rejected','supplier_correction.cancelled'] as $action) { $q=$pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE actor_id=? AND action=?'); $q->execute([$ownerId,$action]); check55((int)$q->fetchColumn()>0,$action.' audited'); }
} catch(Throwable $e) { $failures[]=$e->getMessage(); echo 'FAIL: '.$e.PHP_EOL; }
finally { $_GET=$_POST=[]; if($pdo->inTransaction()) { $pdo->rollBack(); } if(session_status()===PHP_SESSION_ACTIVE) { Session::destroy(); } cleanup55(); }
echo $failures===[]?'All Phase 5.5 integration checks passed ('.$checks.' checks).'.PHP_EOL:count($failures).' Phase 5.5 checks failed.'.PHP_EOL;
ob_end_flush(); exit($failures===[]?0:1);
