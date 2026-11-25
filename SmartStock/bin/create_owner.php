<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
if ($argc !== 3) {
    fwrite(STDERR, "Usage: php bin/create_owner.php USERNAME \"FULL NAME\"\n");
    exit(1);
}

$username = $argv[1];
$fullName = $argv[2];
fwrite(STDOUT, "Enter owner password (12+ characters): ");
$isUnix = stripos(PHP_OS, 'WIN') !== 0 && function_exists('shell_exec');
if ($isUnix) {
    @shell_exec('stty -echo');
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($isUnix) {
    @shell_exec('stty echo');
    fwrite(STDOUT, PHP_EOL);
}

$error = $app->auth()->createInitialOwner($username, $fullName, $password);
if ($error !== null) {
    fwrite(STDERR, $error . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Owner created. A password change is required at first sign-in." . PHP_EOL);
