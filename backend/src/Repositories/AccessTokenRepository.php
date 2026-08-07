<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AppDbConnection;
use PDO;

final class AccessTokenRepository
{
    public function __construct(
        private readonly AppDbConnection $db,
    ) {
    }

    public function isValidForDocument(string $token, int $documentId): bool
    {
        if ($token === '') {
            return false;
        }

        $stmt = $this->db->getConnection()->prepare(
            'SELECT TOP 1 1
             FROM dbo.AccessTokens
             WHERE token = :token
               AND document_id = :document_id
               AND file_id IS NULL
               AND is_revoked = 0
               AND (expires_at IS NULL OR expires_at > GETDATE())'
        );
        $stmt->execute([
            'token' => $token,
            'document_id' => $documentId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    public function isValidForFile(string $token, int $documentId, int $fileId): bool
    {
        if ($token === '') {
            return false;
        }

        $stmt = $this->db->getConnection()->prepare(
            'SELECT TOP 1 1
             FROM dbo.AccessTokens
             WHERE token = :token
               AND document_id = :document_id
               AND file_id = :file_id
               AND is_revoked = 0
               AND (expires_at IS NULL OR expires_at > GETDATE())'
        );
        $stmt->execute([
            'token' => $token,
            'document_id' => $documentId,
            'file_id' => $fileId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Dosya için aktif token (is_revoked=0, süresi dolmamış).
     * Süresiz (expires_at NULL) tercih edilir.
     *
     * @return array{id:int,token:string,expires_at:?string,file_id:?int}|null
     */
    public function findActiveForFile(int $documentId, int $fileId): ?array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT TOP 1 id, token, expires_at, file_id
             FROM dbo.AccessTokens
             WHERE document_id = :document_id
               AND file_id = :file_id
               AND is_revoked = 0
               AND (expires_at IS NULL OR expires_at > GETDATE())
             ORDER BY CASE WHEN expires_at IS NULL THEN 0 ELSE 1 END, id ASC'
        );
        $stmt->execute([
            'document_id' => $documentId,
            'file_id' => $fileId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $expires = $row['expires_at'] ?? null;

        return [
            'id' => (int) $row['id'],
            'token' => (string) $row['token'],
            'expires_at' => $expires instanceof \DateTimeInterface
                ? $expires->format('Y-m-d H:i:s')
                : ($expires !== null ? (string) $expires : null),
            'file_id' => $row['file_id'] !== null ? (int) $row['file_id'] : null,
        ];
    }

    /**
     * Dosya için aktif süresiz token (expires_at NULL). Yoksa null.
     *
     * @return array{id:int,token:string,expires_at:?string,file_id:?int}|null
     */
    public function findPermanentActiveForFile(int $documentId, int $fileId): ?array
    {
        $found = $this->findActiveForFile($documentId, $fileId);
        if ($found === null || $found['expires_at'] !== null) {
            return null;
        }

        return $found;
    }

    /**
     * expires_at: NULL = süresiz; ttlMinutes > 0 ise SQL DATEADD ile süre.
     *
     * @return array{id:int,token:string,expires_at:?string,file_id:?int}
     */
    public function create(
        int $documentId,
        ?int $fileId,
        string $token,
        ?int $ttlMinutes = null,
    ): array {
        if ($ttlMinutes !== null && $ttlMinutes > 0) {
            $stmt = $this->db->getConnection()->prepare(
                'INSERT INTO dbo.AccessTokens (document_id, file_id, token, expires_at, is_revoked)
                 OUTPUT INSERTED.id, INSERTED.token, INSERTED.expires_at, INSERTED.file_id
                 VALUES (
                     :document_id,
                     :file_id,
                     :token,
                     DATEADD(MINUTE, :ttl_minutes, GETDATE()),
                     0
                 )'
            );
            $stmt->execute([
                'document_id' => $documentId,
                'file_id' => $fileId,
                'token' => $token,
                'ttl_minutes' => $ttlMinutes,
            ]);
        } else {
            $stmt = $this->db->getConnection()->prepare(
                'INSERT INTO dbo.AccessTokens (document_id, file_id, token, expires_at, is_revoked)
                 OUTPUT INSERTED.id, INSERTED.token, INSERTED.expires_at, INSERTED.file_id
                 VALUES (:document_id, :file_id, :token, NULL, 0)'
            );
            $stmt->execute([
                'document_id' => $documentId,
                'file_id' => $fileId,
                'token' => $token,
            ]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('AccessToken oluşturulamadı');
        }

        $expires = $row['expires_at'] ?? null;

        return [
            'id' => (int) $row['id'],
            'token' => (string) $row['token'],
            'expires_at' => $expires instanceof \DateTimeInterface
                ? $expires->format('Y-m-d H:i:s')
                : ($expires !== null ? (string) $expires : null),
            'file_id' => $row['file_id'] !== null ? (int) $row['file_id'] : null,
        ];
    }
}
