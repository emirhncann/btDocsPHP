<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\AppDbConnection;
use PDO;

final class UserRepository
{
    public const ALLOWED_ROLES = ['admin', 'saha', 'readonly'];

    public function __construct(
        private readonly AppDbConnection $db,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id, username, password_hash, full_name, role, is_active, created_at
             FROM dbo.Users
             WHERE username = :username'
        );
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT id, username, password_hash, full_name, role, is_active, created_at
             FROM dbo.Users
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $role = null, ?bool $isActive = null): array
    {
        $sql = 'SELECT id, username, full_name, role, is_active, created_at
                FROM dbo.Users
                WHERE 1 = 1';
        $params = [];

        if ($role !== null && $role !== '') {
            $sql .= ' AND role = :role';
            $params['role'] = $role;
        }

        if ($isActive !== null) {
            $sql .= ' AND is_active = :is_active';
            $params['is_active'] = $isActive ? 1 : 0;
        }

        $sql .= ' ORDER BY id ASC';

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'toPublic'], $rows ?: []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPublicById(int $id): ?array
    {
        $user = $this->findById($id);

        return $user === null ? null : $this->toPublic($user);
    }

    public function create(string $username, string $passwordHash, string $fullName, string $role): int
    {
        $stmt = $this->db->getConnection()->prepare(
            'INSERT INTO dbo.Users (username, password_hash, full_name, role, is_active)
             OUTPUT INSERTED.id
             VALUES (:username, :password_hash, :full_name, :role, 1)'
        );
        $stmt->execute([
            'username' => $username,
            'password_hash' => $passwordHash,
            'full_name' => $fullName,
            'role' => $role,
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, string $fullName, string $role, bool $isActive): bool
    {
        $stmt = $this->db->getConnection()->prepare(
            'UPDATE dbo.Users
             SET full_name = :full_name,
                 role = :role,
                 is_active = :is_active
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'full_name' => $fullName,
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function updatePassword(int $id, string $passwordHash): bool
    {
        $stmt = $this->db->getConnection()->prepare(
            'UPDATE dbo.Users
             SET password_hash = :password_hash
             WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'password_hash' => $passwordHash,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deactivate(int $id): bool
    {
        $stmt = $this->db->getConnection()->prepare(
            'UPDATE dbo.Users
             SET is_active = 0
             WHERE id = :id AND is_active = 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function usernameExists(string $username): bool
    {
        $stmt = $this->db->getConnection()->prepare(
            'SELECT 1 FROM dbo.Users WHERE username = :username'
        );
        $stmt->execute(['username' => $username]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function toPublic(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'full_name' => (string) $row['full_name'],
            'role' => (string) $row['role'],
            'is_active' => self::isTruthy($row['is_active'] ?? false),
            'created_at' => $this->formatDateTime($row['created_at'] ?? null),
        ];
    }

    /**
     * SQL Server bit / string değerlerini güvenli boolean'a çevirir.
     * Not: (bool)"0" PHP'de true olduğu için doğrudan cast kullanılmamalı.
     */
    public static function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
