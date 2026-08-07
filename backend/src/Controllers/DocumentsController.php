<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\LogoSchema;
use App\Http\JsonResponse;
use App\Repositories\AccessTokenRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentFileRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\LogoDocumentRepository;
use App\Services\DocumentStorageService;
use App\Services\FileMimeValidator;
use App\Support\ClientIp;
use App\Support\SafeError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;
use Slim\Routing\RouteContext;

final class DocumentsController
{
    /** Uygulama limiti: 20 MiB (byte) */
    private const MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

    private const MIME_PHOTO_ATTACHMENT = [
        'image/jpeg',
        'image/png',
        'application/pdf',
    ];

    private const MIME_SIGNED = [
        'image/png',
    ];

    public function __construct(
        private readonly LogoDocumentRepository $logoDocuments,
        private readonly DocumentRepository $documents,
        private readonly DocumentFileRepository $documentFiles,
        private readonly DocumentStorageService $storage,
        private readonly AuditLogRepository $auditLog,
        private readonly FileMimeValidator $mimeValidator,
        private readonly AccessTokenRepository $accessTokens,
    ) {
    }

    public function search(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        $firmaNo = (int) ($params['firma_no'] ?? 0);
        $donemNo = (int) ($params['donem_no'] ?? 0);
        $belgeTipi = strtolower(trim((string) ($params['belge_tipi'] ?? '')));
        $cariKod = isset($params['cari_kod']) ? trim((string) $params['cari_kod']) : null;
        $belgeNo = isset($params['belge_no']) ? trim((string) $params['belge_no']) : null;
        $tarihBaslangic = isset($params['tarih_baslangic']) ? trim((string) $params['tarih_baslangic']) : null;
        $tarihBitis = isset($params['tarih_bitis']) ? trim((string) $params['tarih_bitis']) : null;

        if ($firmaNo < 1 || $donemNo < 1) {
            return JsonResponse::error($response, 'firma_no ve donem_no zorunludur', 422);
        }

        if (!in_array($belgeTipi, LogoSchema::BELGE_TIPLERI, true)) {
            return JsonResponse::error($response, 'belge_tipi siparis, irsaliye veya fatura olmalı', 422);
        }

        if ($tarihBaslangic !== null && $tarihBaslangic !== '' && !$this->isYmdDate($tarihBaslangic)) {
            return JsonResponse::error($response, 'tarih_baslangic YYYY-MM-DD formatında olmalı', 422);
        }

        if ($tarihBitis !== null && $tarihBitis !== '' && !$this->isYmdDate($tarihBitis)) {
            return JsonResponse::error($response, 'tarih_bitis YYYY-MM-DD formatında olmalı', 422);
        }

        if (
            $tarihBaslangic !== null && $tarihBaslangic !== ''
            && $tarihBitis !== null && $tarihBitis !== ''
            && $tarihBaslangic > $tarihBitis
        ) {
            return JsonResponse::error($response, 'tarih_baslangic tarih_bitis değerinden sonra olamaz', 422);
        }

        try {
            $results = $this->logoDocuments->searchDocuments(
                $firmaNo,
                $donemNo,
                $belgeTipi,
                $cariKod !== '' ? $cariKod : null,
                $belgeNo !== '' ? $belgeNo : null,
                $tarihBaslangic !== '' ? $tarihBaslangic : null,
                $tarihBitis !== '' ? $tarihBitis : null,
            );
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($response, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return JsonResponse::error(
                $response,
                SafeError::message($e, 'Logo sorgu hatası'),
                500
            );
        }

        return JsonResponse::write($response, $results);
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uid = strtolower(trim((string) ($args['uid'] ?? '')));
        if (!$this->isUuid($uid)) {
            return JsonResponse::error($response, 'Geçersiz uid', 422);
        }

        $doc = $this->documents->findByUid($uid);
        if ($doc === null) {
            return JsonResponse::error($response, 'Belge bulunamadı', 404);
        }

        try {
            $detail = $this->logoDocuments->getDocumentDetail(
                (int) $doc['firma_no'],
                (int) $doc['donem_no'],
                (int) $doc['logo_logicalref'],
                (string) $doc['belge_tipi'],
            );
        } catch (\Throwable $e) {
            return JsonResponse::error(
                $response,
                SafeError::message($e, 'Logo detay hatası'),
                500
            );
        }

        if ($detail === null) {
            return JsonResponse::error($response, 'Logo belgesi bulunamadı', 404);
        }

        // App DB uid bağlamını da ekle
        $detail['uid'] = $doc['uid'];
        $detail['document_id'] = $doc['id'];

        return JsonResponse::write($response, $detail);
    }

    /**
     * Salt-okunur: Logo anahtarıyla App DB'de kayıtlı belgeyi bulur (oluşturmaz).
     */
    public function lookup(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        $firmaNo = (int) ($params['firma_no'] ?? 0);
        $donemNo = (int) ($params['donem_no'] ?? 0);
        $logoLogicalref = (int) ($params['logo_logicalref'] ?? 0);
        $belgeTipi = strtolower(trim((string) ($params['belge_tipi'] ?? '')));

        if ($firmaNo < 1 || $donemNo < 1 || $logoLogicalref < 1) {
            return JsonResponse::error($response, 'firma_no, donem_no ve logo_logicalref zorunludur', 422);
        }

        if (!in_array($belgeTipi, LogoSchema::BELGE_TIPLERI, true)) {
            return JsonResponse::error($response, 'belge_tipi siparis, irsaliye veya fatura olmalı', 422);
        }

        $existing = $this->documents->findByLogoMatch($firmaNo, $donemNo, $belgeTipi, $logoLogicalref);
        if ($existing === null) {
            return JsonResponse::error($response, 'Belge henüz sisteme alınmamış', 404);
        }

        return JsonResponse::write($response, [
            'uid' => $existing['uid'],
            'document_id' => $existing['id'],
        ]);
    }

    public function match(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        $firmaNo = (int) ($body['firma_no'] ?? 0);
        $donemNo = (int) ($body['donem_no'] ?? 0);
        $logoLogicalref = (int) ($body['logo_logicalref'] ?? 0);
        $belgeTipi = trim((string) ($body['belge_tipi'] ?? ''));
        $cariKod = trim((string) ($body['cari_kod'] ?? ''));

        if ($firmaNo < 1 || $donemNo < 1 || $logoLogicalref < 1) {
            return JsonResponse::error($response, 'firma_no, donem_no ve logo_logicalref zorunludur', 422);
        }

        if (!in_array($belgeTipi, LogoSchema::BELGE_TIPLERI, true)) {
            return JsonResponse::error($response, 'belge_tipi siparis, irsaliye veya fatura olmalı', 422);
        }

        if ($cariKod === '') {
            return JsonResponse::error($response, 'cari_kod zorunludur', 422);
        }

        $existing = $this->documents->findByLogoMatch($firmaNo, $donemNo, $belgeTipi, $logoLogicalref);
        if ($existing !== null) {
            return JsonResponse::write($response, [
                'uid' => $existing['uid'],
                'document_id' => $existing['id'],
            ]);
        }

        try {
            $logoDetail = $this->logoDocuments->getDocumentDetail(
                $firmaNo,
                $donemNo,
                $logoLogicalref,
                $belgeTipi,
            );
        } catch (\Throwable $e) {
            return JsonResponse::error(
                $response,
                SafeError::message($e, 'Logo doğrulama hatası'),
                500
            );
        }

        if ($logoDetail === null) {
            return JsonResponse::error($response, 'Logo belgesi bulunamadı', 404);
        }

        $belgeNo = (string) ($logoDetail['belge_no'] ?? '');
        if ($belgeNo === '') {
            $belgeNo = (string) $logoLogicalref;
        }

        /** @var array<string, mixed> $user */
        $user = $request->getAttribute('user') ?? [];
        $createdBy = (int) ($user['id'] ?? 0);
        if ($createdBy < 1) {
            return JsonResponse::error($response, 'Yetkisiz erişim', 401);
        }

        try {
            $created = $this->documents->create(
                $firmaNo,
                $donemNo,
                $cariKod,
                $belgeTipi,
                $logoLogicalref,
                $belgeNo,
                $createdBy,
            );

            $this->storage->ensureDocumentFolder(
                $firmaNo,
                $donemNo,
                $cariKod,
                $belgeTipi,
                $belgeNo,
            );

            $this->auditLog->log(
                'document_matched',
                $createdBy,
                (int) $created['id'],
                $this->clientIp($request),
            );
        } catch (\Throwable $e) {
            return JsonResponse::error(
                $response,
                SafeError::message($e, 'Belge eşleştirme hatası'),
                500
            );
        }

        return JsonResponse::write($response, [
            'uid' => $created['uid'],
            'document_id' => $created['id'],
        ], 201);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uid = strtolower(trim((string) ($args['uid'] ?? '')));
        if (!$this->isUuid($uid)) {
            return JsonResponse::error($response, 'Geçersiz uid', 422);
        }

        $doc = $this->documents->findByUid($uid);
        if ($doc === null) {
            return JsonResponse::error($response, 'Belge bulunamadı', 404);
        }

        $params = $request->getQueryParams();
        $allVersions = in_array(
            strtolower((string) ($params['all_versions'] ?? '')),
            ['1', 'true', 'yes'],
            true
        );

        $files = $this->documentFiles->listForDocument((int) $doc['id'], $allVersions);
        $documentId = (int) $doc['id'];

        return JsonResponse::write($response, [
            'uid' => $doc['uid'],
            'belge_tipi' => $doc['belge_tipi'],
            'cari_kod' => $doc['cari_kod'],
            'belge_no' => $doc['belge_no'],
            'status' => $doc['status'],
            'notes' => (string) ($doc['notes'] ?? ''),
            'files' => array_map(
                fn (array $file): array => $this->mapFileForClient($request, $uid, $documentId, $file),
                $files
            ),
        ]);
    }

    public function updateNotes(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uid = strtolower(trim((string) ($args['uid'] ?? '')));
        if (!$this->isUuid($uid)) {
            return JsonResponse::error($response, 'Geçersiz uid', 422);
        }

        $doc = $this->documents->findByUid($uid);
        if ($doc === null) {
            return JsonResponse::error($response, 'Belge bulunamadı', 404);
        }

        $body = (array) $request->getParsedBody();
        if (!array_key_exists('notes', $body)) {
            return JsonResponse::error($response, 'notes alanı zorunludur', 422);
        }

        $notes = is_string($body['notes']) ? $body['notes'] : (string) $body['notes'];

        /** @var array<string, mixed> $user */
        $user = $request->getAttribute('user') ?? [];
        $userId = (int) ($user['id'] ?? 0);

        $this->documents->updateNotes((int) $doc['id'], $notes);
        $this->auditLog->log('notes_updated', $userId > 0 ? $userId : null, (int) $doc['id'], $this->clientIp($request));

        return JsonResponse::write($response, [
            'uid' => $doc['uid'],
            'notes' => $notes,
        ]);
    }

    public function deleteFile(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uid = strtolower(trim((string) ($args['uid'] ?? '')));
        $fileId = (int) ($args['fileId'] ?? 0);

        if (!$this->isUuid($uid) || $fileId < 1) {
            return JsonResponse::error($response, 'Geçersiz istek', 422);
        }

        $doc = $this->documents->findByUid($uid);
        if ($doc === null) {
            return JsonResponse::error($response, 'Belge bulunamadı', 404);
        }

        $file = $this->documentFiles->findByIdForDocument($fileId, (int) $doc['id']);
        if ($file === null) {
            return JsonResponse::error($response, 'Dosya bulunamadı', 404);
        }
        if (!empty($file['is_deleted'])) {
            return JsonResponse::error($response, 'Dosya zaten silinmiş', 410);
        }

        /** @var array<string, mixed> $user */
        $user = $request->getAttribute('user') ?? [];
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1) {
            return JsonResponse::error($response, 'Yetkisiz erişim', 401);
        }

        $wasCurrentSigned = $file['file_type'] === 'signed_document' && !empty($file['is_current']);

        try {
            $this->documentFiles->beginTransaction();
            $ok = $this->documentFiles->softDelete($fileId, $userId);
            if (!$ok) {
                $this->documentFiles->rollBack();

                return JsonResponse::error($response, 'Dosya zaten silinmiş', 410);
            }

            if ($wasCurrentSigned) {
                $this->documentFiles->promoteLatestSignedCurrent((int) $doc['id']);
            }

            $this->auditLog->log('file_deleted', $userId, (int) $doc['id'], $this->clientIp($request));
            $this->documentFiles->commit();
        } catch (\Throwable $e) {
            $this->documentFiles->rollBack();

            return JsonResponse::error(
                $response,
                SafeError::message($e, 'Dosya silinemedi'),
                500
            );
        }

        return JsonResponse::write($response, [
            'ok' => true,
            'id' => $fileId,
            'deleted' => true,
        ]);
    }

