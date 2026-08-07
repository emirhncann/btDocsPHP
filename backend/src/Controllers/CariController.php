<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\JsonResponse;
use App\Repositories\LogoDocumentRepository;
use App\Support\SafeError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CariController
{
    public function __construct(
        private readonly LogoDocumentRepository $logoDocuments,
    ) {
    }

    public function search(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        $firmaNo = (int) ($params['firma_no'] ?? 0);
        $donemNo = (int) ($params['donem_no'] ?? 0);
        $query = trim((string) ($params['q'] ?? ''));

        if ($firmaNo < 1 || $donemNo < 1) {
            return JsonResponse::error($response, 'firma_no ve donem_no zorunludur', 422);
        }

        if (mb_strlen($query) < 2) {
            return JsonResponse::error($response, 'q en az 2 karakter olmalı', 422);
        }

        try {
            $results = $this->logoDocuments->searchCari($firmaNo, $donemNo, $query);
        } catch (\InvalidArgumentException $e) {
            return JsonResponse::error($response, $e->getMessage(), 422);
        } catch (\Throwable $e) {
            return JsonResponse::error(
                $response,
                SafeError::message($e, 'Cari arama hatası'),
                500
            );
        }

        return JsonResponse::write($response, $results);
    }
}
