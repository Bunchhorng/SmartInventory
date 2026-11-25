<section class="page-heading">
    <p class="eyebrow">Owner only</p>
    <h1>Staff accounts</h1>
    <p class="muted">Create Staff accounts, then activate, suspend, or disable them without removing historical records.</p>
</section>

<section class="card staff-list-card">
    <div class="staff-list-heading">
        <div>
            <p class="eyebrow">Account access</p>
            <h2>Existing Staff</h2>
        </div>
        <p class="muted">Suspending or disabling an account immediately revokes its signed-in sessions.</p>
    </div>

    <?php if ($staffMembers === []): ?>
        <p class="empty-state">No Staff accounts have been created yet.</p>
    <?php else: ?>
        <div class="staff-table-wrap">
            <table class="staff-table">
                <thead>
                    <tr>
                        <th scope="col">Staff member</th>
                        <th scope="col">Status</th>
                        <th scope="col">Password</th>
                        <th scope="col">Last sign in</th>
                        <th scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staffMembers as $staff): ?>
                        <tr>
                            <td>
                                <strong><?= e($staff['full_name']) ?></strong>
                                <span class="table-subtext">@<?= e($staff['username']) ?></span>
                            </td>
                            <td><span class="status-badge status-<?= e($staff['status']) ?>"><?= e(ucfirst($staff['status'])) ?></span></td>
                            <td><?= (bool) $staff['must_change_password'] ? 'Change required' : 'Current' ?></td>
                            <td><?= e($staff['last_login_at'] ?? 'Never') ?></td>
                            <td>
                                <div class="staff-actions">
                                    <?php if ($staff['status'] !== 'active'): ?>
                                        <form method="post" action="?r=staff-status">
                                            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="staff_id" value="<?= e((string) $staff['id']) ?>">
                                            <input type="hidden" name="status" value="active">
                                            <button class="button button-small button-secondary" type="submit">Activate</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($staff['status'] === 'active'): ?>
                                        <form method="post" action="?r=staff-status">
                                            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="staff_id" value="<?= e((string) $staff['id']) ?>">
                                            <input type="hidden" name="status" value="suspended">
                                            <button class="button button-small button-secondary" type="submit">Suspend</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($staff['status'] !== 'disabled'): ?>
                                        <form method="post" action="?r=staff-status">
                                            <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="staff_id" value="<?= e((string) $staff['id']) ?>">
                                            <input type="hidden" name="status" value="disabled">
                                            <button class="button button-small button-danger" type="submit">Disable</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<section class="card form-card">
    <?php foreach ($errors as $error): ?>
        <p class="notice notice-error"><?= e($error) ?></p>
    <?php endforeach; ?>
    <form method="post" action="?r=staff-create" class="form-stack">
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <label>
            Username
            <input name="username" value="<?= e($form['username']) ?>" required maxlength="60" autocomplete="username">
        </label>
        <label>
            Full name
            <input name="full_name" value="<?= e($form['full_name']) ?>" required maxlength="180" autocomplete="name">
        </label>
        <label>
            Temporary password
            <input type="password" name="temporary_password" minlength="12" maxlength="128" required autocomplete="new-password">
        </label>
        <button class="button" type="submit">Create staff account</button>
    </form>
</section>
