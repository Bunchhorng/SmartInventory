<section class="page-heading">
    <p class="eyebrow">Purchasing master data</p>
    <h1>Suppliers</h1>
    <p class="muted">Suppliers are retained for historical purchase references. Deactivation prevents new purchase orders without deleting prior records.</p>
</section>

<section class="card form-card">
    <form method="get" action="" class="form-stack">
        <input type="hidden" name="r" value="suppliers">
        <label>Search <input name="search" value="<?= e($search) ?>" maxlength="100"></label>
        <label>Status
            <select name="status">
                <?php foreach (['all' => 'All', 'active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button button-secondary" type="submit">Filter</button>
    </form>
</section>

<?php if ($canManage): ?>
    <section class="card form-card">
        <h2>Create supplier</h2>
        <form method="post" action="?r=supplier-create" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            <label>Supplier code <input name="supplier_code" maxlength="49" required></label>
            <label>Supplier name <input name="name" maxlength="180" required></label>
            <label>Contact person <input name="contact_person" maxlength="180" required></label>
            <label>Phone <input name="phone" maxlength="50" required></label>
            <label>Email <input name="email" type="email" maxlength="190"></label>
            <label>Address <textarea name="address" maxlength="1000" required></textarea></label>
            <label>Payment terms (days) <input name="payment_terms_days" type="number" min="0" max="3650" value="0" required></label>
            <button class="button" type="submit">Create supplier</button>
        </form>
    </section>
<?php endif; ?>

<section class="card staff-list-card">
    <div class="staff-list-heading"><h2>Supplier register</h2><p class="muted"><?= e((string) $suppliers['total']) ?> matching supplier(s)</p></div>
    <?php if ($suppliers['items'] === []): ?>
        <p class="empty-state">No suppliers match the current filter.</p>
    <?php else: ?>
        <div class="staff-table-wrap"><table class="staff-table">
            <thead><tr><th>Code</th><th>Supplier</th><th>Contact</th><th>Phone</th><th>Status</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($suppliers['items'] as $supplier): ?>
                <tr>
                    <td><?= e((string) $supplier['supplier_code']) ?></td>
                    <td><?= e((string) $supplier['name']) ?></td>
                    <td><?= e((string) ($supplier['contact_person'] ?? '—')) ?></td>
                    <td><?= e((string) ($supplier['phone'] ?? '—')) ?></td>
                    <td><?= e(ucfirst((string) $supplier['status'])) ?></td>
                    <?php if ($canManage): ?>
                        <td>
                            <?php if (in_array($supplier['status'], ['active', 'inactive'], true)): ?>
                                <form method="post" action="?r=supplier-status" class="inline-form">
                                    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="supplier_id" value="<?= e((string) $supplier['id']) ?>">
                                    <input type="hidden" name="status" value="<?= $supplier['status'] === 'active' ? 'inactive' : 'active' ?>">
                                    <button class="button button-secondary" type="submit"><?= $supplier['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                </form>
                            <?php endif; ?>
                            <details>
                                <summary>Edit</summary>
                                <form method="post" action="?r=supplier-update" class="form-stack">
                                    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="supplier_id" value="<?= e((string) $supplier['id']) ?>">
                                    <label>Code <input name="supplier_code" maxlength="49" value="<?= e((string) $supplier['supplier_code']) ?>" required></label>
                                    <label>Name <input name="name" maxlength="180" value="<?= e((string) $supplier['name']) ?>" required></label>
                                    <label>Contact <input name="contact_person" maxlength="180" value="<?= e((string) ($supplier['contact_person'] ?? '')) ?>" required></label>
                                    <label>Phone <input name="phone" maxlength="50" value="<?= e((string) ($supplier['phone'] ?? '')) ?>" required></label>
                                    <label>Email <input name="email" type="email" maxlength="190" value="<?= e((string) ($supplier['email'] ?? '')) ?>"></label>
                                    <label>Address <textarea name="address" maxlength="1000" required><?= e((string) ($supplier['address'] ?? '')) ?></textarea></label>
                                    <label>Payment terms <input name="payment_terms_days" type="number" min="0" max="3650" value="<?= e((string) ($supplier['payment_terms_days'] ?? '0')) ?>" required></label>
                                    <button class="button button-secondary" type="submit">Save</button>
                                </form>
                            </details>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
