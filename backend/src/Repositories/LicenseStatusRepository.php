<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AppDbConnection;
use PDO;

final class LicenseStatusRepository
{
    public function __construct(
        private readonly AppDbConnection $db,
    ) {
    }

    /**
     * @return array{
     *   is_valid:bool,
     *   last_checked_at:?string,
     *   last_successful_check_at:?string,
     *   bitis_tarihi:?string,
     *   max_users:?int
     * }
     */
    public function get(): array
    {
        $this->ensureRow();

        $stmt = $this->db->getConnection()->query(
            'SELECT TOP 1 is_valid, last_checked_at, last_successful_check_at, bitis_tarihi, max_users
             FROM dbo.LicenseStatus
             WHERE id = 1'
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return $this->defaultRow();
        }

        return $this->normalize($row);
    }

    public function markNetworkAttempt(): void
    {
        $this->ensureRow();

        $this->db->getConnection()->exec(
            'UPDATE dbo.LicenseStatus
             SET last_checked_at = GETDATE()
             WHERE id = 1'
        );
    }

    public function markSuccessfulCheck(bool $isValid, ?string $bitisTarihi, ?int $maxUsers): void
    {
        $this->ensureRow();

        $maxSql = $maxUsers === null ? 'NULL' : ':max_users';
        $stmt = $this->db->getConnection()->prepare(
            "UPDATE dbo.LicenseStatus
             SET is_valid = :is_valid,
                 bitis_tarihi = :bitis_tarihi,
                 max_users = {$maxSql},
                 last_checked_at = GETDATE(),
                 last_successful_check_at = GETDATE()
             WHERE id = 1"
        );
        $params = [
            'is_valid' => $isValid ? 1 : 0,
            'bitis_tarihi' => $bitisTarihi,
        ];
        if ($maxUsers !== null) {
            $params['max_users'] = $maxUsers;
        }
        $stmt->execute($params);
    }

    /**
     * Yazma izinli mi?
     * - is_valid=0 → hayır (panel geçerli yanıt verdiğinde hemen kilit)
     * - is_valid=1 ve son başarılı kontrol yok veya ≤3 gün → evet (kurulum / ağ toleransı)
     * - is_valid=1 ama son başarılı kontrol >3 gün → hayır (bayat güven)
     */
    public function allowsWrites(int $graceDays = 3): bool
    {
        $row = $this->get();
        if (!$row['is_valid']) {
            return false;
        }

        $lastOk = $row['last_successful_check_at'];
        if ($lastOk === null || $lastOk === '') {
            return true;
        }

        try {
            $dt = new \DateTimeImmutable($lastOk);
        } catch (\Throwable) {
            return false;
        }

        $cutoff = (new \DateTimeImmutable('now'))->modify(sprintf('-%d days', max(1, $graceDays)));

        return $dt >= $cutoff;
    }

    private function ensureRow(): void
    {
        $exists = $this->db->getConnection()->query(
            'SELECT TOP 1 1 FROM dbo.LicenseStatus WHERE id = 1'
        )->fetchColumn();

        if ($exists !== false) {
            return;
        }

        $this->db->getConnection()->exec(
            'INSERT INTO dbo.LicenseStatus (id, is_valid) VALUES (1, 1)'
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   is_valid:bool,
     *   last_checked_at:?string,
     *   last_successful_check_at:?string,
     *   bitis_tarihi:?string,
     *   max_users:?int
     * }
     */
    private function normalize(array $row): array
    {
        return [
            'is_valid' => $this->toBool($row['is_valid'] ?? false),
            'last_checked_at' => $this->formatDateTime($row['last_checked_at'] ?? null),
            'last_successful_check_at' => $this->formatDateTime($row['last_successful_check_at'] ?? null),
            'bitis_tarihi' => $this->formatDate($row['bitis_tarihi'] ?? null),
            'max_users' => $this->formatIntOrNull($row['max_users'] ?? null),
        ];
    }

    /**
     * @return array{
     *   is_valid:bool,
     *   last_checked_at:?string,
     *   last_successful_check_at:?string,
     *   bitis_tarihi:?string,
     *   max_users:?int
     * }
     */
    private function defaultRow(): array
    {
        return [
            'is_valid' => true,
            'last_checked_at' => null,
            'last_successful_check_at' => null,
            'bitis_tarihi' => null,
            'max_users' => null,
        ];
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $s = strtolower(trim((string) $value));

        return in_array($s, ['1', 'true', 'yes'], true);
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function formatIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            return (int) $value;
        }

        return null;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $s = (string) $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s, $m)) {
            return substr($m[0], 0, 10);
        }

        return $s;
    }
}
