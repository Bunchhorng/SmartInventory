<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Controller, Response, Session};
use App\Middleware\{AuthMiddleware, PermissionMiddleware};
use DomainException;

final class SupplierInvoiceController extends Controller
{
    public function listInvoices(): void
    {
        $actor = $this->authorize('supplier_invoices.view');
        $this->respond(fn () => $this->app->supplierInvoiceService()->listForViewer($actor,
            $this->id($_GET['supplier_id'] ?? null), $this->id($_GET['page'] ?? 1)));
    }
    public function detail(): void
    {
        $actor = $this->authorize('supplier_invoices.view');
        $this->respond(fn () => $this->app->supplierInvoiceService()->forViewer($actor, $this->id($_GET['invoice_id'] ?? null)));
    }
    public function createDraft(): void
    {
        $actor = $this->authorize('supplier_invoices.record', true);
        $this->respond(fn () => $this->app->supplierInvoiceService()->createDraft($actor, $_POST));
    }
    public function updateDraft(): void
    {
        $actor = $this->authorize('supplier_invoices.record', true);
        $this->respond(function () use ($actor): array {
            $this->app->supplierInvoiceService()->updateDraft($actor, $this->id($_POST['invoice_id'] ?? null), $_POST);
            return ['updated' => true];
        });
    }
    public function submit(): void
    {
        $actor = $this->authorize('supplier_invoices.record', true);
        $this->respond(fn () => $this->app->supplierInvoiceService()->submit($actor, $this->id($_POST['invoice_id'] ?? null)));
    }
    public function approve(): void
    {
        $actor = $this->authorize('supplier_invoices.approve', true, true);
        $this->respond(fn () => $this->app->supplierInvoiceService()->approve($actor,
            $this->id($_POST['invoice_id'] ?? null), $this->text($_POST['variance_reason'] ?? '')));
    }
    public function post(): void
    {
        $actor = $this->authorize('supplier_invoices.post', true, true);
        $this->respond(fn () => $this->app->supplierInvoiceService()->post($actor, $this->id($_POST['invoice_id'] ?? null)));
    }
    public function reject(): void
    {
        $actor = $this->authorize('supplier_invoices.approve', true, true);
        $this->respond(function () use ($actor): array {
            $this->app->supplierInvoiceService()->reject($actor, $this->id($_POST['invoice_id'] ?? null), $this->text($_POST['reason'] ?? ''));
            return ['rejected' => true];
        });
    }
    public function cancel(): void
    {
        $actor = $this->authorize('supplier_invoices.manage', true, true);
        $this->respond(function () use ($actor): array {
            $this->app->supplierInvoiceService()->cancel($actor, $this->id($_POST['invoice_id'] ?? null), $this->text($_POST['reason'] ?? ''));
            return ['cancelled' => true];
        });
    }
    public function reconcile(): void
    {
        $actor = $this->authorize('supplier_invoices.reconcile', false, true);
        $this->respond(fn () => $this->app->supplierInvoiceService()->reconcile($actor, $this->id($_GET['purchase_id'] ?? null)));
    }
    public function payables(): void
    {
        $actor = $this->authorize('supplier_invoices.reconcile', false, true);
        $this->respond(fn () => $this->app->accountsPayable()->forSupplier($actor, $this->id($_GET['supplier_id'] ?? null)));
    }
    private function authorize(string $permission, bool $mutating = false, bool $owner = false): int
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user, $permission);
        $id = (int) $user['id'];
        if ($owner && !$this->app->users()->isOwner($id)) { Response::abort(403, 'Only an Owner may perform this invoice action.'); }
        if ($mutating && (!is_string($_POST['_csrf'] ?? null) || !Session::verifyCsrf($_POST['_csrf']))) {
            Response::abort(419, 'Your form has expired. Refresh the page and try again.');
        }
        return $id;
    }
    private function respond(callable $fn): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try { $body = ['success' => true, 'data' => $fn()]; http_response_code(200); }
        catch (DomainException $e) { $body = ['success' => false, 'error' => $e->getMessage()]; http_response_code(422); }
        echo json_encode($body, JSON_THROW_ON_ERROR);
    }
    private function id(mixed $id): int
    {
        if (!is_scalar($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) { throw new DomainException('A valid record identifier is required.'); }
        return (int) $id;
    }
    private function text(mixed $value): string
    {
        if (!is_string($value)) { throw new DomainException('A text value is required.'); }
        return $value;
    }
}
