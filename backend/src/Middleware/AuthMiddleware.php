<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\UserRepository;
use App\Services\JwtService;
use App\Services\LicenseCheckService;
use App\Support\SafeError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly JwtService $jwt,
        private readonly UserRepository $users,
        private readonly LicenseCheckService $licenseCheck,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');

        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return $this->unauthorized();
        }

        try {
            $claims = $this->jwt->decodeToken($matches[1]);
        } catch (\Throwable) {
            return $this->unauthorized();
        }

        $user = $this->users->findById($claims['user_id']);

        if ($user === null || !UserRepository::isTruthy($user['is_active'] ?? false)) {
            return $this->unauthorized();
        }

        $request = $request->withAttribute('user', [
            'id' => (int) $user['id'],
            'user_id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'full_name' => (string) $user['full_name'],
            'role' => (string) $user['role'],
            'default_firma_no' => UserRepository::nullableInt($user['default_firma_no'] ?? null),
            'default_donem_no' => UserRepository::nullableInt($user['default_donem_no'] ?? null),
        ]);

        // Authenticated her istek: 24 saatte bir Proje C lisans doğrulaması (çoğu istekte SELECT + return)
        try {
            $this->licenseCheck->ensureFreshCheck();
        } catch (\Throwable $e) {
            SafeError::log($e, 'auth.license.ensureFreshCheck');
        }

        return $handler->handle($request);
    }

    private function unauthorized(): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write((string) json_encode(
            ['error' => 'Yetkisiz erişim'],
            JSON_UNESCAPED_UNICODE
        ));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
