<?php

declare(strict_types=1);

namespace App\Config;

use Dotenv\Dotenv;

final class Settings
{
    private static bool $loaded = false;

    private static string $basePath = '';

    public static function load(string $basePath): void
    {
        self::$basePath = $basePath;

        if (self::$loaded) {
            return;
        }

        self::loadEnvFile($basePath);
        self::$loaded = true;
    }

    /**
     * .env dosyasını yeniden okur (immutable cache/overwrite sorununu aşmak için mutable).
     * PHP built-in server gibi uzun ömürlü süreçlerde PUT sonrası sonraki istekler güncel kalsın.
     */
    public static function reload(?string $basePath = null): void
    {
        $path = $basePath ?? self::$basePath;
        if ($path === '') {
            throw new \RuntimeException('Settings::reload için basePath gerekli');
        }

        self::$loaded = false;
        self::load($path);
    }

    public static function envFilePath(?string $basePath = null): string
    {
        $path = $basePath ?? self::$basePath;
        if ($path === '') {
            throw new \RuntimeException('Settings basePath tanımsız');
        }

        return $path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . '.env';
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

    private static function loadEnvFile(string $basePath): void
    {
        $envDir = $basePath . DIRECTORY_SEPARATOR . 'config';

        if (!is_file($envDir . DIRECTORY_SEPARATOR . '.env')) {
            return;
        }

        // Mutable: dosyadaki değerler $_ENV üzerine yazılır (Immutable bir kez set edince .env güncellemesi görünmez).
        try {
            // UTF-8 BOM Dotenv'de APP_ENV adını bozar — yüklemeden önce temizle
            $envFile = $envDir . DIRECTORY_SEPARATOR . '.env';
            $raw = @file_get_contents($envFile);
            if (is_string($raw) && str_starts_with($raw, "\xEF\xBB\xBF")) {
                @file_put_contents($envFile, substr($raw, 3));
            }
            Dotenv::createMutable($envDir)->safeLoad();
        } catch (\Dotenv\Exception\InvalidFileException $e) {
            // Bozuk DOC_STORAGE_ROOT gibi durumlarda tüm ortamı düşürme —
            // uygulama en azından hata mesajı verebilsin.
            error_log('Settings: .env parse hatası: ' . $e->getMessage());
        }
    }
}
