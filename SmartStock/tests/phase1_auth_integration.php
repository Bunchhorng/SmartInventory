<?php
declare(strict_types=1);

use App\Core\Session;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/tests/TestDatabase.php';
requireTestDatabase();

/** @var App\Core\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = $app->pdo();
$suffix = bin2hex(random_bytes(5));
$ownerUsername = 'phase1_owner_' . $suffix;
$staffUsername = 'phase1_staff_' . $suffix;
$ownerInitialPassword = 'OwnerInitial!' . $suffix;
$ownerNewPassword = 'OwnerChanged!' . $suffix;
$staffTemporaryPassword = 'StaffInitial!' . $suffix;
$staffNewPassword = 'StaffChanged!' . $suffix;
$usernames = [$ownerUsername, $staffUsername];
$failures = [];
$sessionDirectory = dirname(__DIR__) . '/storage/phase1-test-sessions';

if ($app->users()->countOwners() !== 0) {
    fwrite(STDERR, 'This integration test requires an empty test database with no existing Owner account.' . PHP_EOL);
    exit(2);
}

if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0700, true) && !is_dir($sessionDirectory)) {
    throw new RuntimeException('Unable to create the integration-test session directory.');
}
session_save_path($sessionDirectory);

/** @param mixed $condition */
function check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        fwrite(STDOUT, "PASS: {$message}" . PHP_EOL);
        return;
    }

    $failures[] = $message;
    fwrite(STDERR, "FAIL: {$message}" . PHP_EOL);
}

/** @param list<string> $usernames */
function cleanupTestUsers(PDO $pdo, array $usernames): void
{
    foreach ($usernames as $username) {
        $find = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $find->execute([$username]);
        $userId = $find->fetchColumn();
        if ($userId === false) {
            continue;
        }

        $deleteAudit = $pdo->prepare('DELETE FROM audit_logs WHERE actor_id = ?');
        $deleteAudit->execute([$userId]);
        $deleteSessions = $pdo->prepare('DELETE FROM auth_sessions WHERE user_id = ?');
        $deleteSessions->execute([$userId]);
        $deleteRoles = $pdo->prepare('DELETE FROM user_roles WHERE user_id = ?');
        $deleteRoles->execute([$userId]);
        $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $deleteUser->execute([$userId]);
    }
}

