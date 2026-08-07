<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AppDbConnection;
use PDO;

final class DocumentRepository
{
    public function __construct(
        private readonly AppDbConnection $db,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(string $uid): ?array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id, uid, firma_no, donem_no, cari_kod, belge_tipi, belge_no,
                    logo_logicalref, status, notes, created_by, created_at
             FROM dbo.Documents
             WHERE uid = :uid'
        );
        $stmt->execute(['uid' => $uid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalize($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByLogoMatch(
        int $firmaNo,
        int $donemNo,
        string $belgeTipi,
        int $logoLogicalref,
    ): ?array {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id, uid, firma_no, donem_no, cari_kod, belge_tipi, belge_no,
                    logo_logicalref, status, notes, created_by, created_at
             FROM dbo.Documents
             WHERE firma_no = :firma_no
               AND donem_no = :donem_no
               AND belge_tipi = :belge_tipi
               AND logo_logicalref = :logo_logicalref'
        );
        $stmt->execute([
            'firma_no' => $firmaNo,
            'donem_no' => $donemNo,
            'belge_tipi' => $belgeTipi,
            'logo_logicalref' => $logoLogicalref,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->normalize($row);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(
        int $firmaNo,
        int $donemNo,
        string $cariKod,
        string $belgeTipi,
        int $logoLogicalref,
        string $belgeNo,
        int $createdBy,
        string $status = 'matched',
    ): array {
        $stmt = $this->db->getConnection()->prepare(
            'INSERT INTO dbo.Documents
                (firma_no, donem_no, cari_kod, belge_tipi, belge_no, logo_logicalref, status, created_by)
             OUTPUT INSERTED.id, INSERTED.uid, INSERTED.firma_no, INSERTED.donem_no,
                    INSERTED.cari_kod, INSERTED.belge_tipi, INSERTED.belge_no,
                    INSERTED.logo_logicalref, INSERTED.status, INSERTED.notes,
                    INSERTED.created_by, INSERTED.created_at
             VALUES
                (:firma_no, :donem_no, :cari_kod, :belge_tipi, :belge_no, :logo_logicalref, :status, :created_by)'
        );

        $stmt->execute([
            'firma_no' => $firmaNo,
            'donem_no' => $donemNo,
            'cari_kod' => $cariKod,
            'belge_tipi' => $belgeTipi,
            'belge_no' => $belgeNo,
            'logo_logicalref' => $logoLogicalref,
            'status' => $status,
            'created_by' => $createdBy,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Documents kaydı oluşturulamadı');
        }

        return $this->normalize($row);
    }

    public function updateNotes(int $documentId, string $notes): void
    {
        $stmt = $this->db->getConnection()->prepare(
            'UPDATE dbo.Documents
             SET notes = :notes
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $documentId,
            'notes' => $notes,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $uid = $row['uid'] ?? '';
        if ($uid instanceof \Stringable) {
            $uid = (string) $uid;
        }

        return [
            'id' => (int) $row['id'],
            'uid' => strtolower((string) $uid),
            'firma_no' => (int) $row['firma_no'],
            'donem_no' => (int) $row['donem_no'],
            'cari_kod' => (string) $row['cari_kod'],
            'belge_tipi' => (string) $row['belge_tipi'],
            'belge_no' => (string) ($row['belge_no'] ?? ''),
            'logo_logicalref' => (int) $row['logo_logicalref'],
            'status' => (string) $row['status'],
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : '',
            'created_by' => (int) $row['created_by'],
            'created_at' => isset($row['created_at']) && $row['created_at'] instanceof \DateTimeInterface
                ? $row['created_at']->format('Y-m-d H:i:s')
                : (string) ($row['created_at'] ?? ''),
        ];
    }
}
