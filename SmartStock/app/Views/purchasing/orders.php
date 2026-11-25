<section class="page-heading">
    <p class="eyebrow">Purchasing</p>
    <h1><?= e($isOwner ? 'Purchase orders' : 'Purchase requests') ?></h1>
    <p class="muted">Drafting, submitting, and approving an order does not receive inventory, change moving-average cost, or create a supplier payable.</p>
</section>

<section class="card form-card">
    <form method="get" action="" class="form-stack">
        <input type="hidden" name="r" value="purchase-orders">
        <label>Search <input name="search" value="<?= e($search) ?>" maxlength="100"></label>
        <label>Status
            <select name="status">
                <?php foreach (['all' => 'All', 'draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button button-secondary" type="submit">Filter</button>
    </form>
</section>

<?php if ($canRequest): ?>
    <section class="card form-card">
        <h2>Create draft <?= $isOwner ? 'purchase order' : 'purchase request' ?></h2>
        <form method="post" action="?r=purchase-order-create" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="purchase_key" value="<?= e($purchaseKey) ?>">
            <label>Supplier
                <select name="supplier_id" required>
                    <option value="">Select an active supplier</option>
                    <?php foreach ($suppliers as $supplier): ?><option value="<?= e((string) $supplier['id']) ?>"><?= e((string) $supplier['supplier_code']) ?> — <?= e((string) $supplier['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Currency
                <select name="currency"><option value="KHR">KHR</option><?php if ($isOwner): ?><option value="USD">USD</option><?php endif; ?></select>
            </label>
            <label>Exchange rate to KHR <input name="exchange_rate_to_khr" inputmode="decimal" value="1.000000" required></label>
            <label>Expected delivery date <input name="expected_delivery_date" type="date"></label>
            <label>Notes <textarea name="notes" maxlength="1000"></textarea></label>
            <label>Product purchasing unit
                <select name="items[0][product_unit_id]" required>
                    <option value="">Select an active product unit</option>
                    <?php foreach ($productUnits as $productUnit): ?><option value="<?= e((string) $productUnit['id']) ?>"><?= e((string) $productUnit['sku']) ?> — <?= e((string) $productUnit['product_name']) ?> / <?= e((string) $productUnit['unit_name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Ordered quantity <input name="items[0][ordered_qty]" inputmode="decimal" required></label>
            <?php if ($isOwner): ?>
                <label>Unit purchasing price <input name="items[0][unit_purchase_price]" inputmode="decimal"></label>
                <label>Line discount (%) <input name="items[0][discount_percent]" inputmode="decimal" value="0"></label>
            <?php else: ?>
                <p class="muted">Staff requests intentionally do not collect purchasing prices or discounts. An Owner must complete pricing before submission.</p>
            <?php endif; ?>
            <button class="button" type="submit">Save draft</button>
        </form>
    </section>
<?php endif; ?>

<section class="card staff-list-card">
    <div class="staff-list-heading"><h2>Order register</h2><p class="muted"><?= e((string) $orders['total']) ?> matching order(s)</p></div>
    <?php if ($orders['items'] === []): ?>
        <p class="empty-state">No purchase orders match the current filter.</p>
    <?php else: ?>
        <div class="staff-table-wrap"><table class="staff-table">
            <thead><tr><th>Order</th><th>Supplier</th><th>Status</th><th>Expected delivery</th><?php if ($isOwner): ?><th>Estimated total</th><?php endif; ?><th>Created by</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($orders['items'] as $order): ?>
                <tr>
                    <td><?= e((string) $order['purchase_no']) ?></td>
                    <td><?= e((string) $order['supplier_code']) ?> — <?= e((string) $order['supplier_name']) ?></td>
                    <td><?= e(ucwords(str_replace('_', ' ', (string) $order['status']))) ?></td>
                    <td><?= e((string) ($order['expected_delivery_date'] ?? '—')) ?></td>
                    <?php if ($isOwner): ?><td><?= e((string) (($order['estimated_total'] ?? '') === '' ? 'Awaiting prices' : $order['estimated_total'] . ' ' . $order['currency'])) ?></td><?php endif; ?>
                    <td><?= e((string) $order['created_by_name']) ?></td>
                    <td>
                        <?php if ($isOwner && $order['status'] === 'draft' && isset($draftItems[(int) $order['id']])): ?>
                            <details>
                                <summary>Edit and price draft</summary>
                                <form method="post" action="?r=purchase-order-update" class="form-stack">
                                    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="purchase_id" value="<?= e((string) $order['id']) ?>">
                                    <label>Supplier
                                        <select name="supplier_id" required>
                                            <?php foreach ($suppliers as $supplier): ?><option value="<?= e((string) $supplier['id']) ?>" <?= (int) $order['supplier_id'] === (int) $supplier['id'] ? 'selected' : '' ?>><?= e((string) $supplier['supplier_code']) ?> — <?= e((string) $supplier['name']) ?></option><?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>Currency <select name="currency"><option value="KHR" <?= $order['currency'] === 'KHR' ? 'selected' : '' ?>>KHR</option><option value="USD" <?= $order['currency'] === 'USD' ? 'selected' : '' ?>>USD</option></select></label>
                                    <label>Exchange rate to KHR <input name="exchange_rate_to_khr" inputmode="decimal" value="<?= e((string) $order['exchange_rate_to_khr']) ?>" required></label>
                                    <label>Expected delivery date <input name="expected_delivery_date" type="date" value="<?= e((string) ($order['expected_delivery_date'] ?? '')) ?>"></label>
                                    <label>Notes <textarea name="notes" maxlength="1000"><?= e((string) ($order['notes'] ?? '')) ?></textarea></label>
                                    <?php foreach ($draftItems[(int) $order['id']] as $index => $item): ?>
                                        <fieldset>
                                            <legend>Line <?= e((string) ((int) $index + 1)) ?></legend>
                                            <label>Product purchasing unit
                                                <select name="items[<?= e((string) $index) ?>][product_unit_id]" required>
                                                    <?php foreach ($productUnits as $productUnit): ?><option value="<?= e((string) $productUnit['id']) ?>" <?= (int) $item['product_unit_id'] === (int) $productUnit['id'] ? 'selected' : '' ?>><?= e((string) $productUnit['sku']) ?> — <?= e((string) $productUnit['product_name']) ?> / <?= e((string) $productUnit['unit_name']) ?></option><?php endforeach; ?>
                                                </select>
                                            </label>
                                            <label>Ordered quantity <input name="items[<?= e((string) $index) ?>][ordered_qty]" inputmode="decimal" value="<?= e((string) $item['ordered_qty']) ?>" required></label>
                                            <label>Unit purchasing price <input name="items[<?= e((string) $index) ?>][unit_purchase_price]" inputmode="decimal" value="<?= e((string) ($item['unit_purchase_price'] ?? '')) ?>"></label>
                                            <label>Line discount (%) <input name="items[<?= e((string) $index) ?>][discount_percent]" inputmode="decimal" value="<?= e((string) $item['discount_percent']) ?>"></label>
                                        </fieldset>
                                    <?php endforeach; ?>
                                    <button class="button button-secondary" type="submit">Save draft pricing</button>
                                </form>
                            </details>
                        <?php endif; ?>
                        <?php if ($canRequest && $order['status'] === 'draft'): ?>
                            <form method="post" action="?r=purchase-order-submit" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="purchase_id" value="<?= e((string) $order['id']) ?>">
                                <button class="button button-secondary" type="submit">Submit</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($isOwner && $order['status'] === 'submitted'): ?>
                            <form method="post" action="?r=purchase-order-approve" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="purchase_id" value="<?= e((string) $order['id']) ?>">
                                <button class="button" type="submit">Approve</button>
                            </form>
                            <form method="post" action="?r=purchase-order-reject" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="purchase_id" value="<?= e((string) $order['id']) ?>">
                                <input name="reason" maxlength="500" placeholder="Rejection reason" required>
                                <button class="button button-secondary" type="submit">Reject</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($isOwner && in_array($order['status'], ['draft', 'submitted', 'rejected', 'approved'], true)): ?>
                            <form method="post" action="?r=purchase-order-cancel" class="inline-form">
                                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="purchase_id" value="<?= e((string) $order['id']) ?>">
                                <input name="reason" maxlength="500" placeholder="Cancellation reason" required>
                                <button class="button button-secondary" type="submit">Cancel</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
