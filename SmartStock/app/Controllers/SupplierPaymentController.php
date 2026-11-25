<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Controller, Response, Session};
use App\Middleware\{AuthMiddleware, PermissionMiddleware};
use DomainException;

final class SupplierPaymentController extends Controller
{
    public function listPayments(): void
    {
        $actor = $this->authorize('view');
        $this->respond(fn () => $this->app->supplierPaymentService()->listForViewer($actor, $this->id($_GET['supplier_id'] ?? null), $this->id($_GET['page'] ?? 1)));
    }
    public function detail(): void
    {
        $actor = $this->authorize('view');
        $this->respond(fn () => $this->app->supplierPaymentService()->forViewer($actor, $this->id($_GET['supplier_payment_id'] ?? null)));
    }
    public function createDraft(): void
    {
        $actor = $this->authorize('request', true);
        $this->respond(fn () => $this->app->supplierPaymentService()->createDraft($actor, $_POST));
    }
    public function updateDraft(): void
    {
        $actor = $this->authorize('request', true);
        $this->respond(function () use ($actor): array {
            $this->app->supplierPaymentService()->updateDraft($actor, $this->id($_POST['supplier_payment_id'] ?? null), $_POST);
            return ['updated' => true];
        });
    }
    public function submit(): void { $this->action('submit', 'request'); }
    public function approve(): void { $this->action('approve', 'approve', true); }
    public function post(): void { $this->action('post', 'post', true); }
    private function action(string $method, string $permission, bool $owner = false): void
    {
        $actor = $this->authorize($permission, true, $owner);
        $this->respond(fn () => $this->app->supplierPaymentService()->$method($actor, $this->id($_POST['supplier_payment_id'] ?? null)));
    }
    public function verify(): void
    {
        $actor = $this->authorize('verify', true, true);
        $this->respond(fn () => $this->app->supplierPaymentService()->verify($actor, $this->id($_POST['supplier_payment_id'] ?? null), $this->text($_POST['evidence'] ?? '')));
    }
    public function reject(): void { $this->terminate('reject', 'approve'); }
    public function cancel(): void { $this->terminate('cancel', 'manage'); }
    private function terminate(string $method, string $permission): void
    {
        $actor = $this->authorize($permission, true, true);
        $this->respond(function () use ($actor, $method): array {
            $this->app->supplierPaymentService()->$method($actor, $this->id($_POST['supplier_payment_id'] ?? null), $this->text($_POST['reason'] ?? ''));
            return ['changed' => true];
        });
    }
    public function reconcile(): void
    {
        $actor = $this->authorize('reconcile', false, true);
        $this->respond(fn () => $this->app->supplierPaymentService()->reconcile($actor, $this->id($_GET['supplier_id'] ?? null)));
    }
    private function authorize(string $permission, bool $mutating = false, bool $owner = false): int
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'supplier_payments.' . $permission);
        $id = (int) $user['id'];
        if ($owner && !$this->app->users()->isOwner($id)) { Response::abort(403, 'Only an Owner may perform this supplier payment action.'); }
        if ($mutating && (!is_string($_POST['_csrf'] ?? null) || !Session::verifyCsrf($_POST['_csrf']))) { Response::abort(419, 'Your form has expired. Refresh and try again.'); }
        return $id;
    }
    private function respond(callable $fn): void
    {
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
        try { $body = ['success' => true, 'data' => $fn()]; http_response_code(200); }
        catch (DomainException $e) { $body = ['success' => false, 'error' => $e->getMessage()]; http_response_code(422); }
        echo json_encode($body, JSON_THROW_ON_ERROR);
    }
    private function id(mixed $id): int
    {
        if (!is_scalar($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) { throw new DomainException('A valid identifier is required.'); }
        return (int) $id;
    }
    private function text(mixed $value): string
    {
        if (!is_string($value)) { throw new DomainException('A text value is required.'); }
        return $value;
    }
}
