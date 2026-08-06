<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UserRepository;
use App\Services\JwtService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly JwtService $jwt,
    ) {
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            return $this->json($response, ['error' => 'Geçersiz kullanıcı adı veya şifre'], 401);
        }

        $user = $this->users->findByUsername($username);

        if ($user === null || !UserRepository::isTruthy($user['is_active'] ?? false)) {
            return $this->json($response, ['error' => 'Geçersiz kullanıcı adı veya şifre'], 401);
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return $this->json($response, ['error' => 'Geçersiz kullanıcı adı veya şifre'], 401);
        }

        $token = $this->jwt->createToken(
            (int) $user['id'],
            (string) $user['username'],
            (string) $user['role']
        );

        return $this->json($response, [
            'token' => $token,
            'user' => [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'full_name' => (string) $user['full_name'],
                'role' => (string) $user['role'],
            ],
        ], 200);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $status): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
