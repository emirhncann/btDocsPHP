<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Settings;
use App\Http\JsonResponse;
use App\Repositories\AppSettingsRepository;
use App\Repositories\AuditLogRepository;
use App\Services\DocumentStorageService;
use App\Services\FileMimeValidator;
use App\Support\ClientIp;
use App\Support\SafeError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

final class SettingsController
{
    private const MAX_LOGO_BYTES = 2 * 1024 * 1024;
    private const ALLOWED_LOGO_MIME = ['image/png', 'image/jpeg'];

    public function __construct(
        private readonly AppSettingsRepository $settings,
        private readonly AuditLogRepository $auditLog,
        private readonly DocumentStorageService $storage,
        private readonly FileMimeValidator $mimeValidator,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $row = $this->settings->get();

            return JsonResponse::write($response, $this->publicPayload($row));
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
            $firmaVkn = $this->optionalString($body['firma_vkn'] ?? null);
            $firmaEmail = $this->optionalString($body['firma_email'] ?? null);
            $defaultBelgeTipi = AppSettingsRepository::normalizeBelgeTipi($body['default_belge_tipi'] ?? 'tumu');
            $rawBelgeTipi = strtolower(trim((string) ($body['default_belge_tipi'] ?? 'tumu')));
            if ($rawBelgeTipi !== '' && $rawBelgeTipi !== $defaultBelgeTipi) {
                return JsonResponse::error(
                    $response,
                    'default_belge_tipi tumu, siparis, irsaliye veya fatura olmalı',
                    422
                );
            }

            if ($firmaUnvani !== null && mb_strlen($firmaUnvani) > 200) {
                return JsonResponse::error($response, 'firma_unvani en fazla 200 karakter olabilir', 422);
            }
            if ($firmaAdres !== null && mb_strlen($firmaAdres) > 500) {
                return JsonResponse::error($response, 'firma_adres en fazla 500 karakter olabilir', 422);
            }
            if ($firmaTelefon !== null && mb_strlen($firmaTelefon) > 50) {
                return JsonResponse::error($response, 'firma_telefon en fazla 50 karakter olabilir', 422);
            }
            if ($firmaVkn !== null) {
                if (mb_strlen($firmaVkn) > 20) {
                    return JsonResponse::error($response, 'firma_vkn en fazla 20 karakter olabilir', 422);
                }
                if (!preg_match('/^\d+$/', $firmaVkn)) {
                    return JsonResponse::error($response, 'firma_vkn yalnızca rakamlardan oluşmalı', 422);
                }
            }
            if ($firmaEmail !== null) {
                if (mb_strlen($firmaEmail) > 200) {
                    return JsonResponse::error($response, 'firma_email en fazla 200 karakter olabilir', 422);
                }
                if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $firmaEmail)) {
                    return JsonResponse::error($response, 'firma_email geçerli bir e-posta formatında olmalı', 422);
                }
            }

            /** @var array<string, mixed> $user */
            $user = $request->getAttribute('user') ?? [];
            $userId = (int) ($user['id'] ?? 0);
            if ($userId < 1) {
                return JsonResponse::error($response, 'Yetkisiz erişim', 401);
            }

            $row = $this->settings->update(
                $firmaUnvani,
                $firmaAdres,
                $firmaTelefon,
                $firmaVkn,
                $firmaEmail,
                $defaultBelgeTipi,
                $userId,
            );
            $this->auditLog->log('settings_updated', $userId, null, ClientIp::from($request));

            return JsonResponse::write($response, $this->publicPayload($row));
        } catch (\Throwable $e) {
            SafeError::log($e, 'settings.update');

            return JsonResponse::error($response, 'Ayarlar kaydedilemedi', 500);
        }
    }

    public function uploadLogo(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            /** @var array<string, mixed> $user */
            $user = $request->getAttribute('user') ?? [];
            $userId = (int) ($user['id'] ?? 0);
            if ($userId < 1) {
                return JsonResponse::error($response, 'Yetkisiz erişim', 401);
            }

            $files = $request->getUploadedFiles();
            $uploaded = $files['logo'] ?? null;
            if (!$uploaded instanceof UploadedFileInterface) {
                return JsonResponse::error($response, 'logo alanı zorunlu (multipart/form-data)', 422);
            }

            if ($uploaded->getError() !== UPLOAD_ERR_OK) {
                if (in_array($uploaded->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    return JsonResponse::error($response, 'Dosya boyutu 2MB sınırını aşıyor', 413);
                }

                return JsonResponse::error($response, 'Logo yüklenemedi', 400);
            }

            $size = $uploaded->getSize();
            if ($size !== null && $size > self::MAX_LOGO_BYTES) {
                return JsonResponse::error($response, 'Logo en fazla 2MB olabilir', 413);
            }

            $mimeError = $this->mimeValidator->validate($uploaded, self::ALLOWED_LOGO_MIME);
            if ($mimeError !== null) {
                return JsonResponse::error($response, 'Yalnızca PNG veya JPEG logo kabul edilir', 422);
            }

            $mime = $this->mimeValidator->detectMime($uploaded) ?? 'image/png';
            if ($mime === 'image/jpg') {
                $mime = 'image/jpeg';
            }

            $stored = $this->storage->storeCompanyLogo($uploaded, $mime);
            $row = $this->settings->updateLogoPath($stored['relative_path'], $userId);
            $this->auditLog->log('firma_logo_updated', $userId, null, ClientIp::from($request));

            return JsonResponse::write($response, $this->publicPayload($row));
        } catch (\Throwable $e) {
            SafeError::log($e, 'settings.uploadLogo');

            return JsonResponse::error($response, 'Logo yüklenemedi', 500);
        }
    }

    public function deleteLogo(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            /** @var array<string, mixed> $user */
            $user = $request->getAttribute('user') ?? [];
            $userId = (int) ($user['id'] ?? 0);
            if ($userId < 1) {
                return JsonResponse::error($response, 'Yetkisiz erişim', 401);
            }

            $this->storage->deleteCompanyLogo();
            $row = $this->settings->updateLogoPath(null, $userId);
            $this->auditLog->log('firma_logo_deleted', $userId, null, ClientIp::from($request));

            return JsonResponse::write($response, $this->publicPayload($row));
        } catch (\Throwable $e) {
            SafeError::log($e, 'settings.deleteLogo');

            return JsonResponse::error($response, 'Logo kaldırılamadı', 500);
        }
    }

    /** Auth gerekmez — belge yüzeylerinde kullanılan herkese açık logo. */
    public function serveLogo(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $row = $this->settings->get();
            $absolute = $this->storage->resolveCompanyLogoAbsolute($row['firma_logo_path'] ?? null);
            if ($absolute === null || !is_file($absolute)) {
                return JsonResponse::error($response, 'Logo yok', 404);
            }

            $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                default => 'application/octet-stream',
            };

            $stream = fopen($absolute, 'rb');
            if ($stream === false) {
                return JsonResponse::error($response, 'Logo okunamadı', 500);
            }

            $mtime = (int) filemtime($absolute);

            return $response
                ->withHeader('Content-Type', $mime)
                ->withHeader('Cache-Control', 'public, max-age=86400')
                ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', $mtime) . ' GMT')
                ->withBody(new Stream($stream));
        } catch (\Throwable $e) {
            SafeError::log($e, 'settings.serveLogo');

            return JsonResponse::error($response, 'Logo okunamadı', 500);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   firma_unvani:?string,
     *   firma_adres:?string,
     *   firma_telefon:?string,
     *   firma_vkn:?string,
     *   firma_email:?string,
     *   firma_logo_url:?string,
     *   default_belge_tipi:string
     * }
     */
    private function publicPayload(array $row): array
    {
        return [
            'firma_unvani' => $row['firma_unvani'],
            'firma_adres' => $row['firma_adres'],
            'firma_telefon' => $row['firma_telefon'],
            'firma_vkn' => $row['firma_vkn'],
            'firma_email' => $row['firma_email'],
            'firma_logo_url' => $this->buildLogoUrl($row['firma_logo_path'] ?? null),
            'default_belge_tipi' => AppSettingsRepository::normalizeBelgeTipi($row['default_belge_tipi'] ?? 'tumu'),
        ];
    }

    private function buildLogoUrl(?string $relativePath): ?string
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return null;
        }

        $absolute = $this->storage->resolveCompanyLogoAbsolute($relativePath);
        if ($absolute === null || !is_file($absolute)) {
            return null;
        }

        $base = rtrim((string) (Settings::get('PUBLIC_BASE_URL', '') ?? ''), '/');
        $v = (int) filemtime($absolute);
        $path = '/assets/company-logo?v=' . $v;

        return $base !== '' ? $base . $path : $path;
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
