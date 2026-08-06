<?php

declare(strict_types=1);

use App\Config\Settings;
use App\Controllers\AdminUsersController;
use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\MeController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CorsMiddleware;
use App\Middleware\RoleMiddleware;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserRepository;
use App\Services\AppDbConnection;
use App\Services\JwtService;
use App\Services\LogoDbConnection;
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
    UserRepository::class => \DI\autowire(),
    AuditLogRepository::class => \DI\autowire(),
    HealthController::class => \DI\autowire(),
    AuthController::class => \DI\autowire(),
    MeController::class => \DI\autowire(),
    AdminUsersController::class => \DI\autowire(),
    CorsMiddleware::class => \DI\autowire(),
    AuthMiddleware::class => \DI\autowire(),
]);

$container = $containerBuilder->build();
AppFactory::setContainer($container);

$app = AppFactory::create();

// IIS document root /backend ise SCRIPT_NAME /public/index.php olabilir.
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$detectedBasePath = str_replace('\\', '/', dirname($scriptName));
if ($detectedBasePath !== '/' && $detectedBasePath !== '\\' && $detectedBasePath !== '.') {
    $app->setBasePath(rtrim($detectedBasePath, '/'));
}

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(CorsMiddleware::class);

$displayErrorDetails = Settings::get('APP_ENV', 'production') !== 'production';
$app->addErrorMiddleware($displayErrorDetails, true, true);

$app->get('/api/health', HealthController::class);
$app->post('/api/auth/login', [AuthController::class, 'login']);

$app->group('/api', function (RouteCollectorProxy $group) {
    $group->get('/me', MeController::class);
})->add(AuthMiddleware::class);

// Auth önce, Role sonra çalışır (Slim LIFO: son eklenen önce çalışır)
$app->group('/api/admin', function (RouteCollectorProxy $group) {
    $group->get('/users', [AdminUsersController::class, 'index']);
    $group->get('/users/{id:[0-9]+}', [AdminUsersController::class, 'show']);
    $group->post('/users', [AdminUsersController::class, 'create']);
    $group->put('/users/{id:[0-9]+}', [AdminUsersController::class, 'update']);
    $group->post('/users/{id:[0-9]+}/reset-password', [AdminUsersController::class, 'resetPassword']);
    $group->delete('/users/{id:[0-9]+}', [AdminUsersController::class, 'delete']);
})
    ->add(RoleMiddleware::allow('admin'))
    ->add(AuthMiddleware::class);

$app->run();
