<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Repositories\AuditLogRepository;
use App\Repositories\LicenseStatusRepository;
use App\Services\LicenseCheckService;
use App\Support\ClientIp;
use App\Support\SafeError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class LicenseStatusController
{
    public function __construct(
        private readonly LicenseStatusRepository $licenseStatus,
        private readonly LicenseCheckService $licenseCheck,
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            return JsonResponse::write($response, $this->statusPayload());
        } catch (\Throwable $e) {
            SafeError::log($e, 'admin.license-status');

            return JsonResponse::error($response, 'Lisans durumu alınamadı', 500);
        }
    }

    /** Panelden anlık doğrulama — check_our_license.php ile aynı iş. */
    public function check(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            /** @var array<string, mixed> $user */
            $user = $request->getAttribute('user') ?? [];
            $userId = (int) ($user['id'] ?? 0);

            $result = $this->licenseCheck->checkLicense();

            if ($userId > 0) {
                $this->auditLog->log(
                    'license_checked: ' . ($result['ok'] ? 'ok' : 'fail') .
                    ' source=' . $result['source'] .
                    ' valid=' . ($result['is_valid'] === null ? 'null' : ($result['is_valid'] ? '1' : '0')),
                    $userId,
                    null,
                    ClientIp::from($request)
                );
            }

            $payload = $this->statusPayload();
            $payload['success'] = $result['ok'];
            $payload['source'] = $result['source'];
            $payload['error'] = $result['error'];
            $payload['message'] = $this->humanMessage($result);

            return JsonResponse::write($response, $payload, $result['ok'] ? 200 : 422);
        } catch (\Throwable $e) {
            SafeError::log($e, 'admin.license-check');

            return JsonResponse::error($response, 'Lisans kontrolü çalıştırılamadı', 500);
        }
    }

    /**
     * @return array{
     *   is_valid:bool,
     *   bitis_tarihi:?string,
     *   kalan_gun:?int,
     *   last_checked_at:?string,
     *   last_successful_check_at:?string,
     *   writes_allowed:bool
     * }
     */
    private function statusPayload(): array
    {
        $row = $this->licenseStatus->get();

        return [
            'is_valid' => $row['is_valid'],
            'bitis_tarihi' => $row['bitis_tarihi'],
            'kalan_gun' => $this->remainingDays($row['bitis_tarihi']),
            'last_checked_at' => $row['last_checked_at'],
            'last_successful_check_at' => $row['last_successful_check_at'],
            'writes_allowed' => $this->licenseStatus->allowsWrites(),
        ];
    }

    private function remainingDays(?string $bitisTarihi): ?int
    {
        if ($bitisTarihi === null || trim($bitisTarihi) === '') {
            return null;
        }

        try {
            $end = new \DateTimeImmutable(substr($bitisTarihi, 0, 10));
            $today = new \DateTimeImmutable('today');
        } catch (\Throwable) {
            return null;
        }

        return (int) $today->diff($end)->format('%r%a');
    }

    /**
     * @param array{
     *   ok:bool,
     *   source:string,
     *   is_valid:?bool,
     *   bitis_tarihi:?string,
     *   error:?string,
     *   status:array<string,mixed>
     * } $result
     */
    private function humanMessage(array $result): string
    {
        if ($result['ok'] && $result['is_valid'] === true) {
            $bitis = $result['bitis_tarihi'] ?? null;

            return $bitis
                ? 'Lisans geçerli. Bitiş: ' . $bitis
                : 'Lisans geçerli.';
        }

        if ($result['ok'] && $result['is_valid'] === false) {
            return 'Lisans geçersiz veya süresi dolmuş. Yazma işlemleri kilitlenecek.';
        }

        if ($result['source'] === 'config') {
            return ($result['error'] ?? 'Lisans ayarları eksik') . ' (.env dosyasını güncelleyip tekrar deneyin).';
        }

        if ($result['source'] === 'network') {
            return 'Lisans paneline ulaşılamadı (son bilinen durum korundu). ' . ($result['error'] ?? '');
        }

        return $result['error'] ?? 'Lisans kontrolü başarısız.';
    }
}