    public function createViewLink(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uid = strtolower(trim((string) ($args['uid'] ?? '')));
        $fileId = (int) ($args['fileId'] ?? 0);

        if (!$this->isUuid($uid) || $fileId < 1) {
            return JsonResponse::error($response, 'Geçersiz istek', 422);
        }

        $doc = $this->documents->findByUid($uid);
        if ($doc === null) {
            return JsonResponse::error($response, 'Belge bulunamadı', 404);
        }

        $file = $this->documentFiles->findActiveByIdForDocument($fileId, (int) $doc['id']);
        if ($file === null) {
            return JsonResponse::error($response, 'Dosya bulunamadı', 404);
        }

        $existing = $this->accessTokens->findActiveForFile((int) $doc['id'], $fileId);
        $token = $this->ensureFileAccessToken((int) $doc['id'], $fileId);
        $urls = $this->publicFileUrls($request, $uid, $fileId, $token);

        return JsonResponse::write($response, [
            'url' => $urls['viewer_url'],
            'viewer_url' => $urls['viewer_url'],
            'raw_url' => $urls['raw_url'],
            'expires_at' => null,
        ], $existing !== null ? 200 : 201);
    }

    public function downloadFile(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uid = strtolower(trim((string) ($args['uid'] ?? '')));
        $fileId = (int) ($args['fileId'] ?? 0);

        if (!$this->isUuid($uid) || $fileId < 1) {
            return JsonResponse::error($response, 'Geçersiz istek', 422);
        }

        $doc = $this->documents->findByUid($uid);
        if ($doc === null) {
            return JsonResponse::error($response, 'Belge bulunamadı', 404);
        }

        $file = $this->documentFiles->findActiveByIdForDocument($fileId, (int) $doc['id']);
        if ($file === null) {
            return JsonResponse::error($response, 'Dosya bulunamadı', 404);
        }

        try {
            $absolutePath = $this->storage->resolveAbsolutePath((string) $file['file_path']);
        } catch (\Throwable) {
            return JsonResponse::error($response, 'Dosya diskte bulunamadı', 404);
        }

        $stream = fopen($absolutePath, 'rb');
        if ($stream === false) {
            return JsonResponse::error($response, 'Dosya okunamadı', 500);
        }

        $mime = 'application/octet-stream';
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($absolutePath);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        } elseif ((string) $file['file_type'] === 'signed_document') {
            $mime = 'image/png';
        }

