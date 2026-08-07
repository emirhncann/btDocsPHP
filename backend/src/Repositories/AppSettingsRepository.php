<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AppDbConnection;
use PDO;

final class AppSettingsRepository
{
    public function __construct(
        private readonly AppDbConnection $db,
    ) {
    }

    /**
     * @return array{firma_unvani:?string,firma_adres:?string,firma_telefon:?string,updated_at:?string,updated_by:?int}
     */
    public function get(): array
    {
        $this->ensureRow();

        $stmt = $this->db->getConnection()->query(
            'SELECT TOP 1 firma_unvani, firma_adres, firma_telefon, updated_at, updated_by
             FROM dbo.AppSettings
             WHERE id = 1'
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return $this->emptySettings();
        }

        return $this->normalize($row);
    }

    /**
     * @return array{firma_unvani:?string,firma_adres:?string,firma_telefon:?string,updated_at:?string,updated_by:?int}
     */
    public function update(
        ?string $firmaUnvani,
        ?string $firmaAdres,
        ?string $firmaTelefon,
        int $updatedBy,
    ): array {
        $this->ensureRow();

        $stmt = $this->db->getConnection()->prepare(
            'UPDATE dbo.AppSettings
             SET firma_unvani = :firma_unvani,
                 firma_adres = :firma_adres,
                 firma_telefon = :firma_telefon,
                 updated_at = GETDATE(),
                 updated_by = :updated_by
             WHERE id = 1'
        );
        $stmt->execute([
            'firma_unvani' => $firmaUnvani,
            'firma_adres' => $firmaAdres,
            'firma_telefon' => $firmaTelefon,
            'updated_by' => $updatedBy,
        ]);

        return $this->get();
    }

    private function ensureRow(): void
    {
        $exists = $this->db->getConnection()->query(
            'SELECT TOP 1 1 FROM dbo.AppSettings WHERE id = 1'
        )->fetchColumn();

        if ($exists !== false) {
            return;
        }

        $stmt = $this->db->getConnection()->prepare(
            'INSERT INTO dbo.AppSettings (id, firma_unvani, firma_adres, firma_telefon)
             VALUES (1, NULL, NULL, NULL)'
        );
        $stmt->execute();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{firma_unvani:?string,firma_adres:?string,firma_telefon:?string,updated_at:?string,updated_by:?int}
     */
    private function normalize(array $row): array
    {
        $updatedAt = $row['updated_at'] ?? null;

        return [
            'firma_unvani' => $this->nullableString($row['firma_unvani'] ?? null),
            'firma_adres' => $this->nullableString($row['firma_adres'] ?? null),
            'firma_telefon' => $this->nullableString($row['firma_telefon'] ?? null),
            'updated_at' => $updatedAt instanceof \DateTimeInterface
                ? $updatedAt->format('Y-m-d H:i:s')
                : ($updatedAt !== null ? (string) $updatedAt : null),
            'updated_by' => isset($row['updated_by']) && $row['updated_by'] !== null
                ? (int) $row['updated_by']
                : null,
        ];
    }

    /**
     * @return array{firma_unvani:?string,firma_adres:?string,firma_telefon:?string,updated_at:?string,updated_by:?int}
     */
    private function emptySettings(): array
    {
        return [
            'firma_unvani' => null,
            'firma_adres' => null,
            'firma_telefon' => null,
            'updated_at' => null,
            'updated_by' => null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
