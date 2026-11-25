<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Controller, Response, Session};
use App\Middleware\{AuthMiddleware, PermissionMiddleware};
use DomainException;

final class SupplierAdjustmentController extends Controller
{
    public function creditList(): void { $this->list('credit'); }
    public function creditDetail(): void { $this->detail('credit'); }
    public function creditCreate(): void { $this->create('credit'); }
    public function creditUpdate(): void { $this->update('credit'); }
    public function creditSubmit(): void { $this->action('credit','submit','record'); }
    public function creditApprove(): void { $this->action('credit','approve','approve'); }
    public function creditPost(): void { $this->action('credit','post','post'); }
    public function creditReject(): void { $this->terminate('credit','reject','approve'); }
    public function creditCancel(): void { $this->terminate('credit','cancel','record'); }
    public function correctionList(): void { $this->list('correction'); }
    public function correctionDetail(): void { $this->detail('correction'); }
    public function correctionCreate(): void { $this->create('correction'); }
    public function correctionUpdate(): void { $this->update('correction'); }
    public function correctionSubmit(): void { $this->action('correction','submit','record'); }
    public function correctionApprove(): void { $this->action('correction','approve','approve'); }
    public function correctionPost(): void { $this->action('correction','post','post'); }
    public function correctionReject(): void { $this->terminate('correction','reject','approve'); }
    public function correctionCancel(): void { $this->terminate('correction','cancel','record'); }
    private function list(string $kind): void
    {
        $actor = $this->authorize($kind,'view');
        $this->respond(fn () => $this->app->supplierAdjustmentService()->listForViewer($actor,$kind,$this->id($_GET['supplier_id'] ?? null),$this->id($_GET['page'] ?? 1)));
    }
    private function detail(string $kind): void
    {
        $actor = $this->authorize($kind,'view');
        $this->respond(fn () => $this->app->supplierAdjustmentService()->forViewer($actor,$kind,$this->id($_GET['adjustment_id'] ?? null)));
    }
    private function create(string $kind): void
    {
        $actor = $this->authorize($kind,'record',true);
        $this->respond(fn () => $kind === 'credit' ? $this->app->supplierAdjustmentService()->createCreditDraft($actor,$_POST) : $this->app->supplierAdjustmentService()->createCorrectionDraft($actor,$_POST));
    }
    private function update(string $kind): void
    {
        $actor = $this->authorize($kind,'record',true);
        $this->respond(function () use ($actor,$kind): array {
            $this->app->supplierAdjustmentService()->updateDraft($actor,$kind,$this->id($_POST['adjustment_id'] ?? null),$_POST); return ['updated'=>true];
        });
    }
    private function action(string $kind, string $method, string $permission): void
    {
        $actor = $this->authorize($kind,$permission,true);
        $this->respond(fn () => $this->app->supplierAdjustmentService()->$method($actor,$kind,$this->id($_POST['adjustment_id'] ?? null)));
    }
    private function terminate(string $kind, string $method, string $permission): void
    {
        $actor = $this->authorize($kind,$permission,true);
        $this->respond(function () use ($actor,$kind,$method): array {
            $this->app->supplierAdjustmentService()->$method($actor,$kind,$this->id($_POST['adjustment_id'] ?? null),$this->text($_POST['reason'] ?? '')); return ['changed'=>true];
        });
    }
    public function reconcile(): void
    {
        $actor = $this->authorize('credit','reconcile');
        $this->respond(fn () => $this->app->supplierAdjustmentService()->reconcile($actor,$this->id($_GET['supplier_id'] ?? null)));
    }
    private function authorize(string $kind, string $permission, bool $mutating = false): int
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user,($kind === 'credit' ? 'supplier_credits.' : 'supplier_payment_corrections.') . $permission);
        $id = (int) $user['id'];
        if (!$this->app->users()->isOwner($id)) { Response::abort(403,'Supplier financial adjustments are Owner-only.'); }
        if ($mutating && (!is_string($_POST['_csrf'] ?? null) || !Session::verifyCsrf($_POST['_csrf']))) { Response::abort(419,'Your form has expired. Refresh and retry.'); } return $id;
    }
    private function respond(callable $fn): void
    {
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
        try { $body = ['success'=>true,'data'=>$fn()]; http_response_code(200); }
        catch (DomainException $e) { $body = ['success'=>false,'error'=>$e->getMessage()]; http_response_code(422); }
        echo json_encode($body,JSON_THROW_ON_ERROR);
    }
    private function id(mixed $id): int
    {
        if (!is_scalar($id) || filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) === false) { throw new DomainException('A valid identifier is required.'); } return (int) $id;
    }
    private function text(mixed $value): string { if (!is_string($value)) { throw new DomainException('A text value is required.'); } return $value; }
}
