<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;
use DomainException;

final class SalesController extends Controller
{
    public function listSales(): void
    {
        $user = $this->requireSalesCreate();
        $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $actorId = (int) $user['id'];
        $this->render('sales/register', [
            'title' => 'Sales register',
            'sales' => $this->app->salesService()->listSalesForViewer($actorId, $page === false || $page === null ? 1 : $page, 25),
            'locations' => $this->app->sales()->activeLocations(),
            'productUnits' => $this->app->sales()->activeProductUnits(),
            'customers' => $this->app->customers()->activeForSales(),
            'canOverridePrice' => $this->app->users()->hasPermission($actorId, 'pricing.override'),
            'canConfirm' => $this->app->users()->hasPermission($actorId, 'sales.confirm'),
            'canVoid' => $this->app->users()->hasPermission($actorId, 'sales.void'),
            'canViewProfit' => $this->app->users()->hasPermission($actorId, 'reports.profit'),
            'navigation' => $this->navigation($user),
        ]);
    }

    public function createSale(): void
    {
        $user = $this->requireSalesCreate();
        $this->requireCsrf();
        try {
            $saleId = $this->app->salesService()->createDraftSale((int) $user['id'], $_POST);
            Session::flash('success', sprintf('Draft sale #%d created. It does not affect stock or COGS until confirmed.', $saleId));
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('sales');
    }

    public function confirmSale(): void
    {
        $user = $this->requireSalesConfirm();
        $this->requireCsrf();
        try {
            $result = $this->app->salesService()->confirmSale((int) $user['id'], $this->postId('sale_id'));
            Session::flash('success', $result['confirmed']
                ? 'Sale confirmed with immutable revenue and COGS snapshots.'
                : 'This sale was already confirmed; no duplicate posting was made.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('sales');
    }

    public function voidSale(): void
    {
        $user = $this->requireSalesVoid();
        $this->requireCsrf();
        try {
            $result = $this->app->salesService()->voidSale(
                (int) $user['id'],
                $this->postId('sale_id'),
                (string) ($_POST['reason'] ?? ''),
                ($_POST['restock_confirmed'] ?? '') === '1',
            );
            Session::flash('success', $result['voided']
                ? 'Confirmed sale voided with compensating stock and valuation postings.'
                : 'This sale was already voided; no duplicate reversal was made.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('sales');
    }

    /** @return array<string, mixed> */
    private function requireSalesCreate(): array
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'sales.create');
        return $user;
    }

    /** @return array<string, mixed> */
    private function requireSalesConfirm(): array
    {
        $user = $this->requireSalesCreate();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'sales.confirm');
        return $user;
    }

    /** @return array<string, mixed> */
    private function requireSalesVoid(): array
    {
        $user = $this->requireSalesCreate();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'sales.void');
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
