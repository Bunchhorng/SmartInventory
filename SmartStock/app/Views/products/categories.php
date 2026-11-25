<section class="page-heading">
    <p class="eyebrow">Product master data</p>
    <h1>Categories</h1>
    <p class="muted">Categories may be archived without breaking existing product history.</p>
</section>

<section class="card form-card">
    <form method="get" action="" class="form-stack">
        <input type="hidden" name="r" value="categories">
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
        <h2>Create category</h2>
        <form method="post" action="?r=category-create" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            <label>Name <input name="name" maxlength="140" required></label>
            <label>Parent category
                <select name="parent_id">
                    <option value="">None (top level)</option>
                    <?php foreach ($parentOptions as $parent): ?>
                        <option value="<?= e((string) $parent['id']) ?>"><?= e($parent['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="button" type="submit">Create category</button>
        </form>
    </section>
<?php endif; ?>

<section class="card staff-list-card">
    <div class="staff-list-heading"><h2>Category list</h2><p class="muted"><?= e((string) $total) ?> matching record(s)</p></div>
    <?php if ($categories === []): ?>
        <p class="empty-state">No categories match the current filters.</p>
    <?php else: ?>
        <div class="staff-table-wrap"><table class="staff-table">
            <thead><tr><th>Name</th><th>Parent</th><th>Status</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($categories as $category): ?>
                <tr>
                    <td><?= e($category['name']) ?></td>
                    <td><?= e($category['parent_name'] ?? '—') ?></td>
                    <td><?= (bool) $category['is_active'] ? 'Active' : 'Archived' ?></td>
                    <?php if ($canManage): ?><td>
                        <?php if ((bool) $category['is_active']): ?>
                            <form method="post" action="?r=category-archive">
                                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="category_id" value="<?= e((string) $category['id']) ?>">
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
