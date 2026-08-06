<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class RoleMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowedRoles */
    public function __construct(
        private readonly array $allowedRoles,
    ) {
    }

    public static function allow(string ...$roles): self
    {
        return new self(array_values($roles));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        $role = is_array($user) ? (string) ($user['role'] ?? '') : '';

        if ($role === '' || !in_array($role, $this->allowedRoles, true)) {
            $response = new Response(403);
            $response->getBody()->write((string) json_encode(
                ['error' => 'Bu işlem için yetkiniz yok'],
                JSON_UNESCAPED_UNICODE
            ));

            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }
}
