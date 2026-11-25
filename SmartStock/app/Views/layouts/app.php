<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($title ?? 'SmartStock') . ' · ' . ($appName ?? 'SmartStock')) ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <header class="topbar">
        <a class="brand" href="?r=dashboard" aria-label="SmartStock dashboard">
            <span class="brand-mark">S</span>
            <span>SmartStock</span>
        </a>
        <nav class="navigation" aria-label="Primary navigation">
            <?php foreach (($navigation ?? []) as $item): ?>
                <a href="?r=<?= e($item['route']) ?>"><?= e($item['label']) ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if (!empty($currentUser)): ?>
            <div class="account-menu">
                <span><?= e($currentUser['full_name']) ?></span>
                <form method="post" action="?r=logout">
                    <input type="hidden" name="_csrf" value="<?= e($csrfToken ?? '') ?>">
                    <button class="button button-quiet" type="submit">Sign out</button>
                </form>
            </div>
        <?php endif; ?>
    </header>
    <main class="app-shell">
        <?php if (!empty($successMessage)): ?>
            <p class="notice notice-success"><?= e($successMessage) ?></p>
        <?php endif; ?>
        <?php if (!empty($errorMessage)): ?>
            <p class="notice notice-error"><?= e($errorMessage) ?></p>
        <?php endif; ?>
        <?= $content ?>
    </main>
</body>
</html>
