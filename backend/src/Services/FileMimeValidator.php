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
        try {
            $probe = $this->probeUploadedPath($uploaded, 'detectMime');
            if ($probe['fail_reason'] !== null && $probe['detected'] === null) {
                return null;
            }

            return $probe['detected'];
        } catch (\Throwable $e) {
            error_log('UPLOAD READ DEBUG: ' . json_encode([
                'step' => 'detectMime',
                'fail_reason' => 'exception',
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return null;
        }
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
        try {
            $probe = $this->probeUploadedPath($uploaded, 'validate');
            if ($probe['fail_reason'] !== null && $probe['detected'] === null) {
                return 'Dosya içeriği okunamadı';
            }

            $detected = $probe['detected'] ?? '';
            if (!in_array($detected, $allowedMime, true)) {
                return 'Dosya türü izin verilen listede değil';
            }

            $uri = $probe['uri'];
            $header = '';
            if (is_string($uri) && is_file($uri)) {
                $handle = fopen($uri, 'rb');
                if ($handle === false) {
                    error_log('UPLOAD READ DEBUG: ' . json_encode([
                        'step' => 'validate.fopen',
                        'fail_reason' => 'fopen_failed',
                        'function' => 'fopen',
                        'path' => $uri,
                        'php_error' => $uploaded->getError(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                    return 'Dosya içeriği okunamadı';
                }
                $header = (string) fread($handle, 16);
                fclose($handle);
            } else {
                // Path yoksa (php://temp vb.) stream'den magic okunur
                $stream = $uploaded->getStream();
                if ($stream->isSeekable()) {
                    $stream->rewind();
                }
                $header = (string) $stream->read(16);
                if ($stream->isSeekable()) {
                    $stream->rewind();
                }
            }

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

            return null;
        } catch (\Throwable $e) {
            error_log('UPLOAD READ DEBUG: ' . json_encode([
                'step' => 'validate',
                'fail_reason' => 'exception',
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return 'Dosya içeriği okunamadı';
        }
    }

    /**
     * Stream URI / finfo_file / finfo_buffer ile MIME tespiti + detaylı debug.
     *
     * @return array{
     *   uri: mixed,
     *   detected: ?string,
     *   fail_reason: ?string
     * }
     */
    private function probeUploadedPath(UploadedFileInterface $uploaded, string $step): array
    {
        $stream = $uploaded->getStream();
        $uri = $stream->getMetadata('uri');
        $uploadTmpDir = (string) (ini_get('upload_tmp_dir') ?: '');
        $sysTemp = sys_get_temp_dir();

        $uriIsString = is_string($uri);
        $isFile = $uriIsString && is_file($uri);
        $fileExists = $uriIsString && file_exists($uri);
        $isReadable = $uriIsString && is_readable($uri);
        $underUploadTmp = false;
        if ($uriIsString && $uploadTmpDir !== '') {
            $normUri = strtolower(str_replace('\\', '/', $uri));
            $normTmp = strtolower(rtrim(str_replace('\\', '/', $uploadTmpDir), '/')) . '/';
            $underUploadTmp = str_starts_with($normUri, $normTmp);
        }

        $baseLog = [
            'step' => $step,
            'function' => 'getStream()->getMetadata(uri)',
            'php_error' => $uploaded->getError(),
            'size' => $uploaded->getSize(),
            'client_filename' => $uploaded->getClientFilename(),
            'client_media_type' => $uploaded->getClientMediaType(),
            'uri' => $uri,
            'upload_tmp_dir' => $uploadTmpDir !== '' ? $uploadTmpDir : '(empty→sys_temp)',
            'sys_temp_dir' => $sysTemp,
            'uri_under_upload_tmp_dir' => $underUploadTmp,
            'is_file' => $isFile,
            'file_exists' => $fileExists,
            'is_readable' => $isReadable,
            'fileinfo_loaded' => extension_loaded('fileinfo'),
            'finfo_class_exists' => class_exists(\finfo::class),
            'mime_magic.magicfile' => ini_get('mime_magic.magicfile') ?: '(not set)',
        ];

        if (!class_exists(\finfo::class)) {
            $baseLog['fail_reason'] = 'finfo_class_missing';
            error_log('UPLOAD READ DEBUG: ' . json_encode($baseLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return ['uri' => $uri, 'detected' => null, 'fail_reason' => 'finfo_class_missing'];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $raw = false;
        $method = null;

        if ($isFile) {
            $method = 'finfo::file';
            $raw = $finfo->file($uri);
            $baseLog['function'] = 'finfo::file';
            $baseLog['path'] = $uri;
            $baseLog['finfo_file_raw'] = $raw === false ? 'FALSE' : $raw;
        }

        // Path yok / finfo_file başarısız → stream buffer fallback
        if ($raw === false || $raw === '') {
            $method = $method === null ? 'finfo::buffer' : $method . '+finfo::buffer_fallback';
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $contents = $stream->getContents();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $raw = $finfo->buffer($contents);
            $baseLog['function'] = $method;
            $baseLog['path'] = $uri;
            $baseLog['buffer_bytes'] = strlen($contents);
            $baseLog['finfo_buffer_raw'] = $raw === false ? 'FALSE' : $raw;
            if (!$isFile) {
                $baseLog['fail_reason_primary'] = 'uri_not_a_file_or_missing';
            } elseif (($baseLog['finfo_file_raw'] ?? '') === 'FALSE' || ($baseLog['finfo_file_raw'] ?? '') === '') {
                $baseLog['fail_reason_primary'] = 'finfo_file_false_or_empty';
            }
        }

        if ($raw === false || $raw === '') {
            $baseLog['fail_reason'] = 'finfo_returned_false_or_empty';
            error_log('UPLOAD READ DEBUG: ' . json_encode($baseLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return ['uri' => $uri, 'detected' => null, 'fail_reason' => 'finfo_returned_false_or_empty'];
        }

        $detected = strtolower((string) $raw);
        if ($detected === 'image/jpg') {
            $detected = 'image/jpeg';
        }

        $baseLog['fail_reason'] = null;
        $baseLog['detected'] = $detected;
        $baseLog['function'] = $method;
        error_log('UPLOAD READ DEBUG: ' . json_encode($baseLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['uri' => $uri, 'detected' => $detected, 'fail_reason' => null];
    }
}
