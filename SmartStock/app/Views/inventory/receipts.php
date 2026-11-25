<section class="page-heading">
    <p class="eyebrow">Inventory ledger</p>
    <h1>Stock receipts</h1>
    <p class="muted">Drafts do not change stock. Confirmation writes immutable base-unit ledger entries and updates the selected location balance atomically.</p>
</section>

<section class="card form-card">
    <form method="get" action="" class="form-stack">
        <input type="hidden" name="r" value="inventory-receipts">
        <label>Status
            <select name="status">
                <?php foreach (['all' => 'All', 'draft' => 'Draft', 'confirmed' => 'Confirmed', 'reversed' => 'Reversed'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button button-secondary" type="submit">Filter</button>
    </form>
</section>

<?php if ($canReceive): ?>
    <section class="card form-card">
        <h2>Create draft receipt</h2>
        <form method="post" action="?r=inventory-receipt-create" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            <label>Receipt type
                <select name="receipt_type" required><option value="opening">Opening stock</option><option value="purchase">Purchase receipt</option></select>
            </label>
            <label>Reference number <input name="reference_no" maxlength="80" required></label>
            <label>Target location
                <select name="location_id" required>
                    <option value="">Select a location</option>
                    <?php foreach ($locations as $location): ?><option value="<?= e((string) $location['id']) ?>"><?= e($location['name']) ?> (<?= e($location['code']) ?>)</option><?php endforeach; ?>
                </select>
            </label>
            <label>Product unit
                <select name="items[0][product_unit_id]" required>
                    <option value="">Select a product unit</option>
                    <?php foreach ($productUnits as $productUnit): ?><option value="<?= e((string) $productUnit['id']) ?>"><?= e($productUnit['sku']) ?> — <?= e($productUnit['product_name']) ?> / <?= e($productUnit['unit_name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Quantity <input name="items[0][quantity]" inputmode="decimal" required></label>
            <?php if ($canValue): ?>
                <label>Explicit cost per base unit (KHR) <input name="items[0][cost_per_base_khr]" inputmode="decimal"></label>
            <?php endif; ?>
            <button class="button" type="submit">Save draft</button>
        </form>
    </section>
<?php endif; ?>

<section class="card staff-list-card">
    <div class="staff-list-heading"><h2>Receipt register</h2><p class="muted">Cost details are restricted to Owners and are never displayed here.</p></div>
    <?php if ($receipts === []): ?>
        <p class="empty-state">No stock receipts match the current filter.</p>
    <?php else: ?>
        <div class="staff-table-wrap"><table class="staff-table">
            <thead><tr><th>Receipt</th><th>Type</th><th>Reference</th><th>Location</th><th>Status</th><th>Created by</th></tr></thead>
            <tbody>
            <?php foreach ($receipts as $receipt): ?>
                <tr>
                    <td><?= e($receipt['receipt_no']) ?></td>
                    <td><?= e(ucfirst($receipt['receipt_type'])) ?></td>
                    <td><?= e($receipt['reference_no']) ?></td>
                    <td><?= e($receipt['location_name']) ?> (<?= e($receipt['location_code']) ?>)</td>
                    <td><?= e(ucfirst($receipt['status'])) ?></td>
                    <td><?= e($receipt['created_by_name']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
