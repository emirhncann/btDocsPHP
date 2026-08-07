<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Repositories\AppSettingsRepository;
use App\Repositories\AuditLogRepository;
use App\Support\ClientIp;
use App\Support\SafeError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SettingsController
{
    public function __construct(
        private readonly AppSettingsRepository $settings,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $row = $this->settings->get();

            return JsonResponse::write($response, [
                'firma_unvani' => $row['firma_unvani'],
                'firma_adres' => $row['firma_adres'],
                'firma_telefon' => $row['firma_telefon'],
            ]);
        } catch (\Throwable $e) {
            SafeError::log($e, 'settings.show');

            return JsonResponse::error($response, 'Ayarlar alınamadı', 500);
        }
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $body = (array) $request->getParsedBody();

            $firmaUnvani = $this->optionalString($body['firma_unvani'] ?? null);
            $firmaAdres = $this->optionalString($body['firma_adres'] ?? null);
            $firmaTelefon = $this->optionalString($body['firma_telefon'] ?? null);

            if ($firmaUnvani !== null && mb_strlen($firmaUnvani) > 200) {
                return JsonResponse::error($response, 'firma_unvani en fazla 200 karakter olabilir', 422);
            }
            if ($firmaAdres !== null && mb_strlen($firmaAdres) > 500) {
                return JsonResponse::error($response, 'firma_adres en fazla 500 karakter olabilir', 422);
            }
            if ($firmaTelefon !== null && mb_strlen($firmaTelefon) > 50) {
                return JsonResponse::error($response, 'firma_telefon en fazla 50 karakter olabilir', 422);
            }

            /** @var array<string, mixed> $user */
            $user = $request->getAttribute('user') ?? [];
            $userId = (int) ($user['id'] ?? 0);
            if ($userId < 1) {
                return JsonResponse::error($response, 'Yetkisiz erişim', 401);
            }

            $row = $this->settings->update($firmaUnvani, $firmaAdres, $firmaTelefon, $userId);
            $this->auditLog->log('settings_updated', $userId, null, ClientIp::from($request));

            return JsonResponse::write($response, [
                'firma_unvani' => $row['firma_unvani'],
                'firma_adres' => $row['firma_adres'],
                'firma_telefon' => $row['firma_telefon'],
            ]);
        } catch (\Throwable $e) {
            SafeError::log($e, 'settings.update');

            return JsonResponse::error($response, 'Ayarlar kaydedilemedi', 500);
        }
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }
}
