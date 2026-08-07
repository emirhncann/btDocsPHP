<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AppDbConnection;
use PDO;

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

    /**
     * @param array{
     *   user_id:?int,
     *   document_id:?int,
     *   action:?string,
     *   from:?string,
     *   to:?string,
     *   limit:int
     * } $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters): array
    {
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));

        $sql = 'SELECT TOP (' . $limit . ')
                    id, user_id, document_id, action, ip_address, created_at
                FROM dbo.AuditLog
                WHERE 1 = 1';
        $params = [];

        if (!empty($filters['user_id'])) {
            $sql .= ' AND user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }
        if (!empty($filters['document_id'])) {
            $sql .= ' AND document_id = :document_id';
            $params['document_id'] = (int) $filters['document_id'];
        }
        if (!empty($filters['action'])) {
            $sql .= ' AND action = :action';
            $params['action'] = (string) $filters['action'];
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND created_at >= :from_at';
            $params['from_at'] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND created_at <= :to_at';
            $params['to_at'] = (string) $filters['to'];
        }

        $sql .= ' ORDER BY id DESC';

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
                'document_id' => $row['document_id'] === null ? null : (int) $row['document_id'],
                'action' => (string) $row['action'],
                'ip_address' => $row['ip_address'] !== null ? (string) $row['ip_address'] : null,
                'created_at' => isset($row['created_at']) && $row['created_at'] instanceof \DateTimeInterface
                    ? $row['created_at']->format('Y-m-d H:i:s')
                    : (string) ($row['created_at'] ?? ''),
            ];
        }, $rows);
    }
}
