<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Settings;
use PDO;
use PDOException;
use RuntimeException;

final class AppDbConnection
{
    private ?PDO $pdo = null;

    public function getConnection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $host = Settings::require('APP_DB_HOST');
        $name = Settings::require('APP_DB_NAME');
        $user = trim((string) (Settings::get('APP_DB_USER', '') ?? ''));
        $pass = (string) (Settings::get('APP_DB_PASS', '') ?? '');

        $dsn = sprintf(
            'sqlsrv:Server=%s;Database=%s;TrustServerCertificate=yes',
            $host,
            $name
        );

        // pdo_sqlsrv: Trusted_Connection DSN anahtarı yok; kullanıcı/şifre boş → Windows Auth
        try {
            $this->pdo = new PDO(
                $dsn,
                $user === '' ? null : $user,
                $user === '' ? null : $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    // ATTR_PERSISTENT: pdo_sqlsrv desteklemez; belirtilmez = kapalı (istenilen davranış)
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException('App DB bağlantı hatası: ' . $e->getMessage(), 0, $e);
        }

        return $this->pdo;
    }

    public function ping(): void
    {
        $this->getConnection()->query('SELECT 1');
    }
}
