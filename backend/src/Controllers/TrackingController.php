<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Database\DatabaseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class TrackingController
{
    private LoggerInterface $logger;
    private DatabaseHelper $db;
    private string $apiKey = '713d5d59e1fb8c803715340cc6e09c6749e3bf85e0f55963db0e91e6513bf9e0';
    private string $apiUrl = 'https://cloaking.house/api/clicks';

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $dataDir = __DIR__ . '/../../data';

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $this->db = new DatabaseHelper($dataDir);
    }

    private function isAuthenticated(Request $request): bool
    {
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

    public function tracking(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        $html = $this->renderTrackingPage();
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function apiGetClicks(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        $params = $request->getQueryParams();

        $filters = [];
        if (!empty($params['date_from'])) {
            $filters['date_from'] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $filters['date_to'] = $params['date_to'];
        }
        if (!empty($params['countries'])) {
            $filters['countries'] = is_array($params['countries']) ? $params['countries'] : explode(',', $params['countries']);
        }
        if (!empty($params['flow_ids'])) {
            $filters['flow_ids'] = is_array($params['flow_ids']) ? $params['flow_ids'] : array_map('intval', explode(',', $params['flow_ids']));
        }
        if (!empty($params['devices'])) {
            $filters['devices'] = is_array($params['devices']) ? $params['devices'] : explode(',', $params['devices']);
        }
        if (!empty($params['os'])) {
            $filters['os'] = is_array($params['os']) ? $params['os'] : explode(',', $params['os']);
        }
        if (!empty($params['browsers'])) {
            $filters['browsers'] = is_array($params['browsers']) ? $params['browsers'] : explode(',', $params['browsers']);
        }
        if (!empty($params['filter_types'])) {
            $filters['filter_types'] = is_array($params['filter_types']) ? $params['filter_types'] : explode(',', $params['filter_types']);
        }
        if (!empty($params['page_type'])) {
            $filters['page_type'] = $params['page_type'];
        }

        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $perPage = 10;

        try {
            $result = $this->db->getClicks($filters, $page, $perPage);
            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $result['data'],
                'pagination' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'per_page' => $result['per_page'],
                    'total_pages' => $result['total_pages']
                ]
            ]));
        } catch (\Exception $e) {
            $this->logger->error('Error fetching clicks: ' . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => '获取数据失败'
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function apiSyncClicks(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        try {
            $data = $request->getParsedBody() ?? [];

            $postData = [
                'api_key' => $this->apiKey,
                'per_page' => 100,
                'page' => 1
            ];

            if (!empty($data['date_ranges'])) {
                $postData['date_ranges'] = $data['date_ranges'];
            }

            $apiResponse = $this->fetchFromCloakingAPI($postData);

            if (!$apiResponse || empty($apiResponse['status']) || $apiResponse['status'] !== 'success') {
                throw new \Exception('API返回错误: ' . ($apiResponse['msg'] ?? '未知错误'));
            }

            $clicks = $apiResponse['data'] ?? [];

            foreach ($clicks as &$click) {
                if (!empty($click['referer'])) {
                    $domain = $this->extractDomainFromUrl($click['referer']);
                    $click['flow_domain'] = $domain;
                }
            }

            $inserted = $this->db->insertClicks($clicks);

            $response->getBody()->write(json_encode([
                'success' => true,
                'inserted' => $inserted,
                'total_received' => count($clicks),
                'message' => "成功同步 {$inserted} 条新记录"
            ]));
        } catch (\Exception $e) {
            $this->logger->error('Error syncing clicks: ' . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function extractDomainFromUrl(string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $parsed = parse_url($url);
        return $parsed['host'] ?? null;
    }

    public function apiGetFilters(Request $request, Response $response): Response
    {
        $authResponse = $this->requireAuth($request, $response);
        if ($authResponse) return $authResponse;

        try {
            $params = $request->getQueryParams();
            $domain = $params['domain'] ?? null;

            $options = $this->db->getFilterOptions($domain);
            $response->getBody()->write(json_encode([
                'success' => true,
                'filters' => $options
            ]));
        } catch (\Exception $e) {
            $this->logger->error('Error fetching filter options: ' . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => '获取筛选选项失败'
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function fetchFromCloakingAPI(array $postData): ?array
    {
        $ch = curl_init($this->apiUrl);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if (!empty($info['http_code']) && $info['http_code'] == 200 && $body) {
            return json_decode($body, true);
        }

        return null;
    }

    private function renderTrackingPage(): string
    {
        $commonStyles = $this->getCommonStyles();
        $sidebar = $this->getSidebar('tracking');

        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>追踪数据 - 管理后台</title>
    {{COMMON_STYLES}}
    <style>
        .filter-panel {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        .filter-item {
            display: flex;
            flex-direction: column;
        }

        .filter-item label {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--gray-700);
        }

        .filter-item input,
        .filter-item select {
            padding: 8px 12px;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            font-size: 13px;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .tracking-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .tracking-table thead {
            background: var(--gray-50);
        }

        .tracking-table th {
            padding: 12px 8px;
            text-align: left;
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 2px solid var(--gray-200);
            white-space: nowrap;
        }

        .tracking-table td {
            padding: 12px 8px;
            border-bottom: 1px solid var(--gray-100);
        }

        .tracking-table tbody tr:hover {
            background: var(--gray-50);
        }

        .tracking-table tbody tr:nth-child(even) {
            background: #fafafa;
        }

        .tracking-table tbody tr:nth-child(even):hover {
            background: var(--gray-50);
        }

        .country-cell {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .country-flag {
            font-size: 18px;
        }

        .user-agent-cell {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: help;
            position: relative;
        }

        .tooltip {
            position: fixed;
            background: #1f2937;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            max-width: 400px;
            word-wrap: break-word;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }

        .page-tag {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .page-tag.white {
            background: #f3f4f6;
            color: #374151;
        }

        .page-tag.offer {
            background: #d1fae5;
            color: #065f46;
        }

        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
        }

        .pagination button {
            padding: 6px 12px;
            border: 1px solid var(--gray-300);
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
        }

        .pagination button:hover:not(:disabled) {
            background: var(--gray-50);
        }

        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination button.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: var(--gray-500);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-500);
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .sync-info {
            font-size: 12px;
            color: var(--gray-500);
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <div class="app-container">
        {{SIDEBAR}}

        <div class="main-content">
            <div class="header">
                <div>
                    <h1 class="header-title">追踪数据</h1>
                    <span class="sync-info" id="sync-info">点击同步按钮获取最新数据</span>
                </div>
                <div class="header-actions">
                    <button class="btn btn-primary" onclick="syncData()">
                        🔄 同步数据
                    </button>
                </div>
            </div>

            <div class="content">
                <div class="filter-panel">
                    <div class="filter-grid">
                        <div class="filter-item">
                            <label>开始日期</label>
                            <input type="date" id="date_from">
                        </div>
                        <div class="filter-item">
                            <label>结束日期</label>
                            <input type="date" id="date_to">
                        </div>
                        <div class="filter-item">
                            <label>国家</label>
                            <select id="countries" multiple style="height: 38px;">
                                <option value="">全部</option>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label>流程 (Flows)</label>
                            <select id="filter_flows" multiple style="height: 38px;">
                                <option value="">全部</option>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label>设备</label>
                            <select id="devices">
                                <option value="">全部</option>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label>操作系统</label>
                            <select id="os">
                                <option value="">全部</option>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label>浏览器</label>
                            <select id="browsers">
                                <option value="">全部</option>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label>页面类型</label>
                            <select id="page_type">
                                <option value="">全部</option>
                                <option value="white">White Page</option>
                                <option value="offer">Offer Page</option>
                            </select>
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button class="btn" onclick="clearFilters()">清空筛选</button>
                        <button class="btn btn-primary" onclick="applyFilters()">应用筛选</button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-body" style="padding: 0; overflow-x: auto;">
                        <table class="tracking-table">
                            <thead>
                                <tr>
                                    <th>日期</th>
                                    <th>流量流</th>
                                    <th>IP地址</th>
                                    <th>国家</th>
                                    <th>ISP</th>
                                    <th>Referer</th>
                                    <th>User Agent</th>
                                    <th>设备</th>
                                    <th>操作系统</th>
                                    <th>浏览器</th>
                                    <th>过滤器</th>
                                    <th>页面</th>
                                </tr>
                            </thead>
                            <tbody id="table-body">
                                <tr>
                                    <td colspan="12" class="loading">加载中...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="pagination" class="pagination"></div>
            </div>
        </div>
    </div>

    <div id="tooltip" class="tooltip"></div>

    <script>
        let currentPage = 1;
        let currentFilters = {};
        let currentDomain = window.location.hostname;
        let defaultFlows = [];

        const countryNames = {
            'US': '美国', 'CN': '中国', 'JP': '日本', 'GB': '英国', 'DE': '德国',
            'FR': '法国', 'IN': '印度', 'BR': '巴西', 'RU': '俄罗斯', 'KR': '韩国',
            'CA': '加拿大', 'AU': '澳大利亚', 'IT': '意大利', 'ES': '西班牙',
            'MX': '墨西哥', 'ID': '印度尼西亚', 'NL': '荷兰', 'SA': '沙特阿拉伯',
            'TR': '土耳其', 'CH': '瑞士', 'PL': '波兰', 'BE': '比利时', 'SE': '瑞典',
            'TH': '泰国', 'AT': '奥地利', 'NO': '挪威', 'AE': '阿联酋', 'SG': '新加坡',
            'MY': '马来西亚', 'PH': '菲律宾', 'VN': '越南', 'HK': '香港', 'TW': '台湾'
        };

        function countryCodeToFlag(code) {
            if (!code || code.length !== 2) return '🏳️';
            const codePoints = code.toUpperCase().split('').map(char => 127397 + char.charCodeAt());
            return String.fromCodePoint(...codePoints);
        }

        function formatDate(timestamp) {
            if (!timestamp) return '-';
            const date = new Date(timestamp * 1000);
            return date.toLocaleString('zh-CN', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }

        async function loadData(page = 1) {
            currentPage = page;

            const params = new URLSearchParams({
                page: page.toString(),
                ...currentFilters
            });

            try {
                const response = await fetch(`/admin/api/tracking/clicks?${params}`);
                const result = await response.json();

                if (result.success) {
                    renderTable(result.data);
                    renderPagination(result.pagination);
                } else {
                    showError('加载数据失败');
                }
            } catch (error) {
                console.error('Error loading data:', error);
                showError('加载数据失败');
            }
        }

        function renderTable(data) {
            const tbody = document.getElementById('table-body');

            if (!data || data.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="12" class="empty-state">
                            <div class="empty-state-icon">📊</div>
                            <div>暂无数据</div>
                            <div style="font-size: 12px; margin-top: 8px;">点击"同步数据"按钮获取最新追踪数据</div>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = data.map(row => `
                <tr>
                    <td>${formatDate(row.time_created)}</td>
                    <td>${row.flow_id || '-'}</td>
                    <td>${row.ip_address || '-'}</td>
                    <td>
                        <div class="country-cell">
                            <span class="country-flag">${countryCodeToFlag(row.country_code)}</span>
                            <span>${countryNames[row.country_code] || row.country_code || '-'}</span>
                        </div>
                    </td>
                    <td>${row.isp || '-'}</td>
                    <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${row.referer || '-'}">${row.referer || '-'}</td>
                    <td class="user-agent-cell" onmouseenter="showTooltip(event, '${escapeHtml(row.user_agent || '')}')" onmouseleave="hideTooltip()">${truncate(row.user_agent, 30)}</td>
                    <td>${row.device || '-'}</td>
                    <td>${row.os || '-'}</td>
                    <td>${row.browser || '-'}</td>
                    <td>${row.filter_type || '-'}</td>
                    <td>
                        ${row.filter_page ? `<span class="page-tag ${row.filter_page}">${row.filter_page === 'white' ? 'White Page' : 'Offer Page'}</span>` : '-'}
                    </td>
                </tr>
            `).join('');
        }

        function renderPagination(pagination) {
            const container = document.getElementById('pagination');

            if (!pagination || pagination.total_pages <= 1) {
                container.innerHTML = '';
                return;
            }

            const { page, total_pages } = pagination;
            let html = `
                <button onclick="loadData(${page - 1})" ${page <= 1 ? 'disabled' : ''}>上一页</button>
            `;

            const maxButtons = 7;
            let startPage = Math.max(1, page - Math.floor(maxButtons / 2));
            let endPage = Math.min(total_pages, startPage + maxButtons - 1);

            if (endPage - startPage < maxButtons - 1) {
                startPage = Math.max(1, endPage - maxButtons + 1);
            }

            if (startPage > 1) {
                html += `<button onclick="loadData(1)">1</button>`;
                if (startPage > 2) {
                    html += `<span style="padding: 0 8px;">...</span>`;
                }
            }

            for (let i = startPage; i <= endPage; i++) {
                html += `<button onclick="loadData(${i})" class="${i === page ? 'active' : ''}">${i}</button>`;
            }

            if (endPage < total_pages) {
                if (endPage < total_pages - 1) {
                    html += `<span style="padding: 0 8px;">...</span>`;
                }
                html += `<button onclick="loadData(${total_pages})">${total_pages}</button>`;
            }

            html += `
                <button onclick="loadData(${page + 1})" ${page >= total_pages ? 'disabled' : ''}>下一页</button>
            `;

            container.innerHTML = html;
        }

        async function loadFilterOptions() {
            try {
                const response = await fetch(`/admin/api/tracking/filters?domain=${encodeURIComponent(currentDomain)}`);
                const result = await response.json();

                if (result.success) {
                    const filters = result.filters;

                    const countriesSelect = document.getElementById('countries');
                    countriesSelect.innerHTML = '<option value="">全部</option>' +
                        filters.countries.map(c => `<option value="${c}">${countryCodeToFlag(c)} ${countryNames[c] || c}</option>`).join('');

                    const devicesSelect = document.getElementById('devices');
                    devicesSelect.innerHTML = '<option value="">全部</option>' +
                        filters.devices.map(d => `<option value="${d}">${d}</option>`).join('');

                    const osSelect = document.getElementById('os');
                    osSelect.innerHTML = '<option value="">全部</option>' +
                        filters.os.map(o => `<option value="${o}">${o}</option>`).join('');

                    const browsersSelect = document.getElementById('browsers');
                    browsersSelect.innerHTML = '<option value="">全部</option>' +
                        filters.browsers.map(b => `<option value="${b}">${b}</option>`).join('');

                    const flowsSelect = document.getElementById('filter_flows');
                    if (filters.flows && filters.flows.length > 0) {
                        flowsSelect.innerHTML = filters.flows.map(f =>
                            `<option value="${f.flow_id}">${f.flow_id}${f.flow_domain ? ' (' + f.flow_domain + ')' : ''}</option>`
                        ).join('');

                        defaultFlows = filters.flows.map(f => f.flow_id);

                        for (let i = 0; i < flowsSelect.options.length; i++) {
                            flowsSelect.options[i].selected = true;
                        }

                        applyFilters();
                    } else {
                        flowsSelect.innerHTML = '<option value="">暂无数据</option>';
                    }
                }
            } catch (error) {
                console.error('Error loading filter options:', error);
            }
        }

        function applyFilters() {
            currentFilters = {};

            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            const countries = Array.from(document.getElementById('countries').selectedOptions).map(o => o.value).filter(v => v);
            const filterFlows = Array.from(document.getElementById('filter_flows').selectedOptions).map(o => o.value).filter(v => v);
            const devices = document.getElementById('devices').value;
            const os = document.getElementById('os').value;
            const browsers = document.getElementById('browsers').value;
            const pageType = document.getElementById('page_type').value;

            if (dateFrom) currentFilters.date_from = dateFrom;
            if (dateTo) currentFilters.date_to = dateTo;
            if (countries.length) currentFilters.countries = countries.join(',');
            if (filterFlows.length) currentFilters.flow_ids = filterFlows.join(',');
            if (devices) currentFilters.devices = devices;
            if (os) currentFilters.os = os;
            if (browsers) currentFilters.browsers = browsers;
            if (pageType) currentFilters.page_type = pageType;

            loadData(1);
        }

        function clearFilters() {
            document.getElementById('date_from').value = '';
            document.getElementById('date_to').value = '';
            document.getElementById('countries').selectedIndex = 0;
            document.getElementById('devices').selectedIndex = 0;
            document.getElementById('os').selectedIndex = 0;
            document.getElementById('browsers').selectedIndex = 0;
            document.getElementById('page_type').selectedIndex = 0;

            const flowsSelect = document.getElementById('filter_flows');
            for (let i = 0; i < flowsSelect.options.length; i++) {
                flowsSelect.options[i].selected = defaultFlows.includes(flowsSelect.options[i].value);
            }

            currentFilters = {};
            applyFilters();
        }

        async function syncData() {
            const btn = event.target;
            btn.disabled = true;
            btn.textContent = '同步中...';

            try {
                const response = await fetch('/admin/api/tracking/sync', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({})
                });

                const result = await response.json();

                if (result.success) {
                    showToast(result.message || '同步成功', 'success');
                    document.getElementById('sync-info').textContent = `最后同步: ${new Date().toLocaleString('zh-CN')}`;
                    loadData(currentPage);
                } else {
                    showToast(result.error || '同步失败', 'error');
                }
            } catch (error) {
                console.error('Error syncing data:', error);
                showToast('同步失败', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = '🔄 同步数据';
            }
        }

        function showTooltip(event, text) {
            if (!text || text === '-') return;

            const tooltip = document.getElementById('tooltip');
            tooltip.textContent = text;
            tooltip.style.display = 'block';

            const rect = event.target.getBoundingClientRect();
            tooltip.style.left = rect.left + 'px';
            tooltip.style.top = (rect.bottom + 5) + 'px';
        }

        function hideTooltip() {
            document.getElementById('tooltip').style.display = 'none';
        }

        function truncate(str, length) {
            if (!str) return '-';
            return str.length > length ? str.substring(0, length) + '...' : str;
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/'/g, '&#39;').replace(/"/g, '&quot;');
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

        function showError(message) {
            const tbody = document.getElementById('table-body');
            tbody.innerHTML = `
                <tr>
                    <td colspan="12" class="empty-state">
                        <div class="empty-state-icon">⚠️</div>
                        <div>${message}</div>
                    </td>
                </tr>
            `;
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadFilterOptions();
        });
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

    private function getCommonStyles(): string
    {
        return <<<'CSS'
    <style>
        :root {
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
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
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Microsoft YaHei", sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
        }

        .app-container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 240px;
            background: white;
            border-right: 1px solid var(--gray-200);
            padding: 24px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            margin-bottom: 32px;
        }

        .sidebar-logo {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
        }

        .sidebar-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .sidebar-nav {
            padding: 0 12px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            margin-bottom: 4px;
            border-radius: 8px;
            color: var(--gray-700);
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s;
        }

        .nav-item:hover {
            background: var(--gray-50);
            color: var(--primary);
        }

        .nav-item.active {
            background: #eff6ff;
            color: var(--primary);
            font-weight: 600;
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
