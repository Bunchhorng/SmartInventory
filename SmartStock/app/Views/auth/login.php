<section class="login-card">
    <div class="login-product-mark" aria-label="SmartStock operations hub">
        <span class="login-product-icon" aria-hidden="true">S</span>
        <span>
            <strong>SmartStock</strong>
            <small>Operations hub</small>
        </span>
    </div>
    <div class="login-card-header">
        <p class="eyebrow"><span class="status-dot" aria-hidden="true"></span> Secure sign in</p>
        <h1>Welcome back</h1>
        <p class="muted">Sign in to see what is moving across your business.</p>
    </div>
    <?php foreach ($errors as $error): ?>
        <p class="notice notice-error"><?= e($error) ?></p>
    <?php endforeach; ?>
    <form method="post" action="?r=login" class="form-stack login-form">
        <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
        <label class="form-label">
            <span>Username</span>
            <input class="form-control form-control-lg" name="username" value="<?= e($username) ?>" required autocomplete="username" maxlength="60" autofocus>
        </label>
        <label class="form-label">
            <span>Password</span>
            <input class="form-control form-control-lg" type="password" name="password" required autocomplete="current-password">
        </label>
        <button class="btn btn-primary btn-lg login-submit" type="submit">
            <span>Sign in to SmartStock</span>
            <span class="login-submit-arrow" aria-hidden="true">&rarr;</span>
        </button>
    </form>
</section>
