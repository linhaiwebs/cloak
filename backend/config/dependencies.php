<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use App\Controllers\StockController;
use App\Controllers\TrackingController;
use App\Controllers\CustomerServiceController;
use App\Controllers\AdminController;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        LoggerInterface::class => function (ContainerInterface $c) {
            $loggerSettings = [
                'name' => 'slim-app',
                'path' => __DIR__ . '/../logs/app.log',
                'level' => Logger::DEBUG,
            ];

            $logger = new Logger($loggerSettings['name']);

            $processor = new UidProcessor();
            $logger->pushProcessor($processor);

            // 确保日志目录存在
            $logDir = dirname($loggerSettings['path']);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $handler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
            $logger->pushHandler($handler);

            return $logger;
        },

        // 控制器依赖注入
        StockController::class => function (ContainerInterface $c) {
            return new StockController($c->get(LoggerInterface::class));
        },

        TrackingController::class => function (ContainerInterface $c) {
            return new TrackingController($c->get(LoggerInterface::class));
        },

        CustomerServiceController::class => function (ContainerInterface $c) {
            return new CustomerServiceController($c->get(LoggerInterface::class));
        },

        AdminController::class => function (ContainerInterface $c) {
            return new AdminController($c->get(LoggerInterface::class));
        },
    ]);
};