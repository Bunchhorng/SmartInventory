<?php
declare(strict_types=1);
?>
<section class="content-section">
    <h1>Credit notes and refunds</h1>
    <p class="muted">Owner-only financial corrections. Credit notes adjust net sales and receivables only; they never reverse stock or COGS. A bank or KHQR refund remains pending until manually verified.</p>

    <form method="post" action="?r=credit-note-create" class="form-stack">
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="credit_note_key" value="<?= e($creditNoteKey) ?>">
        <h2>Create credit-note draft</h2>
        <label>Confirmed customer sale ID <input name="sale_id" inputmode="numeric" required></label>
        <label>Credit amount KHR <input name="amount_khr" inputmode="decimal" required></label>
        <label>Reason <input name="reason" maxlength="500" required></label>
        <button class="button" type="submit">Create draft</button>
    </form>

    <div class="staff-list-heading"><h2>Credit notes</h2></div>
    <?php if ($creditNotes === []): ?><p class="empty-state">No credit notes have been created.</p><?php else: ?>
        <div class="table-responsive"><table><thead><tr><th>Number</th><th>Invoice</th><th>Customer</th><th>Amount KHR</th><th>Receivable reduction</th><th>Customer credit</th><th>Status</th><th>Reason</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($creditNotes as $note): ?><tr>
                <td><?= e($note['credit_note_no']) ?></td><td><?= e($note['invoice_no']) ?></td><td><?= e($note['customer_name']) ?></td><td><?= e($note['amount_khr']) ?></td><td><?= e($note['receivable_reduction_khr']) ?></td><td><?= e($note['excess_credit_khr']) ?></td><td><?= e(ucfirst($note['status'])) ?></td><td><?= e($note['reason']) ?></td>
                <td>
                    <?php if ($note['status'] === 'draft'): ?><form method="post" action="?r=credit-note-approve"><input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="credit_note_id" value="<?= e((string) $note['id']) ?>"><button class="button button-secondary" type="submit">Approve</button></form><?php endif; ?>
                    <?php if ($note['status'] === 'approved'): ?><form method="post" action="?r=credit-note-post"><input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="credit_note_id" value="<?= e((string) $note['id']) ?>"><button class="button" type="submit">Post</button></form><?php endif; ?>
                    <?php if ($note['status'] === 'posted'): ?><form method="post" action="?r=credit-note-reverse" class="inline-form"><input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="credit_note_id" value="<?= e((string) $note['id']) ?>"><input name="reason" maxlength="500" placeholder="Reversal reason" required><button class="button button-secondary" type="submit">Reverse</button></form><?php endif; ?>
                </td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>

    <form method="post" action="?r=refund-create" class="form-stack">
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="refund_key" value="<?= e($refundKey) ?>">
        <h2>Create refund draft</h2>
        <label>Posted credit-note ID <input name="credit_note_id" inputmode="numeric" required></label>
        <label>Method <select name="payment_method" required><?php foreach ($paymentMethods as $method): ?><option value="<?= e($method['code']) ?>"><?= e($method['name']) ?></option><?php endforeach; ?></select></label>
        <label>Currency <select name="currency" required><option value="KHR">KHR</option><option value="USD">USD</option></select></label>
        <label>Refund amount <input name="amount_original" inputmode="decimal" required></label>
        <label>KHR exchange rate <input name="exchange_rate_to_khr" value="1.000000" inputmode="decimal" required></label>
        <label>Reference (required for bank/KHQR) <input name="reference_no" maxlength="120"></label>
        <label>Reason <input name="reason" maxlength="500" required></label>
        <button class="button" type="submit">Create refund draft</button>
    </form>

    <div class="staff-list-heading"><h2>Refunds</h2></div>
    <?php if ($refunds === []): ?><p class="empty-state">No refunds have been created.</p><?php else: ?>
        <div class="table-responsive"><table><thead><tr><th>Number</th><th>Credit note</th><th>Customer</th><th>Method</th><th>Amount</th><th>KHR</th><th>Status</th><th>Payment verification</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($refunds as $refund): ?><tr>
                <td><?= e($refund['refund_no']) ?></td><td><?= e($refund['credit_note_no']) ?></td><td><?= e($refund['customer_name']) ?></td><td><?= e($refund['method_name']) ?></td><td><?= e($refund['amount_original']) ?> <?= e($refund['currency']) ?></td><td><?= e($refund['amount_khr']) ?></td><td><?= e(ucfirst($refund['status'])) ?></td><td><?= e($refund['verification_status'] ?? '—') ?></td>
                <td>
                    <?php if ($refund['status'] === 'draft'): ?><form method="post" action="?r=refund-approve"><input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="refund_id" value="<?= e((string) $refund['id']) ?>"><button class="button button-secondary" type="submit">Approve</button></form><?php endif; ?>
                    <?php if ($refund['status'] === 'approved'): ?><form method="post" action="?r=refund-post"><input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="refund_id" value="<?= e((string) $refund['id']) ?>"><button class="button" type="submit">Release</button></form><?php endif; ?>
                    <?php if ($refund['status'] === 'posted' && $refund['verification_status'] === 'pending'): ?><form method="post" action="?r=refund-verify"><input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="refund_id" value="<?= e((string) $refund['id']) ?>"><input type="hidden" name="approved" value="1"><button class="button button-secondary" type="submit">Verify bank/KHQR</button></form><form method="post" action="?r=refund-reverse" class="inline-form"><input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>"><input type="hidden" name="refund_id" value="<?= e((string) $refund['id']) ?>"><input name="reason" maxlength="500" placeholder="Cancellation reason" required><button class="button button-secondary" type="submit">Cancel pending</button></form><?php endif; ?>
                </td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</section>
