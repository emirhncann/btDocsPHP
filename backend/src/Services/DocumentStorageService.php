<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\LogoSchema;
use App\Config\Settings;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

final class DocumentStorageService
{
    public function __construct(
        private readonly LogoSchema $schema,
    ) {
    }

    public function getRoot(): string
    {
        $root = Settings::get('DOC_STORAGE_ROOT');
        if ($root === null || $root === '') {
            $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'DocStorage';
        }

        return rtrim($root, "\\/");
    }

    /**
     * /DocStorage/{FirmaNo}/{DonemNo}/{CariKod}/{BelgeTipi}/{BelgeNo}/
     */
    public function ensureDocumentFolder(
        int $firmaNo,
        int $donemNo,
        string $cariKod,
        string $belgeTipi,
        string $belgeNo,
    ): string {
        $path = implode(DIRECTORY_SEPARATOR, [
            $this->getRoot(),
            $this->schema->firmCode($firmaNo),
            $this->schema->periodCode($donemNo),
            $this->sanitizeSegment($cariKod),
            $this->sanitizeSegment($belgeTipi),
            $this->sanitizeSegment($belgeNo !== '' ? $belgeNo : 'UNKNOWN'),
        ]);

        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Belge klasörü oluşturulamadı: ' . $path);
        }

        return $path;
    }

    /**
     * Dosyayı belge klasörüne kaydeder.
     *
     * @param array{firma_no:int,donem_no:int,cari_kod:string,belge_tipi:string,belge_no:string} $document
     * @return array{absolute_path:string,relative_path:string,file_size:int,stored_name:string}
     */
    public function storeUploadedFile(
        array $document,
        string $fileType,
        UploadedFileInterface $uploadedFile,
        ?int $signedVersion = null,
    ): array {
        $folder = $this->ensureDocumentFolder(
            (int) $document['firma_no'],
            (int) $document['donem_no'],
            (string) $document['cari_kod'],
            (string) $document['belge_tipi'],
            (string) ($document['belge_no'] ?? ''),
        );

        $originalName = (string) ($uploadedFile->getClientFilename() ?? 'upload.bin');
        $storedName = $this->buildStoredName($fileType, $originalName, $signedVersion);
        $absolutePath = $folder . DIRECTORY_SEPARATOR . $storedName;

        $uploadedFile->moveTo($absolutePath);

        if (!is_file($absolutePath)) {
            throw new RuntimeException('Dosya diske yazılamadı');
        }

        $relativePath = ltrim(
            str_replace('\\', '/', substr($absolutePath, strlen($this->getRoot()))),
            '/'
        );

        return [
            'absolute_path' => $absolutePath,
            'relative_path' => $relativePath,
            'file_size' => (int) filesize($absolutePath),
            'stored_name' => $storedName,
        ];
    }

    /**
     * DB'deki relative path'i güvenli absolute path'e çevirir (path traversal engelli).
     */
    public function resolveAbsolutePath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '' || str_contains($relativePath, '..')) {
            throw new RuntimeException('Geçersiz dosya yolu');
        }

        $root = $this->getRoot();
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $realRoot = realpath($root);
        $realFile = realpath($absolute);

        if ($realRoot === false || $realFile === false || !is_file($realFile)) {
            throw new RuntimeException('Dosya bulunamadı');
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
        $fileNormalized = str_replace('\\', '/', $realFile);
        if (!str_starts_with($fileNormalized, $rootPrefix) && $fileNormalized !== rtrim($rootPrefix, '/')) {
            throw new RuntimeException('Dosya kök dizin dışında');
        }

        return $realFile;
    }

    /**
     * DOC_STORAGE_ROOT/_brand/company-logo.{png|jpg}
     *
     * @return array{absolute_path:string,relative_path:string,file_size:int}
     */
    public function storeCompanyLogo(UploadedFileInterface $uploadedFile, string $mime): array
    {
        $ext = match (strtolower($mime)) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            default => throw new RuntimeException('Desteklenmeyen logo MIME'),
        };

        $folder = $this->ensureBrandFolder();
        $this->deleteCompanyLogoFiles($folder);

        $storedName = 'company-logo.' . $ext;
        $absolutePath = $folder . DIRECTORY_SEPARATOR . $storedName;
        $uploadedFile->moveTo($absolutePath);

        $size = filesize($absolutePath);
        if ($size === false) {
            throw new RuntimeException('Logo boyutu okunamadı');
        }

        return [
            'absolute_path' => $absolutePath,
            'relative_path' => '_brand/' . $storedName,
            'file_size' => (int) $size,
        ];
    }

    public function deleteCompanyLogo(): void
    {
        $folder = $this->getRoot() . DIRECTORY_SEPARATOR . '_brand';
        if (!is_dir($folder)) {
            return;
        }
        $this->deleteCompanyLogoFiles($folder);
    }

    public function resolveCompanyLogoAbsolute(?string $relativePath): ?string
    {
        if ($relativePath === null || trim($relativePath) === '') {
            return null;
        }

        try {
            return $this->resolveAbsolutePath($relativePath);
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureBrandFolder(): string
    {
        $path = $this->getRoot() . DIRECTORY_SEPARATOR . '_brand';
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Logo klasörü oluşturulamadı: ' . $path);
        }

        return $path;
    }

    private function deleteCompanyLogoFiles(string $folder): void
    {
        foreach (['company-logo.png', 'company-logo.jpg', 'company-logo.jpeg'] as $name) {
            $path = $folder . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function buildStoredName(string $fileType, string $originalName, ?int $signedVersion): string
    {
        if ($fileType === 'signed_document') {
            $version = $signedVersion ?? 1;

            return 'signed_v' . $version . '.png';
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';
        $suffix = $ext !== '' ? '.' . $ext : '';

        return date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . $suffix;
    }

    private function sanitizeSegment(string $value): string
    {
        $value = trim($value);
        // Path traversal / ayırıcı temizliği
        $value = str_replace(['..', "\0"], '', $value);
        $value = preg_replace('/[<>:"|?*\\\\\\/]+/', '_', $value) ?? '_';
        $value = preg_replace('/[^A-Za-z0-9._\-\p{L}\p{N} ]/u', '_', $value) ?? '_';
        $value = trim($value, ". \t\n\r\0\x0B");

        return $value === '' ? '_' : $value;
    }
}
