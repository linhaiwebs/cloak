<?php
declare(strict_types=1);

use Slim\App;

return function (App $app) {
    // CORS 中间件
    $app->add(function ($request, $handler) {
        $response = $handler->handle($request);
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization, timezone, language')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    });

    // 处理 OPTIONS 请求
    $app->options('/{routes:.+}', function ($request, $response, $args) {
        return $response;
    });
};