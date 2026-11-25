<?php
declare(strict_types=1);
use App\Core\{HttpException,Session};

if (PHP_SAPI!=='cli') { http_response_code(403); exit('CLI only'); }
require __DIR__.'/TestDatabase.php'; $testName=requireTestDatabase();
$app=require dirname(__DIR__).'/app/bootstrap.php'; $pdo=$app->pdo();
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$testName || $app->users()->countOwners()!==0) { fwrite(STDERR,'Use an empty disposable _test database with no Owner.'); exit(2); }
if (!$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='purchase_return_correction_postings'")->fetchColumn()) { fwrite(STDERR,'Apply Phase 5.7 to the test database first.'); exit(2); }
$directory=dirname(__DIR__).'/storage/phase56-test-sessions'; if (!is_dir($directory)) { mkdir($directory,0700,true); } session_save_path($directory);
ob_start(); Session::start(); $tag='P56-'.strtoupper(bin2hex(random_bytes(5)));
$ownerId=$staffId=$categoryId=$customerId=null; $productIds=$supplierIds=$purchaseIds=$inventoryIds=$saleIds=$failures=[]; $checks=0;
$trigger='p56_fail_'.strtolower(str_replace('-','_',$tag));
function check56(bool $pass, string $message): void { global $checks,$failures; ++$checks; if (!$pass) { $failures[]=$message; } echo ($pass?'PASS: ':'FAIL: ').$message.PHP_EOL; }
function reject56(callable $fn, string $message): void { try { $fn(); check56(false,$message); } catch (DomainException) { check56(true,$message); } }
function key56(): string { global $tag; return $tag.'-'.bin2hex(random_bytes(8)); }
function product56(): array
{
    global $app,$ownerId,$categoryId,$pcs,$productIds;
    $key=key56(); $id=$app->productDomain()->createProduct($ownerId,['sku'=>$key,'name'=>$key,'category_id'=>$categoryId,'base_unit_id'=>$pcs,'kind'=>'physical']);
    $productIds[]=$id; return ['id'=>$id,'unit'=>(int)$app->products()->findProductUnitByProductAndUnit($id,$pcs)['id']];
}
function receipt56(array $items, int $supplierIndex=0, ?int $location=null, string $currency='KHR', string $rate='1.000000'): array
{
    global $app,$ownerId,$supplierIds,$purchaseIds,$inventoryIds,$locationId;
    $po=$app->purchasingService()->createDraft($ownerId,['purchase_key'=>key56(),'supplier_id'=>$supplierIds[$supplierIndex],
        'currency'=>$currency,'exchange_rate_to_khr'=>$rate,'items'=>$items])['purchase_id']; $purchaseIds[]=$po;
    $app->purchasingService()->submit($ownerId,$po); $app->purchasingService()->approve($ownerId,$po);
    $poItems=$app->purchaseOrders()->items($po); $grItems=[];
    foreach ($poItems as $i) { $grItems[]=['purchase_item_id'=>$i['id'],'quantity'=>$i['ordered_qty']]; }
    $gr=$app->goodsReceiving()->createDraft($ownerId,['purchase_id'=>$po,'receipt_key'=>key56(),'reference_no'=>key56(),
        'location_id'=>$location??$locationId,'items'=>$grItems])['receipt_id'];
    $inventoryIds[]=(int)$app->goodsReceipts()->find($gr)['inventory_receipt_id']; $app->goodsReceiving()->confirm($ownerId,$gr);
    return ['po'=>$po,'gr'=>$gr,'supplier'=>$supplierIds[$supplierIndex],'items'=>$app->goodsReceipts()->items($gr)];
}
function single56(array $product, string $qty='10', string $price='10', int $supplierIndex=0): array
{
    return receipt56([['product_unit_id'=>$product['unit'],'ordered_qty'=>$qty,'unit_purchase_price'=>$price]],$supplierIndex);
}
function input56(array $f, string $qty='1', array $extra=[]): array
{
    return array_replace(['document_key'=>key56(),'supplier_id'=>$f['supplier'],'purchase_id'=>$f['po'],'goods_receipt_id'=>$f['gr'],
        'reason'=>'Supplier agreed goods return','items'=>[['goods_receipt_item_id'=>$f['items'][0]['id'],'quantity'=>$qty,'reason'=>'Goods returned to supplier']]],$extra);
}
function ready56(array $f, string $qty='1', ?array $input=null): int
{
    global $app,$ownerId; $s=$app->purchaseReturnService(); $id=$s->createDraft($ownerId,$input??input56($f,$qty))['return_id']; $s->submit($ownerId,$id); $s->approve($ownerId,$id); return $id;
}
function invoice56(array $f, ?string $quantity=null): int
{
    global $app,$ownerId; $items=[];
    foreach ($f['items'] as $i) { $items[]=['goods_receipt_item_id'=>$i['id'],'quantity'=>$quantity??$i['received_qty']]; }
    $id=$app->supplierInvoiceService()->createDraft($ownerId,['invoice_key'=>key56(),'supplier_invoice_no'=>key56(),'supplier_id'=>$f['supplier'],
        'purchase_id'=>$f['po'],'invoice_date'=>'2026-11-25','items'=>$items])['invoice_id'];
    $app->supplierInvoiceService()->submit($ownerId,$id); $app->supplierInvoiceService()->approve($ownerId,$id); $app->supplierInvoiceService()->post($ownerId,$id); return $id;
}
function credit56(array $f, int $invoice, string $amount, bool $post=true): int
{
    global $app,$ownerId; $s=$app->supplierAdjustmentService();
    $id=$s->createCreditDraft($ownerId,['document_key'=>key56(),'supplier_id'=>$f['supplier'],'invoice_id'=>$invoice,'credit_no'=>key56(),
        'amount_original'=>$amount,'reason'=>'Agreed supplier goods return credit'])['adjustment_id'];
    if ($post) { $s->submit($ownerId,'credit',$id); $s->approve($ownerId,'credit',$id); $s->post($ownerId,'credit',$id); } return $id;
}
function linkInput56(int $return, int $credit, int $invoice, string $qty, string $amount): array
{
    global $app; return ['link_key'=>key56(),'return_item_id'=>$app->purchaseReturns()->items($return)[0]['id'],'credit_id'=>$credit,
        'invoice_match_id'=>$app->supplierInvoices()->matches($invoice)[0]['id'],'quantity'=>$qty,'amount_original'=>$amount,'reason'=>'Supplier credit attributed to physical goods'];
}
function race56(string $method, array $requests): array
{
    global $ownerId; $workers=[];
    foreach ($requests as [$id,$payload]) {
        $arg=is_array($payload)?json_encode($payload,JSON_THROW_ON_ERROR):$payload;
        $process=proc_open([PHP_BINARY,__DIR__.'/phase56_return_worker.php',(string)$ownerId,$method,(string)$id,$arg],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
        if (!is_resource($process)) { throw new RuntimeException('Cannot launch purchase return worker.'); }
        fclose($pipes[0]); $workers[]=[$process,$pipes];
    }
    $results=[]; foreach ($workers as [$p,$pipes]) { $results[]=trim(stream_get_contents($pipes[1])); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if (proc_close($p)!==0 || $error!=='') { throw new RuntimeException('Return worker failed: '.$error); } }
    sort($results); return $results;
}
/** @param list<int> $correctionIds */
function correctionRace56(array $correctionIds): array
{
    global $ownerId;
    $barrier=tempnam(sys_get_temp_dir(),'p57-correction-');
    if ($barrier===false || !unlink($barrier)) { throw new RuntimeException('Cannot prepare correction concurrency barrier.'); }
    $workers=[];
    try {
        foreach ($correctionIds as $id) {
            $process=proc_open([PHP_BINARY,__DIR__.'/phase57_correction_worker.php','post',(string)$ownerId,(string)$id,$barrier],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
            if (!is_resource($process)) { throw new RuntimeException('Cannot launch purchase-return correction worker.'); }
            fclose($pipes[0]); $workers[]=[$process,$pipes];
        }
        $results=[];
        foreach ($workers as [$process,$pipes]) {
            $results[]=trim(stream_get_contents($pipes[1])); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process)!==0 || $error!=='') { throw new RuntimeException('Correction worker failed: '.$error); }
        }
        sort($results); return $results;
    } finally {
        foreach (glob($barrier.'.*.ready') ?: [] as $ready) { unlink($ready); }
    }
}
function unauthenticatedCorrectionStatus56(string $route): int
{
    $process=proc_open([PHP_BINARY,__DIR__.'/phase57_correction_worker.php','unauthenticated',$route],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    if (!is_resource($process)) { throw new RuntimeException('Cannot launch unauthenticated correction-route worker.'); }
    fclose($pipes[0]); $result=trim(stream_get_contents($pipes[1])); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process)!==0 || $error!=='' || !preg_match('/^\d+$/',$result)) { throw new RuntimeException('Unauthenticated correction-route worker failed: '.$error.$result); }
    return (int)$result;
}
function financial56(): array
{
    global $pdo; return array_map(fn($t)=>$pdo->query('SELECT * FROM '.$t.' ORDER BY id')->fetchAll(),
        ['supplier_invoices','supplier_invoice_matches','payables','supplier_credit_notes','supplier_credit_allocations','supplier_payable_activities','payments','payable_allocations','expenses','receivables','credit_notes']);
}
function stock56(): array
{
    global $pdo; return array_map(fn($t)=>$pdo->query('SELECT * FROM '.$t.' ORDER BY id')->fetchAll(),['stock_balances','product_costs','stock_movements','inventory_cost_entries','purchase_return_postings','purchase_return_correction_postings']);
}
function sell56(array $product, string $qty, ?int $customer=null): int
{
    global $app,$ownerId,$locationId,$saleIds;
    $app->productDomain()->setPrice($ownerId,$product['unit'],'retail','KHR','100');
    $id=$app->salesService()->createDraftSale($ownerId,['sale_channel'=>'retail','customer_id'=>$customer,'settlement_type'=>$customer===null?'cash':'credit','items'=>[['product_unit_id'=>$product['unit'],'location_id'=>$locationId,'quantity'=>$qty]]]);
    $saleIds[]=$id; $app->salesService()->confirmSale($ownerId,$id); return $id;
}
function cleanup56(): void
{
    global $pdo,$trigger,$ownerId,$staffId,$supplierIds,$purchaseIds,$inventoryIds,$productIds,$categoryId,$saleIds,$customerId;
    $pdo->exec('DROP TRIGGER IF EXISTS '.$trigger);
    foreach ($purchaseIds as $id) {
        foreach (['purchase_return_correction_postings'] as $t) { $pdo->prepare('DELETE x FROM '.$t.' x JOIN purchase_return_correction_items i ON i.id=x.correction_item_id JOIN purchase_return_corrections c ON c.id=i.correction_id JOIN purchase_returns r ON r.id=c.purchase_return_id WHERE r.purchase_id=?')->execute([$id]); }
        $pdo->prepare('DELETE a FROM purchase_return_correction_approvals a JOIN purchase_return_corrections c ON c.id=a.correction_id JOIN purchase_returns r ON r.id=c.purchase_return_id WHERE r.purchase_id=?')->execute([$id]);
        $pdo->prepare('DELETE i FROM purchase_return_correction_items i JOIN purchase_return_corrections c ON c.id=i.correction_id JOIN purchase_returns r ON r.id=c.purchase_return_id WHERE r.purchase_id=?')->execute([$id]);
        $pdo->prepare('DELETE c FROM purchase_return_corrections c JOIN purchase_returns r ON r.id=c.purchase_return_id WHERE r.purchase_id=?')->execute([$id]);
        foreach (['purchase_return_credit_links','purchase_return_postings'] as $t) { $pdo->prepare('DELETE x FROM '.$t.' x JOIN purchase_return_items i ON i.id=x.return_item_id JOIN purchase_returns r ON r.id=i.return_id WHERE r.purchase_id=?')->execute([$id]); }
        $pdo->prepare('DELETE a FROM purchase_return_approvals a JOIN purchase_returns r ON r.id=a.return_id WHERE r.purchase_id=?')->execute([$id]);
        $pdo->prepare('DELETE i FROM purchase_return_items i JOIN purchase_returns r ON r.id=i.return_id WHERE r.purchase_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM purchase_returns WHERE purchase_id=?')->execute([$id]);
    }
    foreach ($supplierIds as $supplier) {
        foreach (['supplier_payable_activities','supplier_credit_reapplications','supplier_payment_correction_allocations','supplier_credit_allocations'] as $t) { $pdo->prepare('DELETE a FROM '.$t.' a JOIN payables p ON p.id=a.payable_id WHERE p.supplier_id=?')->execute([$supplier]); }
        $pdo->prepare('DELETE a FROM supplier_financial_approvals a JOIN supplier_credit_notes c ON c.id=a.credit_id WHERE c.supplier_id=?')->execute([$supplier]);
        $pdo->prepare('DELETE FROM supplier_credit_notes WHERE supplier_id=?')->execute([$supplier]);
        $q=$pdo->prepare('SELECT id FROM supplier_invoices WHERE supplier_id=?'); $q->execute([$supplier]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $pdo->prepare('DELETE v FROM supplier_invoice_price_variances v JOIN supplier_invoice_matches m ON m.id=v.match_id WHERE m.invoice_id=?')->execute([$id]);
            foreach (['supplier_invoice_matches'=>'invoice_id','payables'=>'supplier_invoice_id','supplier_invoice_approvals'=>'invoice_id','supplier_invoice_items'=>'invoice_id','supplier_invoices'=>'id'] as $t=>$c) { $pdo->prepare('DELETE FROM '.$t.' WHERE '.$c.'=?')->execute([$id]); }
        }
    }
    foreach ($saleIds as $id) {
        foreach (['sale_return_quarantine_holds','sale_return_writeoffs'] as $t) { $pdo->prepare('DELETE h FROM '.$t.' h JOIN sale_return_items i ON i.id=h.sale_return_item_id JOIN sale_returns r ON r.id=i.sale_return_id WHERE r.sale_id=?')->execute([$id]); }
        $pdo->prepare('DELETE i FROM sale_return_items i JOIN sale_returns r ON r.id=i.sale_return_id WHERE r.sale_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM sale_returns WHERE sale_id=?')->execute([$id]);
        foreach (['credit_note_receivable_applications','credit_note_reversals','refunds'] as $t) { $pdo->prepare('DELETE a FROM '.$t.' a JOIN credit_notes c ON c.id=a.credit_note_id WHERE c.sale_id=?')->execute([$id]); }
        $pdo->prepare('DELETE FROM credit_notes WHERE sale_id=?')->execute([$id]);
        foreach (['receivables'=>'sale_id','sale_items'=>'sale_id','sales'=>'id'] as $t=>$c) { $pdo->prepare('DELETE FROM '.$t.' WHERE '.$c.'=?')->execute([$id]); }
    }
    foreach ($purchaseIds as $id) {
        $pdo->prepare('DELETE v FROM goods_receipt_cost_variances v JOIN goods_receipt_items i ON i.id=v.goods_receipt_item_id JOIN goods_receipts g ON g.id=i.receipt_id WHERE g.purchase_id=?')->execute([$id]);
        $pdo->prepare('DELETE i FROM goods_receipt_items i JOIN goods_receipts g ON g.id=i.receipt_id WHERE g.purchase_id=?')->execute([$id]);
        foreach (['goods_receipts'=>'purchase_id','purchase_order_approval_snapshots'=>'purchase_id','purchase_items'=>'purchase_id','purchases'=>'id'] as $t=>$c) { $pdo->prepare('DELETE FROM '.$t.' WHERE '.$c.'=?')->execute([$id]); }
    }
    foreach ($inventoryIds as $id) { $pdo->prepare('DELETE FROM inventory_receipt_items WHERE receipt_id=?')->execute([$id]); $pdo->prepare('DELETE FROM inventory_receipts WHERE id=?')->execute([$id]); }
    foreach ($productIds as $id) {
        $pdo->prepare('DELETE pp FROM product_prices pp JOIN product_units pu ON pu.id=pp.product_unit_id WHERE pu.product_id=?')->execute([$id]);
        foreach (['inventory_cost_entries','stock_movements','stock_balances','product_costs','product_units'] as $t) { $pdo->prepare('DELETE FROM '.$t.' WHERE product_id=?')->execute([$id]); } $pdo->prepare('DELETE FROM products WHERE id=?')->execute([$id]);
    }
    if ($categoryId) { $pdo->prepare('DELETE FROM categories WHERE id=?')->execute([$categoryId]); }
    if ($customerId) { $pdo->prepare('DELETE FROM customers WHERE id=?')->execute([$customerId]); }
    foreach ($supplierIds as $id) { $pdo->prepare('DELETE FROM suppliers WHERE id=?')->execute([$id]); }
    foreach (array_filter([$ownerId,$staffId]) as $id) { foreach (['audit_logs'=>'actor_id','auth_sessions'=>'user_id','user_roles'=>'user_id'] as $t=>$c) { $pdo->prepare('DELETE FROM '.$t.' WHERE '.$c.'=?')->execute([$id]); } $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]); }
}

