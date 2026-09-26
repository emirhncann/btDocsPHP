<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Settings;
use App\Repositories\AppSettingsRepository;
use App\Repositories\LogoDocumentRepository;
use App\Support\SafeError;

final class SmsService
{
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly AppSettingsRepository $settings,
        private readonly LogoDocumentRepository $logoDocuments,
    ) {
    }

    /**
     * İmzalı belge yüklendikten sonra SMS gönderir.
     * TELNRS1 ve TELNRS2 doluysa ikisine de ayrı istek atılır.
     * sms_enabled kapalıysa veya zorunlu alanlar eksikse sessizce atlar.
     * Hata upload'u bozmaz; yalnızca loglanır.
     *
     * @param array{
     *   firma_no:int|string,
     *   donem_no:int|string,
     *   cari_kod:string,
     *   belge_tipi:string,
     *   belge_no:string,
     *   logo_logicalref:int|string
     * } $doc
     * @return array{
     *   sent:bool,
     *   skipped:?string,
     *   error:?string,
     *   sent_count:int,
     *   failed_count:int,
     *   numbers:list<string>
     * }
     */
    public function sendAfterSignedDocument(array $doc, string $imzalayanAdi): array
    {
        $empty = static fn (?string $skipped = null, ?string $error = null): array => [
            'sent' => false,
            'skipped' => $skipped,
            'error' => $error,
            'sent_count' => 0,
            'failed_count' => 0,
            'numbers' => [],
        ];

        try {
            $cfg = $this->settings->get();
            if (empty($cfg['sms_enabled'])) {
                return $empty('sms_disabled');
            }

            $username = trim((string) ($cfg['netgsm_username'] ?? ''));
            $password = trim((string) ($cfg['netgsm_password'] ?? ''));
            $msgheader = trim((string) ($cfg['netgsm_msgheader'] ?? ''));
            $apiUrl = AppSettingsRepository::resolveSmsApiUrl($cfg['sms_api_url'] ?? null);

            if ($username === '' || $password === '' || $password === AppSettingsRepository::PASSWORD_MASK || $msgheader === '') {
                return $empty('sms_config_incomplete');
            }

            $imzalayanAdi = trim($imzalayanAdi);
            if ($imzalayanAdi === '') {
                return $empty('imzalayan_adi_empty');
            }

            $belgeNo = trim((string) ($doc['belge_no'] ?? ''));
            if ($belgeNo === '') {
                return $empty('belge_no_empty');
            }

            $firmaNo = (int) ($doc['firma_no'] ?? 0);
            $donemNo = (int) ($doc['donem_no'] ?? 0);
            $logicalRef = (int) ($doc['logo_logicalref'] ?? 0);
            $belgeTipi = (string) ($doc['belge_tipi'] ?? '');
            $cariKod = trim((string) ($doc['cari_kod'] ?? ''));

            $phones = $this->logoDocuments->findCariTelefonlar($firmaNo, $cariKod);
            if ($phones === []) {
                return $empty('cari_telefon_yok');
            }

            $tarihYmd = $this->logoDocuments->findDocumentTarih($firmaNo, $donemNo, $logicalRef, $belgeTipi);
            $belgeTarih = $this->formatBelgeTarih($tarihYmd);
            if ($belgeTarih === null) {
                return $empty('belge_tarih_yok');
            }

            $sentCount = 0;
            $failedCount = 0;
            $errors = [];

            foreach ($phones as $phone) {
                try {
                    $this->postMultipart($apiUrl, [
                        'netgsm_username' => $username,
                        'netgsm_password' => $password,
                        'msgheader' => $msgheader,
                        'no' => $phone,
                        'belge_tarih' => $belgeTarih,
                        'belge_no' => $belgeNo,
                        'imzalayan_adi' => $imzalayanAdi,
                    ]);
                    $sentCount++;
                } catch (\Throwable $e) {
                    $failedCount++;
                    $errors[] = $phone . ': ' . $e->getMessage();
                    SafeError::log($e, 'sms.sendAfterSignedDocument.' . $phone);
                }
            }

            if ($sentCount === 0) {
                return [
                    'sent' => false,
                    'skipped' => null,
                    'error' => implode(' | ', $errors),
                    'sent_count' => 0,
                    'failed_count' => $failedCount,
                    'numbers' => $phones,
                ];
            }

            return [
                'sent' => true,
                'skipped' => null,
                'error' => $errors === [] ? null : implode(' | ', $errors),
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'numbers' => $phones,
            ];
        } catch (\Throwable $e) {
            SafeError::log($e, 'sms.sendAfterSignedDocument');

            return $empty(null, $e->getMessage());
        }
    }

    /**
     * @param array<string, string> $fields
     */
    private function postMultipart(string $url, array $fields): void
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('SMS gönderimi için curl gerekli');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init başarısız');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => $this->shouldVerifySsl(),
            CURLOPT_SSL_VERIFYHOST => $this->shouldVerifySsl() ? 2 : 0,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            throw new \RuntimeException('SMS API erişilemedi: ' . ($error !== '' ? $error : 'timeout'));
        }

        if ($status < 200 || $status >= 300) {
            $snippet = mb_substr(trim((string) $body), 0, 200);
            throw new \RuntimeException(
                'SMS API HTTP ' . $status . ($snippet !== '' ? ': ' . $snippet : '')
            );
        }
    }

    private function formatBelgeTarih(?string $ymd): ?string
    {
        if ($ymd === null || trim($ymd) === '') {
            return null;
        }

        $raw = trim($ymd);
        // "2026-08-26" veya "2026-08-26 14:32"
        $datePart = substr($raw, 0, 10);
        $ts = strtotime($datePart);
        if ($ts === false) {
            return null;
        }

        return date('d.m.Y', $ts);
    }

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
