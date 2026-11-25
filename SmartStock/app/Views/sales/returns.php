<?php
declare(strict_types=1);
?>
<section class="content-section">
    <h1>Physical sales returns</h1>
    <p class="muted">A return needs an already posted credit note for the same confirmed sale. Confirming a return never creates a second financial credit or a refund: it only records the physical stock disposition at the original sale-line cost.</p>

    <form method="post" action="?r=sales-return-create" class="form-stack">
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="return_key" value="<?= e($returnKey) ?>">
        <h2>Create return request</h2>
        <label>Confirmed sale ID <input name="sale_id" inputmode="numeric" required></label>
        <label>Posted credit-note ID <input name="credit_note_id" inputmode="numeric" required></label>
        <label>Return reason <input name="reason" maxlength="500" required></label>
        <label>Original sale-line ID <input name="items[0][sale_item_id]" inputmode="numeric" required></label>
        <label>Requested quantity (base unit) <input name="items[0][requested_qty_base]" inputmode="decimal" required></label>
        <label>Physical condition
            <select name="items[0][condition_code]" required>
                <option value="resellable">Resellable — return to available stock</option>
                <option value="damaged">Damaged — write off, do not restock</option>
                <option value="expired">Expired — write off, do not restock</option>
                <option value="quarantine">Quarantine — unavailable hold</option>
            </select>
        </label>
        <button class="button" type="submit">Create draft return</button>
    </form>

    <div class="staff-list-heading"><h2>Return register</h2></div>
    <?php if ($returns === []): ?>
        <p class="empty-state">No physical returns have been requested.</p>
    <?php else: ?>
        <div class="table-responsive"><table><thead><tr><th>Return</th><th>Invoice / credit note</th><th>Items</th><th>Status</th><th>Reason</th><th>Requested by</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($returns as $return): ?><tr>
                <td><?= e($return['return_no']) ?></td>
                <td><?= e($return['invoice_no']) ?><br><span class="muted"><?= e($return['credit_note_no']) ?></span></td>
                <td>
                    <?php foreach ($return['items'] as $item): ?>
                        <div><?= e($item['sku']) ?> — <?= e($item['product_name']) ?>: <?= e((string) $item['requested_qty_base']) ?> requested<?php if ($item['approved_qty_base'] !== null): ?>, <?= e((string) $item['approved_qty_base']) ?> approved<?php endif; ?> (<?= e($item['condition_code']) ?>)</div>
                    <?php endforeach; ?>
                </td>
                <td><?= e(ucfirst($return['status'])) ?></td>
                <td><?= e($return['reason']) ?></td>
                <td><?= e($return['requested_by_name']) ?></td>
                <td>
                    <?php if ($canApprove && $return['status'] === 'draft'): ?>
                        <form method="post" action="?r=sales-return-approve" class="form-stack">
                            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                            <input type="hidden" name="return_id" value="<?= e((string) $return['id']) ?>">
                            <?php foreach ($return['items'] as $index => $item): ?>
                                <input type="hidden" name="approved_items[<?= e((string) $index) ?>][return_item_id]" value="<?= e((string) $item['return_item_id']) ?>">
                                <label>Approve <?= e($item['sku']) ?> qty <input name="approved_items[<?= e((string) $index) ?>][approved_qty_base]" value="<?= e((string) $item['requested_qty_base']) ?>" inputmode="decimal" required></label>
                            <?php endforeach; ?>
                            <button class="button button-secondary" type="submit">Approve</button>
                        </form>
                    <?php elseif ($canApprove && $return['status'] === 'approved'): ?>
                        <form method="post" action="?r=sales-return-confirm"><input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="return_id" value="<?= e((string) $return['id']) ?>"><button class="button" type="submit">Confirm physical return</button></form>
                    <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
