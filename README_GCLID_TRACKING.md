# GCLID 离线转化追踪系统文档

## 系统概述

本系统实现了基于 Google Click ID (GCLID) 的离线转化追踪功能，用于追踪用户从 Google Ads 点击到最终转化（如加入 LINE）的完整流程。

## 核心特性

1. **自动会话管理**: 检测到 URL 中的 `gclid` 参数时自动创建追踪会话，有效期 90 天
2. **灵活的价值追踪**: conversion_value 字段可选，支持仅追踪转化次数或同时追踪转化价值
3. **自动同步**: 每 10 分钟自动将新转化数据同步到 Google Sheets
4. **CSV 导出**: 支持导出符合 Google Ads 导入格式的 CSV 文件
5. **在线管理**: 提供完整的 Web 管理界面，支持查看和编辑转化记录

## 数据库表结构

### conversion_sessions 表
存储用户会话信息，用于关联 GCLID 与转化事件。

字段说明：
- `id`: 主键
- `session_marker`: 唯一会话标识符（32位十六进制字符串）
- `gclid`: Google Click ID
- `ip_address`: 用户 IP 地址
- `user_agent`: 浏览器 User-Agent
- `referrer`: 来源页面
- `created_at`: 创建时间
- `expires_at`: 过期时间（90天后）
- `converted`: 是否已转化（0/1）

### conversions 表
存储转化事件记录。

字段说明：
- `id`: 主键
- `session_marker`: 关联的会话标识符
- `gclid`: Google Click ID
- `conversion_name`: 转化名称（默认: "LINE加入"）
- `conversion_time`: 转化时间
- `conversion_value`: 转化价值（可选，NULL 表示无价值）
- `conversion_currency`: 货币代码（默认: JPY）
- `timezone`: 时区
- `ip_address`: 用户 IP 地址
- `user_agent`: 浏览器 User-Agent
- `referrer`: 来源页面
- `created_at`: 记录创建时间
- `updated_at`: 记录更新时间

## 前端集成

### 自动初始化
系统在页面加载时自动检测 URL 中的 `gclid` 参数：

```javascript
// 自动执行，无需手动调用
// URL: https://example.com/?gclid=abc123
// 系统会自动创建会话并存储到 localStorage
```

### 手动记录转化

```javascript
// 不带价值的转化（仅计数）
window.recordConversion();

// 带价值的转化
window.recordConversion(1500); // 1500 JPY
```

### 集成到现有按钮点击流程

转化追踪已集成到 `addjoin()` 函数中，会在用户点击加入按钮时自动记录：

```javascript
// 现有代码无需修改
addjoin('加入LINE');
// 内部会自动调用 recordConversion()
```

## 后端 API

### 创建会话
```
POST /app/maike/api/conversion/create-session
Content-Type: application/json

{
  "gclid": "abc123xyz"
}

响应:
{
  "success": true,
  "session_marker": "a1b2c3d4e5f6...",
  "expires_at": "2024-06-18 12:00:00"
}
```

### 记录转化
```
POST /app/maike/api/conversion/record
Content-Type: application/json

{
  "session_marker": "a1b2c3d4e5f6...",
  "gclid": "abc123xyz",
  "conversion_value": 1500,  // 可选
  "conversion_currency": "JPY",
  "timezone": "Asia/Tokyo"
}

响应:
{
  "success": true,
  "conversion_id": 123,
  "conversion_value": 1500
}
```

注意：
- `conversion_value` 可以省略或传 `null`，表示不记录价值
- 如果传值，必须在 0 到 999999.99 之间

## 管理后台

访问路径: `/admin/conversions`

功能：
1. **查看转化记录**: 显示所有转化记录列表
2. **在线编辑**: 可以修改转化时间、价值和货币
3. **CSV 导出**: 导出符合 Google Ads 导入格式的 CSV 文件
4. **Google Sheets 同步**: 手动触发或自动同步到 Google Sheets

## CSV 导出格式

导出的 CSV 文件格式符合 Google Ads 离线转化导入要求：

```csv
Parameters:TimeZone=Asia/Tokyo
Google Click ID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency
abc123,LINE加入,2024-03-19 14:30:00,1500,JPY
def456,LINE加入,2024-03-19 15:45:00,,JPY
```

注意：
- 第一行包含时区参数
- conversion_value 为空时，该列留空（不输出 "NULL"）
- 文件使用 UTF-8 编码（带 BOM）

## Google Sheets 自动同步

