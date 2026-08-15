<?php

declare(strict_types=1);

/**
 * Manuel / opsiyonel lisans doğrulama.
 *
 * Ana mekanizma: AuthMiddleware → LicenseCheckService::ensureFreshCheck()
 * (authenticated kullanımda 24 saatte bir otomatik).
 *
 * Kullanım (backend kökünden):
 *   php bin/check_our_license.php
 */

$basePath = dirname(__DIR__);
require $basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use App\Config\Settings;
use App\Repositories\LicenseStatusRepository;
use App\Services\AppDbConnection;
use App\Services\LicenseCheckService;

Settings::load($basePath);

$service = new LicenseCheckService(new LicenseStatusRepository(new AppDbConnection()));
$result = $service->checkLicense();

$line = sprintf(
    '[%s] ok=%s source=%s is_valid=%s bitis=%s error=%s' . PHP_EOL,
    date('Y-m-d H:i:s'),
    $result['ok'] ? '1' : '0',
    $result['source'],
    $result['is_valid'] === null ? 'null' : ($result['is_valid'] ? '1' : '0'),
    $result['bitis_tarihi'] ?? 'null',
    $result['error'] ?? ''
);

fwrite(STDOUT, $line);

exit($result['ok'] || $result['source'] === 'network' || $result['source'] === 'config' ? 0 : 1);
