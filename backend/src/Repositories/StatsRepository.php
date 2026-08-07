<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AppDbConnection;
use PDO;

final class StatsRepository
{
    public function __construct(
        private readonly AppDbConnection $db,
    ) {
    }

    /**
     * @return array{signed_document: int, photo: int, attachment: int, total: int, total_size_bytes: int}
     */
    public function countTodayByFileType(): array
    {
        $counts = [
            'signed_document' => 0,
            'photo' => 0,
            'attachment' => 0,
        ];

        $pdo = $this->db->getConnection();

        $stmt = $pdo->query(
            "SELECT file_type, COUNT(*) AS adet
             FROM dbo.DocumentFiles
             WHERE is_deleted = 0
               AND uploaded_at >= CAST(CAST(GETDATE() AS DATE) AS DATETIME)
               AND uploaded_at < DATEADD(day, 1, CAST(CAST(GETDATE() AS DATE) AS DATETIME))
             GROUP BY file_type"
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $type = (string) ($row['file_type'] ?? '');
            if (array_key_exists($type, $counts)) {
                $counts[$type] = (int) $row['adet'];
            }
        }

        $counts['total'] = $counts['signed_document'] + $counts['photo'] + $counts['attachment'];
        $counts['total_size_bytes'] = $this->sumFileSizeBytes(true);

        return $counts;
    }

    /**
     * @return array{documents: int, signed_document: int, photo: int, attachment: int, total_size_bytes: int}
     */
    public function countTotals(): array
    {
        $pdo = $this->db->getConnection();

        $documents = (int) $pdo->query('SELECT COUNT(*) FROM dbo.Documents')->fetchColumn();

        $signed = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM dbo.DocumentFiles
             WHERE file_type = 'signed_document'
               AND is_current = 1
               AND is_deleted = 0"
        )->fetchColumn();

        $photo = 0;
        $attachment = 0;
        $stmt = $pdo->query(
            "SELECT file_type, COUNT(*) AS adet
             FROM dbo.DocumentFiles
             WHERE file_type IN ('photo', 'attachment')
               AND is_deleted = 0
             GROUP BY file_type"
        );

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $type = (string) ($row['file_type'] ?? '');
            if ($type === 'photo') {
                $photo = (int) $row['adet'];
            } elseif ($type === 'attachment') {
                $attachment = (int) $row['adet'];
            }
        }

        return [
            'documents' => $documents,
            'signed_document' => $signed,
            'photo' => $photo,
            'attachment' => $attachment,
            'total_size_bytes' => $this->sumFileSizeBytes(false),
        ];
    }

    /**
     * Disk kullanımı: is_deleted=0 tüm tipler ve signed versiyon geçmişi dahil.
     */
    private function sumFileSizeBytes(bool $todayOnly): int
    {
        $sql = 'SELECT COALESCE(SUM(CAST(file_size AS BIGINT)), 0)
                FROM dbo.DocumentFiles
                WHERE is_deleted = 0';

        if ($todayOnly) {
            $sql .= ' AND uploaded_at >= CAST(CAST(GETDATE() AS DATE) AS DATETIME)
                      AND uploaded_at < DATEADD(day, 1, CAST(CAST(GETDATE() AS DATE) AS DATETIME))';
        }

        return (int) $this->db->getConnection()->query($sql)->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentUploads(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        $stmt = $this->db->getConnection()->query(
            "SELECT TOP ({$limit})
                    df.id,
                    df.file_type,
                    df.uploaded_at,
                    d.belge_no,
                    d.cari_kod,
                    u.full_name AS uploaded_by_name
             FROM dbo.DocumentFiles df
             INNER JOIN dbo.Documents d ON df.document_id = d.id
             INNER JOIN dbo.Users u ON df.uploaded_by = u.id
             WHERE df.is_deleted = 0
             ORDER BY df.uploaded_at DESC"
        );

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $uploadedAt = $row['uploaded_at'] ?? null;

            return [
                'id' => (int) $row['id'],
                'file_type' => (string) $row['file_type'],
                'belge_no' => $row['belge_no'] !== null ? (string) $row['belge_no'] : null,
                'cari_kod' => (string) ($row['cari_kod'] ?? ''),
                'uploaded_by_name' => (string) ($row['uploaded_by_name'] ?? ''),
                'uploaded_at' => $uploadedAt instanceof \DateTimeInterface
                    ? $uploadedAt->format('Y-m-d H:i:s')
                    : (string) ($uploadedAt ?? ''),
            ];
        }, $rows);
    }

    public function countActiveUsers(): int
    {
        return (int) $this->db->getConnection()->query(
            'SELECT COUNT(*) FROM dbo.Users WHERE is_active = 1'
        )->fetchColumn();
    }
}
