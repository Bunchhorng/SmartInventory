<?php
declare(strict_types=1);
/** Fresh schema smoke test. Never connects to, imports into, or drops the main database. */
if (PHP_SAPI!=='cli') { exit(2); }
require __DIR__.'/TestDatabase.php'; $original=requireTestDatabase();
$app=require dirname(__DIR__).'/app/bootstrap.php'; $pdo=$app->pdo();
if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$original) { throw new RuntimeException('Unsafe initial database.'); }
$name='smartstock_phase56_'.bin2hex(random_bytes(5)).'_test';
if (!preg_match('/^smartstock_phase56_[a-f0-9]{10}_test$/',$name) || $name===$original) { throw new RuntimeException('Unsafe temporary database name.'); }
$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name=?'); $q->execute([$name]);
if ($q->fetchColumn()) { throw new RuntimeException('Temporary target already exists.'); }
$created=false;
try {
    $pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created=true; $pdo->exec('USE `'.$name.'`');
    $root=dirname(__DIR__);
    $files=[dirname($root).'/All-documents/SmartStock_Step_1_13_Database_Pack/smartstock_schema.sql',dirname($root).'/All-documents/SmartStock_Step_1_13_Database_Pack/smartstock_seed_reference.sql'];
    foreach (['phase1_additions','phase1_staff_statuses','phase2_product_domain','phase3_inventory_ledger','phase3_2_moving_average_costing',
        'phase4_1_sales_posting','phase4_2_payments_credit','phase4_3_refunds_credit_notes','phase4_4_sales_returns','phase5_1_supplier_purchase_orders',
        'phase5_2_goods_receiving','phase5_3_supplier_invoices','phase5_4_supplier_payments','phase5_5_supplier_credits_corrections','phase5_6_purchase_returns','phase5_7_purchase_return_corrections','phase5_8_linked_supplier_credit_corrections','phase6_1_stock_transfers'] as $file) { $files[]=$root.'/database/'.$file.'.sql'; }
    foreach ($files as $file) {
        $sql=file_get_contents($file); if ($sql===false) { throw new RuntimeException('Missing schema input.'); }
        $sql=preg_replace('/^--.*$/m','',$sql);
        $sql=preg_replace('/^CREATE DATABASE IF NOT EXISTS smartstock[^;]*;/m','',$sql); $sql=preg_replace('/^\s*USE\s+`?smartstock`?\s*;\s*$/mi','',$sql);
        if (preg_match('/\b(?:USE\s|CREATE\s+DATABASE|DROP\s+DATABASE)/i',$sql)) { throw new RuntimeException('Unsafe schema redirection.'); }
        if ($pdo->query('SELECT DATABASE()')->fetchColumn()!==$name) { throw new RuntimeException('Unexpected import target.'); }
        foreach (preg_split('/;\s*(?:\r?\n|$)/',$sql) as $statement) { if (trim($statement)!=='') { $pdo->exec($statement); } }
    }
    $q=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('purchase_returns','purchase_return_items','purchase_return_approvals','purchase_return_postings','purchase_return_credit_links','purchase_return_corrections','purchase_return_correction_items','purchase_return_correction_approvals','purchase_return_correction_postings','supplier_credit_return_corrections','supplier_credit_return_correction_approvals','supplier_credit_return_correction_postings','stock_transfers','stock_transfer_items','stock_transfer_approvals','stock_transfer_postings')");
    if ((int)$q->fetchColumn()!==16) { throw new RuntimeException('Incomplete fresh migration.'); }
    echo 'PASS: fresh base schema, seed and all 18 migrations imported; Phase 5.6-6.1 tables present.',PHP_EOL;
} finally {
    $pdo->exec('USE `'.$original.'`');
    if ($created) { $pdo->exec('DROP DATABASE `'.$name.'`'); echo 'Removed only the newly created disposable migration-smoke database.',PHP_EOL; }
}
