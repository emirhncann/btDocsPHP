<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\RateLimiter;
use App\Support\ClientIp;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly string $bucket,
        private readonly int $maxAttempts,
        private readonly int $decaySeconds,
    ) {
    }

    public static function allow(RateLimiter $limiter, string $bucket, int $maxAttempts, int $decaySeconds): self
    {
        return new self($limiter, $bucket, $maxAttempts, $decaySeconds);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip = ClientIp::from($request);
        $key = $this->bucket . ':' . $ip;

        if ($this->limiter->tooManyAttempts($key, $this->maxAttempts, $this->decaySeconds)) {
            $retry = $this->limiter->availableIn($key);
            $response = new Response(429);
            $response->getBody()->write((string) json_encode([
                'error' => 'Çok fazla istek. Lütfen daha sonra tekrar deneyin.',
                'retry_after' => $retry,
            ], JSON_UNESCAPED_UNICODE));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Retry-After', (string) max(1, $retry));
        }

        $this->limiter->hit($key, $this->decaySeconds);

        return $handler->handle($request);
    }
}
