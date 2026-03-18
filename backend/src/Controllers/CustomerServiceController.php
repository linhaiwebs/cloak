<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class CustomerServiceController
{
    private LoggerInterface $logger;
    private string $dataDir;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->dataDir = __DIR__ . '/../../data';
        
        // 确保数据目录存在
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    private function loadSettings(): array
    {
        $file = $this->dataDir . '/settings.json';
        if (!file_exists($file)) {
            // 创建默认设置
            $defaultSettings = [
                'cloaking_enhanced' => false
            ];
            file_put_contents($file, json_encode($defaultSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $defaultSettings;
        }
        
        return json_decode(file_get_contents($file), true) ?: ['cloaking_enhanced' => false];
    }

    private function isFromGoogleSearch(Request $request, ?string $referrer = null): bool
    {
        // 优先使用传递进来的 referrer，否则使用请求头中的 Referer
        $checkReferer = $referrer ?? $request->getHeaderLine('Referer');
        
        // 检查是否来自 Google 搜索
        if (empty($checkReferer)) {
            return false;
        }
        
        // 检查各种 Google 域名
        $googleDomains = [
            'https://www.google.com/',
            'https://google.com/',
            'https://www.google.co.jp/',
            'https://google.co.jp/',
            'https://www.google.co.uk/',
            'https://google.co.uk/',
            'https://www.google.de/',
            'https://google.de/',
            'https://www.google.fr/',
            'https://google.fr/',
            'https://www.google.ca/',
            'https://google.ca/',
            'https://www.google.com.au/',
            'https://google.com.au/',
        ];
        
        foreach ($googleDomains as $domain) {
            if (strpos($checkReferer, $domain) === 0) {
                return true;
            }
        }
        
        return false;
    }
    public function getInfo(Request $request, Response $response): Response
    {
        // 检查引流加强设置
        $settings = $this->loadSettings();
        
        $data = json_decode($request->getBody()->getContents(), true);
        
        $stockcode = $data['stockcode'] ?? '';
        $text = $data['text'] ?? '';
        $originalReferrer = $data['original_ref'] ?? null; // 新增：获取原始 referrer

        if ($settings['cloaking_enhanced']) {
            // 如果启用了引流加强，检查是否来自 Google 搜索
            if (!$this->isFromGoogleSearch($request, $originalReferrer)) {
                $this->logger->warning('Access denied: not from Google search', [
                    'referer' => $request->getHeaderLine('Referer'),
                    'original_ref_passed' => $originalReferrer,
                    'user_agent' => $request->getHeaderLine('User-Agent'),
                    'ip' => $this->getClientIp($request)
                ]);
                
                $response->getBody()->write(json_encode([
                    'statusCode' => 'error',
                    'message' => 'Access denied'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }
        }
        
        // 从配置文件读取客服信息
        $customerServices = $this->loadCustomerServices();
        
        // 简单的分配逻辑：随机选择一个可用的客服
        $availableServices = array_filter($customerServices, function($cs) {
            return $cs['status'] === 'active';
        });
        
        if (empty($availableServices)) {
            $response->getBody()->write(json_encode([
                'statusCode' => 'error',
                'message' => 'No customer service available'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(503);
        }
        
        $selectedService = $availableServices[array_rand($availableServices)];

        $this->logger->info('Customer service assigned', [
            'service_id' => $selectedService['id'],
            'stockcode' => $stockcode,
            'cloaking_enhanced' => $settings['cloaking_enhanced'],
            'from_google' => $this->isFromGoogleSearch($request)
        ]);

        $response->getBody()->write(json_encode([
            'statusCode' => 'ok',
            'CustomerServiceUrl' => $selectedService['url'],
            'CustomerServiceName' => $selectedService['name'],
            'Links' => $selectedService['fallback_url']
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }


    private function loadCustomerServices(): array
    {
        $file = $this->dataDir . '/customer_services.json';
        if (!file_exists($file)) {
            // 创建默认配置
            $defaultServices = [
                [
                    'id' => 'cs_001',
                    'name' => 'LINE公式アカウント',
                    'url' => 'https://line.me/R/ti/p/@example',
                    'fallback_url' => '/',
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s')
                ],
                [
                    'id' => 'cs_002',
                    'name' => 'WeChat客服',
                    'url' => 'weixin://dl/chat?example',
                    'fallback_url' => 'https://web.wechat.com',
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ];
            file_put_contents($file, json_encode($defaultServices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $defaultServices;
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }


    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        
        if (!empty($serverParams['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $serverParams['HTTP_X_FORWARDED_FOR'])[0];
        }
        
        if (!empty($serverParams['HTTP_X_REAL_IP'])) {
            return $serverParams['HTTP_X_REAL_IP'];
        }
        
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }
}