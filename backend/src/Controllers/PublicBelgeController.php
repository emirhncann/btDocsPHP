<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AccessTokenRepository;
use App\Repositories\DocumentFileRepository;
use App\Repositories\DocumentRepository;
use App\Services\DocumentStorageService;
use App\Services\PublicAccessGuard;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Stream;

/**
 * /belge/{uid} ve /belge/{uid}/dosya/{id} — auth’lu shell (JWT client-side).
 * /belge/.../raw — AccessTokens ile ham binary (auth’suz, token query).
 */
final class PublicBelgeController
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentFileRepository $documentFiles,
        private readonly DocumentStorageService $storage,
        private readonly PublicAccessGuard $accessGuard,
        private readonly AccessTokenRepository $accessTokens,
    ) {
    }

    public function page(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uid = strtolower(trim((string) ($args['uid'] ?? '')));
        if (!$this->isUuid($uid)) {
            return $this->html($response, $this->renderMessage('Geçersiz bağlantı', 'Belge bulunamadı.'), 404);
        }

        // Belge var mı diye erken 404 (auth sonrası boş sayfa yerine)
        if ($this->documents->findByUid($uid) === null) {
            return $this->html($response, $this->renderMessage('Belge bulunamadı', 'İstenen belge bulunamadı veya silinmiş olabilir.'), 404);
        }

        return $this->html($response, $this->renderAuthShell('index', $uid, null), 200);
    }

    public function file(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uid = strtolower(trim((string) ($args['uid'] ?? '')));
        $fileId = (int) ($args['fileId'] ?? 0);

        if (!$this->isUuid($uid) || $fileId < 1) {
            return $this->html($response, $this->renderMessage('Geçersiz bağlantı', 'Bağlantı geçersiz.'), 400);
        }

        $doc = $this->documents->findByUid($uid);
        if ($doc === null) {
            return $this->html($response, $this->renderMessage('Belge bulunamadı', 'İstenen belge bulunamadı.'), 404);
        }

        $file = $this->documentFiles->findActiveByIdForDocument($fileId, (int) $doc['id']);
        if ($file === null) {
            return $this->html($response, $this->renderMessage('Dosya bulunamadı', 'Dosya silinmiş veya mevcut değil.'), 404);
        }

        return $this->html($response, $this->renderAuthShell('file', $uid, $fileId), 200);
    }

    public function image(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uid = strtolower(trim((string) ($args['uid'] ?? '')));
        if (!$this->isUuid($uid)) {
            return $response->withStatus(404);
        }

        $doc = $this->documents->findByUid($uid);
        if ($doc === null) {
            return $response->withStatus(404);
        }

        $params = $request->getQueryParams();
        $token = isset($params['token']) ? (string) $params['token'] : null;
        if ($this->accessGuard->validate($token, (int) $doc['id']) !== null) {
            return $response->withStatus(403);
        }

        $versionParam = isset($params['version']) ? trim((string) $params['version']) : '';
        $requestedVersion = $versionParam !== '' ? (int) $versionParam : null;

        if ($requestedVersion !== null) {
            $signed = $this->documentFiles->findSignedByVersion((int) $doc['id'], $requestedVersion);
        } else {
            $signed = $this->documentFiles->findCurrentSigned((int) $doc['id']);
        }

        if ($signed === null) {
            return $response->withStatus(404);
        }

        $asDownload = in_array(strtolower((string) ($params['download'] ?? '')), ['1', 'true', 'yes'], true);

        return $this->streamFile(
            $response,
            (string) $signed['file_path'],
            (string) ($signed['original_name'] ?: 'imzali-belge.png'),
            'image/png',
            $asDownload
        );
    }

    /**
     * Ham dosya — AccessTokens query token; JWT gerektirmez.
     */
    public function fileRaw(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $uid = strtolower(trim((string) ($args['uid'] ?? '')));
        $fileId = (int) ($args['fileId'] ?? 0);

        if (!$this->isUuid($uid) || $fileId < 1) {
            return $response->withStatus(400);
        }

        $doc = $this->documents->findByUid($uid);
        if ($doc === null) {
            return $response->withStatus(404);
        }

        $params = $request->getQueryParams();
        $token = trim((string) ($params['token'] ?? ''));
        if ($token === '' || !$this->accessTokens->isValidForFile($token, (int) $doc['id'], $fileId)) {
            return $response->withStatus(403);
        }

        $file = $this->documentFiles->findByIdForDocument($fileId, (int) $doc['id']);
        if ($file === null || !empty($file['is_deleted'])) {
            return $response->withStatus(404);
        }

        try {
            $absolutePath = $this->storage->resolveAbsolutePath((string) $file['file_path']);
        } catch (\Throwable) {
            return $response->withStatus(404);
        }

        $mime = $this->detectMime($absolutePath, (string) $file['file_type']);
        $filename = (string) ($file['original_name'] ?: ('file-' . $fileId));
        $asDownload = in_array(strtolower((string) ($params['download'] ?? '')), ['1', 'true', 'yes'], true);

        return $this->streamFile($response, (string) $file['file_path'], $filename, $mime, $asDownload);
    }

    private function renderAuthShell(string $mode, string $uid, ?int $fileId): string
    {
        $uidAttr = $this->e($uid);
        $fileAttr = $fileId !== null ? (string) $fileId : '';
        $title = $mode === 'file' ? 'Dosya Görüntüleme' : 'Belge Dosyaları';
        $titleEsc = $this->e($title);
        $pageTitle = $mode === 'file' ? 'Dosya' : 'Belge';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="tr">
        <head>
          <meta charset="utf-8" />
          <meta name="viewport" content="width=device-width, initial-scale=1" />
          <meta name="robots" content="noindex,nofollow" />
          <title>btDocs — {$titleEsc}</title>
          <link rel="icon" href="/assets/brand/favicon.png" type="image/png" />
          <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/css/tabler.min.css" />
          <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.34.1/dist/tabler-icons.min.css" />
          <link rel="stylesheet" href="/admin/css/brand.css" />
          <link rel="stylesheet" href="/admin/css/admin.css" />
          <link rel="stylesheet" href="/admin/css/belge.css" />
        </head>
        <body
          data-belge-mode="{$this->e($mode)}"
          data-belge-uid="{$uidAttr}"
          data-belge-file-id="{$fileAttr}"
        >
          <aside class="navbar navbar-vertical navbar-expand-lg" data-bs-theme="dark">
            <div class="container-fluid">
              <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Menüyü aç/kapat">
                <span class="navbar-toggler-icon"></span>
              </button>
              <h1 class="navbar-brand">
                <a href="/admin/dashboard.html">
                  <img src="/assets/brand/btdocs.png" height="32" class="navbar-brand-image" alt="BTDocs" />
                </a>
              </h1>
              <div class="collapse navbar-collapse" id="sidebar-menu">
                <ul class="navbar-nav pt-lg-3">
                  <li class="nav-item">
                    <a class="nav-link" href="/admin/dashboard.html" data-nav="dashboard">
                      <span class="nav-link-title">Dashboard</span>
                    </a>
                  </li>
                  <li class="nav-item" data-admin-only>
                    <a class="nav-link" href="/admin/users.html" data-nav="users">
                      <span class="nav-link-title">Kullanıcılar</span>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a class="nav-link" href="/admin/documents.html" data-nav="documents">
                      <span class="nav-link-title">Belgeler</span>
                    </a>
                  </li>
                  <li class="nav-item" data-admin-only>
                    <a class="nav-link" href="/admin/settings.html" data-nav="settings">
                      <span class="nav-link-title">Ayarlar</span>
                    </a>
                  </li>
                </ul>
                <div class="mt-auto p-3">
                  <button type="button" id="logout-btn" class="btn btn-outline-light w-100" data-action="logout">Çıkış Yap</button>
                </div>
              </div>
            </div>
          </aside>

          <div class="page-wrapper">
            <div class="page-header d-print-none">
              <div class="container-xl">
                <div class="row align-items-center g-2">
                  <div class="col">
                    <div class="page-pretitle">Belge</div>
                    <h2 class="page-title" id="belge-page-title">{$this->e($pageTitle)}</h2>
                  </div>
                  <div class="col-auto">
                    <div class="text-end">
                      <div class="fw-bold" id="topbar-user-name">—</div>
                      <div class="text-secondary small" id="topbar-user-role"></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="page-body">
              <div class="container-xl">
                <div id="belge-loading" class="text-center py-5">
                  <div class="spinner-border text-primary" role="status"></div>
                  <div class="text-secondary mt-2">Yükleniyor…</div>
                </div>
                <div id="belge-error" class="alert alert-danger d-none" role="alert"></div>
                <div id="belge-root" class="d-none"></div>
              </div>
            </div>
          </div>

          <div id="toast" class="belge-toast d-print-none" hidden></div>
          <div class="print-footer">btDocs&nbsp;•&nbsp;www.boluteknoloji.com &nbsp;•&nbsp; 0 374 606 06 65</div>

          <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
          <script src="https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/js/tabler.min.js"></script>
          <script src="/admin/js/config.js"></script>
          <script src="/admin/js/api.js"></script>
          <script src="/admin/js/utils.js"></script>
          <script src="/admin/js/layout.js"></script>
          <script src="/admin/js/belge.js"></script>
        </body>
        </html>
        HTML;
    }

    private function streamFile(
        ResponseInterface $response,
        string $relativePath,
        string $filename,
        string $mime,
        bool $asAttachment,
    ): ResponseInterface {
        try {
            $absolutePath = $this->storage->resolveAbsolutePath($relativePath);
        } catch (\Throwable) {
            return $response->withStatus(404);
        }

        $stream = fopen($absolutePath, 'rb');
        if ($stream === false) {
            return $response->withStatus(500);
        }

        $safeName = str_replace(['"', "\r", "\n"], '', $filename);
        $disposition = ($asAttachment ? 'attachment' : 'inline') . '; filename="' . $safeName . '"';

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string) filesize($absolutePath))
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody(new Stream($stream))
            ->withStatus(200);
    }

    private function detectMime(string $absolutePath, string $fileType): string
    {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($absolutePath);
            if (is_string($detected) && $detected !== '') {
                return $detected === 'image/jpg' ? 'image/jpeg' : $detected;
            }
        }

        return match ($fileType) {
            'signed_document', 'photo' => 'image/png',
            'attachment' => 'application/pdf',
            default => 'application/octet-stream',
        };
    }

    private function renderMessage(string $title, string $message): string
    {
        $t = $this->e($title);
        $m = $this->e($message);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="tr">
        <head>
          <meta charset="utf-8" />
          <meta name="viewport" content="width=device-width, initial-scale=1" />
          <title>{$t}</title>
          <link rel="stylesheet" href="/admin/css/brand.css" />
          <style>
            body { font-family: system-ui, sans-serif; background:#f4f1f2; margin:0; padding:2rem; color:#1a1a1a; }
            .box { max-width:480px; margin:2rem auto; background:#fff; border-radius:12px; padding:1.5rem; box-shadow:0 1px 3px rgba(0,0,0,.08); }
            h1 { font-size:1.25rem; margin:0 0 .5rem; }
            p { margin:0; color:#4b5563; }
          </style>
        </head>
        <body><div class="box"><h1>{$t}</h1><p>{$m}</p></div></body>
        </html>
        HTML;
    }

    private function html(ResponseInterface $response, string $html, int $status): ResponseInterface
    {
        $response->getBody()->write($html);

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withStatus($status);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        );
    }
}
