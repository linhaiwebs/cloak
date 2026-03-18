<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class TrackingController
{
    private LoggerInterface $logger;
    private string $dataFile;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->dataFile = __DIR__ . '/../../data/tracking_data.json';
    }

    public function collect(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();

            $requiredFields = ['visitor_ip', 'user_agent', 'label_id'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    return $this->jsonResponse($response, [
                        'success' => false,
                        'error' => "Missing required field: $field"
                    ], 400);
                }
            }

            if (!filter_var($data['visitor_ip'], FILTER_VALIDATE_IP)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'error' => 'Invalid IP address'
                ], 400);
            }

            $record = [
                'id' => $this->getNextId(),
                'visitor_ip' => substr($data['visitor_ip'], 0, 45),
                'user_agent' => substr($data['user_agent'], 0, 500),
                'referer' => isset($data['referer']) ? substr($data['referer'], 0, 1000) : '',
                'query_string' => isset($data['query_string']) ? substr($data['query_string'], 0, 2000) : '',
                'browser_language' => isset($data['browser_language']) ? substr($data['browser_language'], 0, 50) : '',
                'label_id' => (int)$data['label_id'],
                'cloaking_result' => isset($data['cloaking_result']) ? substr($data['cloaking_result'], 0, 50) : '',
                'created_at' => date('Y-m-d H:i:s')
            ];

            $this->saveRecord($record);

            $this->logger->info('Tracking data collected', ['ip' => $record['visitor_ip']]);

            return $this->jsonResponse($response, [
                'success' => true,
                'id' => $record['id']
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Tracking collection failed', ['error' => $e->getMessage()]);
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Internal server error'
            ], 500);
        }
    }

    public function getAll(Request $request, Response $response): Response
    {
        try {
            $params = $request->getQueryParams();
            $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
            $perPage = 10;

            $records = $this->readAllRecords();
            $records = array_reverse($records);

            $total = count($records);
            $totalPages = ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
            $paginatedRecords = array_slice($records, $offset, $perPage);

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $paginatedRecords,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to read tracking data', ['error' => $e->getMessage()]);
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Failed to load data'
            ], 500);
        }
    }

    private function getNextId(): int
    {
        $records = $this->readAllRecords();
        if (empty($records)) {
            return 1;
        }
        $lastRecord = end($records);
        return ($lastRecord['id'] ?? 0) + 1;
    }

    private function readAllRecords(): array
    {
        if (!file_exists($this->dataFile)) {
            return [];
        }

        $content = file_get_contents($this->dataFile);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveRecord(array $record): void
    {
        $records = $this->readAllRecords();
        $records[] = $record;

        $json = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode JSON');
        }

        $result = file_put_contents($this->dataFile, $json, LOCK_EX);
        if ($result === false) {
            throw new \RuntimeException('Failed to write to file');
        }
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }
}
