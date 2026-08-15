<?php

declare(strict_types=1);

use App\Config\Settings;
use App\Controllers\AdminStatsController;
use App\Controllers\AdminUsersController;
use App\Controllers\AuditLogController;
use App\Controllers\AuthController;
use App\Controllers\CariController;
use App\Controllers\DocumentsController;
use App\Controllers\HealthController;
use App\Controllers\InfraSettingsController;
use App\Controllers\LicenseStatusController;
use App\Controllers\MeController;
use App\Controllers\PublicBelgeController;
use App\Controllers\SettingsController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CorsMiddleware;
use App\Middleware\LicenseGuardMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RoleMiddleware;
use App\Repositories\AccessTokenRepository;
use App\Repositories\AppSettingsRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\DocumentFileRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\LicenseStatusRepository;
use App\Repositories\LogoDocumentRepository;
use App\Repositories\StatsRepository;
use App\Repositories\UserRepository;
use App\Services\AppDbConnection;
use App\Services\DocumentStorageService;
use App\Services\EnvFileService;
use App\Services\FileMimeValidator;
use App\Services\JwtService;
use App\Services\LicenseCheckService;
use App\Services\LogoDbConnection;
use App\Services\PublicAccessGuard;
use App\Services\RateLimiter;
use App\Support\SafeError;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

require __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);
Settings::load($basePath);

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions([
    LogoDbConnection::class => \DI\autowire(),
    AppDbConnection::class => \DI\autowire(),
    JwtService::class => \DI\autowire(),
    DocumentStorageService::class => \DI\autowire(),
    EnvFileService::class => static fn (): EnvFileService => new EnvFileService(
        $basePath . DIRECTORY_SEPARATOR . 'config'
    ),
    FileMimeValidator::class => \DI\autowire(),
    RateLimiter::class => static fn (): RateLimiter => new RateLimiter(
        $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ratelimit'
    ),
    UserRepository::class => \DI\autowire(),
    AuditLogRepository::class => \DI\autowire(),
    DocumentRepository::class => \DI\autowire(),
    DocumentFileRepository::class => \DI\autowire(),
    StatsRepository::class => \DI\autowire(),
    AppSettingsRepository::class => \DI\autowire(),
    LicenseStatusRepository::class => \DI\autowire(),
    LogoDocumentRepository::class => \DI\autowire(),
    \App\Services\LogoNoteService::class => \DI\autowire(),
    LicenseCheckService::class => \DI\autowire(),
    AccessTokenRepository::class => \DI\autowire(),
    PublicAccessGuard::class => \DI\autowire(),
    HealthController::class => \DI\autowire(),
    AuthController::class => \DI\autowire(),
    MeController::class => \DI\autowire(),
    AdminStatsController::class => \DI\autowire(),
    AdminUsersController::class => \DI\autowire(),
    DocumentsController::class => \DI\autowire(),
    SettingsController::class => \DI\autowire(),
    InfraSettingsController::class => \DI\autowire(),
    LicenseStatusController::class => \DI\autowire(),
    CariController::class => \DI\autowire(),
    PublicBelgeController::class => \DI\autowire(),
    AuditLogController::class => \DI\autowire(),
    CorsMiddleware::class => \DI\autowire(),
    AuthMiddleware::class => \DI\autowire(),
    LicenseGuardMiddleware::class => \DI\autowire(),
]);

$container = $containerBuilder->build();
AppFactory::setContainer($container);

$app = AppFactory::create();

// IIS'te document root /backend ise SCRIPT_NAME genelde /public/index.php olur.
// PHP built-in server + router'da SCRIPT_NAME bazen istek URI'si olur
// (/assets/company-logo gibi) — bunu base path sanmak tüm rotayı bozar.
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
if (str_ends_with($scriptName, '/index.php')) {
    $detectedBasePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($detectedBasePath !== '' && $detectedBasePath !== '/' && $detectedBasePath !== '.') {
        $app->setBasePath($detectedBasePath);
    }
}

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
// Yazma isteklerinde LicenseStatus okur (dış servise gitmez). Cors dışında kalsın ki 403 CORS alsın.
$app->add(LicenseGuardMiddleware::class);
$app->add(CorsMiddleware::class);

