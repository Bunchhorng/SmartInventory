<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;
use DomainException;

final class InventoryController extends Controller
{
    public function listReceipts(): void
    {
        $user = $this->requireStockView();
        $status = (string) ($_GET['status'] ?? 'all');
        if (!in_array($status, ['all', 'draft', 'confirmed', 'reversed'], true)) {
            $status = 'all';
        }
        $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $receipts = $this->app->inventory()->listReceiptsForViewer(
            (int) $user['id'],
            $status,
            $page === false || $page === null ? 1 : $page,
            25,
        );
        $this->render('inventory/receipts', [
            'title' => 'Stock receipts',
            'receipts' => $receipts,
            'status' => $status,
            'locations' => $this->app->inventoryReceipts()->activeLocations(),
            'productUnits' => $this->app->inventoryReceipts()->activeProductUnits(),
            'canReceive' => $this->app->users()->hasPermission((int) $user['id'], 'stock.receive'),
            'canValue' => $this->app->users()->hasPermission((int) $user['id'], 'stock.adjust.approve'),
            'navigation' => $this->navigation($user),
        ]);
    }

    public function createReceipt(): void
    {
        $user = $this->requireStockReceive();
        $this->requireCsrf();
        try {
            $receiptId = $this->app->inventory()->createDraftReceipt((int) $user['id'], $_POST);
            Session::flash('success', sprintf('Draft stock receipt #%d created. It does not affect stock until an Owner confirms its valuation.', $receiptId));
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('inventory-receipts');
    }

    public function setReceiptCosts(): void
    {
        $user = $this->requireStockValuation();
        $this->requireCsrf();
        try {
            $costs = $_POST['costs'] ?? [];
            $this->app->inventory()->setReceiptCosts(
                (int) $user['id'],
                $this->postId('receipt_id'),
                is_array($costs) ? $costs : [],
            );
            Session::flash('success', 'Receipt valuation details saved.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('inventory-receipts');
    }

    public function confirmReceipt(): void
    {
        $user = $this->requireStockReceive();
        $this->requireCsrf();
        try {
            $result = $this->app->inventory()->confirmReceipt((int) $user['id'], $this->postId('receipt_id'));
            Session::flash(
                'success',
                $result['confirmed'] ? 'Stock receipt confirmed and posted to the immutable ledger.' : 'This receipt was already confirmed; no second posting was made.',
            );
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('inventory-receipts');
    }

    public function reverseReceipt(): void
    {
        $user = $this->requireStockValuation();
        $this->requireCsrf();
        try {
            $this->app->inventory()->reverseReceipt(
                (int) $user['id'],
                $this->postId('receipt_id'),
                (string) ($_POST['reason'] ?? ''),
            );
            Session::flash('success', 'Receipt reversal posted as offsetting ledger entries.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('inventory-receipts');
    }

    /** @return array{balance: string, ledger: string, reconciled: bool} */
    public function reconcile(): array
    {
        $user = $this->requireStockView();
        return $this->app->inventory()->reconcileProductLocation(
            (int) $user['id'],
            $this->queryId('product_id'),
            $this->queryId('location_id'),
        );
    }

    /** @return array<string, mixed> */
    private function requireStockView(): array
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'stock.view');
        return $user;
    }

    /** @return array<string, mixed> */
    private function requireStockReceive(): array
    {
        $user = $this->requireStockView();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'stock.receive');
        return $user;
    }

    /** @return array<string, mixed> */
    private function requireStockValuation(): array
    {
        $user = $this->requireStockReceive();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'stock.adjust.approve');
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
        return $value;
    }

    private function queryId(string $name): int
    {
        $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false || $value === null) {
            throw new DomainException('A valid record identifier is required.');
        }
        return $value;
    }

    /** @param array<string, mixed> $user @return list<array{route: string, label: string}> */
    private function navigation(array $user): array
    {
        $items = [
            ['route' => 'dashboard', 'label' => 'Dashboard'],
            ['route' => 'products', 'label' => 'Products'],
            ['route' => 'inventory-receipts', 'label' => 'Stock receipts'],
        ];
        if ($this->app->users()->isOwner((int) $user['id'])) {
            $items[] = ['route' => 'categories', 'label' => 'Categories'];
            $items[] = ['route' => 'units', 'label' => 'Units'];
            $items[] = ['route' => 'staff-create', 'label' => 'Staff accounts'];
        }
        $items[] = ['route' => 'change-password', 'label' => 'Password'];
        return $items;
    }
}
