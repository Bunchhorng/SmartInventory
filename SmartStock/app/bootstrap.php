<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Session;
use App\Core\View;

const SMARTSTOCK_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

date_default_timezone_set('Asia/Phnom_Penh');
View::setViewsPath(__DIR__ . '/Views');

$configPath = SMARTSTOCK_ROOT . '/config.php';
if (!is_file($configPath)) {
    throw new RuntimeException('Missing config.php. Copy config.example.php and set the database credentials.');
}

$config = require $configPath;
foreach (['db_host', 'db_port', 'db_name', 'db_user', 'db_pass'] as $requiredKey) {
    if (!array_key_exists($requiredKey, $config)) {
        throw new RuntimeException(sprintf('Missing "%s" in config.php.', $requiredKey));
    }
}

$config += [
    'app_name' => 'SmartStock',
    'session_lifetime_minutes' => 480,
];

// Automated tests must opt in to a separately named disposable database. This
// override is deliberately unavailable to web requests.
$testDatabase = getenv('SMARTSTOCK_TEST_DB_NAME');
if (PHP_SAPI === 'cli' && is_string($testDatabase) && $testDatabase !== '') {
    if (!preg_match('/^[A-Za-z0-9_]+_test$/', $testDatabase)) {
        throw new RuntimeException('SMARTSTOCK_TEST_DB_NAME must name a database ending in "_test".');
    }
    $config['db_name'] = $testDatabase;
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $config['db_host'],
    $config['db_port'],
    $config['db_name'],
);

$pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

if (PHP_SAPI !== 'cli') {
    Session::start();
}

return new App($pdo, $config);
