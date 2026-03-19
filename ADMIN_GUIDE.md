# GCLID 转化管理后台使用指南

## 访问管理后台

### 登录

1. 访问: `http://your-domain.com/admin`
2. 输入管理员用户名和密码
3. 登录凭据存储在 `backend/data/settings.json`

### 导航菜单

- **Dashboard**: 概览和统计信息
- **客服管理**: 管理客服账号信息
- **点击追踪**: 查看点击数据
- **转化管理**: GCLID 转化追踪（新增功能）
- **退出**: 退出登录

## 转化管理页面功能

访问路径: `/admin/conversions`

### 1. 查看转化记录

页面显示所有转化记录的列表，包含以下信息:
- **ID**: 转化记录唯一标识
- **GCLID**: Google Click ID
- **转化名称**: 固定为 "LINE加入"
- **转化时间**: 用户完成转化的时间
- **转化价值**: 转化金额（如果记录了价值）
- **货币**: 货币代码（默认 JPY）

### 2. 编辑转化记录

点击记录行的 "编辑" 按钮可以修改：
- **转化时间**: 调整转化发生的时间
- **转化价值**: 修改或清空转化价值（留空表示无价值）
- **货币**: 更改货币代码

**注意**:
- GCLID 不可修改（只读）
- 转化价值可以留空，表示仅追踪转化次数
- 转化价值范围: 0 - 999,999.99

### 3. 导出 CSV

点击 "导出 CSV" 按钮会下载符合 Google Ads 导入格式的 CSV 文件。

**CSV 格式**:
```csv
Parameters:TimeZone=Asia/Tokyo
Google Click ID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency
abc123,LINE加入,2024-03-19 14:30:00,1500,JPY
def456,LINE加入,2024-03-19 15:45:00,,JPY
```

**使用导出的 CSV**:
1. 登录 Google Ads
2. 工具和设置 → 转化 → 上传
3. 选择 "从文件导入"
4. 上传导出的 CSV 文件

### 4. Google Sheets 同步

#### 4.1 初次配置

**步骤 1: 创建 Google Cloud 项目**
1. 访问 https://console.cloud.google.com/
2. 创建新项目或选择现有项目
3. 启用 Google Sheets API

**步骤 2: 创建 OAuth 2.0 凭据**
1. 在侧边栏选择 "API 和服务" → "凭据"
2. 点击 "创建凭据" → "OAuth 客户端 ID"
3. 应用类型: Web 应用
4. 名称: GCLID Tracking
5. 授权重定向 URI: `https://your-domain.com/admin/google-callback`
6. 点击 "创建"
7. 记录 Client ID 和 Client Secret

**步骤 3: 配置系统**
编辑 `backend/data/settings.json`:
```json
{
  "google_client_id": "your-client-id.apps.googleusercontent.com",
  "google_client_secret": "your-client-secret",
  "admin_username": "admin",
  "admin_password": "$2y$10$..."
}
```

**步骤 4: 授权应用**
1. 在转化管理页面点击 "Google Sheets 授权"
2. 选择 Google 账号
3. 授权应用访问 Google Sheets
4. 完成后会自动保存 refresh_token

**步骤 5: 配置 Spreadsheet**
1. 在 Google Sheets 创建新的电子表格
2. 在第一行添加表头:
   ```
   Google Click ID | Conversion Name | Conversion Time | Conversion Value | Conversion Currency
   ```
3. 复制 Spreadsheet ID（URL 中 `/d/` 和 `/edit` 之间的字符串）
4. 在管理页面点击 "修改 Spreadsheet ID"
5. 输入 Spreadsheet ID 并保存

#### 4.2 手动同步

点击 "立即同步到 Google Sheets" 按钮可以手动触发同步。

**同步状态说明**:
- 🟢 **同步成功**: 显示同步的记录数量
- 🔴 **同步失败**: 显示错误信息
- 🟡 **配置未完成**: 需要完成授权或配置 Spreadsheet ID

#### 4.3 自动同步

系统每 10 分钟自动同步新增的转化记录到 Google Sheets。

**查看同步状态**:
- **授权状态**: 已授权/未授权
- **Spreadsheet ID**: 当前配置的表格 ID
- **最后同步时间**: 上次成功同步的时间
- **下次同步时间**: 预计下次自动同步的时间

**同步原理**:
1. 仅同步上次同步时间之后的新记录（增量同步）
2. 每批最多同步 100 条记录
3. 使用 refresh_token 自动刷新 access_token
4. 失败时记录详细错误日志

### 5. 刷新数据

点击 "刷新" 按钮重新加载转化记录列表。

## 同步状态监控

### 查看同步日志

**位置**: `backend/logs/google_sync.log`

**日志内容**:
- 同步开始和结束时间
- 同步的记录数量
- Token 刷新情况
- 错误详情和堆栈跟踪

