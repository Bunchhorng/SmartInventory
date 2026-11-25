<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;
use DomainException;

final class SalesReturnController extends Controller
{
    public function listReturns(): void
    {
        $user = $this->requireSalesCreate();
        $actorId = (int) $user['id'];
        $rows = $this->app->salesReturnService()->returnsForViewer($actorId);
        /** @var array<int, array<string, mixed>> $returns */
        $returns = [];
        foreach ($rows as $row) {
            $returnId = (int) $row['id'];
            if (!isset($returns[$returnId])) {
                $returns[$returnId] = [
                    'id' => $returnId,
                    'return_no' => $row['return_no'],
                    'status' => $row['status'],
                    'reason' => $row['reason'],
                    'financial_amount_khr' => $row['financial_amount_khr'],
                    'invoice_no' => $row['invoice_no'],
                    'credit_note_no' => $row['credit_note_no'],
                    'requested_by_name' => $row['requested_by_name'],
                    'approved_by_name' => $row['approved_by_name'],
                    'items' => [],
                ];
            }
            $returns[$returnId]['items'][] = $row;
        }
        $this->render('sales/returns', [
            'title' => 'Physical sales returns',
            'returns' => array_values($returns),
            'canApprove' => $this->app->users()->isOwner($actorId),
            'returnKey' => bin2hex(random_bytes(16)),
            'navigation' => $this->navigation($user),
        ]);
    }

    public function createReturn(): void
    {
        $user = $this->requireSalesCreate();
        $this->requireCsrf();
        try {
            $result = $this->app->salesReturnService()->createDraft((int) $user['id'], $_POST);
            Session::flash('success', $result['created']
                ? sprintf('Sales return #%d created as a draft request.', $result['return_id'])
                : sprintf('Sales return #%d was already created; no duplicate request was made.', $result['return_id']));
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('sales-returns');
    }

    public function approveReturn(): void
    {
        $user = $this->requireOwner();
        $this->requireCsrf();
        try {
            $result = $this->app->salesReturnService()->approve((int) $user['id'], $this->postId('return_id'), $_POST);
            Session::flash('success', $result['approved'] ? 'Sales return approved.' : 'Sales return was already approved.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('sales-returns');
    }

    public function confirmReturn(): void
    {
        $user = $this->requireOwner();
        $this->requireCsrf();
        try {
            $result = $this->app->salesReturnService()->confirm((int) $user['id'], $this->postId('return_id'));
            Session::flash('success', $result['confirmed']
                ? 'Physical return confirmed with immutable inventory and valuation postings.'
                : 'This physical return was already confirmed; no duplicate posting was made.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('sales-returns');
    }

    /** @return array<string, mixed> */
    private function requireSalesCreate(): array
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'sales.create');
        return $user;
    }

    /** @return array<string, mixed> */
    private function requireOwner(): array
    {
        $user = $this->requireSalesCreate();
        if (!$this->app->users()->isOwner((int) $user['id'])) {
            Response::abort(403, 'Only an Owner can approve or confirm physical sales returns.');
        }
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
            ['route' => 'sales-returns', 'label' => 'Returns'],
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
