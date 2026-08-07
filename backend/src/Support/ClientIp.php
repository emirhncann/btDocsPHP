<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Settings;
use Psr\Http\Message\ServerRequestInterface;

final class ClientIp
{
    public static function from(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        $ip = $server['HTTP_X_FORWARDED_FOR'] ?? $server['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!is_string($ip) || $ip === '') {
            return '0.0.0.0';
        }

        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }

        return mb_substr($ip, 0, 45);
    }
}