**示例日志**:
```
[2024-03-19 14:30:00] sync.INFO: Starting scheduled sync
[2024-03-19 14:30:01] sync.INFO: Sync completed successfully {"count":15,"duration_ms":1234.56}
```

### 检查 Cron 任务

**查看任务**:
```bash
crontab -l
```

**查看 Cron 日志**:
```bash
grep CRON /var/log/syslog
```

**手动运行同步**:
```bash
docker exec <backend_container> php /var/www/html/backend/scripts/sync_google_sheets.php
```

## 故障排查

### 1. 转化记录未显示

**可能原因**:
- 前端未正确初始化 GCLID 追踪
- localStorage 被清除
- API 请求失败

**解决方法**:
1. 检查浏览器控制台错误
2. 确认 URL 包含 `gclid` 参数
3. 查看服务器日志: `backend/logs/app.log`

### 2. CSV 导出乱码

**可能原因**:
- Excel 未正确识别 UTF-8 编码

**解决方法**:
1. 使用 Excel "从文本/CSV" 功能导入
2. 选择文件编码: 65001 (UTF-8)
3. 或使用 Google Sheets 打开 CSV

### 3. Google Sheets 同步失败

**常见错误**:

**错误: "Google Sheets not configured"**
- 原因: 未配置 Spreadsheet ID 或 refresh_token
- 解决: 完成 OAuth 授权并配置 Spreadsheet ID

**错误: "Failed to refresh access token"**
- 原因: refresh_token 过期或无效
- 解决: 重新授权应用

**错误: "Failed to append to Google Sheet"**
- 原因: Spreadsheet ID 错误或权限不足
- 解决:
  1. 确认 Spreadsheet ID 正确
  2. 确认授权的 Google 账号有编辑权限
  3. 检查 Sheet1 是否存在（默认同步到第一个工作表）

**错误: "Sync already running"**
- 原因: 上次同步未完成，文件锁未释放
- 解决:
  1. 等待 60 秒（同步超时时间）
  2. 手动删除锁文件: `backend/data/sync.lock`

### 4. 编辑保存失败

**可能原因**:
- 转化价值超出范围（0-999999.99）
- 时间格式错误

**解决方法**:
1. 确认输入值在有效范围内
2. 检查浏览器控制台错误
3. 查看服务器日志

## 数据安全

### 敏感信息存储

- **OAuth Token**: refresh_token 存储在 `settings.json`，仅服务器可访问
- **Session Marker**: 存储在用户浏览器 localStorage，90 天有效期
- **GCLID**: 明文存储在数据库，用于关联转化

### 权限控制

- 管理后台需要登录认证
- 转化 API 端点无需认证（方便前端调用）
- Google Sheets API 使用 OAuth 2.0 授权

### 数据备份

**定期备份数据库**:
```bash
# 备份 SQLite 数据库
cp backend/data/tracking.db backend/data/tracking.db.backup.$(date +%Y%m%d)
```

**导出转化数据**:
使用 CSV 导出功能定期导出数据作为备份。

## 性能优化建议

### 1. 数据库索引

系统已自动为以下字段创建索引:
- conversion_sessions: session_marker, gclid, created_at, expires_at
- conversions: gclid, session_marker, created_at

### 2. 清理过期会话

定期清理过期的会话记录:
```sql
DELETE FROM conversion_sessions WHERE expires_at < datetime('now');
```

### 3. 同步频率调整

根据转化量调整同步频率:
- 高转化量: 5 分钟一次 (`*/5 * * * *`)
- 中等转化量: 10 分钟一次（默认）
- 低转化量: 30 分钟一次 (`*/30 * * * *`)

## 常见问题 (FAQ)

**Q: conversion_value 字段必须填写吗？**
A: 不必须。可以留空，表示仅追踪转化次数而不记录价值。

**Q: 可以追踪多种转化类型吗？**
A: 当前版本固定为 "LINE加入"。如需多种类型，需要修改代码。

**Q: GCLID 有效期是多久？**
A: 会话有效期为 90 天。超过 90 天的会话无法记录转化。

**Q: 同步失败会重试吗？**
A: 不会自动重试。失败的记录会在下次同步时重新尝试。

**Q: 可以删除转化记录吗？**
A: 当前版本不支持删除。如需删除，可以直接编辑数据库。

**Q: 如何更改同步的 Google Sheet？**
A: 在管理页面点击 "修改 Spreadsheet ID"，输入新的 ID 即可。

## 技术支持

如遇到其他问题，请查看:
- 详细技术文档: `README_GCLID_TRACKING.md`
- 快速入门指南: `QUICKSTART_GCLID.md`
- 服务器日志: `backend/logs/app.log`
- 同步日志: `backend/logs/google_sync.log`
