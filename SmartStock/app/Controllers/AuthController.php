<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if ($this->app->auth()->currentUser() !== null) {
            $this->redirect('dashboard');
        }

        $this->render('auth/login', [
            'title' => 'Sign in',
            'username' => '',
            'errors' => [],
        ], 'layouts/guest');
    }

    public function login(): void
    {
        $this->requireCsrf();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $user = $this->app->auth()->attemptLogin($username, $password);
        if ($user === null) {
            $this->render('auth/login', [
                'title' => 'Sign in',
                'username' => $username,
                'errors' => ['Invalid username, password, or account status.'],
            ], 'layouts/guest');
            return;
        }

        $this->redirect('dashboard');
    }

    public function showChangePassword(): void
    {
        $this->requireUser();
        $this->render('auth/change-password', [
            'title' => 'Change password',
            'errors' => [],
            'navigation' => [['route' => 'dashboard', 'label' => 'Dashboard']],
        ]);
    }

    public function changePassword(): void
    {
        $user = $this->requireUser();
        $this->requireCsrf();

        $error = $this->app->auth()->changePassword(
            (int) $user['id'],
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
        );
        if ($error !== null) {
            $this->render('auth/change-password', [
                'title' => 'Change password',
                'errors' => [$error],
                'navigation' => [['route' => 'dashboard', 'label' => 'Dashboard']],
            ]);
            return;
        }

        Session::flash('success', 'Password updated. Your other signed-in sessions have been revoked.');
        $this->redirect('dashboard');
    }

    public function showCreateStaff(): void
    {
        $this->requireStaffManagement();
        $this->renderStaffAccounts(['username' => '', 'full_name' => ''], []);
    }

    public function createStaff(): void
    {
        $user = $this->requireStaffManagement();
        $this->requireCsrf();

        $form = [
            'username' => trim((string) ($_POST['username'] ?? '')),
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
        ];
        $error = $this->app->auth()->createStaff(
            (int) $user['id'],
            $form['username'],
            $form['full_name'],
            (string) ($_POST['temporary_password'] ?? ''),
        );
        if ($error !== null) {
            $this->renderStaffAccounts($form, [$error]);
            return;
        }

        Session::flash('success', 'Staff account created. Share the temporary password privately.');
        $this->redirect('staff-create');
    }

    public function changeStaffStatus(): void
    {
        $owner = $this->requireStaffManagement();
        $this->requireCsrf();

        $staffId = filter_input(INPUT_POST, 'staff_id', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $status = (string) ($_POST['status'] ?? '');
        if ($staffId === false || $staffId === null) {
            Session::flash('error', 'A valid Staff account is required.');
            $this->redirect('staff-create');
        }

        $error = $this->app->auth()->changeStaffStatus((int) $owner['id'], $staffId, $status);
        if ($error !== null) {
            Session::flash('error', $error);
        } else {
            Session::flash('success', sprintf('Staff account updated to %s.', $status));
        }

        $this->redirect('staff-create');
    }

    public function logout(): void
    {
        $user = $this->requireUser();
        $this->requireCsrf();
        $this->app->auth()->logout((int) $user['id']);
        $this->redirect('login');
    }

    /** @return array<string, mixed> */
    private function requireUser(): array
    {
        return (new AuthMiddleware($this->app))->requireUser();
    }

    /** @return array<string, mixed> */
    private function requireStaffManagement(): array
    {
        $user = $this->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'users.manage');
        return $user;
    }

    private function requireCsrf(): void
    {
        if (!Session::verifyCsrf($_POST['_csrf'] ?? null)) {
            Response::abort(419, 'Your form has expired. Refresh the page and try again.');
        }
    }

    /** @return list<array{route: string, label: string}> */
    private function ownerNavigation(): array
    {
        return [
            ['route' => 'dashboard', 'label' => 'Dashboard'],
            ['route' => 'staff-create', 'label' => 'Staff accounts'],
        ];
    }

    /** @param array{username: string, full_name: string} $form @param list<string> $errors */
    private function renderStaffAccounts(array $form, array $errors): void
    {
        $this->render('users/create', [
            'title' => 'Staff accounts',
            'form' => $form,
            'errors' => $errors,
            'staffMembers' => $this->app->users()->listStaff(),
            'navigation' => $this->ownerNavigation(),
        ]);
    }
}
