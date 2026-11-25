<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;
use DomainException;

final class PurchasingController extends Controller
{
    public function listSuppliers(): void
    {
        $user = $this->requireSupplierView();
        $actorId = (int) $user['id'];
        $status = (string) ($_GET['status'] ?? 'active');
        $page = $this->page();
        $canManage = $this->app->users()->hasPermission($actorId, 'suppliers.manage');
        $this->render('purchasing/suppliers', [
            'title' => 'Suppliers',
            'suppliers' => $this->app->supplierService()->listForViewer(
                $actorId,
                (string) ($_GET['search'] ?? ''),
                $status,
                $page,
                25,
            ),
            'search' => (string) ($_GET['search'] ?? ''),
            'status' => $status,
            'canManage' => $canManage,
            'navigation' => $this->navigation($user),
        ]);
    }

    public function createSupplier(): void
    {
        $user = $this->requireSupplierManage();
        $this->requireCsrf();
        try {
            $supplierId = $this->app->supplierService()->create((int) $user['id'], $_POST);
            Session::flash('success', sprintf('Supplier #%d created.', $supplierId));
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('suppliers');
    }

    public function updateSupplier(): void
    {
        $user = $this->requireSupplierManage();
        $this->requireCsrf();
        try {
            $this->app->supplierService()->update((int) $user['id'], $this->postId('supplier_id'), $_POST);
            Session::flash('success', 'Supplier details updated.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('suppliers');
    }

    public function changeSupplierStatus(): void
    {
        $user = $this->requireSupplierManage();
        $this->requireCsrf();
        try {
            $this->app->supplierService()->setStatus(
                (int) $user['id'],
                $this->postId('supplier_id'),
                (string) ($_POST['status'] ?? ''),
            );
            Session::flash('success', 'Supplier status updated.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('suppliers');
    }

    public function listPurchaseOrders(): void
    {
        $user = $this->requirePurchaseView();
        $actorId = (int) $user['id'];
        $canRequest = $this->app->users()->hasPermission($actorId, 'purchases.request');
        $isOwner = $this->app->users()->isOwner($actorId);
        $status = (string) ($_GET['status'] ?? 'all');
        $orders = $this->app->purchasingService()->listForViewer(
            $actorId,
            (string) ($_GET['search'] ?? ''),
            $status,
            $this->page(),
            25,
        );
        $draftItems = [];
        if ($isOwner) {
            foreach ($orders['items'] as $order) {
                if ($order['status'] === 'draft') {
                    $draftItems[(int) $order['id']] = $this->app->purchaseOrders()->items((int) $order['id']);
                }
            }
        }
        $this->render('purchasing/orders', [
            'title' => $isOwner ? 'Purchase orders' : 'Purchase requests',
            'orders' => $orders,
            'search' => (string) ($_GET['search'] ?? ''),
            'status' => $status,
            'suppliers' => $canRequest ? $this->app->supplierService()->activeForPurchase($actorId) : [],
            'productUnits' => $canRequest ? $this->app->purchasingService()->activeProductUnitsForRequest($actorId) : [],
            'canRequest' => $canRequest,
            'isOwner' => $isOwner,
            'purchaseKey' => bin2hex(random_bytes(16)),
            'draftItems' => $draftItems,
            'navigation' => $this->navigation($user),
        ]);
    }

    public function createPurchaseOrder(): void
    {
        $user = $this->requirePurchaseRequest();
        $this->requireCsrf();
        try {
            $result = $this->app->purchasingService()->createDraft((int) $user['id'], $_POST);
            Session::flash(
                'success',
                $result['created']
                    ? sprintf('Draft purchase order #%d created. It has not changed stock or payables.', $result['purchase_id'])
                    : sprintf('Draft purchase order #%d already exists; no duplicate was created.', $result['purchase_id']),
            );
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('purchase-orders');
    }

    public function updatePurchaseOrder(): void
    {
        $user = $this->requirePurchaseRequest();
        $this->requireCsrf();
        try {
            $this->app->purchasingService()->updateDraft((int) $user['id'], $this->postId('purchase_id'), $_POST);
            Session::flash('success', 'Draft purchase order updated.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('purchase-orders');
    }

    public function submitPurchaseOrder(): void
    {
        $user = $this->requirePurchaseRequest();
        $this->requireCsrf();
        try {
            $result = $this->app->purchasingService()->submit((int) $user['id'], $this->postId('purchase_id'));
            Session::flash('success', $result['submitted'] ? 'Purchase order submitted for Owner approval.' : 'Purchase order was already submitted.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('purchase-orders');
    }

    public function approvePurchaseOrder(): void
    {
        $user = $this->requireOwnerPurchasePermission('purchases.approve');
        $this->requireCsrf();
        try {
            $result = $this->app->purchasingService()->approve((int) $user['id'], $this->postId('purchase_id'));
            Session::flash('success', $result['approved'] ? 'Purchase order approved. No stock or payable has been posted.' : 'Purchase order was already approved; no duplicate approval snapshot was created.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('purchase-orders');
    }

    public function rejectPurchaseOrder(): void
    {
        $user = $this->requireOwnerPurchasePermission('purchases.approve');
        $this->requireCsrf();
        try {
            $this->app->purchasingService()->reject(
                (int) $user['id'],
                $this->postId('purchase_id'),
                (string) ($_POST['reason'] ?? ''),
            );
            Session::flash('success', 'Purchase order rejected. It may be corrected in a future draft workflow.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('purchase-orders');
    }

    public function cancelPurchaseOrder(): void
    {
        $user = $this->requireOwnerPurchasePermission('purchases.cancel');
        $this->requireCsrf();
        try {
            $this->app->purchasingService()->cancel(
                (int) $user['id'],
                $this->postId('purchase_id'),
                (string) ($_POST['reason'] ?? ''),
            );
            Session::flash('success', 'Purchase order cancelled without posting stock or payables.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('purchase-orders');
    }

    /** @return array<string, mixed> */
    private function requireSupplierView(): array
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'suppliers.view');
        return $user;
    }

    /** @return array<string, mixed> */
    private function requireSupplierManage(): array
    {
        $user = $this->requireSupplierView();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'suppliers.manage');
        return $user;
    }

    /** @return array<string, mixed> */
    private function requirePurchaseView(): array
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'purchases.view');
        return $user;
    }

    /** @return array<string, mixed> */
    private function requirePurchaseRequest(): array
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'purchases.request');
        return $user;
    }

    /** @return array<string, mixed> */
    private function requireOwnerPurchasePermission(string $permission): array
    {
        $user = $this->requirePurchaseView();
        (new PermissionMiddleware($this->app))->requirePermission($user, $permission);
        if (!$this->app->users()->isOwner((int) $user['id'])) {
            Response::abort(403, 'Only an Owner may perform this purchasing approval action.');
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

    private function page(): int
    {
        $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $page === false || $page === null ? 1 : (int) $page;
    }

    /** @param array<string, mixed> $user @return list<array{route: string, label: string}> */
    private function navigation(array $user): array
    {
        $items = [
            ['route' => 'dashboard', 'label' => 'Dashboard'],
            ['route' => 'products', 'label' => 'Products'],
            ['route' => 'suppliers', 'label' => 'Suppliers'],
            ['route' => 'purchase-orders', 'label' => 'Purchase orders'],
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
