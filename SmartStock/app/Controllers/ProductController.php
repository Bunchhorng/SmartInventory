<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use App\Middleware\PermissionMiddleware;
use DomainException;

final class ProductController extends Controller
{
    public function listCategories(): void
    {
        $user = $this->requireProductView();
        $filters = $this->filters();
        $result = $this->app->productDomain()->listCategoriesForViewer(
            (int) $user['id'], $filters['search'], $filters['status'], $filters['page'], 25,
        );
        $this->render('products/categories', [
            'title' => 'Categories',
            'categories' => $result['items'],
            'total' => $result['total'],
            'filters' => $filters,
            'parentOptions' => $this->app->categories()->activeOptions(),
            'canManage' => $this->canManage((int) $user['id']),
            'navigation' => $this->navigation($user),
        ]);
    }

    public function createCategory(): void
    {
        $user = $this->requireProductManagement();
        $this->requireCsrf();
        try {
            $this->app->productDomain()->createCategory(
                (int) $user['id'],
                (string) ($_POST['name'] ?? ''),
                $this->nullablePostId('parent_id'),
            );
            Session::flash('success', 'Category created.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('categories');
    }

    public function updateCategory(): void
    {
        $user = $this->requireProductManagement();
        $this->requireCsrf();
        try {
            $this->app->productDomain()->updateCategory(
                (int) $user['id'], $this->postId('category_id'), (string) ($_POST['name'] ?? ''), $this->nullablePostId('parent_id'),
            );
            Session::flash('success', 'Category updated.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('categories');
    }

    public function archiveCategory(): void
    {
        $user = $this->requireProductManagement();
        $this->requireCsrf();
        try {
            $this->app->productDomain()->archiveCategory((int) $user['id'], $this->postId('category_id'));
            Session::flash('success', 'Category archived. Existing products keep their historical category reference.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('categories');
    }

    public function listUnits(): void
    {
        $user = $this->requireProductView();
        $filters = $this->filters();
        $result = $this->app->productDomain()->listUnitsForViewer(
            (int) $user['id'], $filters['search'], $filters['status'], $filters['page'], 25,
        );
        $this->render('products/units', [
            'title' => 'Units',
            'units' => $result['items'],
            'total' => $result['total'],
            'filters' => $filters,
            'canManage' => $this->canManage((int) $user['id']),
            'navigation' => $this->navigation($user),
        ]);
    }

    public function createUnit(): void
    {
        $user = $this->requireProductManagement();
        $this->requireCsrf();
        try {
            $this->app->productDomain()->createUnit(
                (int) $user['id'],
                (string) ($_POST['code'] ?? ''),
                (string) ($_POST['name'] ?? ''),
                $this->postInt('precision_digits'),
            );
            Session::flash('success', 'Unit created.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('units');
    }

    public function updateUnit(): void
    {
        $user = $this->requireProductManagement();
        $this->requireCsrf();
        try {
            $this->app->productDomain()->updateUnit(
                (int) $user['id'], $this->postId('unit_id'), (string) ($_POST['code'] ?? ''),
                (string) ($_POST['name'] ?? ''), $this->postInt('precision_digits'),
            );
            Session::flash('success', 'Unit updated.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('units');
    }

    public function archiveUnit(): void
    {
        $user = $this->requireProductManagement();
        $this->requireCsrf();
        try {
            $this->app->productDomain()->archiveUnit((int) $user['id'], $this->postId('unit_id'));
            Session::flash('success', 'Unit archived.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('units');
    }

    public function listProducts(): void
    {
        $user = $this->requireProductView();
        $filters = $this->filters();
        $result = $this->app->productDomain()->listProductsForViewer(
            (int) $user['id'], $filters['search'], $filters['status'], $filters['page'], 25,
        );
        $this->render('products/index', [
            'title' => 'Products',
            'products' => $result['items'],
            'total' => $result['total'],
            'filters' => $filters,
            'categories' => $this->app->categories()->activeOptions(),
            'units' => $this->app->units()->activeOptions(),
            'brands' => $this->app->products()->brands(),
            'canManage' => $this->canManage((int) $user['id']),
            'navigation' => $this->navigation($user),
        ]);
    }

    public function createProduct(): void
    {
        $user = $this->requireProductManagement();
        $this->requireCsrf();
        try {
            $this->app->productDomain()->createProduct((int) $user['id'], $_POST);
            Session::flash('success', 'Product created.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('products');
    }

    public function updateProduct(): void
    {
        $user = $this->requireProductManagement();
        $this->requireCsrf();
        try {
            $this->app->productDomain()->updateProduct((int) $user['id'], $this->postId('product_id'), $_POST);
            Session::flash('success', 'Product updated.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('products');
    }

    public function archiveProduct(): void
    {
        $user = $this->requireProductManagement();
        $this->requireCsrf();
        try {
            $this->app->productDomain()->archiveProduct((int) $user['id'], $this->postId('product_id'));
            Session::flash('success', 'Product archived. Its related transaction references remain intact.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('products');
    }

    public function createProductUnit(): void
    {
        $user = $this->requireProductManagement();
        $this->requireCsrf();
        try {
            $this->app->productDomain()->addProductUnit(
                (int) $user['id'], $this->postId('product_id'), $this->postId('unit_id'), (string) ($_POST['to_base_factor'] ?? ''),
            );
            Session::flash('success', 'Product unit conversion added.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('products');
    }

    public function updateProductUnit(): void
    {
        $user = $this->requireProductManagement();
        $this->requireCsrf();
        try {
            $this->app->productDomain()->updateProductUnitFactor(
                (int) $user['id'], $this->postId('product_unit_id'), (string) ($_POST['to_base_factor'] ?? ''),
            );
            Session::flash('success', 'Product unit conversion updated.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('products');
    }

    public function addBarcode(): void
    {
        $user = $this->requireProductManagement();
        $this->requireCsrf();
        try {
            $this->app->productDomain()->addBarcode(
                (int) $user['id'], $this->postId('product_unit_id'), (string) ($_POST['barcode'] ?? ''),
            );
            Session::flash('success', 'Barcode added.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('products');
    }

    public function setPrice(): void
    {
        $user = $this->requireProductManagement();
        $this->requireCsrf();
        try {
            $this->app->productDomain()->setPrice(
                (int) $user['id'], $this->postId('product_unit_id'), (string) ($_POST['price_type'] ?? ''),
                (string) ($_POST['currency'] ?? ''), (string) ($_POST['amount'] ?? ''),
            );
            Session::flash('success', 'Current selling price updated; the previous price remains in history.');
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect('products');
    }

    /** @return array<string, mixed> */
    private function requireProductView(): array
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'products.view');
        return $user;
    }

    /** @return array<string, mixed> */
    private function requireProductManagement(): array
    {
        $user = $this->requireProductView();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'products.manage');
        return $user;
    }

    private function requireCsrf(): void
    {
        if (!Session::verifyCsrf($_POST['_csrf'] ?? null)) {
            Response::abort(419, 'Your form has expired. Refresh the page and try again.');
        }
    }

    /** @return array{search: string, status: string, page: int} */
    private function filters(): array
    {
        $status = (string) ($_GET['status'] ?? 'active');
        if (!in_array($status, ['all', 'active', 'archived'], true)) {
            $status = 'active';
        }
        $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return [
            'search' => trim((string) ($_GET['q'] ?? '')),
            'status' => $status,
            'page' => $page === false || $page === null ? 1 : $page,
        ];
    }

    private function postId(string $name): int
    {
        $id = filter_input(INPUT_POST, $name, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            throw new DomainException('A valid record identifier is required.');
        }
        return $id;
    }

    private function nullablePostId(string $name): ?int
    {
        if (!isset($_POST[$name]) || $_POST[$name] === '') {
            return null;
        }
        return $this->postId($name);
    }

    private function postInt(string $name): int
    {
        $value = filter_input(INPUT_POST, $name, FILTER_VALIDATE_INT);
        if ($value === false || $value === null) {
            throw new DomainException('A whole-number value is required.');
        }
        return $value;
    }

    /** @param array<string, mixed> $user @return list<array{route: string, label: string}> */
    private function navigation(array $user): array
    {
        $items = [
            ['route' => 'dashboard', 'label' => 'Dashboard'],
            ['route' => 'products', 'label' => 'Products'],
            ['route' => 'categories', 'label' => 'Categories'],
            ['route' => 'units', 'label' => 'Units'],
        ];
        if ($this->app->users()->isOwner((int) $user['id'])) {
            $items[] = ['route' => 'staff-create', 'label' => 'Staff accounts'];
        }
        $items[] = ['route' => 'change-password', 'label' => 'Password'];
        return $items;
    }

    private function canManage(int $userId): bool
    {
        return $this->app->users()->hasPermission($userId, 'products.manage');
    }
}
