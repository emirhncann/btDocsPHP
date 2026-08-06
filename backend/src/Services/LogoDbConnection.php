<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Settings;
use PDO;
use PDOException;
use RuntimeException;

final class LogoDbConnection
{
    private ?PDO $pdo = null;

    public function getConnection(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $host = Settings::require('LOGO_DB_HOST');
        $name = Settings::require('LOGO_DB_NAME');
        $user = Settings::get('LOGO_DB_USER', '') ?? '';
        $pass = Settings::get('LOGO_DB_PASS', '') ?? '';

        $dsn = sprintf(
            'sqlsrv:Server=%s;Database=%s;TrustServerCertificate=yes',
            $host,
            $name
        );

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Logo DB bağlantı hatası: ' . $e->getMessage(), 0, $e);
        }

        return $this->pdo;
    }

    public function ping(): void
    {
        $this->getConnection()->query('SELECT 1');
    }
}
