<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Settings;

final class SafeError
{
    public static function isProduction(): bool
    {
        return strtolower((string) (Settings::get('APP_ENV', 'production') ?? 'production')) === 'production';
    }

    public static function message(\Throwable $e, string $generic): string
    {
        $detail = $e->getMessage();
        error_log('[btDocs] ' . $generic . ' :: ' . $detail);

        if (self::isProduction()) {
            return $generic;
        }

        return $generic . ': ' . $detail;
    }

    public static function log(\Throwable $e, string $context = ''): void
    {
        error_log('[btDocs] ' . ($context !== '' ? $context . ' :: ' : '') . $e->getMessage());
    }
}
