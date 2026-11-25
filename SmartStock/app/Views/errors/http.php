<section class="card auth-card error-card">
    <p class="eyebrow">Error <?= e((string) $status) ?></p>
    <h1><?= e($title) ?></h1>
    <p class="muted"><?= e($message) ?></p>
    <a class="button" href="?r=login">Go to sign in</a>
</section>
