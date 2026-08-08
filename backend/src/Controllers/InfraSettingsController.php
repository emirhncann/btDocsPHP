<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Repositories\AuditLogRepository;
use App\Services\EnvFileService;
use App\Support\ClientIp;
use App\Support\SafeError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class InfraSettingsController
{
    public function __construct(
        private readonly EnvFileService $envFile,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            return JsonResponse::write($response, $this->envFile->readInfra());
        } catch (\Throwable $e) {
            SafeError::log($e, 'infra_settings.show');

            return JsonResponse::error($response, 'Sistem ayarları alınamadı', 500);
        }
    }

    public function testConnection(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $body = (array) $request->getParsedBody();
            $host = trim((string) ($body['logo_db_host'] ?? ''));
            $name = trim((string) ($body['logo_db_name'] ?? ''));
            $user = trim((string) ($body['logo_db_user'] ?? ''));
            $pass = $this->envFile->resolvePasswordInput(
                isset($body['logo_db_pass']) ? (string) $body['logo_db_pass'] : null,
                'LOGO_DB_PASS'
            );

            $result = $this->envFile->testLogoConnection($host, $name, $user, $pass);

            return JsonResponse::write($response, $result);
        } catch (\Throwable $e) {
            SafeError::log($e, 'infra_settings.test');

            return JsonResponse::write($response, [
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function testAppConnection(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $body = (array) $request->getParsedBody();
            $host = trim((string) ($body['app_db_host'] ?? ''));
            $name = trim((string) ($body['app_db_name'] ?? ''));
            $user = trim((string) ($body['app_db_user'] ?? ''));
            $pass = $this->envFile->resolvePasswordInput(
                isset($body['app_db_pass']) ? (string) $body['app_db_pass'] : null,
                'APP_DB_PASS'
            );

            $result = $this->envFile->testAppConnection($host, $name, $user, $pass);

            return JsonResponse::write($response, $result);
        } catch (\Throwable $e) {
            SafeError::log($e, 'infra_settings.test_app');

            return JsonResponse::write($response, [
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $body = (array) $request->getParsedBody();

            /** @var array<string, mixed> $user */
            $user = $request->getAttribute('user') ?? [];
            $userId = (int) ($user['id'] ?? 0);
            if ($userId < 1) {
                return JsonResponse::error($response, 'Yetkisiz erişim', 401);
            }

            $result = $this->envFile->updateInfra([
                'logo_db_host' => (string) ($body['logo_db_host'] ?? ''),
                'logo_db_name' => (string) ($body['logo_db_name'] ?? ''),
                'logo_db_user' => (string) ($body['logo_db_user'] ?? ''),
                'logo_db_pass' => $body['logo_db_pass'] ?? null,
                'app_db_host' => (string) ($body['app_db_host'] ?? ''),
                'app_db_name' => (string) ($body['app_db_name'] ?? ''),
                'app_db_user' => (string) ($body['app_db_user'] ?? ''),
                'app_db_pass' => $body['app_db_pass'] ?? null,
                'doc_storage_root' => (string) ($body['doc_storage_root'] ?? ''),
                'public_base_url' => (string) ($body['public_base_url'] ?? ''),
            ]);

            $parts = $result['changed'];
            if (!empty($result['logo_password_changed'])) {
                $parts[] = 'logo_db_pass değiştirildi';
            }
            if (!empty($result['app_password_changed'])) {
                $parts[] = 'app_db_pass değiştirildi';
            }
            $detail = $parts === [] ? 'değişiklik yok' : implode(', ', $parts);
            $this->auditLog->log(
                'infra_settings_updated: ' . $detail,
                $userId,
                null,
                ClientIp::from($request),
            );

            $payload = $this->envFile->readInfra();
            $payload['message'] = !empty($result['app_db_changed'])
                ? 'APP_DB ayarları güncellendi ve doğrulandı. Bir sonraki istekten itibaren yeni veritabanı kullanılacak.'
                : 'Ayarlar güncellendi ve bir sonraki istekten itibaren aktif.';
            $payload['backup_path'] = 'backups/' . basename((string) $result['backup_path']);
            $payload['app_db_changed'] = (bool) $result['app_db_changed'];

            return JsonResponse::write($response, $payload);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($response, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            SafeError::log($e, 'infra_settings.update');

            return JsonResponse::error($response, 'Sistem ayarları kaydedilemedi', 500);
        }
    }
}
