<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'SmartStock') ?> &middot; <?= e($appName ?? 'SmartStock') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="guest-page">
    <main class="auth-shell">
        <section class="auth-intro" aria-label="About SmartStock">
            <a class="brand auth-brand" href="?r=login" aria-label="SmartStock home">
                <span class="brand-mark">S</span>
                <span>SmartStock</span>
            </a>

            <div class="auth-intro-content">
                <p class="auth-kicker">Inventory, in sync</p>
                <h1>Run a smarter stockroom.</h1>
                <p class="auth-intro-copy">Keep products, people, and daily operations connected from one secure workspace.</p>
                <div class="auth-benefits" aria-label="SmartStock highlights">
                    <div class="auth-benefit"><span class="auth-benefit-icon" aria-hidden="true">✓</span><span>Clear stock visibility</span></div>
                    <div class="auth-benefit"><span class="auth-benefit-icon" aria-hidden="true">✓</span><span>Role-based access</span></div>
                    <div class="auth-benefit"><span class="auth-benefit-icon" aria-hidden="true">✓</span><span>Built for daily operations</span></div>
                </div>
            </div>

            <p class="auth-caption">SmartStock &middot; Inventory management for growing businesses</p>
        </section>

        <section class="auth-panel">
            <div class="auth-form-frame">
                <?php if (!empty($successMessage)): ?>
                    <p class="notice notice-success"><?= e($successMessage) ?></p>
                <?php endif; ?>
                <?php if (!empty($errorMessage)): ?>
                    <p class="notice notice-error"><?= e($errorMessage) ?></p>
                <?php endif; ?>
                <?= $content ?>
                <p class="auth-security-note"><span aria-hidden="true">&#128274;</span> Your session is protected with secure sign-in controls.</p>
            </div>
        </section>
    </main>
</body>
</html>
