<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response = new \Slim\Psr7\Response(204);
            return $this->withCorsHeaders($response, $origin);
        }

        $response = $handler->handle($request);

        return $this->withCorsHeaders($response, $origin);
    }

    private function withCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        $allowed = $this->allowedOrigin($origin);
        if ($allowed !== null) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $allowed)
                ->withHeader('Vary', 'Origin');
        }

        return $response
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Access-Control-Max-Age', '86400');
    }

    private function allowedOrigin(string $origin): ?string
    {
        $raw = Settings::get('CORS_ALLOWED_ORIGINS', '') ?? '';
        $list = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $env = strtolower((string) (Settings::get('APP_ENV', 'production') ?? 'production'));
        $isProd = $env === 'production';

        // Development: * veya boş liste → gelen Origin'i yansıt (LAN test için)
        if (!$isProd && ($list === [] || in_array('*', $list, true))) {
            if ($origin !== '') {
                return $origin;
            }

            return '*';
        }

        if ($origin === '') {
            return null;
        }

        // Production'da wildcard yok
        $list = array_values(array_filter($list, static fn (string $o): bool => $o !== '*'));

        if ($list === []) {
            return null;
        }

        return in_array($origin, $list, true) ? $origin : null;
    }
}
