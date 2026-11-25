<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\LicenseStatusRepository;

/**
 * Lisans kotası: LicenseStatus.max_users (NULL = sınırsız).
 * Aktif kullanıcı sayısı Users.is_active = 1 üzerinden sayılır.
 */
final class UserLimitService
{
    public function __construct(
        private readonly AppDbConnection $db,
        private readonly LicenseStatusRepository $licenseStatus,
    ) {
    }

    public function getActiveUserCount(): int
    {
        $count = $this->db->getConnection()->query(
            'SELECT COUNT(*) FROM dbo.Users WHERE is_active = 1'
        )->fetchColumn();

        return (int) $count;
    }

    public function getMaxUsers(): ?int
    {
        return $this->licenseStatus->get()['max_users'];
    }

    public function canAddUser(): bool
    {
        $maxUsers = $this->getMaxUsers();
        if ($maxUsers === null) {
            return true;
        }

        return $this->getActiveUserCount() < $maxUsers;
    }

    /**
     * Limit doluysa 403 gövdesi; değilse null.
     *
     * @return array{error:string, error_type:string}|null
     */
    public function limitReachedPayload(): ?array
    {
        $maxUsers = $this->getMaxUsers();
        if ($maxUsers === null) {
            return null;
        }

        $active = $this->getActiveUserCount();
        if ($active < $maxUsers) {
            return null;
        }

        return [
            'error' => sprintf(
                'Kullanıcı limitine ulaşıldı (%d/%d). Lisansınızı yükseltin.',
                $active,
                $maxUsers
            ),
            'error_type' => 'user_limit_reached',
        ];
    }
}
