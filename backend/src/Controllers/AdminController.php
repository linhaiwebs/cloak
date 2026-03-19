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

        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public function isAuthenticated(Request $request = null): bool
    {
        if ($request === null) {
            $cookies = $_COOKIE;
        } else {
            $cookies = $request->getCookieParams();
        }
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


    private function getCommonStyles(): string
    {
        return <<<'CSS'
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            :root {
                --primary: #6366f1;
                --primary-dark: #4f46e5;
                --secondary: #8b5cf6;
                --success: #10b981;
                --warning: #f59e0b;
                --danger: #ef4444;
                --info: #3b82f6;
                --dark: #1e293b;
                --gray-50: #f8fafc;
                --gray-100: #f1f5f9;
                --gray-200: #e2e8f0;
                --gray-300: #cbd5e1;
                --gray-400: #94a3b8;
                --gray-500: #64748b;
                --gray-600: #475569;
                --gray-700: #334155;
                --gray-800: #1e293b;
                --gray-900: #0f172a;
                --sidebar-width: 240px;
                --header-height: 56px;
            }

            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
                background: var(--gray-50);
                color: var(--gray-900);
                font-size: 14px;
                line-height: 1.5;
            }

            .app-container {
                display: flex;
                min-height: 100vh;
            }

            .sidebar {
                width: var(--sidebar-width);
                background: white;
                border-right: 1px solid var(--gray-200);
                position: fixed;
                left: 0;
                top: 0;
                bottom: 0;
                z-index: 1000;
                overflow-y: auto;
            }

            .sidebar-header {
                padding: 16px 20px;
                border-bottom: 1px solid var(--gray-200);
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .sidebar-logo {
                width: 32px;
                height: 32px;
                background: linear-gradient(135deg, var(--primary), var(--secondary));
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-weight: 700;
                font-size: 16px;
            }

            .sidebar-title {
                font-size: 16px;
                font-weight: 600;
                color: var(--gray-900);
            }

            .sidebar-nav {
                padding: 12px;
            }

            .nav-item {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 8px 12px;
                margin-bottom: 2px;
                color: var(--gray-600);
                text-decoration: none;
                border-radius: 6px;
                transition: all 0.2s;
                font-size: 13px;
            }

            .nav-item:hover {
                background: var(--gray-50);
                color: var(--gray-900);
            }

            .nav-item.active {
                background: var(--primary);
                color: white;
            }

            .nav-icon {
                width: 18px;
                height: 18px;
                flex-shrink: 0;
            }

            .main-content {
                margin-left: var(--sidebar-width);
                flex: 1;
                min-height: 100vh;
            }

            .header {
                height: var(--header-height);
                background: white;
                border-bottom: 1px solid var(--gray-200);
                padding: 0 24px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                position: sticky;
                top: 0;
                z-index: 100;
            }

            .header-title {
                font-size: 18px;
                font-weight: 600;
                color: var(--gray-900);
            }

            .header-actions {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .btn {
                padding: 6px 14px;
                border: none;
                border-radius: 6px;
                font-size: 13px;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.2s;
                display: inline-flex;
                align-items: center;
                gap: 6px;
                text-decoration: none;
            }

            .btn-primary {
                background: var(--primary);
                color: white;
            }

            .btn-primary:hover {
                background: var(--primary-dark);
                transform: translateY(-1px);
            }

            .btn-secondary {
                background: var(--gray-100);
                color: var(--gray-700);
            }

            .btn-secondary:hover {
                background: var(--gray-200);
            }

            .btn-success {
                background: var(--success);
                color: white;
            }

            .btn-danger {
                background: var(--danger);
                color: white;
            }

            .btn-sm {
                padding: 4px 10px;
                font-size: 12px;
            }

            .content {
                padding: 20px 24px;
            }

            .card {
                background: white;
                border-radius: 8px;
                border: 1px solid var(--gray-200);
                margin-bottom: 16px;
            }

            .card-header {
                padding: 14px 18px;
                border-bottom: 1px solid var(--gray-200);
                font-weight: 600;
                font-size: 14px;
                display: flex;
                align-items: center;
                justify-content: space-between;
            }

            .card-body {
                padding: 18px;
            }

            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 16px;
                margin-bottom: 20px;
            }

            .stat-card {
                background: white;
                padding: 16px;
                border-radius: 8px;
                border: 1px solid var(--gray-200);
                transition: all 0.2s;
            }

            .stat-card:hover {
                border-color: var(--primary);
                box-shadow: 0 4px 12px rgba(99, 102, 241, 0.1);
            }

            .stat-label {
                font-size: 12px;
                color: var(--gray-500);
                margin-bottom: 6px;
                font-weight: 500;
            }

            .stat-value {
                font-size: 24px;
                font-weight: 700;
                color: var(--gray-900);
            }

            .stat-icon {
                width: 36px;
                height: 36px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 10px;
                font-size: 18px;
            }

            .badge {
                padding: 3px 8px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 600;
                display: inline-block;
            }

            .badge-success {
                background: #d1fae5;
                color: #065f46;
            }

            .badge-warning {
                background: #fef3c7;
                color: #92400e;
            }

            .badge-danger {
                background: #fee2e2;
                color: #991b1b;
            }

            .badge-info {
                background: #dbeafe;
                color: #1e40af;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 13px;
            }

            thead {
                background: linear-gradient(to bottom, #f8fafc, #f1f5f9);
            }

            th {
                text-align: left;
                padding: 14px 12px;
                font-weight: 600;
                color: var(--gray-700);
                border-bottom: 2px solid var(--gray-300);
                font-size: 13px;
                white-space: nowrap;
            }

            td {
                padding: 14px 12px;
                font-size: 13px;
                color: var(--gray-700);
                border-bottom: 1px solid var(--gray-200);
            }

            tbody tr {
                transition: background-color 0.15s;
            }

            tbody tr:hover {
                background: #f8fafc;
            }

            .modal {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.5);
                z-index: 2000;
                align-items: center;
                justify-content: center;
            }

            .modal.active {
                display: flex;
            }

            .modal-content {
                background: white;
                border-radius: 12px;
                width: 90%;
                max-width: 500px;
                max-height: 90vh;
                overflow-y: auto;
            }

            .modal-header {
                padding: 18px 24px;
                border-bottom: 1px solid var(--gray-200);
                display: flex;
                align-items: center;
                justify-content: space-between;
            }

            .modal-title {
                font-size: 16px;
                font-weight: 600;
            }

            .modal-close {
                width: 32px;
                height: 32px;
                border: none;
                background: var(--gray-100);
                border-radius: 6px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                color: var(--gray-600);
            }

            .modal-body {
                padding: 24px;
            }

            .form-group {
                margin-bottom: 16px;
            }

            .form-label {
                display: block;
                margin-bottom: 6px;
                font-size: 13px;
                font-weight: 500;
                color: var(--gray-700);
            }

            .form-control {
                width: 100%;
                padding: 8px 12px;
                border: 1px solid var(--gray-300);
                border-radius: 6px;
                font-size: 13px;
                transition: all 0.2s;
            }

            .form-control:focus {
                outline: none;
                border-color: var(--primary);
                box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            }

            .toggle {
                position: relative;
                display: inline-block;
                width: 44px;
                height: 24px;
            }

            .toggle input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .toggle-slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: var(--gray-300);
                transition: 0.3s;
                border-radius: 24px;
            }

            .toggle-slider:before {
                position: absolute;
                content: "";
                height: 18px;
                width: 18px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: 0.3s;
                border-radius: 50%;
            }

            .toggle input:checked + .toggle-slider {
                background-color: var(--primary);
            }

            .toggle input:checked + .toggle-slider:before {
                transform: translateX(20px);
            }

            @media (max-width: 768px) {
                .sidebar {
                    transform: translateX(-100%);
                }

                .main-content {
                    margin-left: 0;
                }
            }
        </style>
CSS;
    }

    private function renderLoginPage(string $error = ''): string
    {
        $errorHtml = $error ? "<div class='error-message'>$error</div>" : '';

        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - 管理后台</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 400px;
            padding: 40px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .logo {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            font-weight: 700;
        }

        h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .subtitle {
            color: #64748b;
            font-size: 14px;
        }

        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            border-left: 3px solid #ef4444;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #334155;
            margin-bottom: 8px;
        }

        input {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }

        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }

        button:active {
            transform: translateY(0);
        }

        .login-footer {
            text-align: center;
            margin-top: 24px;
            font-size: 12px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">A</div>
            <h1>管理后台</h1>
            <p class="subtitle">请登录以继续访问</p>
        </div>

        {{ERROR_HTML}}

        <form method="POST" action="/admin/login">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <button type="submit">登录</button>
        </form>

        <div class="login-footer">
            © 2024 管理后台. All rights reserved.
        </div>
    </div>
</body>
</html>
HTML;

        return str_replace('{{ERROR_HTML}}', $errorHtml, $html);
    }

    private function renderDashboard(): string
    {
        $settings = $this->loadSettings();
        $cloakingStatus = $settings['cloaking_enhanced'] ? '启用' : '禁用';
        $cloakingChecked = $settings['cloaking_enhanced'] ? 'checked' : '';

        $commonStyles = $this->getCommonStyles();
        $sidebar = $this->getSidebar('dashboard');

        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>仪表板 - 管理后台</title>
    {{COMMON_STYLES}}
</head>
<body>
    <div class="app-container">
        {{SIDEBAR}}

        <div class="main-content">
            <div class="header">
                <h1 class="header-title">仪表板</h1>
                <div class="header-actions">
                    <span style="font-size: 12px; color: var(--gray-500);">欢迎回来</span>
                </div>
            </div>

            <div class="content">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #dbeafe; color: #1e40af;">
                            👥
                        </div>
                        <div class="stat-label">活跃客服</div>
                        <div class="stat-value" id="active-services">-</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                            🛡️
                        </div>
                        <div class="stat-label">引流加强</div>
                        <div class="stat-value" style="font-size: 18px;">{{CLOAKING_STATUS}}</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        系统设置
                    </div>
                    <div class="card-body">
                        <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 0;">
                            <div>
                                <div style="font-weight: 600; margin-bottom: 4px;">引流加强模式</div>
                                <div style="font-size: 12px; color: var(--gray-500);">启用后，只允许来自Google搜索的用户访问客服分配接口</div>
                            </div>
                            <label class="toggle">
                                <input type="checkbox" id="cloaking-switch" onchange="toggleCloaking()" {{CLOAKING_CHECKED}}>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function toggleCloaking() {
            const checkbox = document.getElementById('cloaking-switch');
            const enabled = checkbox.checked;

            fetch('/admin/api/settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ cloaking_enhanced: enabled })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('设置已更新', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('更新失败', 'error');
                    checkbox.checked = !enabled;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('更新失败', 'error');
                checkbox.checked = !enabled;
            });
        }

        function loadStats() {
            fetch('/admin/api/customer-services')
                .then(r => r.json())
                .then(services => {
                    const activeServices = services.filter(s => s.status === 'active').length;
                    document.getElementById('active-services').textContent = activeServices;
                })
                .catch(error => {
                    console.error('Error loading stats:', error);
                });
        }

        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 20px;
                background: ${type === 'success' ? '#10b981' : '#ef4444'};
                color: white;
                border-radius: 8px;
                font-size: 13px;
                z-index: 9999;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            `;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        document.addEventListener('DOMContentLoaded', loadStats);
    </script>
</body>
</html>
HTML;

        return str_replace(
            ['{{COMMON_STYLES}}', '{{SIDEBAR}}', '{{CLOAKING_STATUS}}', '{{CLOAKING_CHECKED}}'],
            [$commonStyles, $sidebar, $cloakingStatus, $cloakingChecked],
            $html
        );
    }

    private function renderCustomerServices(): string
    {
        $commonStyles = $this->getCommonStyles();
        $sidebar = $this->getSidebar('customer-services');

        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>客服管理 - 管理后台</title>
    {{COMMON_STYLES}}
</head>
<body>
    <div class="app-container">
        {{SIDEBAR}}

        <div class="main-content">
            <div class="header">
                <h1 class="header-title">客服管理</h1>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="showAddModal()">
                        ➕ 添加客服
                    </button>
                </div>
            </div>

            <div class="content">
                <div class="card">
                    <div class="card-body" style="padding: 0;">
                        <table id="services-table">
                            <thead>
                                <tr>
                                    <th>名称</th>
                                    <th>URL</th>
                                    <th>备用URL</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th style="text-align: right;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="6" style="text-align: center; color: var(--gray-400);">加载中...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal" id="serviceModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modal-title">添加客服</h3>
                <button class="modal-close" onclick="closeModal()">✕</button>
            </div>
            <div class="modal-body">
                <form id="service-form" onsubmit="handleSubmit(event)">
                    <input type="hidden" id="service-id">

                    <div class="form-group">
                        <label class="form-label">名称</label>
                        <input type="text" id="service-name" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">URL</label>
                        <input type="url" id="service-url" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">备用URL</label>
                        <input type="url" id="service-fallback" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">状态</label>
                        <select id="service-status" class="form-control">
                            <option value="active">活跃</option>
                            <option value="inactive">停用</option>
                        </select>
                    </div>

                    <div style="display: flex; gap: 10px; margin-top: 24px;">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">保存</button>
                        <button type="button" class="btn btn-secondary" onclick="closeModal()">取消</button>
                    </div>
                </form>
            </div>
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

            if (services.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: var(--gray-400); padding: 40px;">暂无客服数据</td></tr>';
                return;
            }

            tbody.innerHTML = services.map(service => {
                const truncatedUrl = service.url.length > 50 ? service.url.substring(0, 50) + '...' : service.url;
                const truncatedFallback = service.fallback_url.length > 40 ? service.fallback_url.substring(0, 40) + '...' : service.fallback_url;
                return `
                <tr>
                    <td><strong style="color: var(--gray-800);">${service.name}</strong></td>
                    <td><a href="${service.url}" target="_blank" style="color: var(--primary); text-decoration: none; font-size: 13px;" title="${service.url}">${truncatedUrl}</a></td>
                    <td><a href="${service.fallback_url}" target="_blank" style="color: var(--gray-600); text-decoration: none; font-size: 13px;" title="${service.fallback_url}">${truncatedFallback}</a></td>
                    <td><span class="badge badge-${service.status === 'active' ? 'success' : 'danger'}">${service.status === 'active' ? '活跃' : '停用'}</span></td>
                    <td style="color: var(--gray-600);">${service.created_at}</td>
                    <td style="text-align: right;">
                        <div style="display: inline-flex; gap: 6px;">
                            <button class="btn btn-secondary btn-sm" onclick="editService('${service.id}')">✏️ 编辑</button>
                            <button class="btn btn-danger btn-sm" onclick="deleteService('${service.id}')">🗑️ 删除</button>
                        </div>
                    </td>
                </tr>
                `;
            }).join('');
        }

        function showAddModal() {
            document.getElementById('modal-title').textContent = '添加客服';
            document.getElementById('service-form').reset();
            document.getElementById('service-id').value = '';
            document.getElementById('serviceModal').classList.add('active');
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
                document.getElementById('serviceModal').classList.add('active');
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
                        showToast('删除成功', 'success');
                        loadServices();
                    } else {
                        showToast('删除失败', 'error');
                    }
                })
                .catch(error => console.error('Error:', error));
            }
        }

        function closeModal() {
            document.getElementById('serviceModal').classList.remove('active');
        }

        function handleSubmit(e) {
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
                    showToast('保存成功', 'success');
                    closeModal();
                    loadServices();
                } else {
                    showToast('保存失败', 'error');
                }
            })
            .catch(error => console.error('Error:', error));
        }

        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 20px;
                background: ${type === 'success' ? '#10b981' : '#ef4444'};
                color: white;
                border-radius: 8px;
                font-size: 13px;
                z-index: 9999;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            `;
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        document.addEventListener('DOMContentLoaded', loadServices);
    </script>
</body>
</html>
HTML;

        return str_replace(
            ['{{COMMON_STYLES}}', '{{SIDEBAR}}'],
            [$commonStyles, $sidebar],
            $html
        );
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
                <div class="sidebar-logo">A</div>
                <span class="sidebar-title">管理后台</span>
            </div>
            <div class="sidebar-nav">
                $navHtml
                <a href="/admin/logout" class="nav-item" style="margin-top: 20px; color: var(--danger);">
                    <span class="nav-icon">🚪</span>
                    退出登录
                </a>
            </div>
        </div>
HTML;
    }
}
