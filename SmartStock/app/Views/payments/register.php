<?php
declare(strict_types=1);
?>
<section class="content-section">
    <h1>Payments and receivables</h1>
    <p class="muted">Payments settle receivables; they do not post sales revenue. Bank and KHQR entries remain pending until an Owner manually verifies them.</p>

    <form method="post" action="?r=payment-record" class="form-stack">
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="payment_key" value="<?= e($paymentKey) ?>">
        <h2>Record payment</h2>
        <label>Method <select name="payment_method" required><?php foreach ($paymentMethods as $method): ?><option value="<?= e($method['code']) ?>"><?= e($method['name']) ?></option><?php endforeach; ?></select></label>
        <label>Currency <select name="currency" required><option value="KHR">KHR</option><option value="USD">USD</option></select></label>
        <label>Amount received <input name="amount_original" inputmode="decimal" required></label>
        <label>KHR exchange rate <input name="exchange_rate_to_khr" value="1.000000" inputmode="decimal" required></label>
        <label>Reference (required for bank/KHQR) <input name="reference_no" maxlength="120"></label>
        <label>Purpose <select name="payment_purpose"><option value="sale_settlement">Sale settlement</option><option value="customer_repayment">Customer debt repayment</option></select></label>
        <label>Invoice sale ID <input name="allocations[0][sale_id]" inputmode="numeric" required></label>
        <label>Allocated KHR <input name="allocations[0][amount_khr]" inputmode="decimal" required></label>
        <button class="button" type="submit">Record payment</button>
    </form>

    <div class="staff-list-heading"><h2>Receivables</h2></div>
    <?php if ($receivables === []): ?><p class="empty-state">No confirmed-sale receivables have been established.</p><?php else: ?>
        <div class="table-responsive"><table><thead><tr><th>Invoice</th><th>Customer</th><th>Due</th><th>Original KHR</th><th>Paid KHR</th><th>Outstanding KHR</th><th>Status</th></tr></thead><tbody>
            <?php foreach ($receivables as $receivable): ?><tr><td><?= e($receivable['invoice_no']) ?></td><td><?= e($receivable['customer_name'] ?? 'Walk-in cash sale') ?></td><td><?= e($receivable['due_date'] ?? '—') ?></td><td><?= e($receivable['original_khr']) ?></td><td><?= e($receivable['paid_khr']) ?></td><td><?= e($receivable['outstanding_khr']) ?></td><td><?= e(ucfirst($receivable['payment_status'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>

    <div class="staff-list-heading"><h2>Payments</h2></div>
    <?php if ($payments === []): ?><p class="empty-state">No payments have been recorded.</p><?php else: ?>
        <div class="table-responsive"><table><thead><tr><th>Method</th><th>Customer</th><th>Amount</th><th>KHR</th><th>Reference</th><th>Status</th><th>Received</th><?php if ($canManageCredit): ?><th>Verification</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($payments as $payment): ?><tr><td><?= e($payment['method_name']) ?></td><td><?= e($payment['customer_name'] ?? 'Walk-in') ?></td><td><?= e($payment['amount_original']) ?> <?= e($payment['currency']) ?></td><td><?= e($payment['amount_khr']) ?></td><td><?= e($payment['reference_no'] ?? '—') ?></td><td><?= e(ucfirst($payment['verification_status'])) ?></td><td><?= e($payment['recorded_by_name']) ?></td><?php if ($canManageCredit): ?><td><?php if ($payment['verification_status'] === 'pending'): ?><form method="post" action="?r=payment-verify"><input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="payment_id" value="<?= e((string) $payment['id']) ?>"><input type="hidden" name="approved" value="1"><button class="button button-secondary" type="submit">Verify</button></form><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>

    <?php if ($canManageCredit): ?>
        <div class="staff-list-heading"><h2>Customer credit profiles</h2></div>
        <form method="post" action="?r=customer-create" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            <label>Customer name <input name="name" maxlength="180" required></label><label>Phone <input name="phone" maxlength="50"></label>
            <label>Credit limit KHR <input name="credit_limit_khr" value="0" inputmode="decimal" required></label><label>Terms days <input name="payment_terms_days" value="0" inputmode="numeric" required></label>
            <label><input type="checkbox" name="credit_enabled" value="1"> Enable credit</label><button class="button" type="submit">Create customer</button>
        </form>
        <?php if ($customers !== []): ?><div class="table-responsive"><table><thead><tr><th>Customer</th><th>Credit limit</th><th>Terms</th><th>Outstanding</th></tr></thead><tbody><?php foreach ($customers as $customer): ?><tr><td><?= e($customer['name']) ?></td><td><?= e($customer['credit_limit']) ?></td><td><?= e($customer['payment_terms_days']) ?></td><td><?= e($customer['outstanding_khr']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php endif; ?>
</section>
