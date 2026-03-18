<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class AdminController
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

    private function isAuthenticated(Request $request): bool
    {
        // 简单的认证检查 - 在生产环境中应该使用更安全的方法
        $cookies = $request->getCookieParams();
        return isset($cookies['admin_logged_in']) && $cookies['admin_logged_in'] === 'true';
    }

    private function requireAuth(Request $request, Response $response): ?Response
    {
        if (!$this->isAuthenticated($request)) {
            return $response->withHeader('Location', '/admin')->withStatus(302);
        }
        return null;
    }

    public function login(Request $request, Response $response): Response
    {
        if ($this->isAuthenticated($request)) {
            return $response->withHeader('Location', '/admin/dashboard')->withStatus(302);
        }

        $html = $this->renderLoginPage();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function handleLogin(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        // 简单的认证 - 在生产环境中应该使用数据库和哈希密码
        if ($username === 'admin' && $password === 'admin123') {
            return $response
                ->withHeader('Set-Cookie', 'admin_logged_in=true; Path=/; HttpOnly')
                ->withHeader('Location', '/admin/dashboard')
                ->withStatus(302);
        }

        $html = $this->renderLoginPage('用户名或密码错误');
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function logout(Request $request, Response $response): Response
    {
        return $response
            ->withHeader('Set-Cookie', 'admin_logged_in=; Path=/; HttpOnly; Expires=Thu, 01 Jan 1970 00:00:00 GMT')
            ->withHeader('Location', '/admin')
            ->withStatus(302);
    }

    public function dashboard(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        $html = $this->renderDashboard();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function customerServices(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        if ($request->getMethod() === 'POST') {
            return $this->handleCustomerServiceUpdate($request, $response);
        }

        $html = $this->renderCustomerServices();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function trackingData(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        $html = $this->renderTrackingData();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function assignments(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        $html = $this->renderAssignments();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    // API 方法
    public function apiCustomerServices(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $method = $request->getMethod();
        
        switch ($method) {
            case 'GET':
                $services = $this->loadCustomerServices();
                $response->getBody()->write(json_encode($services));
                return $response->withHeader('Content-Type', 'application/json');
                
            case 'POST':
                $data = json_decode($request->getBody()->getContents(), true);
                $result = $this->createCustomerService($data);
                $response->getBody()->write(json_encode($result));
                return $response->withHeader('Content-Type', 'application/json');
                
            case 'PUT':
                $data = json_decode($request->getBody()->getContents(), true);
                $result = $this->updateCustomerService($data);
                $response->getBody()->write(json_encode($result));
                return $response->withHeader('Content-Type', 'application/json');
                
            case 'DELETE':
                $data = json_decode($request->getBody()->getContents(), true);
                $result = $this->deleteCustomerService($data['id'] ?? '');
                $response->getBody()->write(json_encode($result));
                return $response->withHeader('Content-Type', 'application/json');
                
            default:
                $response->getBody()->write(json_encode(['error' => 'Method not allowed']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(405);
        }
    }

    public function apiTrackingData(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $trackingData = $this->loadTrackingData();
        $response->getBody()->write(json_encode($trackingData));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function apiAssignments(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $assignments = $this->loadAssignments();
        $response->getBody()->write(json_encode($assignments));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function apiSettings(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) {
            $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        if ($request->getMethod() === 'POST') {
            $data = json_decode($request->getBody()->getContents(), true);
            $result = $this->updateSettings($data);
            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');
        } else {
            $settings = $this->loadSettings();
            $response->getBody()->write(json_encode($settings));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    // 数据处理方法
    private function loadSettings(): array
    {
        $file = $this->dataDir . '/settings.json';
        if (!file_exists($file)) {
            $defaultSettings = [
                'cloaking_enhanced' => false
            ];
            file_put_contents($file, json_encode($defaultSettings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $defaultSettings;
        }
        
        return json_decode(file_get_contents($file), true) ?: ['cloaking_enhanced' => false];
    }

    private function updateSettings(array $data): array
    {
        $file = $this->dataDir . '/settings.json';
        $settings = $this->loadSettings();
        
        if (isset($data['cloaking_enhanced'])) {
            $settings['cloaking_enhanced'] = (bool)$data['cloaking_enhanced'];
        }
        
        file_put_contents($file, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->logger->info('Settings updated', $settings);
        
        return ['success' => true, 'settings' => $settings];
    }

    private function loadCustomerServices(): array
    {
        $file = $this->dataDir . '/customer_services.json';
        if (!file_exists($file)) {
            return [];
        }
        
        return json_decode(file_get_contents($file), true) ?: [];
    }

    private function createCustomerService(array $data): array
    {
        $services = $this->loadCustomerServices();
        
        $newService = [
            'id' => uniqid('cs_', true),
            'name' => $data['name'] ?? '',
            'url' => $data['url'] ?? '',
            'fallback_url' => $data['fallback_url'] ?? '/',
            'status' => $data['status'] ?? 'active',
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $services[] = $newService;
        
        file_put_contents($this->dataDir . '/customer_services.json', json_encode($services, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        return ['success' => true, 'service' => $newService];
    }

    private function updateCustomerService(array $data): array
    {
        $services = $this->loadCustomerServices();
        $updated = false;
        
        for ($i = 0; $i < count($services); $i++) {
            if ($services[$i]['id'] === ($data['id'] ?? '')) {
                $services[$i]['name'] = $data['name'] ?? $services[$i]['name'];
                $services[$i]['url'] = $data['url'] ?? $services[$i]['url'];
                $services[$i]['fallback_url'] = $data['fallback_url'] ?? $services[$i]['fallback_url'];
                $services[$i]['status'] = $data['status'] ?? $services[$i]['status'];
                $updated = true;
                break;
            }
        }
        
        if ($updated) {
            file_put_contents($this->dataDir . '/customer_services.json', json_encode($services, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return ['success' => true];
        }
        
        return ['success' => false, 'error' => 'Service not found'];
    }

    private function deleteCustomerService(string $id): array
    {
        $services = $this->loadCustomerServices();
        $originalCount = count($services);
        
        $services = array_filter($services, function($service) use ($id) {
            return $service['id'] !== $id;
        });
        
        if (count($services) < $originalCount) {
            file_put_contents($this->dataDir . '/customer_services.json', json_encode(array_values($services), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return ['success' => true];
        }
        
        return ['success' => false, 'error' => 'Service not found'];
    }

    private function handleCustomerServiceUpdate(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        if (isset($data['action'])) {
            switch ($data['action']) {
                case 'create':
                    $result = $this->createCustomerService($data);
                    break;
                case 'update':
                    $result = $this->updateCustomerService($data);
                    break;
                case 'delete':
                    $result = $this->deleteCustomerService($data['id'] ?? '');
                    break;
                default:
                    $result = ['success' => false, 'error' => 'Invalid action'];
            }
        } else {
            $result = ['success' => false, 'error' => 'No action specified'];
        }

        return $response->withHeader('Location', '/admin/customer-services')->withStatus(302);
    }

    private function loadTrackingData(): array
    {
        $file = $this->dataDir . '/../logs/tracking.log';
        if (!file_exists($file)) {
            return [];
        }
        
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $data = [];
        
        foreach (array_reverse(array_slice($lines, -100)) as $line) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[([^\]]+)\] (.+)$/', $line, $matches)) {
                $data[] = [
                    'timestamp' => $matches[1],
                    'type' => $matches[2],
                    'data' => json_decode($matches[3], true) ?: $matches[3]
                ];
            }
        }
        
        return $data;
    }

    private function loadAssignments(): array
    {
        $file = $this->dataDir . '/assignments.jsonl';
        if (!file_exists($file)) {
            return [];
        }
        
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $assignments = [];
        
        foreach (array_reverse(array_slice($lines, -100)) as $line) {
            $assignment = json_decode($line, true);
            if ($assignment) {
                $assignments[] = $assignment;
            }
        }
        
        return $assignments;
    }

    // 渲染方法
    private function renderLoginPage(string $error = ''): string
    {
        $errorHtml = $error ? "<div class='alert alert-danger'>$error</div>" : '';
        
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台登录</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 50px; }
        .login-container { max-width: 400px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="text"], input[type="password"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #0056b3; }
        .alert { padding: 10px; margin-bottom: 20px; border-radius: 4px; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        h2 { text-align: center; margin-bottom: 30px; color: #333; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>管理后台登录</h2>
        $errorHtml
        <form method="POST" action="/admin/login">
            <div class="form-group">
                <label for="username">用户名:</label>
                <input type="text" id="username" name="username" required>
            </div>
            <div class="form-group">
                <label for="password">密码:</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit">登录</button>
        </form>
    </div>
</body>
</html>
HTML;
    }

    private function renderDashboard(): string
    {
        $settings = $this->loadSettings();
        $cloakingStatus = $settings['cloaking_enhanced'] ? '启用' : '禁用';
        $cloakingClass = $settings['cloaking_enhanced'] ? 'text-success' : 'text-danger';
        $cloakingChecked = $settings['cloaking_enhanced'] ? 'checked' : '';
        
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - 仪表板</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f8f9fa; }
        .navbar { background: #343a40; color: white; padding: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .navbar h1 { margin: 0; font-size: 1.5rem; }
        .navbar a { color: white; text-decoration: none; margin-left: 1rem; }
        .navbar a:hover { text-decoration: underline; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        .nav-tabs { display: flex; border-bottom: 1px solid #dee2e6; margin-bottom: 2rem; }
        .nav-tab { padding: 0.75rem 1.5rem; background: none; border: none; cursor: pointer; border-bottom: 2px solid transparent; }
        .nav-tab.active { border-bottom-color: #007bff; color: #007bff; font-weight: bold; }
        .nav-tab:hover { background: #f8f9fa; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #dee2e6; font-weight: bold; }
        .card-body { padding: 1.5rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
        .stat-number { font-size: 2rem; font-weight: bold; color: #007bff; }
        .stat-label { color: #6c757d; margin-top: 0.5rem; }
        .text-success { color: #28a745 !important; }
        .text-danger { color: #dc3545 !important; }
        .btn { padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn:hover { opacity: 0.9; }
        .form-group { margin-bottom: 1rem; }
        .form-control { width: 100%; padding: 0.5rem; border: 1px solid #ced4da; border-radius: 4px; }
        .switch { position: relative; display: inline-block; width: 60px; height: 34px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 26px; width: 26px; left: 4px; bottom: 4px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #2196F3; }
        input:focus + .slider { box-shadow: 0 0 1px #2196F3; }
        input:checked + .slider:before { transform: translateX(26px); }
        .setting-item { display: flex; justify-content: space-between; align-items: center; padding: 1rem 0; border-bottom: 1px solid #eee; }
        .setting-item:last-child { border-bottom: none; }
        .setting-label { font-weight: bold; }
        .setting-description { color: #6c757d; font-size: 0.9rem; margin-top: 0.25rem; }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>管理后台</h1>
        <div>
            <a href="/admin/dashboard">仪表板</a>
            <a href="/admin/customer-services">客服管理</a>
            <a href="/admin/tracking">追踪数据</a>
            <a href="/admin/assignments">分配记录</a>
            <a href="/admin/logout">退出</a>
        </div>
    </nav>

    <div class="container">
        <div class="nav-tabs">
            <button class="nav-tab active" onclick="showTab('overview')">概览</button>
            <button class="nav-tab" onclick="showTab('settings')">系统设置</button>
        </div>

        <div id="overview" class="tab-content active">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number" id="total-assignments">-</div>
                    <div class="stat-label">总分配数</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="success-rate">-</div>
                    <div class="stat-label">成功率</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="active-services">-</div>
                    <div class="stat-label">活跃客服</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number $cloakingClass">$cloakingStatus</div>
                    <div class="stat-label">引流加强</div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">最近活动</div>
                <div class="card-body">
                    <div id="recent-activity">加载中...</div>
                </div>
            </div>
        </div>

        <div id="settings" class="tab-content">
            <div class="card">
                <div class="card-header">系统设置</div>
                <div class="card-body">
                    <div class="setting-item">
                        <div>
                            <div class="setting-label">引流加强</div>
                            <div class="setting-description">启用后，只允许来自Google搜索的用户访问客服分配接口</div>
                        </div>
                        <label class="switch">
                            <input type="checkbox" id="cloaking-switch" onchange="toggleCloaking()" $cloakingChecked>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // 隐藏所有标签内容
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // 移除所有标签的活跃状态
            document.querySelectorAll('.nav-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // 显示选中的标签内容
            document.getElementById(tabName).classList.add('active');
            
            // 设置选中的标签为活跃状态
            event.target.classList.add('active');
        }

        function toggleCloaking() {
            const checkbox = document.getElementById('cloaking-switch');
            const enabled = checkbox.checked;
            
            fetch('/admin/api/settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    cloaking_enhanced: enabled
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('设置已更新');
                    location.reload();
                } else {
                    alert('更新失败');
                    checkbox.checked = !enabled; // 恢复原状态
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('更新失败');
                checkbox.checked = !enabled; // 恢复原状态
            });
        }

        // 加载统计数据
        function loadStats() {
            Promise.all([
                fetch('/admin/api/assignments').then(r => r.json()),
                fetch('/admin/api/customer-services').then(r => r.json())
            ]).then(([assignments, services]) => {
                document.getElementById('total-assignments').textContent = assignments.length;
                
                const successCount = assignments.filter(a => a.launch_success).length;
                const successRate = assignments.length > 0 ? Math.round((successCount / assignments.length) * 100) : 0;
                document.getElementById('success-rate').textContent = successRate + '%';
                
                const activeServices = services.filter(s => s.status === 'active').length;
                document.getElementById('active-services').textContent = activeServices;
                
                // 显示最近活动
                const recentActivity = assignments.slice(0, 10).map(a => 
                    `<div style="padding: 0.5rem 0; border-bottom: 1px solid #eee;">
                        <strong>\${a.stockcode || '未知股票'}</strong> - \${a.customer_service_name} 
                        <span style="color: #6c757d; float: right;">\${a.created_at}</span>
                    </div>`
                ).join('');
                
                document.getElementById('recent-activity').innerHTML = recentActivity || '暂无活动记录';
            }).catch(error => {
                console.error('Error loading stats:', error);
            });
        }

        // 页面加载时执行
        document.addEventListener('DOMContentLoaded', loadStats);
    </script>
</body>
</html>
HTML;
    }

    private function renderCustomerServices(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>客服管理</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f8f9fa; }
        .navbar { background: #343a40; color: white; padding: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .navbar h1 { margin: 0; font-size: 1.5rem; }
        .navbar a { color: white; text-decoration: none; margin-left: 1rem; }
        .navbar a:hover { text-decoration: underline; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #dee2e6; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
        .card-body { padding: 1.5rem; }
        .btn { padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn:hover { opacity: 0.9; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #dee2e6; }
        .table th { background: #f8f9fa; font-weight: bold; }
        .status-active { color: #28a745; font-weight: bold; }
        .status-inactive { color: #dc3545; font-weight: bold; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: white; margin: 5% auto; padding: 2rem; width: 80%; max-width: 500px; border-radius: 8px; }
        .form-group { margin-bottom: 1rem; }
        .form-control { width: 100%; padding: 0.5rem; border: 1px solid #ced4da; border-radius: 4px; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: black; }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>客服管理</h1>
        <div>
            <a href="/admin/dashboard">仪表板</a>
            <a href="/admin/customer-services">客服管理</a>
            <a href="/admin/tracking">追踪数据</a>
            <a href="/admin/assignments">分配记录</a>
            <a href="/admin/logout">退出</a>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <div class="card-header">
                客服列表
                <button class="btn btn-primary" onclick="showAddModal()">添加客服</button>
            </div>
            <div class="card-body">
                <table class="table" id="services-table">
                    <thead>
                        <tr>
                            <th>名称</th>
                            <th>URL</th>
                            <th>备用URL</th>
                            <th>状态</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- 数据将通过JavaScript加载 -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 添加/编辑模态框 -->
    <div id="serviceModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modal-title">添加客服</h2>
            <form id="service-form">
                <input type="hidden" id="service-id">
                <div class="form-group">
                    <label for="service-name">名称:</label>
                    <input type="text" id="service-name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="service-url">URL:</label>
                    <input type="url" id="service-url" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="service-fallback">备用URL:</label>
                    <input type="url" id="service-fallback" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="service-status">状态:</label>
                    <select id="service-status" class="form-control">
                        <option value="active">活跃</option>
                        <option value="inactive">停用</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-success">保存</button>
                <button type="button" class="btn btn-danger" onclick="closeModal()">取消</button>
            </form>
        </div>
    </div>

    <script>
        let services = [];

        function loadServices() {
            fetch('/admin/api/customer-services')
                .then(response => response.json())
                .then(data => {
                    services = data;
                    renderServicesTable();
                })
                .catch(error => console.error('Error:', error));
        }

        function renderServicesTable() {
            const tbody = document.querySelector('#services-table tbody');
            tbody.innerHTML = services.map(service => `
                <tr>
                    <td>\${service.name}</td>
                    <td><a href="\${service.url}" target="_blank">\${service.url}</a></td>
                    <td><a href="\${service.fallback_url}" target="_blank">\${service.fallback_url}</a></td>
                    <td><span class="status-\${service.status}">\${service.status === 'active' ? '活跃' : '停用'}</span></td>
                    <td>\${service.created_at}</td>
                    <td>
                        <button class="btn btn-primary" onclick="editService('\${service.id}')">编辑</button>
                        <button class="btn btn-danger" onclick="deleteService('\${service.id}')">删除</button>
                    </td>
                </tr>
            `).join('');
        }

        function showAddModal() {
            document.getElementById('modal-title').textContent = '添加客服';
            document.getElementById('service-form').reset();
            document.getElementById('service-id').value = '';
            document.getElementById('serviceModal').style.display = 'block';
        }

        function editService(id) {
            const service = services.find(s => s.id === id);
            if (service) {
                document.getElementById('modal-title').textContent = '编辑客服';
                document.getElementById('service-id').value = service.id;
                document.getElementById('service-name').value = service.name;
                document.getElementById('service-url').value = service.url;
                document.getElementById('service-fallback').value = service.fallback_url;
                document.getElementById('service-status').value = service.status;
                document.getElementById('serviceModal').style.display = 'block';
            }
        }

        function deleteService(id) {
            if (confirm('确定要删除这个客服吗？')) {
                fetch('/admin/api/customer-services', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadServices();
                    } else {
                        alert('删除失败');
                    }
                })
                .catch(error => console.error('Error:', error));
            }
        }

        function closeModal() {
            document.getElementById('serviceModal').style.display = 'none';
        }

        document.getElementById('service-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = {
                id: document.getElementById('service-id').value,
                name: document.getElementById('service-name').value,
                url: document.getElementById('service-url').value,
                fallback_url: document.getElementById('service-fallback').value,
                status: document.getElementById('service-status').value
            };

            const method = formData.id ? 'PUT' : 'POST';
            
            fetch('/admin/api/customer-services', {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeModal();
                    loadServices();
                } else {
                    alert('保存失败');
                }
            })
            .catch(error => console.error('Error:', error));
        });

        // 页面加载时执行
        document.addEventListener('DOMContentLoaded', loadServices);
    </script>
</body>
</html>
HTML;
    }

    private function renderTrackingData(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>追踪数据</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f8f9fa; }
        .navbar { background: #343a40; color: white; padding: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .navbar h1 { margin: 0; font-size: 1.5rem; }
        .navbar a { color: white; text-decoration: none; margin-left: 1rem; }
        .navbar a:hover { text-decoration: underline; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #dee2e6; font-weight: bold; }
        .card-body { padding: 1.5rem; }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { padding: 0.75rem; text-align: left; border-bottom: 1px solid #dee2e6; }
        .table th { background: #f8f9fa; font-weight: bold; }
        .log-entry { margin-bottom: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 4px; }
        .log-timestamp { font-weight: bold; color: #007bff; }
        .log-type { display: inline-block; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem; font-weight: bold; }
        .log-type-page_track { background: #d4edda; color: #155724; }
        .log-type-uppage_track { background: #d1ecf1; color: #0c5460; }
        .log-type-error_log { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>追踪数据</h1>
        <div>
            <a href="/admin/dashboard">仪表板</a>
            <a href="/admin/customer-services">客服管理</a>
            <a href="/admin/tracking">追踪数据</a>
            <a href="/admin/assignments">分配记录</a>
            <a href="/admin/logout">退出</a>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <div class="card-header">最近追踪记录</div>
            <div class="card-body">
                <div id="tracking-data">加载中...</div>
            </div>
        </div>
    </div>

    <script>
        function loadTrackingData() {
            fetch('/admin/api/tracking')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('tracking-data');
                    if (data.length === 0) {
                        container.innerHTML = '<p>暂无追踪数据</p>';
                        return;
                    }
                    
                    container.innerHTML = data.map(entry => `
                        <div class="log-entry">
                            <div class="log-timestamp">\${entry.timestamp}</div>
                            <span class="log-type log-type-\${entry.type}">\${entry.type}</span>
                            <pre style="margin-top: 0.5rem; white-space: pre-wrap;">\${JSON.stringify(entry.data, null, 2)}</pre>
                        </div>
                    `).join('');
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('tracking-data').innerHTML = '<p>加载失败</p>';
                });
        }

        document.addEventListener('DOMContentLoaded', loadTrackingData);
    </script>
</body>
</html>
HTML;
    }

    private function renderAssignments(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>分配记录</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f8f9fa; }
        .navbar { background: #343a40; color: white; padding: 1rem; display: flex; justify-content: space-between; align-items: center; }
        .navbar h1 { margin: 0; font-size: 1.5rem; }
        .navbar a { color: white; text-decoration: none; margin-left: 1rem; }
        .navbar a:hover { text-decoration: underline; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .card-header { padding: 1rem 1.5rem; border-bottom: 1px solid #dee2e6; font-weight: bold; }
        .card-body { padding: 1.5rem; }
        .table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
        .table th, .table td { padding: 0.5rem; text-align: left; border-bottom: 1px solid #dee2e6; }
        .table th { background: #f8f9fa; font-weight: bold; }
        .status-success { color: #28a745; font-weight: bold; }
        .status-failed { color: #dc3545; font-weight: bold; }
        .status-pending { color: #ffc107; font-weight: bold; }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>分配记录</h1>
        <div>
            <a href="/admin/dashboard">仪表板</a>
            <a href="/admin/customer-services">客服管理</a>
            <a href="/admin/tracking">追踪数据</a>
            <a href="/admin/assignments">分配记录</a>
            <a href="/admin/logout">退出</a>
        </div>
    </nav>

    <div class="container">
        <div class="card">
            <div class="card-header">最近分配记录</div>
            <div class="card-body">
                <table class="table" id="assignments-table">
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>股票代码</th>
                            <th>客服名称</th>
                            <th>状态</th>
                            <th>IP地址</th>
                            <th>用户代理</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- 数据将通过JavaScript加载 -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function loadAssignments() {
            fetch('/admin/api/assignments')
                .then(response => response.json())
                .then(data => {
                    const tbody = document.querySelector('#assignments-table tbody');
                    if (data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6">暂无分配记录</td></tr>';
                        return;
                    }
                    
                    tbody.innerHTML = data.map(assignment => {
                        let status = '待处理';
                        let statusClass = 'status-pending';
                        
                        if (assignment.launch_success) {
                            status = '成功';
                            statusClass = 'status-success';
                        } else if (assignment.page_leave_at || assignment.fallback_redirect_at) {
                            status = '失败';
                            statusClass = 'status-failed';
                        }
                        
                        return `
                            <tr>
                                <td>\${assignment.created_at}</td>
                                <td>\${assignment.stockcode || '-'}</td>
                                <td>\${assignment.customer_service_name}</td>
                                <td><span class="\${statusClass}">\${status}</span></td>
                                <td>\${assignment.ip}</td>
                                <td title="\${assignment.user_agent}">\${assignment.user_agent.substring(0, 50)}...</td>
                            </tr>
                        `;
                    }).join('');
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.querySelector('#assignments-table tbody').innerHTML = '<tr><td colspan="6">加载失败</td></tr>';
                });
        }

        document.addEventListener('DOMContentLoaded', loadAssignments);
    </script>
</body>
</html>
HTML;
    }
}