<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Controller,Response,Session};
use App\Middleware\{AuthMiddleware,PermissionMiddleware};
use DomainException;

final class PurchaseReturnController extends Controller
{
    public function listReturns(): void
    {
        $actor=$this->authorize('view');
        $this->respond(fn()=>$this->app->purchaseReturnService()->listForViewer($actor,$this->id($_GET['purchase_id']??null),$this->id($_GET['page']??1)));
    }
    public function detail(): void
    {
        $actor=$this->authorize('view'); $this->respond(fn()=>$this->app->purchaseReturnService()->forViewer($actor,$this->id($_GET['return_id']??null)));
    }
    public function createDraft(): void
    {
        $actor=$this->authorize('request',true); $this->respond(fn()=>$this->app->purchaseReturnService()->createDraft($actor,$_POST));
    }
    public function submit(): void
    {
        $actor=$this->authorize('request',true); $this->respond(fn()=>$this->app->purchaseReturnService()->submit($actor,$this->id($_POST['return_id']??null)));
    }
    public function approve(): void
    {
        $actor=$this->authorize('approve',true,true); $this->respond(fn()=>$this->app->purchaseReturnService()->approve($actor,$this->id($_POST['return_id']??null)));
    }
    public function confirm(): void
    {
        $actor=$this->authorize('confirm',true,true);
        $this->respond(fn()=>$this->app->purchaseReturnService()->confirm($actor,$this->id($_POST['return_id']??null),$this->text($_POST['dispatch_reference']??null)));
    }
    public function reject(): void { $this->terminate('reject'); }
    public function cancel(): void { $this->terminate('cancel'); }
    private function terminate(string $method): void
    {
        $actor=$this->authorize('approve',true,true);
        $this->respond(function () use ($actor,$method): array {
            $this->app->purchaseReturnService()->$method($actor,$this->id($_POST['return_id']??null),$this->text($_POST['reason']??null)); return [$method=>true];
        });
    }
    public function linkCredit(): void
    {
        $actor=$this->authorize('link_credit',true,true);
        $this->respond(fn()=>$this->app->purchaseReturnService()->linkCredit($actor,$this->id($_POST['return_id']??null),$_POST));
    }
    public function reconcile(): void
    {
        $actor=$this->authorize('reconcile',false,true);
        $this->respond(fn()=>$this->app->purchaseReturnService()->reconcile($actor,$this->id($_GET['purchase_id']??null)));
    }
    private function authorize(string $action, bool $write=false, bool $owner=false): int
    {
        $user=(new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user,'purchase_returns.'.$action); $actor=(int)$user['id'];
        if ($owner && !$this->app->users()->isOwner($actor)) { Response::abort(403,'Only an Owner may perform this action.'); }
        if ($write && (!is_string($_POST['_csrf']??null) || !Session::verifyCsrf($_POST['_csrf']))) { Response::abort(419,'Your form has expired. Refresh the page and try again.'); }
        return $actor;
    }
    private function respond(callable $fn): void
    {
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
        try { $body=['success'=>true,'data'=>$fn()]; http_response_code(200); }
        catch (DomainException $e) { $body=['success'=>false,'error'=>$e->getMessage()]; http_response_code(422); }
        echo json_encode($body,JSON_THROW_ON_ERROR);
    }
    private function id(mixed $id): int
    {
        if (!is_scalar($id) || filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false) { throw new DomainException('A positive identifier is required.'); } return (int)$id;
    }
    private function text(mixed $value): string { if (!is_string($value)) { throw new DomainException('Text input is required.'); } return $value; }
}
