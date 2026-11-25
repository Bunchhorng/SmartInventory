<section class="page-heading">
    <p class="eyebrow">Account security</p>
    <h1>Change your password</h1>
    <p class="muted">Choose a new password with 12–128 characters.</p>
</section>
<section class="card form-card">
    <?php foreach ($errors as $error): ?>
        <p class="notice notice-error"><?= e($error) ?></p>
    <?php endforeach; ?>
    <form method="post" action="?r=change-password" class="form-stack">
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <label>
            Current password
            <input type="password" name="current_password" required autocomplete="current-password">
        </label>
        <label>
            New password
            <input type="password" name="new_password" minlength="12" maxlength="128" required autocomplete="new-password">
        </label>
        <button class="button" type="submit">Update password</button>
    </form>
</section>
