# GCLID 转化追踪系统 - 快速入门

## 功能概述

已成功实现基于 Google Click ID (GCLID) 的离线转化追踪系统，核心特性：

✅ **自动会话追踪**: URL 中的 `gclid` 参数自动创建 90 天有效期的追踪会话
✅ **灵活的价值记录**: conversion_value 可选，支持仅追踪次数或同时追踪价值
✅ **自动同步**: 每 10 分钟自动将新转化同步到 Google Sheets
✅ **CSV 导出**: 符合 Google Ads 导入格式的 CSV 导出功能
✅ **完整管理界面**: Web 界面查看、编辑转化记录

## 快速测试

### 1. 启动服务

```bash
docker-compose up -d
```

服务将在端口 9700 启动。

### 2. 访问管理后台

访问: `http://localhost:9700/admin`

默认登录凭据查看 `backend/data/settings.json`

### 3. 查看转化管理

登录后访问: `http://localhost:9700/admin/conversions`

## 前端集成测试

### 测试 GCLID 会话创建

1. 访问带 GCLID 参数的 URL:
   ```
   http://localhost:9700/?gclid=test_gclid_123
   ```

2. 打开浏览器控制台，检查 localStorage:
   ```javascript
   localStorage.getItem('conversion_session_marker')
   localStorage.getItem('conversion_gclid')
   ```

### 测试转化记录

1. 在页面上点击"加入"按钮，系统会自动记录转化

2. 或手动调用:
   ```javascript
   // 不带价值
   window.recordConversion()

   // 带价值（1500 JPY）
   window.recordConversion(1500)
   ```

3. 在管理后台查看转化记录

## API 测试

### 创建会话

```bash
curl -X POST http://localhost:9700/app/maike/api/conversion/create-session \
  -H "Content-Type: application/json" \
  -d '{"gclid": "test_123"}'
```

### 记录转化

```bash
curl -X POST http://localhost:9700/app/maike/api/conversion/record \
  -H "Content-Type: application/json" \
  -d '{
    "gclid": "test_123",
    "conversion_value": 1500,
    "conversion_currency": "JPY",
    "timezone": "Asia/Tokyo"
  }'
```

## Google Sheets 同步设置

### 1. 配置 Google OAuth

编辑 `backend/data/settings.json`:

```json
{
  "google_client_id": "your-client-id.apps.googleusercontent.com",
  "google_client_secret": "your-client-secret"
}
```

### 2. 配置 Cron 任务

在服务器上添加定时任务（每 10 分钟执行）:

```bash
# 编辑 crontab
crontab -e

# 添加这行
*/10 * * * * docker exec <container_name> php /var/www/html/backend/scripts/sync_google_sheets.php >> /var/log/gclid_sync.log 2>&1
```

或在 Docker 容器内:

```bash
# 进入容器
docker exec -it <backend_container> bash

# 添加 crontab
echo "*/10 * * * * php /var/www/html/backend/scripts/sync_google_sheets.php" | crontab -

# 启动 cron
service cron start
```

### 3. 手动测试同步

```bash
docker exec <backend_container> php /var/www/html/backend/scripts/sync_google_sheets.php
```

## 数据库结构

系统自动创建两个表：

**conversion_sessions** (追踪会话)
- session_marker: 唯一会话标识
- gclid: Google Click ID
- expires_at: 过期时间（90天）
- converted: 是否已转化

**conversions** (转化记录)
- gclid: Google Click ID
- conversion_name: 转化名称（默认 "LINE加入"）
- conversion_time: 转化时间
- conversion_value: 转化价值（可为 NULL）
- conversion_currency: 货币代码

## CSV 导出格式示例

```csv
Parameters:TimeZone=Asia/Tokyo
Google Click ID,Conversion Name,Conversion Time,Conversion Value,Conversion Currency
test_123,LINE加入,2024-03-19 14:30:00,1500,JPY
test_456,LINE加入,2024-03-19 15:45:00,,JPY
```

注意：value 为空时该列留空，不输出 "NULL"。

## 故障排查

### 转化未记录

1. 检查浏览器控制台错误
2. 确认 localStorage 中有 session_marker
3. 查看日志: `backend/logs/app.log`

### 同步失败

1. 查看同步日志: `backend/logs/google_sync.log`
2. 确认 Google OAuth 凭据配置正确
3. 确认 Spreadsheet ID 已配置
4. 检查 cron 任务是否运行: `crontab -l`

### 数据库问题

运行测试脚本:
```bash
docker exec <backend_container> php /var/www/html/backend/scripts/test_database.php
```

## 性能优化建议

1. **数据库索引**: 已为 gclid, session_marker, created_at 添加索引
2. **批量同步**: Google Sheets 每批最多 100 条记录
3. **增量更新**: 仅同步新增记录，避免重复
4. **并发控制**: 使用文件锁防止并发同步

## 下一步

1. **配置 Google OAuth**: 在 Google Cloud Console 创建凭据
2. **设置 Cron**: 配置自动同步任务
3. **测试完整流程**: 从点击广告到同步 Google Sheets
4. **导入 Google Ads**: 使用导出的 CSV 或 Google Sheets 导入转化数据

## 详细文档

查看 `README_GCLID_TRACKING.md` 获取完整文档。

## 支持

如有问题，请检查：
- 服务器日志: `backend/logs/app.log`
- 同步日志: `backend/logs/google_sync.log`
- Nginx 日志: `docker logs <nginx_container>`