$displayErrorDetails = !SafeError::isProduction();
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
if (SafeError::isProduction()) {
    $errorMiddleware->setDefaultErrorHandler(function (
        $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($app) {
        SafeError::log($exception, 'unhandled');
        $response = $app->getResponseFactory()->createResponse(500);
        $response->getBody()->write((string) json_encode(
            ['error' => 'Sunucu hatası'],
            JSON_UNESCAPED_UNICODE
        ));

        return $response->withHeader('Content-Type', 'application/json');
    });
}

/** @var RateLimiter $rateLimiter */
$rateLimiter = $container->get(RateLimiter::class);

$app->get('/api/health', HealthController::class);
$app->post('/api/auth/login', [AuthController::class, 'login']);

// Firma logosu — belgelerde görünür; auth gerekmez
$app->get('/assets/company-logo', [SettingsController::class, 'serveLogo']);

// Belge sayfaları — index/viewer shell JWT ile; /raw AccessTokens
$app->group('/belge', function (RouteCollectorProxy $group) {
    $group->get('/{uid}', [PublicBelgeController::class, 'page']);
    $group->get('/{uid}/image', [PublicBelgeController::class, 'image']);
    $group->get('/{uid}/dosya/{fileId:[0-9]+}/raw', [PublicBelgeController::class, 'fileRaw']);
    $group->get('/{uid}/dosya/{fileId:[0-9]+}', [PublicBelgeController::class, 'file']);
})->add(RateLimitMiddleware::allow($rateLimiter, 'belge', 30, 60));

$app->group('/api', function (RouteCollectorProxy $group) {
    $group->get('/me', MeController::class);

    $group->get('/cari/search', [CariController::class, 'search']);

    $group->get('/documents/search', [DocumentsController::class, 'search']);
    $group->get('/documents/lookup', [DocumentsController::class, 'lookup']);
    $group->post('/documents/match', [DocumentsController::class, 'match'])
        ->add(RoleMiddleware::allow('admin', 'user'));
    $group->get('/documents/{uid}/detail', [DocumentsController::class, 'detail']);
    $group->put('/documents/{uid}/notes', [DocumentsController::class, 'updateNotes'])
        ->add(RoleMiddleware::allow('admin', 'user'));
    $group->get('/documents/{uid}/files/{fileId:[0-9]+}', [DocumentsController::class, 'downloadFile']);
    $group->delete('/documents/{uid}/files/{fileId:[0-9]+}', [DocumentsController::class, 'deleteFile'])
        ->add(RoleMiddleware::allow('admin'));
    $group->post('/documents/{uid}/files/{fileId:[0-9]+}/view-link', [DocumentsController::class, 'createViewLink']);
    $group->get('/documents/{uid}', [DocumentsController::class, 'show']);
    $group->post('/documents/{uid}/files', [DocumentsController::class, 'upload'])
        ->add(RoleMiddleware::allow('admin', 'user'));

    $group->get('/settings', [SettingsController::class, 'show']);
})->add(AuthMiddleware::class);

// Stats: tüm roller (admin/user/readonly) — RoleMiddleware YOK
$app->get('/api/admin/stats', [AdminStatsController::class, 'index'])
    ->add(AuthMiddleware::class);

$app->get('/api/admin/license-status', [LicenseStatusController::class, 'show'])
    ->add(AuthMiddleware::class);

$app->post('/api/admin/license/check', [LicenseStatusController::class, 'check'])
    ->add(RoleMiddleware::allow('admin'))
    ->add(AuthMiddleware::class);

$app->group('/api/admin', function (RouteCollectorProxy $group) {
    $group->get('/users', [AdminUsersController::class, 'index']);
    $group->get('/users/{id:[0-9]+}', [AdminUsersController::class, 'show']);
    $group->post('/users', [AdminUsersController::class, 'create']);
    $group->put('/users/{id:[0-9]+}', [AdminUsersController::class, 'update']);
    $group->post('/users/{id:[0-9]+}/reset-password', [AdminUsersController::class, 'resetPassword']);
    $group->delete('/users/{id:[0-9]+}', [AdminUsersController::class, 'delete']);
    $group->get('/audit-log', [AuditLogController::class, 'index']);
    $group->put('/settings', [SettingsController::class, 'update']);
    $group->post('/settings/logo', [SettingsController::class, 'uploadLogo']);
    $group->delete('/settings/logo', [SettingsController::class, 'deleteLogo']);
    $group->get('/settings/infra', [InfraSettingsController::class, 'show']);
    $group->post('/settings/infra/test-connection', [InfraSettingsController::class, 'testConnection']);
    $group->post('/settings/infra/test-app-connection', [InfraSettingsController::class, 'testAppConnection']);
    $group->put('/settings/infra', [InfraSettingsController::class, 'update']);
})
    ->add(RoleMiddleware::allow('admin'))
    ->add(AuthMiddleware::class);

$app->run();
