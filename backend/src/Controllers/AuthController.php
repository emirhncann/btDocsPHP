<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Repositories\UserRepository;
use App\Services\JwtService;
use App\Services\RateLimiter;
use App\Support\ClientIp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
    private const LOGIN_MAX_FAILURES = 10;
    private const LOGIN_WINDOW_SECONDS = 900; // 15 dakika

    public function __construct(
        private readonly UserRepository $users,
        private readonly JwtService $jwt,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = ClientIp::from($request);
        $limitKey = 'login_fail:' . $ip;

        if ($this->rateLimiter->tooManyAttempts($limitKey, self::LOGIN_MAX_FAILURES, self::LOGIN_WINDOW_SECONDS)) {
            $retry = $this->rateLimiter->availableIn($limitKey);

            return JsonResponse::write($response, [
                'error' => 'Çok fazla başarısız giriş denemesi. Lütfen daha sonra tekrar deneyin.',
                'retry_after' => $retry,
            ], 429)->withHeader('Retry-After', (string) max(1, $retry));
        }

        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($username === '' || $password === '') {
            $this->rateLimiter->hit($limitKey, self::LOGIN_WINDOW_SECONDS);

            return JsonResponse::error($response, 'Geçersiz kullanıcı adı veya şifre', 401);
        }

        $user = $this->users->findByUsername($username);

        if ($user === null || !UserRepository::isTruthy($user['is_active'] ?? false)) {
            $this->rateLimiter->hit($limitKey, self::LOGIN_WINDOW_SECONDS);

            return JsonResponse::error($response, 'Geçersiz kullanıcı adı veya şifre', 401);
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->rateLimiter->hit($limitKey, self::LOGIN_WINDOW_SECONDS);

            return JsonResponse::error($response, 'Geçersiz kullanıcı adı veya şifre', 401);
        }

        $this->rateLimiter->clear($limitKey);

        $token = $this->jwt->createToken(
            (int) $user['id'],
            (string) $user['username'],
            (string) $user['role']
        );

        return JsonResponse::write($response, [
            'token' => $token,
            'user' => [
                'id' => (int) $user['id'],
                'username' => (string) $user['username'],
                'full_name' => (string) $user['full_name'],
                'role' => (string) $user['role'],
            ],
        ], 200);
    }
}
