<?php
declare(strict_types=1);

/**
 * Prevent CLI integration checks from falling back to the configured business
 * database. The matching override is consumed by app/bootstrap.php only in CLI.
 */
function requireTestDatabase(): string
{
    $database = getenv('SMARTSTOCK_TEST_DB_NAME');
    if (!is_string($database) || !preg_match('/^[A-Za-z0-9_]+_test$/', $database)) {
        fwrite(STDERR, "Set SMARTSTOCK_TEST_DB_NAME to an explicitly disposable database ending in _test.\n");
        exit(2);
    }

    return $database;
}
