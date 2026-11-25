<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class AuditLog
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(
        ?int $actorId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?string $reason = null,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, reason)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$actorId, $action, $entityType, $entityId, $reason]);
    }
}
