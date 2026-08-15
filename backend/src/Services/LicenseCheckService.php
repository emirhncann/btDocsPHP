<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Settings;
use App\Repositories\LicenseStatusRepository;
use App\Support\SafeError;

final class LicenseCheckService
{
    private const TIMEOUT_SECONDS = 5;
    private const GRACE_DAYS = 3;

    public function __construct(
        private readonly LicenseStatusRepository $status,
    ) {
    }

    /**
     * Lisans panelini sorgular ve LicenseStatus tablosunu günceller.
     *
     * @return array{
     *   ok:bool,
     *   source:string,
     *   is_valid:?bool,
     *   bitis_tarihi:?string,
     *   error:?string,
     *   status:array{
     *     is_valid:bool,
     *     last_checked_at:?string,
     *     last_successful_check_at:?string,
     *     bitis_tarihi:?string
     *   }
     * }
     */
    public function checkLicense(): array
    {
        // PHP built-in server gibi uzun ömürlü süreçlerde .env güncellemesini al
        try {
            Settings::reload();
        } catch (\Throwable) {
            // basePath henüz yoksa load edilmiş olmalı; sessiz geç
        }

        $panelUrl = rtrim((string) (Settings::get('LICENSE_PANEL_URL', '') ?? ''), '/');
        $apiKey = trim((string) (Settings::get('LICENSE_API_KEY', '') ?? ''));

        if ($panelUrl === '' || $apiKey === '') {
            $missing = [];
            if ($panelUrl === '') {
                $missing[] = 'LICENSE_PANEL_URL';
            }
            if ($apiKey === '') {
                $missing[] = 'LICENSE_API_KEY';
            }
            $this->status->markNetworkAttempt();

            return [
                'ok' => false,
                'source' => 'config',
                'is_valid' => null,
                'bitis_tarihi' => null,
                'error' => implode(' ve ', $missing) . ' .env içinde eksik',
                'status' => $this->status->get(),
            ];
        }

        // Proje C: /api/license-verify.php?api_key=...
        $url = $panelUrl . '/api/license-verify.php?api_key=' . rawurlencode($apiKey);

        try {
            $payload = $this->httpGetJson($url);
        } catch (\Throwable $e) {
            SafeError::log($e, 'license.check.network');
            $this->status->markNetworkAttempt();

            return [
                'ok' => false,
                'source' => 'network',
                'is_valid' => null,
                'bitis_tarihi' => null,
                'error' => $e->getMessage(),
                'status' => $this->status->get(),
            ];
        }

        $parsed = $this->parseVerifyPayload($payload);
        if ($parsed['is_valid'] === null) {
            $this->status->markNetworkAttempt();

            return [
                'ok' => false,
                'source' => 'response',
                'is_valid' => null,
                'bitis_tarihi' => null,
                'error' => 'Lisans paneli yanıtı beklenen formatta değil',
                'status' => $this->status->get(),
            ];
        }

        $this->status->markSuccessfulCheck($parsed['is_valid'], $parsed['bitis_tarihi']);

        return [
            'ok' => true,
            'source' => 'panel',
            'is_valid' => $parsed['is_valid'],
            'bitis_tarihi' => $parsed['bitis_tarihi'],
            'error' => null,
            'status' => $this->status->get(),
        ];
    }

    public function allowsWrites(): bool
    {
        return $this->status->allowsWrites(self::GRACE_DAYS);
    }

    /**
     * AuthMiddleware: çoğu istekte tek SELECT + erken çıkış.
     * last_checked_at 24 saatten eskiyse (veya yoksa) Proje C'ye bir kez sor.
     */
    public function ensureFreshCheck(): void
    {
        $row = $this->status->get();

        if ($this->wasCheckedWithinHours($row['last_checked_at'] ?? null, 24)) {
            return;
        }

        // Basit kilitleme: diğer eşzamanlı istekler de SELECT sonrası aynı
        // pencereyi görsün diye önce damgala (mükemmel değil, düşük trafik için yeterli).
        $this->updateLastCheckedAtOnly();

        $this->checkLicense();
    }

