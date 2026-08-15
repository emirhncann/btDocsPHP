<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\LicenseStatusRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class LicenseGuardMiddleware implements MiddlewareInterface
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const ALLOWLIST_PATHS = [
        '/api/auth/login',
        '/api/health',
        '/api/admin/license/check',
    ];

    public function __construct(
        private readonly LicenseStatusRepository $licenseStatus,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, self::WRITE_METHODS, true)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        foreach (self::ALLOWLIST_PATHS as $allowed) {
            if ($path === $allowed || str_ends_with($path, $allowed)) {
                return $handler->handle($request);
            }
        }

        if ($this->licenseStatus->allowsWrites()) {
            return $handler->handle($request);
        }

        $response = new Response(403);
        $response->getBody()->write((string) json_encode(
            [
                'error' => 'Lisans süresi dolmuş. Yazma işlemleri devre dışı bırakıldı. Lütfen lisansınızı yenileyin.',
                'error_type' => 'license_expired',
            ],
            JSON_UNESCAPED_UNICODE
        ));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
