<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Settings;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use stdClass;

final class JwtService
{
    private const DEFAULT_TTL_HOURS = 8;
    private const ALGORITHM = 'HS256';

    public function createToken(int $userId, string $username, string $role): string
    {
        $now = time();
        $ttlHours = (int) (Settings::get('JWT_TTL_HOURS', (string) self::DEFAULT_TTL_HOURS) ?? self::DEFAULT_TTL_HOURS);
        if ($ttlHours <= 0) {
            $ttlHours = self::DEFAULT_TTL_HOURS;
        }

        $payload = [
            'iat' => $now,
            'exp' => $now + ($ttlHours * 3600),
            'user_id' => $userId,
            'username' => $username,
            'role' => $role,
        ];

        return JWT::encode($payload, $this->secret(), self::ALGORITHM);
    }

    /**
     * @return array{user_id: int, username: string, role: string, exp: int}
     */
    public function decodeToken(string $token): array
    {
        try {
            /** @var stdClass $decoded */
            $decoded = JWT::decode($token, new Key($this->secret(), self::ALGORITHM));
        } catch (\Throwable $e) {
            throw new RuntimeException('Geçersiz veya süresi dolmuş token', 0, $e);
        }

        if (!isset($decoded->user_id, $decoded->username, $decoded->role, $decoded->exp)) {
            throw new RuntimeException('Token payload eksik');
        }

        return [
            'user_id' => (int) $decoded->user_id,
            'username' => (string) $decoded->username,
            'role' => (string) $decoded->role,
            'exp' => (int) $decoded->exp,
        ];
    }

    private function secret(): string
    {
        return Settings::require('JWT_SECRET');
    }
}