        $filename = (string) ($file['original_name'] ?: ('file-' . $fileId));
        $filename = str_replace(['"', "\r", "\n"], '', $filename);

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) filesize($absolutePath))
            ->withHeader(
                'Content-Disposition',
                'inline; filename="' . $filename . '"'
            )
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody(new Stream($stream))
            ->withStatus(200);
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uid = strtolower(trim((string) ($args['uid'] ?? '')));
        if (!$this->isUuid($uid)) {
            return JsonResponse::error($response, 'Geçersiz uid', 422);
        }

        $doc = $this->documents->findByUid($uid);
        if ($doc === null) {
            return JsonResponse::error($response, 'Belge bulunamadı', 404);
        }

        $body = (array) $request->getParsedBody();
        $rawFileType = $body['file_type'] ?? null;
        $fileType = is_string($rawFileType) ? trim($rawFileType) : '';

        $uploaded = $this->extractUploadedFile($request->getUploadedFiles());
        if ($uploaded === null) {
            return JsonResponse::error($response, 'file alanı zorunludur', 422);
        }

        if ($uploaded->getError() !== UPLOAD_ERR_OK) {
            if (in_array($uploaded->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                // Bu hata çoğu zaman PHP upload_max_filesize / post_max_size yüzünden gelir
                // (uygulama 20MB limitinden ÖNCE). Mesajı yanıltıcı olmasın diye ayır.
                $phpLimit = (string) ini_get('upload_max_filesize');
                $phpBytes = self::iniSizeToBytes($phpLimit);
                if ($phpBytes > 0 && $phpBytes < self::MAX_UPLOAD_BYTES) {
                    return JsonResponse::error(
                        $response,
                        'Dosya, PHP yükleme limitini aşıyor (upload_max_filesize='
                            . $phpLimit . '). Sunucu php.ini içinde en az 20M olmalıdır.',
                        413
                    );
                }

                return JsonResponse::error($response, 'Dosya boyutu 20MB sınırını aşıyor', 413);
            }

            return JsonResponse::error($response, 'Dosya yükleme hatası', 422);
        }

        $size = $uploaded->getSize();
        if ($size !== null && $size > self::MAX_UPLOAD_BYTES) {
            return JsonResponse::error($response, 'Dosya boyutu 20MB sınırını aşıyor', 413);
        }

        // file_type gönderilmediyse yalnızca photo/attachment otomatik tespit edilir.
        // signed_document asla otomatik atanmaz; açıkça gönderilmelidir.
        if ($fileType === '') {
            $detectedMime = $this->mimeValidator->detectMime($uploaded);
            if ($detectedMime === null) {
                return JsonResponse::error($response, 'Dosya içeriği okunamadı', 422);
            }

            $inferred = $this->mimeValidator->inferManualFileType($detectedMime);
            if ($inferred === null) {
                return JsonResponse::error($response, 'Dosya türü izin verilen listede değil', 422);
            }

            $fileType = $inferred;
        } elseif (!in_array($fileType, DocumentFileRepository::FILE_TYPES, true)) {
            return JsonResponse::error(
                $response,
                'file_type photo, attachment veya signed_document olmalı',
                422
            );
        }

        $mimeError = $this->validateMime($uploaded, $fileType);
        if ($mimeError !== null) {
            return JsonResponse::error($response, $mimeError, 422);
        }

        /** @var array<string, mixed> $user */
        $user = $request->getAttribute('user') ?? [];
        $uploadedBy = (int) ($user['id'] ?? 0);
        if ($uploadedBy < 1) {
            return JsonResponse::error($response, 'Yetkisiz erişim', 401);
        }

        $version = null;
        $isCurrent = true;
        $stored = null;

        try {
            $this->documentFiles->beginTransaction();

            if ($fileType === 'signed_document') {
                $version = $this->documentFiles->getMaxSignedVersion((int) $doc['id']) + 1;
                $this->documentFiles->markSignedDocumentsNotCurrent((int) $doc['id']);
            }

            $stored = $this->storage->storeUploadedFile($doc, $fileType, $uploaded, $version);

            if ($stored['file_size'] > self::MAX_UPLOAD_BYTES) {
                @unlink($stored['absolute_path']);
                $this->documentFiles->rollBack();

                return JsonResponse::error($response, 'Dosya boyutu 20MB sınırını aşıyor', 413);
            }

            $originalName = (string) ($uploaded->getClientFilename() ?? $stored['stored_name']);
            $record = $this->documentFiles->create(
                (int) $doc['id'],
                $stored['relative_path'],
                $fileType,
                $version,
                $isCurrent,
                $originalName,
                $stored['file_size'],
                $uploadedBy,
            );

            $this->auditLog->log(
                'file_uploaded',
                $uploadedBy,
                (int) $doc['id'],
                $this->clientIp($request),
            );

            $this->documentFiles->commit();
        } catch (\Throwable $e) {
            $this->documentFiles->rollBack();
            if (is_array($stored) && isset($stored['absolute_path']) && is_file($stored['absolute_path'])) {
                @unlink($stored['absolute_path']);
            }

            return JsonResponse::error(
                $response,
                SafeError::message($e, 'Dosya kaydedilemedi'),
                500
            );
        }

        return JsonResponse::write($response, [
            'id' => $record['id'],
            'file_type' => $record['file_type'],
            'version' => $record['version'],
            'uploaded_at' => $record['uploaded_at'],
            ...$this->publicFileUrls(
                $request,
                $uid,
                (int) $record['id'],
                $this->ensureFileAccessToken((int) $doc['id'], (int) $record['id']),
            ),
        ], 201);
    }

    /**
     * @param array<string, mixed> $files
     */
    private function extractUploadedFile(array $files): ?UploadedFileInterface
    {
        $file = $files['file'] ?? null;

        if ($file instanceof UploadedFileInterface) {
            return $file;
        }

        if (is_array($file) && isset($file[0]) && $file[0] instanceof UploadedFileInterface) {
            return $file[0];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private function mapFileForClient(
        ServerRequestInterface $request,
        string $uid,
        int $documentId,
        array $file,
    ): array {
        $fileId = (int) $file['id'];
        $token = $this->ensureFileAccessToken($documentId, $fileId);

        return [
            'id' => $fileId,
            'file_type' => $file['file_type'],
            'version' => $file['version'],
            'is_current' => $file['is_current'],
            'original_name' => $file['original_name'],
            'file_size' => $file['file_size'],
            'uploaded_at' => $file['uploaded_at'],
            'uploaded_by_name' => $file['uploaded_by_name'] ?? null,
            ...$this->publicFileUrls($request, $uid, $fileId, $token),
        ];
    }

    /**
     * @return array{viewer_url: string, raw_url: string}
     */
    private function publicFileUrls(
        ServerRequestInterface $request,
        string $uid,
        int $fileId,
        string $token,
    ): array {
        $base = $this->absolutePublicBase($request);
        $path = '/belge/' . rawurlencode($uid) . '/dosya/' . $fileId;
        $query = '?token=' . rawurlencode($token);

        return [
            'viewer_url' => $base . $path . $query,
            'raw_url' => $base . $path . '/raw' . $query,
        ];
    }

    private function ensureFileAccessToken(int $documentId, int $fileId): string
    {
        $existing = $this->accessTokens->findActiveForFile($documentId, $fileId);
        if ($existing !== null) {
            return (string) $existing['token'];
        }

        $created = $this->accessTokens->create(
            $documentId,
            $fileId,
            bin2hex(random_bytes(32)),
            null,
        );

        return (string) $created['token'];
    }

    private function absolutePublicBase(ServerRequestInterface $request): string
    {
        $routeContext = RouteContext::fromRequest($request);
        $basePath = rtrim($routeContext->getBasePath(), '/');

        $uri = $request->getUri();
        $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'http';
        $host = $uri->getHost() !== '' ? $uri->getHost() : 'localhost';
        $port = $uri->getPort();
        $portPart = ($port !== null && !in_array($port, [80, 443], true)) ? ':' . $port : '';

        return $scheme . '://' . $host . $portPart . $basePath;
    }

    private function validateMime(UploadedFileInterface $uploaded, string $fileType): ?string
    {
        $allowed = $fileType === 'signed_document'
            ? self::MIME_SIGNED
            : self::MIME_PHOTO_ATTACHMENT;

        $error = $this->mimeValidator->validate($uploaded, $allowed);
        if ($error === null) {
            return null;
        }

        if ($fileType === 'signed_document') {
            return 'signed_document için yalnızca geçerli image/png kabul edilir';
        }

        return $error;
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }

    private function isYmdDate(string $value): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $dt !== false && $dt->format('Y-m-d') === $value;
    }

    private function clientIp(ServerRequestInterface $request): ?string
    {
        return ClientIp::from($request);
    }

    /**
     * php.ini boyut dizgisini (örn. "2M", "512K") byte'a çevirir.
     */
    private static function iniSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d+)([KMG]?)B?$/i', $value, $m)) {
            return 0;
        }

        $n = (int) $m[1];
        return match (strtoupper($m[2] ?? '')) {
            'G' => $n * 1024 * 1024 * 1024,
            'M' => $n * 1024 * 1024,
            'K' => $n * 1024,
            default => $n,
        };
    }
}