### 配置步骤

1. **创建 Google Cloud 项目**
   - 访问 https://console.cloud.google.com/
   - 创建新项目
   - 启用 Google Sheets API

2. **创建 OAuth 2.0 凭据**
   - 在 API 凭据页面创建 OAuth 2.0 客户端 ID
   - 应用类型：Web 应用
   - 授权重定向 URI: `https://your-domain.com/admin/google-callback`

3. **配置系统**
   - 在 `backend/data/settings.json` 中添加：
   ```json
   {
     "google_client_id": "your-client-id",
     "google_client_secret": "your-client-secret"
   }
   ```

4. **授权并配置 Spreadsheet**
   - 访问管理后台的转化管理页面
   - 点击 "Google Sheets 授权" 按钮
   - 完成 OAuth 授权流程
   - 配置 Spreadsheet ID

### 定时同步设置

#### Linux/Unix 系统 (推荐)

编辑 crontab:
```bash
crontab -e
```

添加以下行（每 10 分钟执行一次）:
```
*/10 * * * * /usr/bin/php /path/to/backend/scripts/sync_google_sheets.php >> /path/to/logs/cron.log 2>&1
```

#### 手动测试同步

```bash
php backend/scripts/sync_google_sheets.php
```

### 同步逻辑

1. **增量同步**: 仅同步上次同步时间戳之后的新记录
2. **批量处理**: 每次最多同步 100 条记录一批，避免 API 限制
3. **文件锁**: 使用文件锁防止并发执行
4. **自动重试**: Token 过期时自动刷新
5. **详细日志**: 记录每次同步的详细信息到 `logs/google_sync.log`

### 同步状态查询

访问管理后台查看：
- 授权状态
- Spreadsheet ID
- 最后同步时间
- 下次同步时间
- 同步历史记录

## 数据流程

1. **用户点击广告** → URL 包含 `gclid=xxx`
2. **页面加载** → JavaScript 检测 GCLID，调用 `create-session` API
3. **会话创建** → 服务器生成 session_marker，存储到数据库
4. **session_marker 存储** → 保存到 localStorage（有效期 90 天）
5. **用户转化** → 点击加入按钮，调用 `recordConversion()`
6. **转化记录** → 服务器记录转化数据，更新会话状态
7. **自动同步** → 每 10 分钟，cron 任务将新记录同步到 Google Sheets
8. **数据导入** → 从 Google Sheets 或 CSV 导入到 Google Ads

## 安全考虑

1. **会话过期**: 会话默认 90 天后自动过期
2. **数据验证**: 所有输入数据都经过严格验证
3. **SQL 注入防护**: 使用 PDO 预处理语句
4. **XSS 防护**: 所有输出都经过适当转义
5. **认证保护**: 管理后台需要登录才能访问
6. **Token 安全**: refresh_token 存储在服务器端，不暴露给客户端

## 故障排查

### 转化未记录

1. 检查浏览器控制台是否有错误
2. 确认 localStorage 中是否存在 `conversion_session_marker`
3. 检查服务器日志 `backend/logs/app.log`

### 同步失败

1. 检查 `logs/google_sync.log` 查看详细错误
2. 确认 refresh_token 是否有效
3. 确认 Spreadsheet ID 是否正确
4. 检查 Google Sheets API 配额是否用尽
5. 验证 cron 任务是否正常运行: `crontab -l`

### CSV 导出乱码

确保使用支持 UTF-8 的软件打开，或在 Excel 中：
1. 数据 → 从文本/CSV
2. 选择文件
3. 文件原始格式: 65001: Unicode (UTF-8)

## 性能优化

1. **索引优化**: 为常用查询字段添加了数据库索引
2. **批量同步**: Google Sheets 同步采用批量方式，每批 100 条
3. **增量更新**: 仅同步新增记录，避免重复数据
4. **Token 缓存**: refresh_token 复用，减少 OAuth 请求
5. **文件锁**: 防止并发同步导致的资源竞争

## 扩展功能建议

1. **多转化类型**: 支持自定义多种转化名称
2. **转化漏斗**: 添加中间步骤追踪（如访问页面、填写表单）
3. **实时推送**: 使用 webhook 实时推送转化数据
4. **数据分析**: 添加转化率、转化价值等统计图表
5. **A/B 测试**: 支持不同广告系列的转化对比
6. **邮件通知**: 同步失败时自动发送邮件通知

## 许可证

查看项目根目录的 LICENSE 文件。
