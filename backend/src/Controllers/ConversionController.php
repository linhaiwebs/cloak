<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use App\Database\DatabaseHelper;

class ConversionController
{
    private LoggerInterface $logger;
    private DatabaseHelper $db;
    private string $dataDir;
    private string $settingsPath;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->dataDir = __DIR__ . '/../../data';
        $this->db = new DatabaseHelper($this->dataDir);
        $this->settingsPath = $this->dataDir . '/settings.json';
    }

    public function createSession(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            $gclid = $data['gclid'] ?? '';

            if (empty($gclid)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'GCLID is required'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            $sessionMarker = bin2hex(random_bytes(16));
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $referrer = $_SERVER['HTTP_REFERER'] ?? '';
            $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));

            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare(
                "INSERT INTO conversion_sessions
                (session_marker, gclid, ip_address, user_agent, referrer, expires_at)
                VALUES (:marker, :gclid, :ip, :ua, :ref, :expires)"
            );

            $stmt->execute([
                ':marker' => $sessionMarker,
                ':gclid' => $gclid,
                ':ip' => $ipAddress,
                ':ua' => $userAgent,
                ':ref' => $referrer,
                ':expires' => $expiresAt
            ]);

            $response->getBody()->write(json_encode([
                'success' => true,
                'session_marker' => $sessionMarker,
                'expires_at' => $expiresAt
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $this->logger->error('Create session error: ' . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Internal server error'
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function recordConversion(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            $sessionMarker = $data['session_marker'] ?? '';
            $gclid = $data['gclid'] ?? '';
            $conversionValue = isset($data['conversion_value']) && $data['conversion_value'] !== '' ?
                floatval($data['conversion_value']) : null;
            $conversionCurrency = $data['conversion_currency'] ?? 'JPY';
            $timezone = $data['timezone'] ?? 'Asia/Tokyo';

            if (empty($sessionMarker) && empty($gclid)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Session marker or GCLID is required'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            if ($conversionValue !== null && ($conversionValue < 0 || $conversionValue > 999999.99)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Conversion value must be between 0 and 999999.99'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            $pdo = $this->db->getConnection();

            if (!empty($sessionMarker)) {
                $stmt = $pdo->prepare(
                    "SELECT * FROM conversion_sessions
                    WHERE session_marker = :marker AND expires_at > datetime('now')"
                );
                $stmt->execute([':marker' => $sessionMarker]);
                $session = $stmt->fetch(\PDO::FETCH_ASSOC);

                if (!$session) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'error' => 'Invalid or expired session'
                    ]));
                    return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
                }

                $gclid = $session['gclid'];
            }

            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $referrer = $_SERVER['HTTP_REFERER'] ?? '';
            $conversionTime = date('Y-m-d H:i:s');

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO conversions
                (session_marker, gclid, conversion_name, conversion_time, conversion_value,
                conversion_currency, timezone, ip_address, user_agent, referrer)
                VALUES (:marker, :gclid, 'LINE加入', :time, :value, :currency, :tz, :ip, :ua, :ref)"
            );

            $stmt->execute([
                ':marker' => $sessionMarker ?: '',
                ':gclid' => $gclid,
                ':time' => $conversionTime,
                ':value' => $conversionValue,
                ':currency' => $conversionCurrency,
                ':tz' => $timezone,
                ':ip' => $ipAddress,
                ':ua' => $userAgent,
                ':ref' => $referrer
            ]);

            $conversionId = $pdo->lastInsertId();

            if (!empty($sessionMarker)) {
                $stmt = $pdo->prepare(
                    "UPDATE conversion_sessions SET converted = 1 WHERE session_marker = :marker"
                );
                $stmt->execute([':marker' => $sessionMarker]);
            }

            $pdo->commit();

            $response->getBody()->write(json_encode([
                'success' => true,
                'conversion_id' => $conversionId,
                'conversion_value' => $conversionValue
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->logger->error('Record conversion error: ' . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Internal server error'
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function conversions(Request $request, Response $response): Response
    {
        $adminController = new AdminController($this->logger);
        if (!$adminController->isAuthenticated($request)) {
            return $response
                ->withHeader('Location', '/admin')
                ->withStatus(302);
        }

        $html = $this->renderConversionsPage();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    private function renderConversionsPage(): string
    {
        $commonStyles = $this->getCommonStyles();
        $sidebar = $this->getSidebar('conversions');

        return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>转化记录管理</title>
    {$commonStyles}
    <style>
        .edit-btn {
            background: #17a2b8;
            padding: 5px 10px;
            border-radius: 6px;
            color: white;
            text-decoration: none;
            font-size: 14px;
            display: inline-block;
            transition: background 0.2s;
        }
        .edit-btn:hover {
            background: #138496;
        }
        .delete-btn {
            background: var(--danger);
            padding: 5px 10px;
            border-radius: 6px;
            color: white;
            text-decoration: none;
            font-size: 14px;
            display: inline-block;
            transition: background 0.2s;
            margin-left: 8px;
        }
        .delete-btn:hover {
            background: #dc2626;
        }
    </style>
</head>
<body>
    {$sidebar}
    <div class="main-content">
        <div class="header">
            <h1 class="header-title">GCLID 转化记录管理</h1>
            <div class="header-actions">
                <button class="btn" onclick="location.reload()">刷新</button>
                <button class="btn btn-success" onclick="exportCSV()">导出 CSV</button>
                <button class="btn" onclick="showGoogleAuth()">Google Sheets 授权</button>
                <button class="btn btn-primary" id="syncBtn" onclick="syncGoogleSheets()">立即同步到 Google Sheets</button>
            </div>
        </div>

        <div id="syncStatus" class="alert alert-info" style="display: none;"></div>

        <div class="card">
            <div class="card-body">
                <div class="loading" id="loading">加载中...</div>
                <table id="conversionsTable" style="display: none;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>GCLID</th>
                            <th>转化名称</th>
                            <th>转化时间</th>
                            <th>转化价值</th>
                            <th>货币</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="conversionsBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2>编辑转化记录</h2>
            <form id="editForm">
                <input type="hidden" id="edit_id">
                <div class="form-group">
                    <label>GCLID</label>
                    <input type="text" id="edit_gclid" readonly>
                </div>
                <div class="form-group">
                    <label>转化时间</label>
                    <input type="datetime-local" id="edit_time" required>
                </div>
                <div class="form-group">
                    <label>转化价值 (可选)</label>
                    <input type="number" id="edit_value" step="0.01" min="0" max="999999.99" placeholder="留空表示无价值">
                </div>
                <div class="form-group">
                    <label>货币</label>
                    <input type="text" id="edit_currency" value="JPY">
                </div>
                <div class="actions">
                    <button type="submit" class="btn">保存</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">取消</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let conversions = [];

        async function loadConversions() {
            try {
                const res = await fetch('/admin/api/conversions');
                const data = await res.json();
                conversions = data.conversions || [];
                renderTable();
            } catch (e) {
                alert('加载失败: ' + e.message);
            }
        }

        function renderTable() {
            const tbody = document.getElementById('conversionsBody');
            tbody.innerHTML = '';

            conversions.forEach(conv => {
                const tr = document.createElement('tr');
                const value = conv.conversion_value !== null && conv.conversion_value !== '' ?
                    parseFloat(conv.conversion_value).toFixed(2) : '-';
                tr.innerHTML = `
                    <td>${conv.id}</td>
                    <td>${conv.gclid}</td>
                    <td>${conv.conversion_name}</td>
                    <td>${conv.conversion_time}</td>
                    <td>${value}</td>
                    <td>${conv.conversion_currency}</td>
                    <td><a href="#" class="edit-btn" onclick="editConversion(${conv.id}); return false;">编辑</a></td>
                `;
                tbody.appendChild(tr);
            });

            document.getElementById('loading').style.display = 'none';
            document.getElementById('conversionsTable').style.display = 'table';
        }

        function editConversion(id) {
            const conv = conversions.find(c => c.id === id);
            if (!conv) return;

            document.getElementById('edit_id').value = conv.id;
            document.getElementById('edit_gclid').value = conv.gclid;
            document.getElementById('edit_time').value = conv.conversion_time.replace(' ', 'T');
            document.getElementById('edit_value').value = conv.conversion_value !== null ? conv.conversion_value : '';
            document.getElementById('edit_currency').value = conv.conversion_currency;
            document.getElementById('editModal').style.display = 'block';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        document.getElementById('editForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('edit_id').value;
            const value = document.getElementById('edit_value').value;

            const data = {
                id: parseInt(id),
                conversion_time: document.getElementById('edit_time').value.replace('T', ' '),
                conversion_value: value !== '' ? parseFloat(value) : null,
                conversion_currency: document.getElementById('edit_currency').value
            };

            try {
                const res = await fetch('/admin/api/conversions', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await res.json();
                if (result.success) {
                    closeEditModal();
                    loadConversions();
                } else {
                    alert('保存失败: ' + (result.error || '未知错误'));
                }
            } catch (e) {
                alert('保存失败: ' + e.message);
            }
        });

        function exportCSV() {
            window.location.href = '/admin/api/conversions/export';
        }

        async function syncGoogleSheets() {
            const btn = document.getElementById('syncBtn');
            const status = document.getElementById('syncStatus');
            btn.disabled = true;
            btn.textContent = '同步中...';
            status.style.display = 'block';
            status.className = 'alert alert-info';
            status.textContent = '正在同步到 Google Sheets...';

            try {
                const res = await fetch('/admin/api/conversions/google-sync-now', { method: 'POST' });
                const data = await res.json();

                if (data.success) {
                    status.className = 'alert alert-success';
                    status.textContent = `同步成功！已同步 ${data.synced_count || 0} 条记录`;
                } else {
                    status.className = 'alert alert-error';
                    status.textContent = '同步失败: ' + (data.error || '未知错误');
                }
            } catch (e) {
                status.className = 'alert alert-error';
                status.textContent = '同步失败: ' + e.message;
            }

            btn.disabled = false;
            btn.textContent = '立即同步到 Google Sheets';
        }

        function showGoogleAuth() {
            alert('Google OAuth 授权功能将在下一步实现');
        }

        window.onclick = function(event) {
            if (event.target == document.getElementById('editModal')) {
                closeEditModal();
            }
        }

        loadConversions();
    </script>
</body>
</html>
HTML;
    }

    public function apiConversions(Request $request, Response $response): Response
    {
        $method = $request->getMethod();

        if ($method === 'GET') {
            return $this->apiGetConversions($request, $response);
        } elseif ($method === 'PUT') {
            return $this->apiUpdateConversion($request, $response);
        } elseif ($method === 'DELETE') {
            return $this->apiDeleteConversion($request, $response);
        }

        $response->getBody()->write(json_encode(['error' => 'Method not allowed']));
        return $response->withStatus(405)->withHeader('Content-Type', 'application/json');
    }

    private function apiGetConversions(Request $request, Response $response): Response
    {
        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->query(
                "SELECT * FROM conversions ORDER BY created_at DESC LIMIT 500"
            );
            $conversions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $response->getBody()->write(json_encode([
                'success' => true,
                'conversions' => $conversions
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $this->logger->error('Get conversions error: ' . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Internal server error'
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    private function apiUpdateConversion(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            $id = $data['id'] ?? 0;
            $conversionTime = $data['conversion_time'] ?? '';
            $conversionValue = isset($data['conversion_value']) && $data['conversion_value'] !== '' ?
                floatval($data['conversion_value']) : null;
            $conversionCurrency = $data['conversion_currency'] ?? 'JPY';

            if ($conversionValue !== null && ($conversionValue < 0 || $conversionValue > 999999.99)) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Conversion value must be between 0 and 999999.99'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare(
                "UPDATE conversions
                SET conversion_time = :time, conversion_value = :value,
                    conversion_currency = :currency, updated_at = datetime('now')
                WHERE id = :id"
            );

            $stmt->execute([
                ':id' => $id,
                ':time' => $conversionTime,
                ':value' => $conversionValue,
                ':currency' => $conversionCurrency
            ]);

            $response->getBody()->write(json_encode([
                'success' => true
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $this->logger->error('Update conversion error: ' . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Internal server error'
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    private function apiDeleteConversion(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            $id = $data['id'] ?? 0;

            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare("DELETE FROM conversions WHERE id = :id");
            $stmt->execute([':id' => $id]);

            $response->getBody()->write(json_encode([
                'success' => true
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $this->logger->error('Delete conversion error: ' . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Internal server error'
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function apiExportConversions(Request $request, Response $response): Response
    {
        try {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->query(
                "SELECT gclid, conversion_name, conversion_time, conversion_value, conversion_currency, timezone
                FROM conversions
                ORDER BY conversion_time ASC"
            );
            $conversions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $timezone = $conversions[0]['timezone'] ?? 'Asia/Tokyo';

            $csv = "\xEF\xBB\xBF";
            $csv .= "Parameters:TimeZone=" . $timezone . "\n";
            $csv .= "Google Click ID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency\n";

            foreach ($conversions as $conv) {
                $value = $conv['conversion_value'] !== null && $conv['conversion_value'] !== '' ?
                    $conv['conversion_value'] : '';

                $csv .= $this->escapeCsvField($conv['gclid']) . ',';
                $csv .= $this->escapeCsvField($conv['conversion_name']) . ',';
                $csv .= $this->escapeCsvField($conv['conversion_time']) . ',';
                $csv .= $this->escapeCsvField($value) . ',';
                $csv .= $this->escapeCsvField($conv['conversion_currency']) . "\n";
            }

            $filename = 'conversions_' . date('Y-m-d_His') . '.csv';

            $response->getBody()->write($csv);
            return $response
                ->withHeader('Content-Type', 'text/csv; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
        } catch (\Exception $e) {
            $this->logger->error('Export conversions error: ' . $e->getMessage());
            $response->getBody()->write('Export failed');
            return $response->withStatus(500);
        }
    }

    private function escapeCsvField($field): string
    {
        $field = str_replace('"', '""', $field);
        if (strpos($field, ',') !== false || strpos($field, "\n") !== false || strpos($field, '"') !== false) {
            return '"' . $field . '"';
        }
        return $field;
    }

    public function apiSyncNow(Request $request, Response $response): Response
    {
        try {
            $result = $this->syncGoogleSheets();
            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $this->logger->error('Sync now error: ' . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function apiSyncStatus(Request $request, Response $response): Response
    {
        try {
            $settings = $this->loadSettings();
            $lastSync = $settings['last_sync_timestamp'] ?? null;
            $spreadsheetId = $settings['google_spreadsheet_id'] ?? '';
            $hasAuth = !empty($settings['google_refresh_token']);

            $response->getBody()->write(json_encode([
                'success' => true,
                'has_auth' => $hasAuth,
                'spreadsheet_id' => $spreadsheetId,
                'last_sync' => $lastSync,
                'next_sync' => $lastSync ? date('Y-m-d H:i:s', strtotime($lastSync) + 600) : null
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function apiUpdateSpreadsheetConfig(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            $spreadsheetId = $data['spreadsheet_id'] ?? '';

            $settings = $this->loadSettings();
            $settings['google_spreadsheet_id'] = $spreadsheetId;
            $this->saveSettings($settings);

            $response->getBody()->write(json_encode([
                'success' => true
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    private function syncGoogleSheets(): array
    {
        $settings = $this->loadSettings();
        $refreshToken = $settings['google_refresh_token'] ?? '';
        $spreadsheetId = $settings['google_spreadsheet_id'] ?? '';
        $lastSyncTimestamp = $settings['last_sync_timestamp'] ?? '1970-01-01 00:00:00';

        if (empty($refreshToken) || empty($spreadsheetId)) {
            $this->logger->info('Sync skipped: Missing configuration');
            return [
                'success' => false,
                'error' => 'Google Sheets not configured',
                'synced_count' => 0
            ];
        }

        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare(
            "SELECT gclid, conversion_name, conversion_time, conversion_value, conversion_currency
            FROM conversions
            WHERE created_at > :last_sync
            ORDER BY created_at ASC"
        );
        $stmt->execute([':last_sync' => $lastSyncTimestamp]);
        $newConversions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($newConversions)) {
            $this->logger->info('Sync skipped: No new conversions');
            return [
                'success' => true,
                'synced_count' => 0
            ];
        }

        try {
            $accessToken = $this->refreshAccessToken($refreshToken, $settings);

            $values = [];
            foreach ($newConversions as $conv) {
                $value = $conv['conversion_value'] !== null && $conv['conversion_value'] !== '' ?
                    $conv['conversion_value'] : '';
                $values[] = [
                    $conv['gclid'],
                    $conv['conversion_name'],
                    $conv['conversion_time'],
                    $value,
                    $conv['conversion_currency']
                ];
            }

            $this->appendToGoogleSheet($accessToken, $spreadsheetId, $values);

            $settings['last_sync_timestamp'] = date('Y-m-d H:i:s');
            $this->saveSettings($settings);

            $this->logger->info('Sync successful: ' . count($values) . ' conversions');

            return [
                'success' => true,
                'synced_count' => count($values)
            ];
        } catch (\Exception $e) {
            $this->logger->error('Sync failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'synced_count' => 0
            ];
        }
    }

    private function refreshAccessToken(string $refreshToken, array &$settings): string
    {
        $clientId = $settings['google_client_id'] ?? '';
        $clientSecret = $settings['google_client_secret'] ?? '';

        if (empty($clientId) || empty($clientSecret)) {
            throw new \Exception('Google OAuth credentials not configured');
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token'
            ])
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception('Failed to refresh access token: ' . $result);
        }

        $data = json_decode($result, true);
        if (!isset($data['access_token'])) {
            throw new \Exception('Invalid token response');
        }

        return $data['access_token'];
    }

    private function appendToGoogleSheet(string $accessToken, string $spreadsheetId, array $values): void
    {
        $batchSize = 100;
        for ($i = 0; $i < count($values); $i += $batchSize) {
            $batch = array_slice($values, $i, $batchSize);

            $url = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/Sheet1:append";
            $url .= '?valueInputOption=RAW&insertDataOption=INSERT_ROWS';

            $payload = json_encode([
                'values' => $batch
            ]);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ]
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                throw new \Exception('Failed to append to Google Sheet: ' . $result);
            }
        }
    }

    private function loadSettings(): array
    {
        if (!file_exists($this->settingsPath)) {
            return [];
        }
        $content = file_get_contents($this->settingsPath);
        return json_decode($content, true) ?: [];
    }

    private function saveSettings(array $settings): void
    {
        file_put_contents($this->settingsPath, json_encode($settings, JSON_PRETTY_PRINT));
    }

    private function getCommonStyles(): string
    {
        return <<<'CSS'
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1e40af;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            display: flex;
        }

        .sidebar {
            width: 240px;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            background: white;
            border-right: 1px solid var(--gray-200);
            padding: 24px 0;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 0 24px 24px;
            border-bottom: 1px solid var(--gray-200);
            margin-bottom: 16px;
        }

        .sidebar-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 24px;
            color: var(--gray-700);
            text-decoration: none;
            transition: all 0.2s;
            font-weight: 500;
        }

        .nav-item:hover {
            background: var(--gray-50);
            color: var(--primary);
        }

        .nav-item.active {
            background: #eff6ff;
            color: var(--primary);
            border-left: 3px solid var(--primary);
            padding-left: 21px;
        }

        .nav-icon {
            font-size: 18px;
        }

        .main-content {
            margin-left: 240px;
            flex: 1;
            padding: 32px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .header-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .header-actions {
            display: flex;
            gap: 12px;
        }

        .btn {
            padding: 10px 20px;
            border: 1px solid var(--gray-300);
            background: white;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn:hover {
            background: var(--gray-50);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success);
            color: white;
            border-color: var(--success);
        }

        .btn-success:hover {
            background: #059669;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-200);
            font-weight: 600;
            font-size: 16px;
        }

        .card-body {
            padding: 24px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
        }

        th {
            background: var(--gray-50);
            font-weight: 600;
            color: var(--gray-700);
        }

        tr:hover {
            background: var(--gray-50);
        }

        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-synced {
            background: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: var(--gray-500);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            width: 90%;
            max-width: 600px;
            border-radius: 12px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-700);
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 14px;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-top: 16px;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .alert-info {
            background: #dbeafe;
            color: #1e40af;
        }
    </style>
CSS;
    }

    private function getSidebar(string $active = ''): string
    {
        $nav = [
            ['id' => 'dashboard', 'label' => '仪表板', 'icon' => '📊', 'url' => '/admin/dashboard'],
            ['id' => 'customer-services', 'label' => '客服管理', 'icon' => '👥', 'url' => '/admin/customer-services'],
            ['id' => 'tracking', 'label' => '追踪数据', 'icon' => '📈', 'url' => '/admin/tracking'],
            ['id' => 'conversions', 'label' => '转化管理', 'icon' => '🎯', 'url' => '/admin/conversions'],
        ];

        $navHtml = '';
        foreach ($nav as $item) {
            $activeClass = $item['id'] === $active ? 'active' : '';
            $navHtml .= "<a href='{$item['url']}' class='nav-item {$activeClass}'>
                <span class='nav-icon'>{$item['icon']}</span>
                {$item['label']}
            </a>";
        }

        return <<<HTML
        <div class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-title">管理后台</div>
            </div>
            {$navHtml}
            <a href="/admin/logout" class="nav-item" style="position: absolute; bottom: 24px; width: calc(100% - 48px);">
                <span class="nav-icon">🚪</span>
                退出登录
            </a>
        </div>
HTML;
    }
}
