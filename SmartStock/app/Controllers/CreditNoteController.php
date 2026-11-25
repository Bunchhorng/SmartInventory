<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\Session;
use App\Middleware\AuthMiddleware;
use DomainException;

final class CreditNoteController extends Controller
{
    public function listCreditNotes(): void
    {
        $user = $this->requireOwner();
        $actorId = (int) $user['id'];
        $this->render('credit-notes/register', [
            'title' => 'Credit notes and refunds',
            'creditNotes' => $this->app->creditNoteService()->creditNotesForOwner($actorId),
            'refunds' => $this->app->creditNoteService()->refundsForOwner($actorId),
            'paymentMethods' => $this->app->payments()->activeMethods(),
            'creditNoteKey' => bin2hex(random_bytes(16)),
            'refundKey' => bin2hex(random_bytes(16)),
            'navigation' => $this->navigation($user),
        ]);
    }

    public function createCreditNote(): void
    {
        $this->perform(fn (int $actorId) => $this->app->creditNoteService()->createDraft($actorId, $_POST), 'Credit-note draft created.', 'credit-notes');
    }

    public function approveCreditNote(): void
    {
        $this->perform(fn (int $actorId) => $this->app->creditNoteService()->approve($actorId, $this->postId('credit_note_id')), 'Credit note approved.', 'credit-notes');
    }

    public function postCreditNote(): void
    {
        $this->perform(fn (int $actorId) => $this->app->creditNoteService()->post($actorId, $this->postId('credit_note_id')), 'Credit note posted without inventory or COGS movement.', 'credit-notes');
    }

    public function reverseCreditNote(): void
    {
        $this->perform(fn (int $actorId) => $this->app->creditNoteService()->reverse($actorId, $this->postId('credit_note_id'), (string) ($_POST['reason'] ?? '')), 'Credit note reversed with a compensating entry.', 'credit-notes');
    }

    public function createRefund(): void
    {
        $this->perform(fn (int $actorId) => $this->app->creditNoteService()->createRefundDraft($actorId, $_POST), 'Refund draft created.', 'credit-notes');
    }

    public function approveRefund(): void
    {
        $this->perform(fn (int $actorId) => $this->app->creditNoteService()->approveRefund($actorId, $this->postId('refund_id')), 'Refund approved and reserved against customer credit.', 'credit-notes');
    }

    public function postRefund(): void
    {
        $this->perform(fn (int $actorId) => $this->app->creditNoteService()->postRefund($actorId, $this->postId('refund_id')), 'Refund released. Bank and KHQR refunds require manual verification.', 'credit-notes');
    }

    public function verifyRefund(): void
    {
        $this->perform(
            fn (int $actorId) => $this->app->creditNoteService()->verifyRefund(
                $actorId,
                $this->postId('refund_id'),
                ($_POST['approved'] ?? '') === '1',
                (string) ($_POST['reason'] ?? ''),
            ),
            'Refund verification recorded.',
            'credit-notes',
        );
    }

    public function reversePendingRefund(): void
    {
        $this->perform(fn (int $actorId) => $this->app->creditNoteService()->reversePendingRefund($actorId, $this->postId('refund_id'), (string) ($_POST['reason'] ?? '')), 'Pending refund reversed.', 'credit-notes');
    }

    /** @param callable(int):mixed $operation */
    private function perform(callable $operation, string $success, string $route): void
    {
        $user = $this->requireOwner();
        $this->requireCsrf();
        try {
            $operation((int) $user['id']);
            Session::flash('success', $success);
        } catch (DomainException $exception) {
            Session::flash('error', $exception->getMessage());
        }
        $this->redirect($route);
    }

    /** @return array<string, mixed> */
    private function requireOwner(): array
    {
        $user = (new AuthMiddleware($this->app))->requireUser();
        if (!$this->app->users()->isOwner((int) $user['id'])) {
            Response::abort(403, 'Only an Owner can manage credit notes and refunds.');
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

    /** @param array<string, mixed> $user @return list<array{route: string, label: string}> */
    private function navigation(array $user): array
    {
        return [
            ['route' => 'dashboard', 'label' => 'Dashboard'],
            ['route' => 'products', 'label' => 'Products'],
            ['route' => 'inventory-receipts', 'label' => 'Stock receipts'],
            ['route' => 'sales', 'label' => 'Sales'],
            ['route' => 'payments', 'label' => 'Payments'],
            ['route' => 'credit-notes', 'label' => 'Credit notes'],
            ['route' => 'categories', 'label' => 'Categories'],
            ['route' => 'units', 'label' => 'Units'],
            ['route' => 'staff-create', 'label' => 'Staff accounts'],
            ['route' => 'change-password', 'label' => 'Password'],
        ];
    }
}
