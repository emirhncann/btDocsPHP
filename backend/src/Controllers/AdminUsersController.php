<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminUsersController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $role = isset($params['role']) ? trim((string) $params['role']) : null;
        $isActive = $this->parseOptionalBool($params['is_active'] ?? null);

        if ($role !== null && $role !== '' && !in_array($role, UserRepository::ALLOWED_ROLES, true)) {
            return JsonResponse::error($response, 'Geçersiz role filtresi', 422);
        }

        $items = $this->users->list(
            $role !== '' ? $role : null,
            $isActive
        );

        return JsonResponse::write($response, $items);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $user = $this->users->findPublicById($id);

        if ($user === null) {
            return JsonResponse::error($response, 'Kullanıcı bulunamadı', 404);
        }

        return JsonResponse::write($response, $user);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $fullName = trim((string) ($body['full_name'] ?? ''));
        $role = trim((string) ($body['role'] ?? ''));

        $validationError = $this->validateCreate($username, $password, $fullName, $role);
        if ($validationError !== null) {
            return JsonResponse::error($response, $validationError, 422);
        }

        if ($this->users->usernameExists($username)) {
            return JsonResponse::error($response, 'Bu kullanıcı adı zaten kullanılıyor', 409);
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        if ($hash === false) {
            return JsonResponse::error($response, 'Şifre hash işlemi başarısız', 500);
        }

        $id = $this->users->create($username, $hash, $fullName, $role);
        $created = $this->users->findPublicById($id);

        $this->auditLog->log(
            'user_created',
            $this->actorId($request),
            null,
            $this->clientIp($request)
        );

        return JsonResponse::write($response, $created ?? [], 201);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->users->findById($id);

        if ($existing === null) {
            return JsonResponse::error($response, 'Kullanıcı bulunamadı', 404);
        }

        $body = (array) $request->getParsedBody();
        $fullName = array_key_exists('full_name', $body)
            ? trim((string) $body['full_name'])
            : (string) $existing['full_name'];
        $role = array_key_exists('role', $body)
            ? trim((string) $body['role'])
            : (string) $existing['role'];

        if (array_key_exists('is_active', $body)) {
            if (is_bool($body['is_active'])) {
                $isActive = $body['is_active'];
            } else {
                $parsed = $this->parseOptionalBool($body['is_active']);
                if ($parsed === null) {
                    return JsonResponse::error($response, 'is_active 0 veya 1 olmalı', 422);
                }
                $isActive = $parsed;
            }
        } else {
            $isActive = UserRepository::isTruthy($existing['is_active'] ?? false);
        }

        if ($fullName === '') {
            return JsonResponse::error($response, 'full_name boş olamaz', 422);
        }

        if (!in_array($role, UserRepository::ALLOWED_ROLES, true)) {
            return JsonResponse::error($response, 'role sadece admin, saha veya readonly olabilir', 422);
        }

        $this->users->update($id, $fullName, $role, $isActive);
        $updated = $this->users->findPublicById($id);

        $this->auditLog->log(
            'user_updated',
            $this->actorId($request),
            null,
            $this->clientIp($request)
        );

        return JsonResponse::write($response, $updated ?? []);
    }

    public function resetPassword(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->users->findById($id);

        if ($existing === null) {
            return JsonResponse::error($response, 'Kullanıcı bulunamadı', 404);
        }

        $body = (array) $request->getParsedBody();
        $newPassword = (string) ($body['new_password'] ?? '');

        if (strlen($newPassword) < 6) {
            return JsonResponse::error($response, 'new_password en az 6 karakter olmalı', 422);
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        if ($hash === false) {
            return JsonResponse::error($response, 'Şifre hash işlemi başarısız', 500);
        }

        $this->users->updatePassword($id, $hash);

        $this->auditLog->log(
            'user_password_reset',
            $this->actorId($request),
            null,
            $this->clientIp($request)
        );

        return JsonResponse::write($response, ['message' => 'Şifre güncellendi']);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->users->findById($id);

        if ($existing === null) {
            return JsonResponse::error($response, 'Kullanıcı bulunamadı', 404);
        }

        if (!UserRepository::isTruthy($existing['is_active'] ?? false)) {
            return JsonResponse::write($response, $this->users->toPublic($existing));
        }

        $this->users->deactivate($id);
        $deactivated = $this->users->findPublicById($id);

        $this->auditLog->log(
            'user_deactivated',
            $this->actorId($request),
            null,
            $this->clientIp($request)
        );

        return JsonResponse::write($response, $deactivated ?? []);
    }

    private function validateCreate(string $username, string $password, string $fullName, string $role): ?string
    {
        if ($username === '' || mb_strlen($username) < 3) {
            return 'username en az 3 karakter olmalı';
        }

        if (strlen($password) < 6) {
            return 'password en az 6 karakter olmalı';
        }

        if ($fullName === '') {
            return 'full_name boş olamaz';
        }

        if (!in_array($role, UserRepository::ALLOWED_ROLES, true)) {
            return 'role sadece admin, saha veya readonly olabilir';
        }

        return null;
    }

    private function actorId(ServerRequestInterface $request): ?int
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');

        if (!is_array($user)) {
            return null;
        }

        return isset($user['id']) ? (int) $user['id'] : null;
    }

    private function clientIp(ServerRequestInterface $request): ?string
    {
        $server = $request->getServerParams();
        $ip = $server['HTTP_X_FORWARDED_FOR'] ?? $server['REMOTE_ADDR'] ?? null;

        if (!is_string($ip) || $ip === '') {
            return null;
        }

        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        return mb_substr($ip, 0, 45);
    }

    private function parseOptionalBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }
}
