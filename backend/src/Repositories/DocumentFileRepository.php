<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AppDbConnection;
use PDO;

final class DocumentFileRepository
{
    public const FILE_TYPES = ['photo', 'attachment', 'signed_document'];

    private const SELECT_COLS = 'id, document_id, file_path, file_type, version, is_current,
                       original_name, file_size, uploaded_by, uploaded_at,
                       is_deleted, deleted_at, deleted_by';

    private const SELECT_COLS_ALIASED = 'df.id, df.document_id, df.file_path, df.file_type, df.version, df.is_current,
                       df.original_name, df.file_size, df.uploaded_by, df.uploaded_at,
                       df.is_deleted, df.deleted_at, df.deleted_by,
                       u.full_name AS uploaded_by_name';

    public function __construct(
        private readonly AppDbConnection $db,
    ) {
    }

    public function getMaxSignedVersion(int $documentId): int
    {
        $stmt = $this->db->getConnection()->prepare(
            "SELECT ISNULL(MAX(version), 0)
             FROM dbo.DocumentFiles
             WHERE document_id = :document_id
               AND file_type = 'signed_document'
               AND is_deleted = 0"
        );
        $stmt->execute(['document_id' => $documentId]);

        return (int) $stmt->fetchColumn();
    }

    public function markSignedDocumentsNotCurrent(int $documentId): void
    {
        $stmt = $this->db->getConnection()->prepare(
            "UPDATE dbo.DocumentFiles
             SET is_current = 0
             WHERE document_id = :document_id
               AND file_type = 'signed_document'
               AND is_current = 1
               AND is_deleted = 0"
        );
        $stmt->execute(['document_id' => $documentId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(
        int $documentId,
        string $filePath,
        string $fileType,
        ?int $version,
        bool $isCurrent,
        string $originalName,
        int $fileSize,
        int $uploadedBy,
    ): array {
        $stmt = $this->db->getConnection()->prepare(
            'INSERT INTO dbo.DocumentFiles
                (document_id, file_path, file_type, version, is_current,
                 original_name, file_size, uploaded_by)
             OUTPUT INSERTED.id, INSERTED.document_id, INSERTED.file_path,
                    INSERTED.file_type, INSERTED.version, INSERTED.is_current,
                    INSERTED.original_name, INSERTED.file_size,
                    INSERTED.uploaded_by, INSERTED.uploaded_at,
                    INSERTED.is_deleted, INSERTED.deleted_at, INSERTED.deleted_by
             VALUES
                (:document_id, :file_path, :file_type, :version, :is_current,
                 :original_name, :file_size, :uploaded_by)'
        );

        $stmt->execute([
            'document_id' => $documentId,
            'file_path' => $filePath,
            'file_type' => $fileType,
            'version' => $version,
            'is_current' => $isCurrent ? 1 : 0,
            'original_name' => $originalName,
            'file_size' => $fileSize,
            'uploaded_by' => $uploadedBy,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('DocumentFiles kaydı oluşturulamadı');
        }

        return $this->normalize($row);
    }

    /**
     * Soft delete. Diskteki dosyaya dokunulmaz.
     *
     * @return bool true = güncellendi, false = zaten silinmiş veya yok
     */
    public function softDelete(int $fileId, int $deletedBy): bool
    {
        $stmt = $this->db->getConnection()->prepare(
            "UPDATE dbo.DocumentFiles
             SET is_deleted = 1,
                 deleted_at = GETDATE(),
                 deleted_by = :deleted_by,
                 is_current = CASE
                     WHEN file_type = 'signed_document' THEN 0
                     ELSE is_current
                 END
             WHERE id = :id
               AND is_deleted = 0"
        );
        $stmt->execute([
            'id' => $fileId,
            'deleted_by' => $deletedBy,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Silme sonrası en yüksek versiyonlu imzalı belgeyi güncel yapar.
     */
    public function promoteLatestSignedCurrent(int $documentId): void
    {
        $pdo = $this->db->getConnection();

        $check = $pdo->prepare(
            "SELECT TOP 1 id
             FROM dbo.DocumentFiles
             WHERE document_id = :document_id
               AND file_type = 'signed_document'
               AND is_deleted = 0
               AND is_current = 1"
        );
        $check->execute(['document_id' => $documentId]);
        if ($check->fetchColumn() !== false) {
            return;
        }

        $pick = $pdo->prepare(
            "SELECT TOP 1 id
             FROM dbo.DocumentFiles
             WHERE document_id = :document_id
               AND file_type = 'signed_document'
               AND is_deleted = 0
             ORDER BY version DESC, id DESC"
        );
        $pick->execute(['document_id' => $documentId]);
        $id = $pick->fetchColumn();
        if ($id === false) {
            return;
        }

        $stmt = $pdo->prepare(
            'UPDATE dbo.DocumentFiles
             SET is_current = 1
             WHERE id = :id
               AND is_deleted = 0'
        );
        $stmt->execute(['id' => (int) $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForDocument(int $documentId, bool $allSignedVersions = false): array
    {
        $sql = 'SELECT ' . self::SELECT_COLS_ALIASED . '
                FROM dbo.DocumentFiles df
                LEFT JOIN dbo.Users u ON u.id = df.uploaded_by
                WHERE df.document_id = :document_id
                  AND df.is_deleted = 0
                  AND (
                        df.file_type IN (\'photo\', \'attachment\')
                        OR (
                            df.file_type = \'signed_document\'
                            AND (:all_versions = 1 OR df.is_current = 1)
                        )
                  )
                ORDER BY
                    CASE df.file_type
                        WHEN \'photo\' THEN 1
                        WHEN \'attachment\' THEN 2
                        WHEN \'signed_document\' THEN 3
                        ELSE 4
                    END,
                    df.version ASC,
                    df.id ASC';

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute([
            'document_id' => $documentId,
            'all_versions' => $allSignedVersions ? 1 : 0,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'normalize'], $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForDocument(int $fileId, int $documentId): ?array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT TOP 1 ' . self::SELECT_COLS . '
             FROM dbo.DocumentFiles
             WHERE id = :id
               AND document_id = :document_id'
        );
        $stmt->execute([
            'id' => $fileId,
            'document_id' => $documentId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalize($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByIdForDocument(int $fileId, int $documentId): ?array
    {
        $file = $this->findByIdForDocument($fileId, $documentId);
        if ($file === null || !empty($file['is_deleted'])) {
            return null;
        }

        return $file;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCurrentSigned(int $documentId): ?array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT TOP 1 ' . self::SELECT_COLS . "
             FROM dbo.DocumentFiles
             WHERE document_id = :document_id
               AND file_type = 'signed_document'
               AND is_current = 1
               AND is_deleted = 0
             ORDER BY version DESC, id DESC"
        );
        $stmt->execute(['document_id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalize($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSignedByVersion(int $documentId, int $version): ?array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT TOP 1 ' . self::SELECT_COLS . "
             FROM dbo.DocumentFiles
             WHERE document_id = :document_id
               AND file_type = 'signed_document'
               AND version = :version
               AND is_deleted = 0"
        );
        $stmt->execute([
            'document_id' => $documentId,
            'version' => $version,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalize($row);
    }

    public function beginTransaction(): void
    {
        $this->db->getConnection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->getConnection()->commit();
    }

    public function rollBack(): void
    {
        $pdo = $this->db->getConnection();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $name = isset($row['uploaded_by_name']) ? trim((string) $row['uploaded_by_name']) : '';

        return [
            'id' => (int) $row['id'],
            'document_id' => (int) $row['document_id'],
            'file_path' => (string) $row['file_path'],
            'file_type' => (string) $row['file_type'],
            'version' => $row['version'] === null ? null : (int) $row['version'],
            'is_current' => UserRepository::isTruthy($row['is_current'] ?? false),
            'original_name' => (string) $row['original_name'],
            'file_size' => (int) $row['file_size'],
            'uploaded_by' => (int) $row['uploaded_by'],
            'uploaded_by_name' => $name !== '' ? $name : null,
            'uploaded_at' => isset($row['uploaded_at']) && $row['uploaded_at'] instanceof \DateTimeInterface
                ? $row['uploaded_at']->format('Y-m-d H:i:s')
                : (string) ($row['uploaded_at'] ?? ''),
            'is_deleted' => UserRepository::isTruthy($row['is_deleted'] ?? false),
            'deleted_at' => isset($row['deleted_at']) && $row['deleted_at'] instanceof \DateTimeInterface
                ? $row['deleted_at']->format('Y-m-d H:i:s')
                : ($row['deleted_at'] !== null ? (string) $row['deleted_at'] : null),
            'deleted_by' => $row['deleted_by'] !== null ? (int) $row['deleted_by'] : null,
        ];
    }
}
