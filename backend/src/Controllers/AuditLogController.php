<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Repositories\AuditLogRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuditLogController
{
    public function __construct(
        private readonly AuditLogRepository $auditLog,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        $filters = [
            'user_id' => isset($params['user_id']) && $params['user_id'] !== ''
                ? (int) $params['user_id'] : null,
            'document_id' => isset($params['document_id']) && $params['document_id'] !== ''
                ? (int) $params['document_id'] : null,
            'action' => isset($params['action']) ? trim((string) $params['action']) : null,
            'from' => isset($params['from']) ? trim((string) $params['from']) : null,
            'to' => isset($params['to']) ? trim((string) $params['to']) : null,
            'limit' => isset($params['limit']) ? (int) $params['limit'] : 100,
        ];

        if ($filters['action'] !== null && $filters['action'] !== ''
            && !preg_match('/^[a-z0-9_]{1,100}$/i', $filters['action'])) {
            return JsonResponse::error($response, 'Geçersiz action filtresi', 422);
        }

        foreach (['from', 'to'] as $dateKey) {
            if ($filters[$dateKey] !== null && $filters[$dateKey] !== ''
                && !preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $filters[$dateKey])) {
                return JsonResponse::error($response, "Geçersiz {$dateKey} tarihi (YYYY-MM-DD)", 422);
            }
        }

        $items = $this->auditLog->search($filters);

        return JsonResponse::write($response, $items);
    }
}
