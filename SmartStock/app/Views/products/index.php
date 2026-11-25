<section class="page-heading">
    <p class="eyebrow">Product catalog</p>
    <h1>Products</h1>
    <p class="muted">Selling prices are versioned per product unit. Purchase costs are intentionally not included on this screen.</p>
</section>

<section class="card form-card">
    <form method="get" action="" class="form-stack">
        <input type="hidden" name="r" value="products">
        <label>Search SKU or name <input name="q" value="<?= e($filters['search']) ?>" maxlength="100"></label>
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
        <h2>Create product</h2>
        <form method="post" action="?r=product-create" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
            <label>SKU <input name="sku" maxlength="80" placeholder="Leave blank to generate"></label>
            <label>Name <input name="name" maxlength="200" required></label>
            <label>Category
                <select name="category_id" required>
                    <option value="">Select a category</option>
                    <?php foreach ($categories as $category): ?><option value="<?= e((string) $category['id']) ?>"><?= e($category['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label>Base unit
                <select name="base_unit_id" required>
                    <option value="">Select a unit</option>
                    <?php foreach ($units as $unit): ?><option value="<?= e((string) $unit['id']) ?>"><?= e($unit['name']) ?> (<?= e($unit['code']) ?>)</option><?php endforeach; ?>
                </select>
            </label>
            <label>Existing brand
                <select name="brand_id"><option value="">None</option><?php foreach ($brands as $brand): ?><option value="<?= e((string) $brand['id']) ?>"><?= e($brand['name']) ?></option><?php endforeach; ?></select>
            </label>
            <label>Or new brand <input name="brand_name" maxlength="120"></label>
            <label>Barcode (optional) <input name="barcode" maxlength="100"></label>
            <label>Kind <select name="kind"><option value="physical">Physical</option><option value="fuel">Fuel</option></select></label>
            <label><input type="checkbox" name="track_batches" value="1"> Track batches</label>
            <label><input type="checkbox" name="track_expiry" value="1"> Track expiration (requires batches)</label>
            <button class="button" type="submit">Create product</button>
        </form>
    </section>
<?php endif; ?>

<section class="card staff-list-card">
    <div class="staff-list-heading"><h2>Product list</h2><p class="muted"><?= e((string) $total) ?> matching record(s)</p></div>
    <?php if ($products === []): ?>
        <p class="empty-state">No products match the current filters.</p>
    <?php else: ?>
        <div class="staff-table-wrap"><table class="staff-table">
            <thead><tr><th>SKU</th><th>Product</th><th>Category</th><th>Base unit</th><th>Selling prices</th><th>Status</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($products as $product): ?>
                <tr>
                    <td><?= e($product['sku']) ?></td>
                    <td><strong><?= e($product['name']) ?></strong><?php if (!empty($product['brand_name'])): ?><span class="table-subtext"><?= e($product['brand_name']) ?></span><?php endif; ?></td>
                    <td><?= e($product['category_name']) ?></td>
                    <td><?= e($product['base_unit_name']) ?> (<?= e($product['base_unit_code']) ?>)</td>
                    <td><?= e($product['current_prices'] ?? 'Not set') ?></td>
                    <td><?= e(ucfirst($product['status'])) ?></td>
                    <?php if ($canManage): ?><td>
                        <?php if ($product['status'] === 'active'): ?>
                            <form method="post" action="?r=product-archive">
                                <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="product_id" value="<?= e((string) $product['id']) ?>">
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
