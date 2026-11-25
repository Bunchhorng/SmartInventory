<section class="page-heading">
    <p class="eyebrow">Product master data</p>
    <h1>Measurement units</h1>
    <p class="muted">Base stock quantities support up to three fractional digits. Product-specific conversion factors are configured separately.</p>
</section>

<section class="card form-card">
    <form method="get" action="" class="form-stack">
        <input type="hidden" name="r" value="units">
        <label>Search <input name="q" value="<?= e($filters['search']) ?>" maxlength="100"></label>
        <label>Status
            <select name="status">
                <?php foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button button-secondary" type="submit">Filter</button>
    </form>
</section>

<?php if ($canManage): ?>
    <section class="card form-card">
        <h2>Create unit</h2>
        <form method="post" action="?r=unit-create" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            <label>Code <input name="code" maxlength="30" required pattern="[a-z][a-z0-9_-]{1,29}"></label>
            <label>Name <input name="name" maxlength="80" required></label>
            <label>Decimal precision <input type="number" name="precision_digits" min="0" max="3" value="0" required></label>
            <button class="button" type="submit">Create unit</button>
        </form>
    </section>
<?php endif; ?>

<section class="card staff-list-card">
    <div class="staff-list-heading"><h2>Unit list</h2><p class="muted"><?= e((string) $total) ?> matching record(s)</p></div>
    <?php if ($units === []): ?>
        <p class="empty-state">No units match the current filters.</p>
    <?php else: ?>
        <div class="staff-table-wrap"><table class="staff-table">
            <thead><tr><th>Code</th><th>Name</th><th>Precision</th><th>Status</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($units as $unit): ?>
                <tr>
                    <td><?= e($unit['code']) ?></td>
                    <td><?= e($unit['name']) ?></td>
                    <td><?= e((string) $unit['precision_digits']) ?></td>
                    <td><?= e(ucfirst($unit['status'])) ?></td>
                    <?php if ($canManage): ?><td>
                        <?php if ($unit['status'] === 'active'): ?>
                            <form method="post" action="?r=unit-archive">
                                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="unit_id" value="<?= e((string) $unit['id']) ?>">
                                <button class="button button-small button-danger" type="submit">Archive</button>
                            </form>
                        <?php endif; ?>
                    </td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
