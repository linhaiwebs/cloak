<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Database\DatabaseHelper;

class InfoController
{
    private DatabaseHelper $db;

    public function __construct(DatabaseHelper $db)
    {
        $this->db = $db;
    }

    public function pageTrack(Request $request, Response $response): Response
    {
        try {
            $body = $request->getParsedBody();
            $url = $body['url'] ?? '';
            $timestamp = $body['timestamp'] ?? date('Y-m-d H:i:s');
            $clickType = $body['click_type'] ?? '';

            $serverParams = $request->getServerParams();
            $ipAddress = $serverParams['REMOTE_ADDR'] ?? '';
            $userAgent = $serverParams['HTTP_USER_AGENT'] ?? '';

            $stmt = $this->db->getConnection()->prepare(
                'INSERT INTO page_tracks (url, timestamp, click_type, ip_address, user_agent, created_at)
                 VALUES (:url, :timestamp, :click_type, :ip_address, :user_agent, :created_at)'
            );

            $stmt->execute([
                ':url' => $url,
                ':timestamp' => $timestamp,
                ':click_type' => $clickType,
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent,
                ':created_at' => date('Y-m-d H:i:s')
            ]);

            $result = [
                'statusCode' => 'ok',
                'message' => 'Page track recorded successfully'
            ];

            $response->getBody()->write(json_encode($result));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);

        } catch (\Exception $e) {
            error_log('Page track error: ' . $e->getMessage());

            $result = [
                'statusCode' => 'error',
                'message' => 'Failed to record page track'
            ];

            $response->getBody()->write(json_encode($result));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }

    public function logError(Request $request, Response $response): Response
    {
        try {
            $body = $request->getParsedBody();

            $message = $body['message'] ?? '';
            $stack = $body['stack'] ?? '';
            $phase = $body['phase'] ?? '';
            $btnText = $body['btn_text'] ?? '';
            $clickType = $body['click_type'] ?? '';
            $stockcode = $body['stockcode'] ?? '';
            $href = $body['href'] ?? '';
            $ref = $body['ref'] ?? '';
            $ts = $body['ts'] ?? date('Y-m-d H:i:s');

            $serverParams = $request->getServerParams();
            $ipAddress = $serverParams['REMOTE_ADDR'] ?? '';
            $userAgent = $serverParams['HTTP_USER_AGENT'] ?? '';

            $stmt = $this->db->getConnection()->prepare(
                'INSERT INTO error_logs (message, stack, phase, btn_text, click_type, stockcode, href, ref, ts, ip_address, user_agent, created_at)
                 VALUES (:message, :stack, :phase, :btn_text, :click_type, :stockcode, :href, :ref, :ts, :ip_address, :user_agent, :created_at)'
            );

            $stmt->execute([
                ':message' => $message,
                ':stack' => $stack,
                ':phase' => $phase,
                ':btn_text' => $btnText,
                ':click_type' => $clickType,
                ':stockcode' => $stockcode,
                ':href' => $href,
                ':ref' => $ref,
                ':ts' => $ts,
                ':ip_address' => $ipAddress,
                ':user_agent' => $userAgent,
                ':created_at' => date('Y-m-d H:i:s')
            ]);

            error_log("JavaScript Error: $message | Phase: $phase | Stack: $stack");

            $result = [
                'statusCode' => 'ok',
                'message' => 'Error logged successfully'
            ];

            $response->getBody()->write(json_encode($result));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(200);

        } catch (\Exception $e) {
            error_log('Error logging failed: ' . $e->getMessage());

            $result = [
                'statusCode' => 'error',
                'message' => 'Failed to log error'
            ];

            $response->getBody()->write(json_encode($result));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }
}
