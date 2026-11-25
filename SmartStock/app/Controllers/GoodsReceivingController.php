<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Controller, Response, Session};
use App\Middleware\{AuthMiddleware, PermissionMiddleware};
use DomainException;

/** Backend JSON endpoints. Form-encoded POST requests use the existing CSRF token. */
final class GoodsReceivingController extends Controller
{
    public function listReceipts(): void
    {
        $actorId = $this->authorize('purchases.view');
        $this->respond(fn () => $this->app->goodsReceiving()->listForViewer($actorId,
            $this->id($_GET['purchase_id'] ?? null), $this->page($_GET['page'] ?? 1)));
    }

    public function showReceipt(): void
    {
        $actorId = $this->authorize('purchases.view');
        $this->respond(fn () => $this->app->goodsReceiving()->forViewer($actorId, $this->id($_GET['receipt_id'] ?? null)));
    }

    public function showPurchase(): void
    {
        $actorId = $this->authorize('purchases.view');
        $this->respond(fn () => $this->app->purchasingService()->forViewer($actorId, $this->id($_GET['purchase_id'] ?? null)));
    }

    public function createDraft(): void
    {
        $actorId = $this->authorize('purchases.receive', true);
        $this->respond(fn () => $this->app->goodsReceiving()->createDraft($actorId, $_POST));
    }

    public function confirm(): void
    {
        $actorId = $this->authorize('purchases.receive.confirm', true, true);
        $this->respond(fn () => $this->app->goodsReceiving()->confirm($actorId, $this->id($_POST['receipt_id'] ?? null)));
    }

    public function approveVariance(): void
    {
        $actorId = $this->authorize('purchases.cost_variance.approve', true, true);
        $this->respond(function () use ($actorId): array {
            $this->app->goodsReceiving()->approveCostVariance($actorId, $this->id($_POST['receipt_id'] ?? null),
                $this->id($_POST['receipt_item_id'] ?? null), $this->text($_POST['actual_cost_per_base_khr'] ?? ''), $this->text($_POST['reason'] ?? ''));
            return ['approved' => true];
        });
    }

    public function cancelDraft(): void
    {
        $actorId = $this->authorize('purchases.receive.confirm', true, true);
        $this->respond(function () use ($actorId): array {
            $this->app->goodsReceiving()->cancelDraft($actorId, $this->id($_POST['receipt_id'] ?? null), $this->text($_POST['reason'] ?? ''));
            return ['cancelled' => true];
        });
    }

    public function closePurchase(): void
    {
        $actorId = $this->authorize('purchases.close', true, true);
        $this->respond(fn () => $this->app->goodsReceiving()->close($actorId, $this->id($_POST['purchase_id'] ?? null)));
    }

    public function reconcile(): void
    {
        $actorId = $this->authorize('purchases.receive.confirm', false, true);
        $this->respond(fn () => $this->app->goodsReceiving()->reconcile($actorId, $this->id($_GET['purchase_id'] ?? null)));
    }

    private function authorize(string $permission, bool $mutating = false, bool $ownerOnly = false): int
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user, $permission);
        $actorId = (int) $user['id'];
        if ($ownerOnly && !$this->app->users()->isOwner($actorId)) { Response::abort(403, 'Only an Owner may perform this action.'); }
        if ($mutating && (!is_string($_POST['_csrf'] ?? null) || !Session::verifyCsrf($_POST['_csrf']))) {
            Response::abort(419, 'Your form has expired. Refresh the page and try again.');
        }
        return $actorId;
    }

    private function respond(callable $operation): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $body = ['success' => true, 'data' => $operation()];
            http_response_code(200);
        } catch (DomainException $e) {
            http_response_code(422);
            $body = ['success' => false, 'error' => $e->getMessage()];
        }
        echo json_encode($body, JSON_THROW_ON_ERROR);
    }

    private function id(mixed $id): int
    {
        if (!is_scalar($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new DomainException('A valid record identifier is required.');
        }
        return (int) $id;
    }

    private function page(mixed $page): int
    {
        return $this->id($page);
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) { throw new DomainException('A text value is required.'); }
        return $value;
    }
}
