<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Repositories\StatsRepository;
use App\Support\SafeError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AdminStatsController
{
    public function __construct(
        private readonly StatsRepository $stats,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            /** @var array<string, mixed> $user */
            $user = $request->getAttribute('user') ?? [];
            $role = (string) ($user['role'] ?? '');

            $payload = [
                'today' => $this->stats->countTodayByFileType(),
                'totals' => $this->stats->countTotals(),
                'recent_uploads' => $this->stats->recentUploads(10),
                'active_users' => $role === 'admin' ? $this->stats->countActiveUsers() : null,
            ];

            return JsonResponse::write($response, $payload);
        } catch (\Throwable $e) {
            SafeError::log($e, 'admin.stats');

            return JsonResponse::error($response, 'İstatistikler alınamadı', 500);
        }
    }
}