    public function updateLastCheckedAtOnly(): void
    {
        $this->status->markNetworkAttempt();
    }

    private function wasCheckedWithinHours(?string $lastCheckedAt, int $hours): bool
    {
        if ($lastCheckedAt === null || trim($lastCheckedAt) === '') {
            return false;
        }

        try {
            $last = new \DateTimeImmutable($lastCheckedAt);
        } catch (\Throwable) {
            return false;
        }

        $elapsed = (new \DateTimeImmutable('now'))->getTimestamp() - $last->getTimestamp();

        return $elapsed >= 0 && $elapsed < ($hours * 3600);
    }

    /**
     * @return array{is_valid:?bool, bitis_tarihi:?string}
     * @param array<string, mixed> $payload
     */
    private function parseVerifyPayload(array $payload): array
    {
        $data = $payload;
        if (isset($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];
        }

        $validRaw = $data['valid'] ?? $data['is_valid'] ?? $payload['valid'] ?? $payload['is_valid'] ?? null;
        $isValid = null;
        if (is_bool($validRaw)) {
            $isValid = $validRaw;
        } elseif (is_int($validRaw) || is_float($validRaw)) {
            $isValid = (int) $validRaw === 1;
        } elseif (is_string($validRaw)) {
            $s = strtolower(trim($validRaw));
            if (in_array($s, ['1', 'true', 'yes', 'valid'], true)) {
                $isValid = true;
            } elseif (in_array($s, ['0', 'false', 'no', 'invalid', 'expired'], true)) {
                $isValid = false;
            }
        }

        $bitis = $data['bitis_tarihi']
            ?? $data['expires_at']
            ?? $payload['bitis_tarihi']
            ?? $payload['expires_at']
            ?? null;
        $bitisTarihi = null;
        if ($bitis !== null && trim((string) $bitis) !== '') {
            $bitisTarihi = substr((string) $bitis, 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bitisTarihi)) {
                $bitisTarihi = null;
            }
        }

        return [
            'is_valid' => $isValid,
            'bitis_tarihi' => $bitisTarihi,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function httpGetJson(string $url): array
    {
        if (function_exists('curl_init')) {
            return $this->httpGetJsonCurl($url);
        }

        return $this->httpGetJsonStream($url);
    }

    /**
     * @return array<string, mixed>
     */
    private function httpGetJsonCurl(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init başarısız');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => $this->shouldVerifySsl(),
            CURLOPT_SSL_VERIFYHOST => $this->shouldVerifySsl() ? 2 : 0,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            throw new \RuntimeException('Lisans paneli erişilemedi: ' . ($error !== '' ? $error : 'timeout'));
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Lisans paneli HTTP ' . $status);
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Lisans paneli JSON döndürmedi');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function httpGetJsonStream(string $url): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'header' => "Accept: application/json\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $this->shouldVerifySsl(),
                'verify_peer_name' => $this->shouldVerifySsl(),
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new \RuntimeException('Lisans paneli erişilemedi (timeout/network)');
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($status > 0 && ($status < 200 || $status >= 300)) {
            throw new \RuntimeException('Lisans paneli HTTP ' . $status);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Lisans paneli JSON döndürmedi');
        }

        return $decoded;
    }

    /**
     * Windows yerel PHP'de CA bundle sık eksik olur.
     * PRODUCTION'da varsayılan doğrula; development'ta veya LICENSE_SSL_VERIFY=false ile gevşet.
     */
    private function shouldVerifySsl(): bool
    {
        $override = strtolower(trim((string) (Settings::get('LICENSE_SSL_VERIFY', '') ?? '')));
        if ($override === '0' || $override === 'false' || $override === 'no') {
            return false;
        }
        if ($override === '1' || $override === 'true' || $override === 'yes') {
            return true;
        }

        $env = strtolower(trim((string) (Settings::get('APP_ENV', 'production') ?? 'production')));

        return $env === 'production';
    }
}
