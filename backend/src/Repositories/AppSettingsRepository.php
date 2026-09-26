<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AppDbConnection;
use PDO;

final class AppSettingsRepository
{
    public const PASSWORD_MASK = '********';
    public const DEFAULT_SMS_API_URL = 'https://btdocs.boluteknoloji.tr/api/sms_gonder.php';

    public function __construct(
        private readonly AppDbConnection $db,
    ) {
    }

    /**
     * @return array{
     *   firma_unvani:?string,
     *   firma_adres:?string,
     *   firma_telefon:?string,
     *   firma_vkn:?string,
     *   firma_email:?string,
     *   firma_logo_path:?string,
     *   default_belge_tipi:string,
     *   sms_enabled:bool,
     *   netgsm_username:?string,
     *   netgsm_password:?string,
     *   netgsm_msgheader:?string,
     *   sms_api_url:?string,
     *   updated_at:?string,
     *   updated_by:?int
     * }
     */
    public function get(): array
    {
        $this->ensureRow();

        $stmt = $this->db->getConnection()->query(
            'SELECT TOP 1 firma_unvani, firma_adres, firma_telefon, firma_vkn, firma_email,
                    firma_logo_path, default_belge_tipi,
                    sms_enabled, netgsm_username, netgsm_password, netgsm_msgheader, sms_api_url,
                    updated_at, updated_by
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
     * @return array{
     *   firma_unvani:?string,
     *   firma_adres:?string,
     *   firma_telefon:?string,
     *   firma_vkn:?string,
     *   firma_email:?string,
     *   firma_logo_path:?string,
     *   default_belge_tipi:string,
     *   sms_enabled:bool,
     *   netgsm_username:?string,
     *   netgsm_password:?string,
     *   netgsm_msgheader:?string,
     *   sms_api_url:?string,
     *   updated_at:?string,
     *   updated_by:?int
     * }
     */
    public function update(
        ?string $firmaUnvani,
        ?string $firmaAdres,
        ?string $firmaTelefon,
        ?string $firmaVkn,
        ?string $firmaEmail,
        string $defaultBelgeTipi,
        bool $smsEnabled,
        ?string $netgsmUsername,
        ?string $netgsmPassword,
        bool $updateNetgsmPassword,
        ?string $netgsmMsgheader,
        ?string $smsApiUrl,
        int $updatedBy,
    ): array {
        $this->ensureRow();

        if ($updateNetgsmPassword) {
            $stmt = $this->db->getConnection()->prepare(
                'UPDATE dbo.AppSettings
                 SET firma_unvani = :firma_unvani,
                     firma_adres = :firma_adres,
                     firma_telefon = :firma_telefon,
                     firma_vkn = :firma_vkn,
                     firma_email = :firma_email,
                     default_belge_tipi = :default_belge_tipi,
                     sms_enabled = :sms_enabled,
                     netgsm_username = :netgsm_username,
                     netgsm_password = :netgsm_password,
                     netgsm_msgheader = :netgsm_msgheader,
                     sms_api_url = :sms_api_url,
                     updated_at = GETDATE(),
                     updated_by = :updated_by
                 WHERE id = 1'
            );
            $stmt->execute([
                'firma_unvani' => $firmaUnvani,
                'firma_adres' => $firmaAdres,
                'firma_telefon' => $firmaTelefon,
                'firma_vkn' => $firmaVkn,
                'firma_email' => $firmaEmail,
                'default_belge_tipi' => $defaultBelgeTipi,
                'sms_enabled' => $smsEnabled ? 1 : 0,
                'netgsm_username' => $netgsmUsername,
                'netgsm_password' => $netgsmPassword,
                'netgsm_msgheader' => $netgsmMsgheader,
                'sms_api_url' => $smsApiUrl,
                'updated_by' => $updatedBy,
            ]);
        } else {
            $stmt = $this->db->getConnection()->prepare(
                'UPDATE dbo.AppSettings
                 SET firma_unvani = :firma_unvani,
                     firma_adres = :firma_adres,
                     firma_telefon = :firma_telefon,
                     firma_vkn = :firma_vkn,
                     firma_email = :firma_email,
                     default_belge_tipi = :default_belge_tipi,
                     sms_enabled = :sms_enabled,
                     netgsm_username = :netgsm_username,
                     netgsm_msgheader = :netgsm_msgheader,
                     sms_api_url = :sms_api_url,
                     updated_at = GETDATE(),
                     updated_by = :updated_by
                 WHERE id = 1'
            );
            $stmt->execute([
                'firma_unvani' => $firmaUnvani,
                'firma_adres' => $firmaAdres,
                'firma_telefon' => $firmaTelefon,
                'firma_vkn' => $firmaVkn,
                'firma_email' => $firmaEmail,
                'default_belge_tipi' => $defaultBelgeTipi,
                'sms_enabled' => $smsEnabled ? 1 : 0,
                'netgsm_username' => $netgsmUsername,
                'netgsm_msgheader' => $netgsmMsgheader,
                'sms_api_url' => $smsApiUrl,
                'updated_by' => $updatedBy,
            ]);
        }

        return $this->get();
    }

    /**
     * @return array{
     *   firma_unvani:?string,
     *   firma_adres:?string,
     *   firma_telefon:?string,
     *   firma_vkn:?string,
     *   firma_email:?string,
     *   firma_logo_path:?string,
     *   default_belge_tipi:string,
     *   sms_enabled:bool,
     *   netgsm_username:?string,
     *   netgsm_password:?string,
     *   netgsm_msgheader:?string,
     *   sms_api_url:?string,
     *   updated_at:?string,
     *   updated_by:?int
     * }
     */
    public function updateLogoPath(?string $relativePath, int $updatedBy): array
    {
        $this->ensureRow();

        $stmt = $this->db->getConnection()->prepare(
            'UPDATE dbo.AppSettings
             SET firma_logo_path = :firma_logo_path,
                 updated_at = GETDATE(),
                 updated_by = :updated_by
             WHERE id = 1'
        );
        $stmt->execute([
            'firma_logo_path' => $relativePath,
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
            'INSERT INTO dbo.AppSettings
                (id, firma_unvani, firma_adres, firma_telefon, firma_vkn, firma_email, firma_logo_path,
                 default_belge_tipi, sms_enabled, netgsm_username, netgsm_password, netgsm_msgheader, sms_api_url)
             VALUES (1, NULL, NULL, NULL, NULL, NULL, NULL, \'tumu\', 0, NULL, NULL, NULL, NULL)'
        );
        $stmt->execute();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   firma_unvani:?string,
     *   firma_adres:?string,
     *   firma_telefon:?string,
     *   firma_vkn:?string,
     *   firma_email:?string,
     *   firma_logo_path:?string,
     *   default_belge_tipi:string,
     *   sms_enabled:bool,
     *   netgsm_username:?string,
     *   netgsm_password:?string,
     *   netgsm_msgheader:?string,
     *   sms_api_url:?string,
     *   updated_at:?string,
     *   updated_by:?int
     * }
     */
    private function normalize(array $row): array
    {
        $updatedAt = $row['updated_at'] ?? null;
        $smsEnabled = $row['sms_enabled'] ?? false;
        if (is_string($smsEnabled)) {
            $smsEnabled = in_array(strtolower($smsEnabled), ['1', 'true', 'yes'], true);
        }

        return [
            'firma_unvani' => $this->nullableString($row['firma_unvani'] ?? null),
            'firma_adres' => $this->nullableString($row['firma_adres'] ?? null),
            'firma_telefon' => $this->nullableString($row['firma_telefon'] ?? null),
            'firma_vkn' => $this->nullableString($row['firma_vkn'] ?? null),
            'firma_email' => $this->nullableString($row['firma_email'] ?? null),
            'firma_logo_path' => $this->nullableString($row['firma_logo_path'] ?? null),
            'default_belge_tipi' => self::normalizeBelgeTipi($row['default_belge_tipi'] ?? null),
            'sms_enabled' => (bool) $smsEnabled,
            'netgsm_username' => $this->nullableString($row['netgsm_username'] ?? null),
            'netgsm_password' => $this->nullableString($row['netgsm_password'] ?? null),
            'netgsm_msgheader' => $this->nullableString($row['netgsm_msgheader'] ?? null),
            'sms_api_url' => $this->nullableString($row['sms_api_url'] ?? null),
            'updated_at' => $updatedAt instanceof \DateTimeInterface
                ? $updatedAt->format('Y-m-d H:i:s')
                : ($updatedAt !== null ? (string) $updatedAt : null),
            'updated_by' => isset($row['updated_by']) && $row['updated_by'] !== null
                ? (int) $row['updated_by']
                : null,
        ];
    }

    /**
     * @return array{
     *   firma_unvani:?string,
     *   firma_adres:?string,
     *   firma_telefon:?string,
     *   firma_vkn:?string,
     *   firma_email:?string,
     *   firma_logo_path:?string,
     *   default_belge_tipi:string,
     *   sms_enabled:bool,
     *   netgsm_username:?string,
     *   netgsm_password:?string,
     *   netgsm_msgheader:?string,
     *   sms_api_url:?string,
     *   updated_at:?string,
     *   updated_by:?int
     * }
     */
    private function emptySettings(): array
    {
        return [
            'firma_unvani' => null,
            'firma_adres' => null,
            'firma_telefon' => null,
            'firma_vkn' => null,
            'firma_email' => null,
            'firma_logo_path' => null,
            'default_belge_tipi' => 'tumu',
            'sms_enabled' => false,
            'netgsm_username' => null,
            'netgsm_password' => null,
            'netgsm_msgheader' => null,
            'sms_api_url' => null,
            'updated_at' => null,
            'updated_by' => null,
        ];
    }

    public static function normalizeBelgeTipi(mixed $value): string
    {
        $s = strtolower(trim((string) ($value ?? '')));
        if (in_array($s, ['tumu', 'siparis', 'irsaliye', 'fatura'], true)) {
            return $s;
        }

        return 'tumu';
    }

    public static function resolveSmsApiUrl(?string $value): string
    {
        $s = trim((string) ($value ?? ''));

        return $s !== '' ? $s : self::DEFAULT_SMS_API_URL;
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
