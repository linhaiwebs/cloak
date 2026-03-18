<?php
declare(strict_types=1);

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// 设置时区
date_default_timezone_set('Asia/Tokyo');

// 检查 autoload 文件
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Dependencies not installed',
        'message' => 'Composer dependencies are missing. Please run "composer install" in the backend directory.',
        'path_checked' => $autoloadPath,
        'current_dir' => __DIR__,
        'files_in_parent' => is_dir(__DIR__ . '/..') ? scandir(__DIR__ . '/..') : 'parent directory not accessible'
    ]);
    exit;
}

require $autoloadPath;

use App\Application\Handlers\HttpErrorHandler;
use App\Application\Handlers\ShutdownHandler;
use App\Application\ResponseEmitter\ResponseEmitter;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;

try {
    // 加载环境变量
    if (file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->load();
    }

    // 实例化 PHP-DI ContainerBuilder
    $containerBuilder = new ContainerBuilder();

    // 在生产环境启用编译缓存
    $isProduction = ($_ENV['APP_ENV'] ?? 'development') === 'production';
    if ($isProduction) {
        $cacheDir = __DIR__ . '/../var/cache';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $containerBuilder->enableCompilation($cacheDir);
    }

    // 设置依赖注入
    $dependencies = require __DIR__ . '/../config/dependencies.php';
    $dependencies($containerBuilder);

    // 构建 DI 容器实例
    $container = $containerBuilder->build();

    // 实例化应用
    AppFactory::setContainer($container);
    $app = AppFactory::create();
    $callableResolver = $app->getCallableResolver();

    // 注册中间件
    $middleware = require __DIR__ . '/../config/middleware.php';
    $middleware($app);

    // 注册路由
    $routes = require __DIR__ . '/../config/routes.php';
    $routes($app);

    // 创建请求对象
    $serverRequestCreator = ServerRequestCreatorFactory::create();
    $request = $serverRequestCreator->createServerRequestFromGlobals();

    // 创建错误处理器
    $responseFactory = $app->getResponseFactory();
    $errorHandler = new HttpErrorHandler($callableResolver, $responseFactory);

    // 创建关闭处理器
    $displayErrorDetails = !$isProduction;
    $shutdownHandler = new ShutdownHandler($request, $errorHandler, $displayErrorDetails);
    register_shutdown_function($shutdownHandler);

    // 添加错误中间件
    $errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
    $errorMiddleware->setDefaultErrorHandler($errorHandler);

    // 运行应用并发出响应
    $response = $app->handle($request);
    $responseEmitter = new ResponseEmitter();
    $responseEmitter->emit($response);

} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Application Error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}