try {
    cleanupTestUsers($pdo, $usernames);
    Session::start();

    $ownerCreation = $app->auth()->createInitialOwner(
        $ownerUsername,
        'Phase 1 Test Owner',
        $ownerInitialPassword,
    );
    check($ownerCreation === null, 'initial Owner can be created when no Owner exists');

    $owner = $app->users()->findByUsername($ownerUsername);
    check($owner !== null && (bool) $owner['must_change_password'], 'new Owner must change the initial password');
    check(
        $app->auth()->createInitialOwner('duplicate_owner_' . $suffix, 'Duplicate Owner', $ownerInitialPassword) !== null,
        'a second Owner is rejected',
    );

    $ownerLogin = $app->auth()->attemptLogin($ownerUsername, $ownerInitialPassword);
    check($ownerLogin !== null, 'Owner can sign in with the initial password');
    check($ownerLogin !== null && (bool) $ownerLogin['must_change_password'], 'Owner login retains forced password-change state');
    check($app->auth()->changePassword((int) $owner['id'], $ownerInitialPassword, $ownerNewPassword) === null, 'Owner can change the initial password');

    check(
        $app->auth()->createStaff((int) $owner['id'], $staffUsername, 'Phase 1 Test Staff', $staffTemporaryPassword) === null,
        'Owner can create a Staff account with a temporary password',
    );
    $staff = $app->users()->findByUsername($staffUsername);
    check($staff !== null && (bool) $staff['must_change_password'], 'new Staff account requires a password change');
    check($staff !== null && !$app->users()->isOwner((int) $staff['id']), 'Staff account has no Owner role');
    check($staff !== null && !$app->users()->hasPermission((int) $staff['id'], 'users.manage'), 'Staff cannot manage accounts');

    Session::clearAuthentication();
    $staffLogin = $app->auth()->attemptLogin($staffUsername, $staffTemporaryPassword);
    check($staffLogin !== null && (bool) $staffLogin['must_change_password'], 'Staff can sign in only into the password-change state');
    check($app->auth()->changePassword((int) $staff['id'], $staffTemporaryPassword, $staffNewPassword) === null, 'Staff can replace the temporary password');

    check($app->auth()->changeStaffStatus((int) $owner['id'], (int) $staff['id'], 'suspended') === null, 'Owner can suspend Staff');
    check($app->auth()->currentUser() === null, 'suspending Staff revokes the active session');
    check($app->auth()->attemptLogin($staffUsername, $staffNewPassword) === null, 'suspended Staff cannot sign in');

    check($app->auth()->changeStaffStatus((int) $owner['id'], (int) $staff['id'], 'active') === null, 'Owner can activate Staff');
    $staffLogin = $app->auth()->attemptLogin($staffUsername, $staffNewPassword);
    check($staffLogin !== null, 'activated Staff can sign in');

    check($app->auth()->changeStaffStatus((int) $owner['id'], (int) $staff['id'], 'disabled') === null, 'Owner can disable Staff');
    check($app->auth()->currentUser() === null, 'disabling Staff revokes the active session');
    check($app->auth()->attemptLogin($staffUsername, $staffNewPassword) === null, 'disabled Staff cannot sign in');
    $disabled = $app->users()->findStaffById((int) $staff['id']);
    check($disabled !== null && $disabled['status'] === 'disabled' && $disabled['disabled_at'] !== null, 'disabled Staff retains a disable timestamp');

    check($app->auth()->changeStaffStatus((int) $owner['id'], (int) $staff['id'], 'active') === null, 'Owner can reactivate disabled Staff');
    $reactivated = $app->users()->findStaffById((int) $staff['id']);
    check($reactivated !== null && $reactivated['status'] === 'active' && $reactivated['disabled_at'] === null, 'activation clears the disable timestamp');
    check(
        $app->auth()->changeStaffStatus((int) $staff['id'], (int) $staff['id'], 'suspended') !== null,
        'Staff cannot change account statuses through the service',
    );

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $app->auth()->attemptLogin($staffUsername, 'incorrect-password');
    }
    $locked = $app->users()->findByUsername($staffUsername);
    check($locked !== null && !empty($locked['locked_until']), 'five failed sign-ins lock an active account');
    check($app->auth()->attemptLogin($staffUsername, $staffNewPassword) === null, 'a locked account cannot sign in');

    check($app->auth()->changeStaffStatus((int) $owner['id'], (int) $staff['id'], 'disabled') === null, 'Owner can disable a locked Staff account');
    check($app->auth()->changeStaffStatus((int) $owner['id'], (int) $staff['id'], 'active') === null, 'Owner can reactivate a locked Staff account');
    $unlocked = $app->users()->findByUsername($staffUsername);
    check($unlocked !== null && empty($unlocked['locked_until']) && (int) $unlocked['failed_attempts'] === 0, 'activation clears lockout state');

    $staffLogin = $app->auth()->attemptLogin($staffUsername, $staffNewPassword);
    $rawToken = Session::authSessionToken();
    check($staffLogin !== null && $rawToken !== null, 'Staff receives a server-side session after sign-in');
    if ($rawToken !== null) {
        $expireSession = $pdo->prepare('UPDATE auth_sessions SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE token_hash = ?');
        $expireSession->execute([hash('sha256', $rawToken)]);
        check($app->auth()->currentUser() === null, 'expired server-side session is rejected');
    }

    Session::clearAuthentication();
    $ownerLogin = $app->auth()->attemptLogin($ownerUsername, $ownerNewPassword);
    $ownerToken = Session::authSessionToken();
    check($ownerLogin !== null && $ownerToken !== null, 'Owner can sign in after changing the initial password');
    if ($ownerToken !== null) {
        $app->auth()->logout((int) $owner['id']);
        check(
            !$app->users()->hasValidAuthSession((int) $owner['id'], hash('sha256', $ownerToken)),
            'logout revokes the current server-side session',
        );
    }

    $auditCount = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs
         WHERE actor_id = ? AND entity_id = ?
           AND action IN ('user.staff_suspended', 'user.staff_disabled', 'user.staff_active')"
    );
    $auditCount->execute([(int) $owner['id'], (int) $staff['id']]);
    check((int) $auditCount->fetchColumn() >= 4, 'Staff lifecycle transitions are audited');
} catch (Throwable $exception) {
    $failures[] = 'unexpected exception: ' . $exception->getMessage();
    fwrite(STDERR, 'FAIL: unexpected exception: ' . $exception . PHP_EOL);
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        Session::destroy();
    }
    cleanupTestUsers($pdo, $usernames);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf('%d integration check(s) failed.%s', count($failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, 'All Phase 1 authentication integration checks passed.' . PHP_EOL);
