<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class TrackingController
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function pageTrack(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        $trackingData = [
            'url' => $data['url'] ?? '',
            'timestamp' => $data['timestamp'] ?? date('c'),
            'click_type' => $data['click_type'] ?? 0,
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'ip' => $this->getClientIp($request),
            'timezone' => $request->getHeaderLine('timezone'),
            'language' => $request->getHeaderLine('language'),
        ];

        $this->logger->info('Page tracking', $trackingData);

        // 这里可以保存到数据库
        $this->saveTrackingData('page_track', $trackingData);

        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Tracking data recorded']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function upPageTrack(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        $trackingData = [
            'id' => $data['id'] ?? 0,
            'timestamp' => date('c'),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'ip' => $this->getClientIp($request),
        ];

        $this->logger->info('Up page tracking', $trackingData);
        
        // 这里可以保存到数据库
        $this->saveTrackingData('uppage_track', $trackingData);

        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Up page tracking recorded']));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function logError(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        
        $errorData = [
            'message' => $data['message'] ?? '',
            'stack' => $data['stack'] ?? '',
            'phase' => $data['phase'] ?? 'unknown',
            'btnText' => $data['btnText'] ?? '',
            'click_type' => $data['click_type'] ?? 0,
            'stockcode' => $data['stockcode'] ?? '',
            'href' => $data['href'] ?? '',
            'ref' => $data['ref'] ?? '',
            'ts' => $data['ts'] ?? time(),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'ip' => $this->getClientIp($request),
        ];

        $this->logger->error('Frontend error', $errorData);
        
        // 这里可以保存到数据库
        $this->saveTrackingData('error_log', $errorData);

        $response->getBody()->write(json_encode(['status' => 'success', 'message' => 'Error logged']));
        return $response->withHeader('Content-Type', 'application/json');
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

    private function saveTrackingData(string $type, array $data): void
    {
        // 这里可以实现数据库保存逻辑
        // 目前只记录到日志文件
        $logFile = __DIR__ . '/../../logs/tracking.log';
        $logEntry = date('Y-m-d H:i:s') . " [{$type}] " . json_encode($data) . PHP_EOL;
        
        if (!is_dir(dirname($logFile))) {
            mkdir(dirname($logFile), 0755, true);
        }
        
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}