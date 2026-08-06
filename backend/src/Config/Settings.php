<?php

declare(strict_types=1);

namespace App\Config;

use Dotenv\Dotenv;

final class Settings
{
    private static bool $loaded = false;

    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }

        $envDir = $basePath . DIRECTORY_SEPARATOR . 'config';

        if (is_file($envDir . DIRECTORY_SEPARATOR . '.env')) {
            Dotenv::createImmutable($envDir)->safeLoad();
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return (string) $value;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            throw new \RuntimeException("Eksik ortam değişkeni: {$key}");
        }

        return $value;
    }
}
