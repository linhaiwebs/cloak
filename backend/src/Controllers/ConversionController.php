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
        if (!$adminController->isAuthenticated()) {
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
        return <<<'HTML'
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>转化记录管理</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header h1 { font-size: 24px; margin-bottom: 10px; }
        .actions { display: flex; gap: 10px; margin-top: 15px; }
        .btn { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn:hover { background: #0056b3; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #545b62; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
        .table-container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: 600; }
        tr:hover { background: #f8f9fa; }
        .status { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
        .status-synced { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .edit-btn { background: #17a2b8; padding: 5px 10px; border-radius: 3px; color: white; text-decoration: none; font-size: 14px; }
        .edit-btn:hover { background: #138496; }
        .nav { margin-bottom: 10px; }
        .nav a { color: #007bff; text-decoration: none; margin-right: 15px; }
        .nav a:hover { text-decoration: underline; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 5% auto; padding: 30px; width: 90%; max-width: 600px; border-radius: 8px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
        .form-group input, .form-group select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .loading { text-align: center; padding: 40px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="nav">
                <a href="/admin/dashboard">Dashboard</a>
                <a href="/admin/customer-services">客服管理</a>
                <a href="/admin/tracking">点击追踪</a>
                <a href="/admin/conversions" style="font-weight: bold;">转化管理</a>
                <a href="/admin/logout" style="float: right;">退出</a>
            </div>
            <h1>GCLID 转化记录管理</h1>
            <div class="actions">
                <button class="btn" onclick="location.reload()">刷新</button>
                <button class="btn btn-success" onclick="exportCSV()">导出 CSV</button>
                <button class="btn btn-secondary" onclick="showGoogleAuth()">Google Sheets 授权</button>
                <button class="btn btn-secondary" id="syncBtn" onclick="syncGoogleSheets()">立即同步到 Google Sheets</button>
            </div>
            <div id="syncStatus" style="margin-top: 15px; padding: 10px; border-radius: 4px; display: none;"></div>
        </div>

        <div class="table-container">
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
            status.style.background = '#cce5ff';
            status.style.color = '#004085';
            status.textContent = '正在同步到 Google Sheets...';

            try {
                const res = await fetch('/admin/api/conversions/google-sync-now', { method: 'POST' });
                const data = await res.json();

                if (data.success) {
                    status.style.background = '#d4edda';
                    status.style.color = '#155724';
                    status.textContent = `同步成功！已同步 ${data.synced_count || 0} 条记录`;
                } else {
                    status.style.background = '#f8d7da';
                    status.style.color = '#721c24';
                    status.textContent = '同步失败: ' + (data.error || '未知错误');
                }
            } catch (e) {
                status.style.background = '#f8d7da';
                status.style.color = '#721c24';
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
}
