<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;
use DomainException;

final class PaymentController extends Controller
{
    public function listPayments(): void
    {
        $user = $this->requirePaymentRecord();
        $actorId = (int) $user['id'];
        $canManageCredit = $this->app->users()->hasPermission($actorId, 'credit.manage');
        $this->render('payments/register', [
            'title' => 'Payments and receivables',
            'paymentMethods' => $this->app->paymentService()->paymentMethods($actorId),
            'receivables' => $this->app->paymentService()->listReceivablesForViewer($actorId),
            'payments' => $this->app->paymentService()->listPaymentsForViewer($actorId),
            'customers' => $canManageCredit ? $this->app->paymentService()->customersForOwner($actorId) : [],
            'canManageCredit' => $canManageCredit,
            'paymentKey' => bin2hex(random_bytes(16)),
            'navigation' => $this->navigation($user),
        ]);
    }

    public function recordPayment(): void
    {
        $user = $this->requirePaymentRecord();
        $this->requireCsrf();
        try {
            $result = $this->app->paymentService()->recordPayment((int) $user['id'], $_POST);
            Session::flash('success', $result['recorded']
                ? sprintf('Payment #%d recorded as %s.', $result['payment_id'], $result['verification_status'])
                : sprintf('Payment #%d was already recorded; no duplicate posting was made.', $result['payment_id']));
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('payments');
    }

    public function verifyPayment(): void
    {
        $user = $this->requireCreditManage();
        $this->requireCsrf();
        try {
            $approved = ($_POST['approved'] ?? '') === '1';
            $result = $this->app->paymentService()->verifyPayment(
                (int) $user['id'],
                $this->postId('payment_id'),
                $approved,
                (string) ($_POST['reason'] ?? ''),
            );
            Session::flash('success', $result['changed']
                ? sprintf('Payment #%d is now %s.', $result['payment_id'], $result['verification_status'])
                : sprintf('Payment #%d was already %s.', $result['payment_id'], $result['verification_status']));
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('payments');
    }

    public function createCustomer(): void
    {
        $user = $this->requireCreditManage();
        $this->requireCsrf();
        try {
            $customerId = $this->app->paymentService()->createCustomer((int) $user['id'], $_POST);
            Session::flash('success', sprintf('Customer #%d created with its credit profile.', $customerId));
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('payments');
    }

    public function updateCustomerCredit(): void
    {
        $user = $this->requireCreditManage();
        $this->requireCsrf();
        try {
            $this->app->paymentService()->updateCustomerCredit((int) $user['id'], $this->postId('customer_id'), $_POST);
            Session::flash('success', 'Customer credit profile updated.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('payments');
    }

    /** @return array<string, mixed> */
    private function requirePaymentRecord(): array
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'payments.record');
        return $user;
    }

    /** @return array<string, mixed> */
    private function requireCreditManage(): array
    {
        $user = $this->requirePaymentRecord();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'credit.manage');
        return $user;
    }

    private function requireCsrf(): void
    {
        if (!Session::verifyCsrf($_POST['_csrf'] ?? null)) {
            Response::abort(419, 'Your form has expired. Refresh the page and try again.');
        }
    }

    private function postId(string $name): int
    {
        $value = filter_input(INPUT_POST, $name, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false || $value === null) {
            throw new DomainException('A valid record identifier is required.');
        }
        return (int) $value;
    }

    /** @param array<string, mixed> $user @return list<array{route: string, label: string}> */
    private function navigation(array $user): array
    {
        $items = [
            ['route' => 'dashboard', 'label' => 'Dashboard'],
            ['route' => 'products', 'label' => 'Products'],
            ['route' => 'inventory-receipts', 'label' => 'Stock receipts'],
            ['route' => 'sales', 'label' => 'Sales'],
            ['route' => 'payments', 'label' => 'Payments'],
        ];
        if ($this->app->users()->isOwner((int) $user['id'])) {
            $items[] = ['route' => 'credit-notes', 'label' => 'Credit notes'];
            $items[] = ['route' => 'categories', 'label' => 'Categories'];
            $items[] = ['route' => 'units', 'label' => 'Units'];
            $items[] = ['route' => 'staff-create', 'label' => 'Staff accounts'];
        }
        $items[] = ['route' => 'change-password', 'label' => 'Password'];
        return $items;
    }
}
