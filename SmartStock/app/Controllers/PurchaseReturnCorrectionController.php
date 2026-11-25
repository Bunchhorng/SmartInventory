<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\{Controller,Response,Session};
use App\Middleware\{AuthMiddleware,PermissionMiddleware};
use DomainException;

final class PurchaseReturnCorrectionController extends Controller
{
    public function list():void{$actor=$this->authorize('view');$this->respond(fn()=>$this->app->purchaseReturnCorrectionService()->listForViewer($actor,$this->id($_GET['purchase_return_id']??null)));}
    public function detail():void{$actor=$this->authorize('view');$this->respond(fn()=>$this->app->purchaseReturnCorrectionService()->forViewer($actor,$this->id($_GET['correction_id']??null)));}
    public function create():void{$actor=$this->authorize('request',true);$this->respond(fn()=>$this->app->purchaseReturnCorrectionService()->createDraft($actor,$_POST));}
    public function submit():void{$actor=$this->authorize('request',true);$this->respond(fn()=>$this->app->purchaseReturnCorrectionService()->submit($actor,$this->id($_POST['correction_id']??null)));}
    public function approve():void{$actor=$this->authorize('approve',true,true);$this->respond(fn()=>$this->app->purchaseReturnCorrectionService()->approve($actor,$this->id($_POST['correction_id']??null)));}
    public function post():void{$actor=$this->authorize('post',true,true);$this->respond(fn()=>$this->app->purchaseReturnCorrectionService()->post($actor,$this->id($_POST['correction_id']??null)));}
    public function reject():void{$this->terminate('reject');} public function cancel():void{$this->terminate('cancel');}
    private function terminate(string $method):void{$actor=$this->authorize('approve',true,true);$this->respond(function()use($actor,$method):array{$this->app->purchaseReturnCorrectionService()->$method($actor,$this->id($_POST['correction_id']??null),$this->text($_POST['reason']??null));return[$method=>true];});}
    public function reconcile():void{$actor=$this->authorize('reconcile',false,true);$this->respond(fn()=>$this->app->purchaseReturnCorrectionService()->reconcile($actor,$this->id($_GET['purchase_id']??null)));}
    private function authorize(string $action,bool $write=false,bool $owner=false):int{$user=(new AuthMiddleware($this->app))->requireUser();(new PermissionMiddleware($this->app))->requirePermission($user,'purchase_return_corrections.'.$action);$actor=(int)$user['id'];if($owner&&!$this->app->users()->isOwner($actor)){Response::abort(403,'Only an Owner may perform this action.');}if($write&&(!is_string($_POST['_csrf']??null)||!Session::verifyCsrf($_POST['_csrf']))){Response::abort(419,'Your form has expired. Refresh the page and try again.');}return$actor;}
    private function respond(callable $fn):void{header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');try{$body=['success'=>true,'data'=>$fn()];http_response_code(200);}catch(DomainException $e){$body=['success'=>false,'error'=>$e->getMessage()];http_response_code(422);}echo json_encode($body,JSON_THROW_ON_ERROR);}
    private function id(mixed $id):int{if(!is_scalar($id)||filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])===false){throw new DomainException('A positive identifier is required.');}return(int)$id;}
    private function text(mixed $v):string{if(!is_string($v)){throw new DomainException('Text input is required.');}return$v;}
}
