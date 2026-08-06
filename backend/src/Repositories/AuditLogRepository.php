<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AppDbConnection;

final class AuditLogRepository
{
    public function __construct(
        private readonly AppDbConnection $db,
    ) {
    }

    public function log(
        string $action,
        ?int $userId = null,
        ?int $documentId = null,
        ?string $ipAddress = null,
    ): void {
        $stmt = $this->db->getConnection()->prepare(
            'INSERT INTO dbo.AuditLog (user_id, document_id, action, ip_address)
             VALUES (:user_id, :document_id, :action, :ip_address)'
        );

        $stmt->execute([
            'user_id' => $userId,
            'document_id' => $documentId,
            'action' => $action,
            'ip_address' => $ipAddress,
        ]);
    }
}
