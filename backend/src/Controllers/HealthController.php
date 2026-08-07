<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AppDbConnection;
use App\Services\LogoDbConnection;
use App\Support\SafeError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HealthController
{
    public function __construct(
        private readonly LogoDbConnection $logoDb,
        private readonly AppDbConnection $appDb,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = [
            'status' => 'ok',
            'logo_db' => 'connected',
            'app_db' => 'connected',
        ];

        $hasError = false;

        try {
            $this->logoDb->ping();
        } catch (\Throwable $e) {
            SafeError::log($e, 'health.logo_db');
            $payload['logo_db'] = SafeError::isProduction() ? 'unavailable' : $e->getMessage();
            $hasError = true;
        }

        try {
            $this->appDb->ping();
        } catch (\Throwable $e) {
            SafeError::log($e, 'health.app_db');
            $payload['app_db'] = SafeError::isProduction() ? 'unavailable' : $e->getMessage();
            $hasError = true;
        }

        if ($hasError) {
            $payload['status'] = 'error';
        }

        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($hasError ? 500 : 200);
    }
}
