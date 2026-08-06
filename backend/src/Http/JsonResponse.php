<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;

final class JsonResponse
{
    /**
     * @param array<string, mixed>|list<mixed> $payload
     */
    public static function write(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    public static function error(ResponseInterface $response, string $message, int $status): ResponseInterface
    {
        return self::write($response, ['error' => $message], $status);
    }
}
