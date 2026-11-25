<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Controller, Response, Session};
use App\Middleware\{AuthMiddleware, PermissionMiddleware};
use DomainException;

/** JSON HTTP boundary for the Phase 6.1 internal-transfer workflow. */
final class StockTransferController extends Controller
{
    public function list(): void
    {
        $actor = $this->authorize('view');
        $this->respond(fn (): array => $this->app->stockTransferService()->listForViewer(
            $actor, (string) ($_GET['status'] ?? 'all'), (int) ($_GET['page'] ?? 1), 25,
        ));
    }

    public function detail(): void
    {
        $actor = $this->authorize('view');
        $this->respond(fn (): array => $this->app->stockTransferService()->forViewer($actor, $this->id($_GET['transfer_id'] ?? null)));
    }

    public function create(): void
    {
        $actor = $this->authorize('request', true);
        $this->respond(fn (): array => $this->app->stockTransferService()->createDraft($actor, $_POST));
    }

    public function submit(): void
    {
        $actor = $this->authorize('request', true);
        $this->respond(fn (): array => $this->app->stockTransferService()->submit($actor, $this->id($_POST['transfer_id'] ?? null)));
    }

    public function approve(): void
    {
        $actor = $this->authorize('approve', true, true);
        $this->respond(fn (): array => $this->app->stockTransferService()->approve($actor, $this->id($_POST['transfer_id'] ?? null)));
    }

    public function confirm(): void
    {
        $actor = $this->authorize('confirm', true, true);
        $this->respond(fn (): array => $this->app->stockTransferService()->confirm($actor, $this->id($_POST['transfer_id'] ?? null)));
    }

    public function reject(): void
    {
        $this->terminate('reject');
    }

    public function cancel(): void
    {
        $this->terminate('cancel');
    }

    public function reconcile(): void
    {
        $actor = $this->authorize('reconcile', false, true);
        $this->respond(fn (): array => $this->app->stockTransferService()->reconcile($actor, $this->id($_GET['transfer_id'] ?? null)));
    }

    private function terminate(string $method): void
    {
        $actor = $this->authorize('approve', true, true);
        $this->respond(function () use ($actor, $method): array {
            $this->app->stockTransferService()->{$method}(
                $actor,
                $this->id($_POST['transfer_id'] ?? null),
                $this->text($_POST['reason'] ?? null),
            );
            return [$method => true];
        });
    }

    private function authorize(string $action, bool $write = false, bool $owner = false): int
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        (new PermissionMiddleware($this->app))->requirePermission($user, 'stock_transfers.' . $action);
        $actor = (int) $user['id'];
        if ($owner && !$this->app->users()->isOwner($actor)) {
            Response::abort(403, 'Only an Owner may perform this action.');
        }
        if ($write && (!is_string($_POST['_csrf'] ?? null) || !Session::verifyCsrf($_POST['_csrf']))) {
            Response::abort(419, 'Your form has expired. Refresh the page and try again.');
        }
        return $actor;
    }

    private function respond(callable $operation): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            $body = ['success' => true, 'data' => $operation()];
            http_response_code(200);
        } catch (DomainException $exception) {
            $body = ['success' => false, 'error' => $exception->getMessage()];
            http_response_code(422);
        }
        echo json_encode($body, JSON_THROW_ON_ERROR);
    }

    private function id(mixed $value): int
    {
        if (!is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new DomainException('A positive identifier is required.');
        }
        return (int) $value;
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new DomainException('Text input is required.');
        }
        return $value;
    }
}
