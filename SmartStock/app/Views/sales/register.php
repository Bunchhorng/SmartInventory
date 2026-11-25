<section class="page-heading">
    <p class="eyebrow">Sales ledger</p>
    <h1>Sales register</h1>
    <p class="muted">Drafts do not affect stock, revenue, or COGS. Confirmation posts immutable sale, stock, and valuation snapshots together.</p>
</section>

<section class="card form-card">
    <h2>Create draft sale</h2>
    <form method="post" action="?r=sale-create" class="form-stack">
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <label>Sale channel
            <select name="sale_channel" required><option value="retail">Retail</option><option value="wholesale">Wholesale</option></select>
        </label>
        <label>Settlement type
            <select name="settlement_type" required><option value="cash">Cash / settle after confirmation</option><option value="credit">Customer credit</option></select>
        </label>
        <label>Customer (required for credit)
            <select name="customer_id"><option value="">Walk-in cash sale</option><?php foreach ($customers as $customer): ?><option value="<?= e((string) $customer['id']) ?>"><?= e($customer['name']) ?></option><?php endforeach; ?></select>
        </label>
        <label>Stock location
            <select name="items[0][location_id]" required>
                <option value="">Select a location</option>
                <?php foreach ($locations as $location): ?><option value="<?= e((string) $location['id']) ?>"><?= e($location['name']) ?> (<?= e($location['code']) ?>)</option><?php endforeach; ?>
            </select>
        </label>
        <label>Product unit
            <select name="items[0][product_unit_id]" required>
                <option value="">Select a product unit</option>
                <?php foreach ($productUnits as $productUnit): ?><option value="<?= e((string) $productUnit['id']) ?>"><?= e($productUnit['sku']) ?> â€” <?= e($productUnit['product_name']) ?> / <?= e($productUnit['unit_name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label>Quantity <input name="items[0][quantity]" inputmode="decimal" required></label>
        <label>Line discount (KHR) <input name="items[0][discount_khr]" value="0" inputmode="decimal"></label>
        <?php if ($canOverridePrice): ?>
            <label>Authorized override price per selected unit (KHR, optional) <input name="items[0][override_price_khr]" inputmode="decimal"></label>
        <?php endif; ?>
        <button class="button" type="submit">Save sale draft</button>
    </form>
</section>

<section class="card staff-list-card">
    <div class="staff-list-heading"><h2>Sales register</h2><p class="muted">Customer payments settle receivables separately; sale confirmation posts revenue once.</p></div>
    <?php if ($sales === []): ?>
        <p class="empty-state">No sales have been recorded.</p>
    <?php else: ?>
        <div class="staff-table-wrap"><table class="staff-table">
            <thead><tr><th>Invoice</th><th>Channel</th><th>Sale status</th><th>Payment status</th><th>Total (KHR)</th><?php if ($canViewProfit): ?><th>COGS</th><th>Gross profit</th><?php endif; ?><th>Created by</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($sales as $sale): ?>
                <tr>
                    <td><?= e($sale['invoice_no']) ?></td>
                    <td><?= e(ucfirst($sale['sale_channel'])) ?></td>
                    <td><?= e(ucfirst($sale['status'])) ?></td>
                    <td><?= e(ucfirst($sale['payment_status'])) ?></td>
                    <td><?= e($sale['total_khr']) ?></td>
                    <?php if ($canViewProfit): ?><td><?= e((string) $sale['cogs_khr']) ?></td><td><?= e((string) $sale['gross_profit_khr']) ?></td><?php endif; ?>
                    <td><?= e($sale['created_by_name']) ?></td>
                    <td>
                        <?php if ($sale['status'] === 'draft' && $canConfirm): ?>
                            <form method="post" action="?r=sale-confirm"><input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="sale_id" value="<?= e((string) $sale['id']) ?>"><button class="button button-secondary" type="submit">Confirm</button></form>
                        <?php elseif ($sale['status'] === 'confirmed' && $canVoid): ?>
                            <form method="post" action="?r=sale-void" class="form-stack"><input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="sale_id" value="<?= e((string) $sale['id']) ?>"><label>Void reason <input name="reason" maxlength="500" required></label><label><input type="checkbox" name="restock_confirmed" value="1" required> All items are physically resellable</label><button class="button button-danger" type="submit">Void and restock</button></form>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
