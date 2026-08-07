<?php

declare(strict_types=1);

namespace App\Services;

use Psr\Http\Message\UploadedFileInterface;

final class FileMimeValidator
{
    private const MAGIC = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png' => ["\x89PNG\r\n\x1A\n"],
        'application/pdf' => ['%PDF'],
    ];

    /**
     * İçerik tabanlı MIME tespiti (finfo). Başarısızsa null.
     */
    public function detectMime(UploadedFileInterface $uploaded): ?string
    {
        $stream = $uploaded->getStream();
        $uri = $stream->getMetadata('uri');
        if (!is_string($uri) || !is_file($uri)) {
            return null;
        }

        if (!class_exists(\finfo::class)) {
            return null;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = strtolower((string) $finfo->file($uri));
        if ($detected === 'image/jpg') {
            $detected = 'image/jpeg';
        }

        return $detected !== '' ? $detected : null;
    }

    /**
     * photo / attachment çıkarımı. signed_document asla otomatik atanmaz.
     */
    public function inferManualFileType(?string $mime): ?string
    {
        if ($mime === null || $mime === '') {
            return null;
        }

        $mime = strtolower($mime);
        if ($mime === 'image/jpg') {
            $mime = 'image/jpeg';
        }

        if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return 'photo';
        }

        if ($mime === 'application/pdf') {
            return 'attachment';
        }

        return null;
    }

    /**
     * @param list<string> $allowedMime
     */
    public function validate(UploadedFileInterface $uploaded, array $allowedMime): ?string
    {
        $stream = $uploaded->getStream();
        $uri = $stream->getMetadata('uri');
        if (!is_string($uri) || !is_file($uri)) {
            return 'Dosya içeriği okunamadı';
        }

        if (!class_exists(\finfo::class)) {
            return 'Sunucuda fileinfo uzantısı gerekli';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = strtolower((string) $finfo->file($uri));
        if ($detected === 'image/jpg') {
            $detected = 'image/jpeg';
        }

        if (!in_array($detected, $allowedMime, true)) {
            return 'Dosya türü izin verilen listede değil';
        }

        $handle = fopen($uri, 'rb');
        if ($handle === false) {
            return 'Dosya içeriği okunamadı';
        }
        $header = (string) fread($handle, 16);
        fclose($handle);

        $signatures = self::MAGIC[$detected] ?? [];
        $matched = false;
        foreach ($signatures as $sig) {
            if (str_starts_with($header, $sig)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            return 'Dosya içeriği (magic bytes) beyan edilen tür ile uyuşmuyor';
        }

        // Uzantı istem tarafında yanıltıcı olabilir; içeriğe güvenilir.
        // signed_document için sadece png — çağıran taraf allowedMime ile kısıtlar.

        return null;
    }
}