try {
    $ownerName=strtolower($tag).'_owner'; $staffName=strtolower($tag).'_staff'; $password='ReturnTests!'.$tag;
    $app->auth()->createInitialOwner($ownerName,'Purchase Return Owner',$password); $ownerId=(int)$app->users()->findByUsername($ownerName)['id'];
    $app->auth()->createStaff($ownerId,$staffName,'Purchase Return Staff',$password); $staffId=(int)$app->users()->findByUsername($staffName)['id'];
    foreach (['A','B'] as $code) { $supplierIds[]=$app->supplierService()->create($ownerId,['supplier_code'=>$tag.$code,'name'=>$tag.$code,'contact_person'=>'Contact','phone'=>'12345678','address'=>'Test','payment_terms_days'=>'30']); }
    $categoryId=$app->productDomain()->createCategory($ownerId,$tag,null); $pcs=(int)$pdo->query("SELECT id FROM units WHERE code='pcs'")->fetchColumn();
    $locationId=(int)$pdo->query("SELECT id FROM stock_locations WHERE code='MAIN'")->fetchColumn(); $s=$app->purchaseReturnService();
    $p=product56(); $f=single56($p); $foreign=single56(product56(),supplierIndex:1);
    $input=input56($f,'2'); $id=$s->createDraft($staffId,$input)['return_id']; $stock=stock56(); $fin=financial56();
    check56(!$s->createDraft($staffId,$input)['created'],'identical draft creation is idempotent');
    reject56(fn()=>$s->createDraft($staffId,array_replace($input,['reason'=>'Different request'])),'request key cannot change its payload');
    reject56(fn()=>$s->createDraft($staffId,input56($f,extra:['supplier_id'=>$foreign['supplier']])),'wrong supplier rejected');
    reject56(fn()=>$s->createDraft($staffId,input56($f,extra:['purchase_id'=>$foreign['po']])),'invalid purchase/receipt relationship rejected');
    reject56(fn()=>$s->createDraft($staffId,input56($f,extra:['goods_receipt_id'=>$foreign['gr']])),'foreign receipt rejected');
    foreach (['0','-1','1e2','1.0001','0.5','11'] as $qty) { reject56(fn()=>$s->createDraft($staffId,input56($f,$qty)),'invalid/over/precision quantity rejected: '.$qty); }
    foreach (['unit_cost_khr','amount_original','credit_id','status','approved_by'] as $field) { reject56(fn()=>$s->createDraft($staffId,input56($f,extra:[$field=>'1'])),'Staff cannot submit '.$field); }
    reject56(fn()=>$s->confirm($ownerId,$id,'DISPATCH-1'),'draft cannot dispatch without approval');
    $s->submit($staffId,$id); check56(!$s->submit($staffId,$id)['submitted'],'submission replay is idempotent');
    $s->approve($ownerId,$id); check56(!$s->approve($ownerId,$id)['approved'],'approval replay is idempotent');
    check56($stock===stock56() && $fin===financial56(),'draft/submission/approval change no inventory or financial ledger');
    foreach (['approve','confirm','reject','cancel','reconcile'] as $method) {
        reject56(fn()=>match($method) {'confirm'=>$s->confirm($staffId,$id,'DISPATCH-1'),'reject'=>$s->reject($staffId,$id,'Not authorized'),'cancel'=>$s->cancel($staffId,$id,'Not authorized'),'reconcile'=>$s->reconcile($staffId,$f['po']),default=>$s->approve($staffId,$id)},'Staff cannot '.$method);
    }
    $receiptRows=$app->goodsReceipts()->evidence($f['po']); $s->confirm($ownerId,$id,'DISPATCH-1');
    check56($fin===financial56(),'physical dispatch without a credit changes no AP, credit, cash, expense or revenue record');
    $post=$app->purchaseReturns()->postings($id)[0];
    check56($post['qty_base']==='2.000' && $post['carrying_value_khr']==='20.0000','partial purchase return posts exact outbound stock/valuation');
    check56(!$s->confirm($ownerId,$id,'DISPATCH-1')['confirmed'],'duplicate confirmation creates no posting');
    reject56(fn()=>$s->confirm($ownerId,$id,'DIFFERENT'),'confirmation replay cannot change dispatch evidence');
    reject56(fn()=>$s->cancel($ownerId,$id,'Erase goods'),'posted return cannot be cancelled');
    reject56(fn()=>$s->reject($ownerId,$id,'Erase goods'),'posted return cannot be rejected');
    check56($receiptRows===$app->goodsReceipts()->evidence($f['po']),'original receipt quantities and cost snapshots stay immutable');
    $report=$s->reconcile($ownerId,$f['po']);
    check56($report['reconciled'] && $report['receipt_lines'][0]['remaining_quantity']==='8.000' && count($report['unmatched_returns'])===1,'read-only reconciliation exposes uninvoiced unmatched return and remaining quantity');
    $readBefore=[stock56(),financial56()]; $s->reconcile($ownerId,$f['po']); check56($readBefore===[stock56(),financial56()],'reconciliation does not repair or materialize records');
    $rest=ready56($f,'8'); $s->confirm($ownerId,$rest,'DISPATCH-REST');
    check56($app->inventory()->reconcileProductValuation($ownerId,$p['id'])['cost_qty']==='0.000','multiple physical returns can fully deplete a receipt');
    reject56(fn()=>$s->createDraft($staffId,input56($f)),'already returned quantities cannot be requested again');
    $invoice=invoice56($f); $credit=credit56($f,$invoice,'100'); $financial=financial56(); $stock=stock56();
    $link=linkInput56($id,$credit,$invoice,'2','20'); $linkId=$s->linkCredit($ownerId,$id,$link)['link_id'];
    check56(!$s->linkCredit($ownerId,$id,$link)['linked'],'credit linkage replay is idempotent');
    reject56(fn()=>$s->linkCredit($ownerId,$id,array_replace($link,['amount_original'=>'19'])),'link key cannot change amount');
    reject56(fn()=>$s->linkCredit($staffId,$id,$link),'Staff cannot link financial credits');
    reject56(fn()=>$s->linkCredit($ownerId,$id,linkInput56($id,$credit,$invoice,'1','1')),'a returned quantity cannot be attributed twice');
    $s->linkCredit($ownerId,$rest,linkInput56($rest,$credit,$invoice,'8','80'));
    check56($financial===financial56() && $stock===stock56(),'credit attribution creates no second financial or stock posting');
    $report=$s->reconcile($ownerId,$f['po']); check56($report['reconciled'] && !$report['unmatched_returns'] && !$report['unmatched_credits'],'pre-invoice return can later match posted invoice and credit without rewriting gross receiving');
    $missing=$app->purchaseReturns()->postings($id)[0]; $pdo->prepare('DELETE FROM purchase_return_postings WHERE id=?')->execute([$missing['id']]);
    try { check56(!$s->reconcile($ownerId,$f['po'])['reconciled'] && !$app->purchaseReturns()->postings($id),'missing physical posting behind existing credit links is reported without inventing or repairing evidence'); }
    finally { $pdo->prepare('INSERT INTO purchase_return_postings ('.implode(',',array_keys($missing)).') VALUES ('.implode(',',array_fill(0,count($missing),'?')).')')->execute(array_values($missing)); }

    $g=single56(product56()); $gi=invoice56($g); $gc=credit56($g,$gi,'10'); $gd=credit56($g,$gi,'1',false);
    $ga=ready56($g,'2'); $gb=ready56($g,'2'); $s->confirm($ownerId,$ga,'G-A'); $s->confirm($ownerId,$gb,'G-B');
    reject56(fn()=>$s->linkCredit($ownerId,$ga,linkInput56($ga,$gd,$gi,'1','1')),'unposted financial credit is ineligible');
    reject56(fn()=>$s->linkCredit($ownerId,$ga,linkInput56($ga,$credit,$invoice,'1','1')),'credit from another PO is rejected');
    $fi=invoice56($foreign); $fc=credit56($foreign,$fi,'10');
    reject56(fn()=>$s->linkCredit($ownerId,$ga,linkInput56($ga,$fc,$fi,'1','1')),'credit from another supplier is rejected');
    check56(race56('link',[[$ga,linkInput56($ga,$gc,$gi,'1','7')],[$gb,linkInput56($gb,$gc,$gi,'1','7')]])===['posted','rejected'],'concurrent credit attribution cannot consume the credit amount twice');
    check56($s->reconcile($ownerId,$g['po'])['reconciled'],'partial negotiated credit/carrying-value difference reconciles separately');
    $excessLink=linkInput56($gb,$gc,$gi,'1','4'); reject56(fn()=>$s->linkCredit($ownerId,$gb,$excessLink),'credit original-currency amount cannot be over-attributed');
    $mismatch=linkInput56($ga,$gc,$gi,'1','1'); $mismatch['invoice_match_id']=$app->supplierInvoices()->matches($fi)[0]['id'];
    reject56(fn()=>$s->linkCredit($ownerId,$ga,$mismatch),'foreign original invoice match is rejected');
    $historicalCredit=credit56($g,$gi,'1'); $gProduct=$app->purchaseOrders()->items($g['po'])[0]['product_id'];
    $pdo->prepare("UPDATE products SET status='archived' WHERE id=?")->execute([$gProduct]);
    try { check56($s->linkCredit($ownerId,$ga,linkInput56($ga,$historicalCredit,$gi,'1','1'))['linked'],'historical credit attribution is independent of current product availability after physical confirmation'); }
    finally { $pdo->prepare("UPDATE products SET status='active' WHERE id=?")->execute([$gProduct]); }
    $partF=single56(product56(),'3'); $partI=invoice56($partF,'1'); $partC1=credit56($partF,$partI,'1'); $partC2=credit56($partF,$partI,'1');
    $partR1=ready56($partF); $partR2=ready56($partF); $s->confirm($ownerId,$partR1,'PARTIAL-MATCH-1'); $s->confirm($ownerId,$partR2,'PARTIAL-MATCH-2');
    $s->linkCredit($ownerId,$partR1,linkInput56($partR1,$partC1,$partI,'1','1'));
    reject56(fn()=>$s->linkCredit($ownerId,$partR2,linkInput56($partR2,$partC2,$partI,'1','1')),'a posted invoice match quantity cannot be reused through another credit and physical return');
    check56($s->reconcile($ownerId,$partF['po'])['reconciled'],'partial invoice, unmatched physical quantities and unmatched financial credits remain separately reconcilable');

    $c=single56(product56(),'5'); $a=ready56($c,'4'); $b=ready56($c,'4');
    check56(race56('confirm',[[$a,'RACE-A'],[$b,'RACE-B']])===['posted','rejected'],'concurrent physical returns serialize remaining receipt quantity');
    $winner=$app->purchaseReturns()->find($a)['status']==='confirmed'?$a:$b; $reference=$winner===$a?'RACE-A':'RACE-B';
    check56(race56('confirm',[[$winner,$reference],[$winner,$reference]])===['duplicate','duplicate'],'concurrent duplicate dispatch creates no duplicate movement');

    $m=product56(); $m1=single56($m,'1','1'); $m2=single56($m,'2','2');
    $mret=ready56($m1); $s->confirm($ownerId,$mret,'AVERAGE-1'); $mpost=$app->purchaseReturns()->postings($mret)[0];
    check56($mpost['unit_cost_khr']==='1.666667' && $mpost['carrying_value_khr']==='1.6667' && $mpost['receipt_value_khr']==='1.0000' && $mpost['carrying_difference_khr']==='0.6667','return uses locked current average and records difference from original receipt cost');
    $mrest=ready56($m2,'2'); $s->confirm($ownerId,$mrest,'AVERAGE-REST'); $value=$app->inventory()->reconcileProductValuation($ownerId,$m['id']);
    check56($value['cost_qty']==='0.000' && $value['cost_value']==='0.0000' && $value['average_cost']==='0.000000' && $app->purchaseReturns()->postings($mrest)[0]['carrying_value_khr']==='3.3333','full depletion clears exact residual value without a negative balance');

    $snap=product56(); $case=(int)$pdo->query("SELECT id FROM units WHERE code='box'")->fetchColumn();
    if (!$case) { $case=(int)$pdo->query("SELECT id FROM units WHERE code<>'pcs' AND precision_digits=0 LIMIT 1")->fetchColumn(); }
    $alternate=$app->productDomain()->addProductUnit($ownerId,$snap['id'],$case,'2');
    $sf=receipt56([['product_unit_id'=>$alternate,'ordered_qty'=>'5','unit_purchase_price'=>'20']]); $sr=ready56($sf);
    reject56(fn()=>$app->productDomain()->updateProductUnitFactor($ownerId,$alternate,'3'),'catalog service protects a conversion already used by transactions');
    // Simulate an externally changed catalog in this disposable fixture only.
    $pdo->prepare('UPDATE product_units SET to_base_factor=3 WHERE id=?')->execute([$alternate]);
    $s->confirm($ownerId,$sr,'SNAPSHOT');
    check56($app->purchaseReturns()->items($sr)[0]['qty_base']==='2.000','historical conversion snapshot survives a live catalog conversion change');

    $low=product56(); $lf=single56($low,'5'); $lr=ready56($lf,'4');
    $app->productDomain()->setPrice($ownerId,$low['unit'],'retail','KHR','100');
    $sale=$app->salesService()->createDraftSale($ownerId,['sale_channel'=>'retail','items'=>[['product_unit_id'=>$low['unit'],'location_id'=>$locationId,'quantity'=>'3']]]); $saleIds[]=$sale;
    $app->salesService()->confirmSale($ownerId,$sale); $before=stock56();
    reject56(fn()=>$s->confirm($ownerId,$lr,'LOW-STOCK'),'insufficient available inventory rejects dispatch after approval');
    check56($before===stock56() && $app->purchaseReturns()->find($lr)['status']==='approved','failed dispatch leaves all stock and document state untouched');

    $retail=(int)$pdo->query("SELECT id FROM stock_locations WHERE code='RETAIL'")->fetchColumn();
    receipt56([['product_unit_id'=>$low['unit'],'ordered_qty'=>'10','unit_purchase_price'=>'10']],location:$retail);
    reject56(fn()=>$s->confirm($ownerId,$lr,'OTHER-LOCATION'),'stock at another location cannot satisfy original-location dispatch');
    $damage=(int)$pdo->query("SELECT id FROM stock_locations WHERE code='DAMAGED'")->fetchColumn();
    $damaged=receipt56([['product_unit_id'=>product56()['unit'],'ordered_qty'=>'1','unit_purchase_price'=>'10']],location:$damage);
    reject56(fn()=>$s->createDraft($ownerId,input56($damaged)),'damage/unavailable locations cannot feed the available-stock workflow');

    $customerId=$app->paymentService()->createCustomer($ownerId,['name'=>$tag.' quarantine customer','credit_enabled'=>'1','credit_limit_khr'=>'1000']);
    $qprod=product56(); $qf=single56($qprod,'5'); $qr=ready56($qf,'4'); $qSale=sell56($qprod,'3',$customerId);
    $customerCredit=$app->creditNoteService()->createDraft($ownerId,['credit_note_key'=>key56(),'sale_id'=>$qSale,'amount_khr'=>'300','reason'=>'Goods need quarantine'])['credit_note_id'];
    $app->creditNoteService()->approve($ownerId,$customerCredit); $app->creditNoteService()->post($ownerId,$customerCredit);
    $salesReturn=$app->salesReturnService()->createDraft($staffId,['return_key'=>key56(),'sale_id'=>$qSale,'credit_note_id'=>$customerCredit,'reason'=>'Quarantine returned goods',
        'items'=>[['sale_item_id'=>$app->sales()->items($qSale)[0]['id'],'requested_qty_base'=>'3','condition_code'=>'quarantine']]])['return_id'];
    $app->salesReturnService()->approve($ownerId,$salesReturn,['approved_items'=>[['return_item_id'=>$app->saleReturns()->items($salesReturn)[0]['id'],'approved_qty_base'=>'3']]]);
    $app->salesReturnService()->confirm($ownerId,$salesReturn);
    reject56(fn()=>$s->confirm($ownerId,$qr,'QUARANTINE'),'quarantined physical goods cannot supplement available inventory');

    $shared=product56(); $sh1=single56($shared,'5'); $sh2=single56($shared,'5'); $shA=ready56($sh1,'4'); $shB=ready56($sh2,'4'); sell56($shared,'4');
    check56(race56('confirm',[[$shA,'SHARED-A'],[$shB,'SHARED-B']])===['posted','rejected'],'different POs sharing a product cannot race into negative available stock');

    $same=product56(); $sameUnit=$app->productDomain()->addProductUnit($ownerId,$same['id'],$case,'2');
    $sameF=receipt56([['product_unit_id'=>$same['unit'],'ordered_qty'=>'1','unit_purchase_price'=>'10'],['product_unit_id'=>$sameUnit,'ordered_qty'=>'1','unit_purchase_price'=>'20']]);
    $sameInput=input56($sameF); $sameInput['items']=array_map(fn($i)=>['goods_receipt_item_id'=>$i['id'],'quantity'=>'1','reason'=>'Full multi-unit return'],$sameF['items']);
    $sameR=ready56($sameF,input:$sameInput); $s->confirm($ownerId,$sameR,'MULTI-UNIT'); $sameValue=$app->inventory()->reconcileProductValuation($ownerId,$same['id']);
    check56($sameValue['cost_qty']==='0.000' && $sameValue['cost_value']==='0.0000' && $s->reconcile($ownerId,$sameF['po'])['reconciled'],'one full return reuses evolving cost/balance state across multiple purchasing units of the same product');

    $usdF=receipt56([['product_unit_id'=>product56()['unit'],'ordered_qty'=>'3','unit_purchase_price'=>'0.3702']],currency:'USD',rate:'1.234567');
    $usdI=invoice56($usdF); $usdC=credit56($usdF,$usdI,'1.1106');
    $firstCreated=ready56($usdF,'1'); $lastCreated=ready56($usdF,'2');
    $s->confirm($ownerId,$lastCreated,'REVERSE-ORDER-2'); $s->confirm($ownerId,$firstCreated,'REVERSE-ORDER-1');
    $firstLink=linkInput56($firstCreated,$usdC,$usdI,'1','0.3702');
    check56(race56('link',[[$firstCreated,$firstLink],[$firstCreated,$firstLink]])===['duplicate','posted'],'concurrent duplicate credit linkage creates one attribution');
    $s->linkCredit($ownerId,$lastCreated,linkInput56($lastCreated,$usdC,$usdI,'2','0.7404'));
    $usdLinks=$app->purchaseReturns()->linksForCredit($usdC); $sum='0.0000'; foreach ($usdLinks as $usdLink) { $sum=App\Support\Decimal::add($sum,$usdLink['credit_value_khr'],4); }
    check56($sum===$app->supplierAdjustments()->creditPosting($usdC)['amount_khr'] && $s->reconcile($ownerId,$usdF['po'])['reconciled'],'USD cumulative credit/receipt carrying shares preserve exact totals in physical posting order, not draft order');

    $ledgerLine=$app->purchaseReturns()->postings($lastCreated)[0];
    $pdo->prepare('UPDATE inventory_cost_entries SET value_delta_khr=value_delta_khr+0.0001 WHERE stock_movement_id=?')->execute([$ledgerLine['stock_movement_id']]);
    reject56(fn()=>$s->confirm($ownerId,$lastCreated,'REVERSE-ORDER-2'),'duplicate dispatch refuses inconsistent valuation evidence');
    reject56(fn()=>$s->linkCredit($ownerId,$lastCreated,linkInput56($lastCreated,$usdC,$usdI,'1','0.0001')),'financial attribution refuses inconsistent physical evidence');
    $pdo->prepare('UPDATE inventory_cost_entries SET value_delta_khr=value_delta_khr-0.0001 WHERE stock_movement_id=?')->execute([$ledgerLine['stock_movement_id']]);

    $multi=receipt56([['product_unit_id'=>$p['unit'],'ordered_qty'=>'2','unit_purchase_price'=>'10'],['product_unit_id'=>$m['unit'],'ordered_qty'=>'2','unit_purchase_price'=>'20']]);
    $multiInput=input56($multi); $multiInput['items']=array_map(fn($i)=>['goods_receipt_item_id'=>$i['id'],'quantity'=>'1','reason'=>'Rollback goods'],$multi['items']);
    $rollback=ready56($multi,input:$multiInput); $second=$app->purchaseReturns()->items($rollback)[1]['id']; $before=stock56(); $auditBefore=$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    $pdo->exec('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON purchase_return_postings FOR EACH ROW BEGIN IF NEW.return_item_id='.(int)$second." THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected late return failure'; END IF; END");
    try { reject56(fn()=>$s->confirm($ownerId,$rollback,'ROLLBACK'),'late second-line posting failure rolls back the transaction'); }
    finally { $pdo->exec('DROP TRIGGER IF EXISTS '.$trigger); }
    check56($before===stock56() && $app->purchaseReturns()->find($rollback)['status']==='approved' && $auditBefore===$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(),'rollback restores both products, ledgers, status and audit');
    $s->confirm($ownerId,$rollback,'ROLLBACK'); check56($s->reconcile($ownerId,$multi['po'])['reconciled'],'retry succeeds after rollback cause is removed');
    $ledgerGuard=ready56($multi); $originalMovement=(int)$app->goodsReceipts()->evidence($f['po'])[0]['movement_id'];
    $pdo->prepare('UPDATE inventory_cost_entries SET value_delta_khr=value_delta_khr+0.0001 WHERE stock_movement_id=?')->execute([$originalMovement]);
    $guardBefore=stock56(); reject56(fn()=>$s->confirm($ownerId,$ledgerGuard,'BAD-LEDGER'),'dispatch refuses a valuation ledger that differs from materialized inventory value');
    check56($guardBefore===stock56(),'ledger-integrity rejection commits no partial stock deduction');
    $pdo->prepare('UPDATE inventory_cost_entries SET value_delta_khr=value_delta_khr-0.0001 WHERE stock_movement_id=?')->execute([$originalMovement]);

    $cancel=$s->createDraft($staffId,input56($g))['return_id']; $s->cancel($ownerId,$cancel,'Request abandoned'); reject56(fn()=>$s->submit($staffId,$cancel),'cancelled draft is terminal');
    $rejected=$s->createDraft($staffId,input56($g))['return_id']; $s->submit($staffId,$rejected); $s->reject($ownerId,$rejected,'Incorrect goods request'); reject56(fn()=>$s->approve($ownerId,$rejected),'rejected request cannot be approved');
    $tamper=ready56($g); $item=$app->purchaseReturns()->items($tamper)[0]; $pdo->prepare("UPDATE purchase_return_items SET reason='Tampered approval' WHERE id=?")->execute([$item['id']]);
    reject56(fn()=>$s->confirm($ownerId,$tamper,'TAMPER'),'immutable Owner approval blocks changed return content');
    check56(!$s->reconcile($ownerId,$g['po'])['reconciled'],'read-only reconciliation flags approval tampering');
    $pdo->prepare('UPDATE purchase_return_items SET reason=? WHERE id=?')->execute([$item['reason'],$item['id']]);
    $movement=$app->purchaseReturns()->postings($ga)[0]['stock_movement_id']; $pdo->prepare('UPDATE stock_movements SET unit_cost_khr=unit_cost_khr+1 WHERE id=?')->execute([$movement]);
    $bad=stock56(); check56(!$s->reconcile($ownerId,$g['po'])['reconciled'] && $bad===stock56(),'ledger discrepancy is reported without repair');
    $pdo->prepare('UPDATE stock_movements SET unit_cost_khr=unit_cost_khr-1 WHERE id=?')->execute([$movement]);

    $correctionProduct=product56(); $correctionFixture=single56($correctionProduct,'5'); $correctionReturn=ready56($correctionFixture,'2'); $s->confirm($ownerId,$correctionReturn,'CORRECTION-SOURCE');
    $sourcePosting=$app->purchaseReturns()->postings($correctionReturn)[0]; $c=$app->purchaseReturnCorrectionService();
    $correctionInput=['document_key'=>key56(),'purchase_return_id'=>$correctionReturn,'reason'=>'Supplier collection was documented in error','evidence_reference'=>'Warehouse count C-1',
        'items'=>[['purchase_return_posting_id'=>$sourcePosting['id'],'quantity'=>'1','evidence_reference'=>'Count sheet C-1 line 1']]];
    $correctionId=$c->createDraft($staffId,$correctionInput)['correction_id'];
    check56(!$c->createDraft($staffId,$correctionInput)['created'],'purchase-return correction draft creation is idempotent');
    $c->submit($staffId,$correctionId); $c->approve($ownerId,$correctionId); $beforeCorrection=stock56(); $c->post($ownerId,$correctionId);
    check56($app->purchaseReturnCorrections()->postings($correctionId)[0]['carrying_value_khr']==='10.0000' && $c->post($ownerId,$correctionId)['posted']===false,'partial correction restores immutable original carrying value once');
    reject56(fn()=>$c->createDraft($staffId,array_replace_recursive($correctionInput,['document_key'=>key56(),'items'=>[['purchase_return_posting_id'=>$sourcePosting['id'],'quantity'=>'2','evidence_reference'=>'Count sheet C-2']]])),'over-correction beyond original returned quantity is rejected');
    $secondCorrection=array_replace_recursive($correctionInput,['document_key'=>key56(),'evidence_reference'=>'Warehouse count C-3','items'=>[['purchase_return_posting_id'=>$sourcePosting['id'],'quantity'=>'1','evidence_reference'=>'Count sheet C-3 line 1']]]);
    $secondId=$c->createDraft($staffId,$secondCorrection)['correction_id']; $c->submit($staffId,$secondId); $c->approve($ownerId,$secondId); $c->post($ownerId,$secondId);
    check56($app->inventory()->reconcileProductValuation($ownerId,$correctionProduct['id'])['cost_value']==='50.0000' && $c->reconcile($ownerId,$correctionFixture['po'])['reconciled'],'multiple corrections restore exact original value and reconcile without repair');
    reject56(fn()=>$c->createDraft($staffId,array_replace($correctionInput,['document_key'=>key56(),'evidence_reference'=>'x'])),'missing correction evidence is rejected');
    $correctionJson=json_encode($c->forViewer($staffId,$correctionId),JSON_THROW_ON_ERROR);
    check56(!preg_match('/cost|value|approval|reason|evidence/',$correctionJson),'Staff correction detail omits costs, approval, reasons and evidence');
    $linkedCorrection=$c->createDraft($staffId,['document_key'=>key56(),'purchase_return_id'=>$id,'reason'=>'Supplier says goods never left','evidence_reference'=>'Supplier dispute D-1',
        'items'=>[['purchase_return_posting_id'=>$app->purchaseReturns()->postings($id)[0]['id'],'quantity'=>'1','evidence_reference'=>'Dispute D-1 line 1']]])['correction_id'];
    reject56(fn()=>$c->submit($staffId,$linkedCorrection),'linked supplier credit blocks physical correction pending a financial correction');

    $sameCorrectionProduct=product56(); $sameCorrectionFixture=single56($sameCorrectionProduct,'3'); $sameCorrectionReturn=ready56($sameCorrectionFixture,'1');
    $s->confirm($ownerId,$sameCorrectionReturn,'CORRECTION-CONCURRENT-SAME'); $sameCorrectionSource=$app->purchaseReturns()->postings($sameCorrectionReturn)[0];
    $sameCorrectionId=$c->createDraft($staffId,['document_key'=>key56(),'purchase_return_id'=>$sameCorrectionReturn,'reason'=>'Two terminals reported the same verified restoration','evidence_reference'=>'Concurrent count CC-1',
        'items'=>[['purchase_return_posting_id'=>$sameCorrectionSource['id'],'quantity'=>'1','evidence_reference'=>'Concurrent count CC-1 line 1']]])['correction_id'];
    $c->submit($staffId,$sameCorrectionId); $c->approve($ownerId,$sameCorrectionId);
    $sameRace=correctionRace56([$sameCorrectionId,$sameCorrectionId]);
    $q=$pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE source_type='purchase_return_correction' AND source_id=?"); $q->execute([$sameCorrectionId]); $sameMovements=(int)$q->fetchColumn();
    $q=$pdo->prepare("SELECT COUNT(*) FROM inventory_cost_entries WHERE source_type='purchase_return_correction' AND source_id=?"); $q->execute([$sameCorrectionId]); $sameCosts=(int)$q->fetchColumn();
    $q=$pdo->prepare('SELECT COUNT(*) FROM purchase_return_correction_postings p JOIN purchase_return_correction_items i ON i.id=p.correction_item_id WHERE i.correction_id=?'); $q->execute([$sameCorrectionId]); $samePostings=(int)$q->fetchColumn();
    $sameValue=$app->inventory()->reconcileProductValuation($ownerId,$sameCorrectionProduct['id']);
    check56($sameRace===['duplicate','posted'] && $sameMovements===1 && $sameCosts===1 && $samePostings===1 && $sameValue['reconciled'] && $c->reconcile($ownerId,$sameCorrectionFixture['po'])['reconciled'],
        'two independent correction posters create exactly one complete positive stock and valuation posting');

    $distinctCorrectionProduct=product56(); $distinctCorrectionFixture=single56($distinctCorrectionProduct,'3'); $distinctCorrectionReturn=ready56($distinctCorrectionFixture,'2');
    $s->confirm($ownerId,$distinctCorrectionReturn,'CORRECTION-CONCURRENT-DISTINCT'); $distinctCorrectionSource=$app->purchaseReturns()->postings($distinctCorrectionReturn)[0];
    $distinctA=$c->createDraft($staffId,['document_key'=>key56(),'purchase_return_id'=>$distinctCorrectionReturn,'reason'=>'First independently evidenced restoration request','evidence_reference'=>'Concurrent count CD-1',
        'items'=>[['purchase_return_posting_id'=>$distinctCorrectionSource['id'],'quantity'=>'1','evidence_reference'=>'Concurrent count CD-1 line 1']]])['correction_id'];
    $distinctB=$c->createDraft($staffId,['document_key'=>key56(),'purchase_return_id'=>$distinctCorrectionReturn,'reason'=>'Second independently evidenced restoration request','evidence_reference'=>'Concurrent count CD-2',
        'items'=>[['purchase_return_posting_id'=>$distinctCorrectionSource['id'],'quantity'=>'2','evidence_reference'=>'Concurrent count CD-2 line 1']]])['correction_id'];
    foreach ([$distinctA,$distinctB] as $correction) { $c->submit($staffId,$correction); $c->approve($ownerId,$correction); }
    $distinctRace=correctionRace56([$distinctA,$distinctB]);
    $q=$pdo->prepare("SELECT COALESCE(SUM(i.quantity),0) FROM purchase_return_correction_items i JOIN purchase_return_corrections c ON c.id=i.correction_id JOIN purchase_return_correction_postings p ON p.correction_item_id=i.id WHERE i.purchase_return_posting_id=? AND c.status='posted'");
    $q->execute([$distinctCorrectionSource['id']]); $distinctUsed=(string)$q->fetchColumn();
    check56($distinctRace===['posted','rejected'] && App\Support\Decimal::compare($distinctUsed,$distinctCorrectionSource['quantity'],3)<=0 && $c->reconcile($ownerId,$distinctCorrectionFixture['po'])['reconciled'],
        'two distinct concurrent corrections cannot restore more than the original returned quantity');

    $rollbackCorrectionProduct=product56(); $rollbackCorrectionFixture=single56($rollbackCorrectionProduct,'2'); $rollbackCorrectionReturn=ready56($rollbackCorrectionFixture,'1');
    $s->confirm($ownerId,$rollbackCorrectionReturn,'CORRECTION-ROLLBACK'); $rollbackCorrectionSource=$app->purchaseReturns()->postings($rollbackCorrectionReturn)[0];
    $rollbackCorrectionId=$c->createDraft($staffId,['document_key'=>key56(),'purchase_return_id'=>$rollbackCorrectionReturn,'reason'=>'Rollback coverage restoration request','evidence_reference'=>'Rollback count CR-1',
        'items'=>[['purchase_return_posting_id'=>$rollbackCorrectionSource['id'],'quantity'=>'1','evidence_reference'=>'Rollback count CR-1 line 1']]])['correction_id'];
    $c->submit($staffId,$rollbackCorrectionId); $c->approve($ownerId,$rollbackCorrectionId); $rollbackStock=stock56(); $rollbackAudit=(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn();
    $pdo->exec("CREATE TRIGGER $trigger BEFORE INSERT ON purchase_return_correction_postings FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected correction posting failure'; END");
    try { reject56(fn()=>$c->post($ownerId,$rollbackCorrectionId),'injected correction posting failure rolls back after stock and valuation writes'); }
    finally { $pdo->exec('DROP TRIGGER IF EXISTS '.$trigger); }
    $q=$pdo->prepare('SELECT COUNT(*) FROM purchase_return_correction_postings p JOIN purchase_return_correction_items i ON i.id=p.correction_item_id WHERE i.correction_id=?'); $q->execute([$rollbackCorrectionId]);
    check56($rollbackStock===stock56() && $app->purchaseReturnCorrections()->find($rollbackCorrectionId)['status']==='approved' && (int)$q->fetchColumn()===0 && $rollbackAudit===(int)$pdo->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn(),
        'correction rollback leaves no stock, valuation, posting, status or audit residue');
    $retryCorrection=$c->post($ownerId,$rollbackCorrectionId); $q->execute([$rollbackCorrectionId]);
    check56($retryCorrection['posted'] && (int)$q->fetchColumn()===1 && $c->reconcile($ownerId,$rollbackCorrectionFixture['po'])['reconciled'],
        'correction retry after fault removal posts exactly once and reconciles');

    $public=json_encode($s->forViewer($staffId,$id),JSON_THROW_ON_ERROR);
    check56(!preg_match('/cost|value_khr|credit|amount|approval|dispatch_reference|reason|exchange_rate/',$public),'Staff detail allowlist contains no costs, financial amounts or Owner evidence');
    check56(!str_contains(json_encode($s->listForViewer($staffId,$f['po'])),'request_hash'),'Staff list excludes internal request hash');
    $pdo->prepare("UPDATE users SET status='disabled' WHERE id=?")->execute([$staffId]); reject56(fn()=>$s->createDraft($staffId,input56($g)),'inactive Staff cannot request returns'); $pdo->prepare("UPDATE users SET status='active',must_change_password=0 WHERE id=?")->execute([$staffId]);
    $httpCorrectionProduct=product56(); $httpCorrectionFixture=single56($httpCorrectionProduct,'2'); $httpCorrectionReturn=ready56($httpCorrectionFixture,'1');
    $s->confirm($ownerId,$httpCorrectionReturn,'CORRECTION-HTTP'); $httpCorrectionSource=$app->purchaseReturns()->postings($httpCorrectionReturn)[0];
    $httpCorrectionInput=['document_key'=>key56(),'purchase_return_id'=>$httpCorrectionReturn,'reason'=>'HTTP route quantity-only restoration request','evidence_reference'=>'HTTP count CH-1',
        'items'=>[['purchase_return_posting_id'=>$httpCorrectionSource['id'],'quantity'=>'1','evidence_reference'=>'HTTP count CH-1 line 1']]];
    $router=require dirname(__DIR__).'/routes/web.php'; Session::clearAuthentication();
    check56(unauthenticatedCorrectionStatus56('purchase-return-correction-create')===302 && unauthenticatedCorrectionStatus56('purchase-return-correction-reconciliation')===302,
        'unauthenticated correction HTTP routes redirect with the contract HTTP 302 status');
    $app->auth()->attemptLogin($ownerName,$password);
    foreach (['create','submit','approve','dispatch','confirm','reject','cancel','link-credit'] as $action) {
        foreach (['bad-token',[]] as $token) { $_POST=['_csrf'=>$token]; try { $router->dispatch('purchase-return-'.$action,'POST'); check56(false,'CSRF '.$action); } catch (HttpException $e) { check56($e->statusCode()===419,'invalid/array CSRF rejected for '.$action); } }
    }
    foreach (['create','submit','approve','post','reject','cancel'] as $action) {
        foreach ([null,'bad-token',[]] as $token) {
            $_POST=$token===null?[]:['_csrf'=>$token];
            try { $router->dispatch('purchase-return-correction-'.$action,'POST'); check56(false,'correction CSRF '.$action); }
            catch (HttpException $e) { check56($e->statusCode()===419,'missing/invalid correction CSRF rejected for '.$action); }
        }
    }
    $_POST=['_csrf'=>Session::csrfToken(),'return_id'=>[]]; ob_start(); $router->dispatch('purchase-return-submit','POST'); $json=json_decode(ob_get_clean(),true,flags:JSON_THROW_ON_ERROR);
    check56(!$json['success'] && http_response_code()===422,'malformed identifiers return safe HTTP 422 JSON');
    $_POST=['_csrf'=>Session::csrfToken(),'return_id'=>$id,'dispatch_reference'=>'DISPATCH-1']; ob_start(); $router->dispatch('purchase-return-confirm','POST'); $json=json_decode(ob_get_clean(),true,flags:JSON_THROW_ON_ERROR);
    check56($json['success'] && !$json['data']['confirmed'],'HTTP confirmation retry returns idempotent JSON');
    Session::clearAuthentication(); $app->auth()->attemptLogin($staffName,$password);
    foreach (['approve','confirm','dispatch','reject','cancel','link-credit'] as $action) {
        $_POST=['_csrf'=>Session::csrfToken(),'return_id'=>$id]; try { $router->dispatch('purchase-return-'.$action,'POST'); check56(false,'Staff HTTP '.$action); } catch (HttpException $e) { check56($e->statusCode()===403,'HTTP forbids Staff '.$action); }
    }
    $_GET=['return_id'=>$id]; ob_start(); $router->dispatch('purchase-return-detail','GET'); $json=ob_get_clean(); check56(!str_contains($json,'carrying_value') && !str_contains($json,'credit_links'),'Staff HTTP detail exposes no financial posting');
    $_POST=['_csrf'=>Session::csrfToken()]+input56($g); ob_start(); $router->dispatch('purchase-return-create','POST'); $json=json_decode(ob_get_clean(),true,flags:JSON_THROW_ON_ERROR);
    check56($json['success'] && $json['data']['created'] && http_response_code()===200,'authorized Staff can create a quantity-only request through the protected HTTP route');
    $_GET=['purchase_id'=>$f['po']]; try { $router->dispatch('purchase-return-reconciliation','GET'); check56(false,'Staff reconciliation'); } catch (HttpException $e) { check56($e->statusCode()===403,'Staff cannot read financial reconciliation'); }
    $httpCorrectionFinancialInput=$httpCorrectionInput; $httpCorrectionFinancialInput['document_key']=key56(); $httpCorrectionFinancialInput['unit_cost_khr']='1';
    $_POST=['_csrf'=>Session::csrfToken()]+$httpCorrectionFinancialInput; ob_start(); $router->dispatch('purchase-return-correction-create','POST'); $json=json_decode(ob_get_clean(),true,flags:JSON_THROW_ON_ERROR);
    check56(!$json['success'] && http_response_code()===422,'Staff correction HTTP route rejects injected valuation input');
    $_POST=['_csrf'=>Session::csrfToken()]+$httpCorrectionInput; ob_start(); $router->dispatch('purchase-return-correction-create','POST'); $json=json_decode(ob_get_clean(),true,flags:JSON_THROW_ON_ERROR); $httpCorrectionId=(int)($json['data']['correction_id']??0);
    check56($json['success'] && $json['data']['created'] && $httpCorrectionId>0 && http_response_code()===200,'Staff can create a quantity-only correction through its protected HTTP route');
    $_POST=['_csrf'=>Session::csrfToken(),'correction_id'=>$httpCorrectionId]; ob_start(); $router->dispatch('purchase-return-correction-submit','POST'); $json=json_decode(ob_get_clean(),true,flags:JSON_THROW_ON_ERROR);
    check56($json['success'] && $json['data']['submitted'] && http_response_code()===200,'Staff can submit its own correction through the protected HTTP route');
    foreach (['approve','post','reject','cancel'] as $action) {
        $_POST=['_csrf'=>Session::csrfToken(),'correction_id'=>$httpCorrectionId,'reason'=>'Staff must not terminate corrections'];
        try { $router->dispatch('purchase-return-correction-'.$action,'POST'); check56(false,'Staff correction '.$action); }
        catch (HttpException $e) { check56($e->statusCode()===403,'HTTP forbids Staff correction '.$action); }
    }
    $_GET=['correction_id'=>$httpCorrectionId]; ob_start(); $router->dispatch('purchase-return-correction-detail','GET'); $correctionJson=ob_get_clean();
    check56(!preg_match('/cost|value|approval|reason|evidence|supplier|payable|credit/i',$correctionJson),'Staff correction HTTP detail contains no valuation or supplier financial data');
    $_GET=['purchase_id'=>$httpCorrectionFixture['po']]; try { $router->dispatch('purchase-return-correction-reconciliation','GET'); check56(false,'Staff correction reconciliation'); }
    catch (HttpException $e) { check56($e->statusCode()===403,'Staff cannot access correction reconciliation'); }
    $_GET=[]; try { $router->dispatch('purchase-return-correction-post','GET'); check56(false,'GET correction post'); }
    catch (HttpException $e) { check56($e->statusCode()===405 && $app->purchaseReturnCorrections()->find($httpCorrectionId)['status']==='submitted','GET correction post is HTTP 405 and cannot change state'); }
    Session::clearAuthentication(); $app->auth()->attemptLogin($ownerName,$password);
    $_POST=['_csrf'=>Session::csrfToken(),'correction_id'=>$httpCorrectionId]; ob_start(); $router->dispatch('purchase-return-correction-approve','POST'); $json=json_decode(ob_get_clean(),true,flags:JSON_THROW_ON_ERROR);
    check56($json['success'] && $json['data']['approved'] && http_response_code()===200,'Owner can approve a correction through the protected HTTP route');
    $_POST=['_csrf'=>Session::csrfToken(),'correction_id'=>$httpCorrectionId]; ob_start(); $router->dispatch('purchase-return-correction-post','POST'); $json=json_decode(ob_get_clean(),true,flags:JSON_THROW_ON_ERROR);
    check56($json['success'] && $json['data']['posted'] && http_response_code()===200,'Owner can post a correction through the protected HTTP route');
    $_GET=['purchase_id'=>$httpCorrectionFixture['po']]; ob_start(); $router->dispatch('purchase-return-correction-reconciliation','GET'); $json=json_decode(ob_get_clean(),true,flags:JSON_THROW_ON_ERROR);
    check56($json['success'] && $json['data']['reconciled'] && http_response_code()===200,'Owner can read correction reconciliation through the protected HTTP route');
    Session::clearAuthentication(); $app->auth()->attemptLogin($staffName,$password);
    try {
        $pdo->exec("INSERT INTO role_permissions (role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.code='staff' AND p.code IN ('purchase_returns.approve','purchase_returns.confirm','purchase_returns.link_credit','purchase_returns.reconcile')");
        reject56(fn()=>$s->confirm($staffId,$id,'DISPATCH-1'),'explicit Owner service guard survives accidental Staff permission grant');
        $_POST=['_csrf'=>Session::csrfToken(),'return_id'=>$id]; try { $router->dispatch('purchase-return-confirm','POST'); check56(false,'explicit Owner HTTP guard'); } catch (HttpException $e) { check56($e->statusCode()===403,'explicit Owner HTTP guard survives accidental Staff permission grant'); }
    } finally { $pdo->exec("DELETE rp FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id WHERE r.code='staff' AND p.code IN ('purchase_returns.approve','purchase_returns.confirm','purchase_returns.link_credit','purchase_returns.reconcile')"); }
    foreach (['draft_created','submitted','approved','dispatched','confirmed','credit_linked','rejected','cancelled'] as $action) { $q=$pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action=?'); $q->execute(['purchase_return.'.$action]); check56((int)$q->fetchColumn()>0,$action.' audited'); }
} catch (Throwable $e) { $failures[]=$e->getMessage(); echo 'FAIL: '.$e.PHP_EOL; }
finally { $_GET=$_POST=[]; if ($pdo->inTransaction()) { $pdo->rollBack(); } if (session_status()===PHP_SESSION_ACTIVE) { Session::destroy(); } cleanup56(); }
echo $failures===[]?'All Phase 5.6/5.7 integration checks passed ('.$checks.' checks).'.PHP_EOL:count($failures).' Phase 5.6/5.7 checks failed.'.PHP_EOL;
ob_end_flush(); exit($failures===[]?0:1);
