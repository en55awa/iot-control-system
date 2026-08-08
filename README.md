# IoT 控制系统

ESP8266 + PHP + MySQL HTTP Polling 架构的物联网控制平台。

---

## 文件清单

| 文件 | 说明 |
|------|------|
| `api.php` | API 路由入口（ESP8266 轮询、登录、设备管理、指令系统） |
| `config.php` | 数据库配置，自动加载 secrets.php |
| `jwt.php` | JWT 认证工具（HS256） |
| `key_manager.php` | Key 生成/管理 CLI 工具 |
| `secrets.php` | **敏感配置：JWT 密钥、CLI 密码** |
| `index.html` | Web 控制面板（单页应用） |
| `.htaccess` | 保护敏感文件不被 HTTP 直接访问 |
| `upgrade_db_ota.php` | OTA 数据库迁移脚本（v3.30+） |
| `upgrade_db_ota_status.php` | OTA 状态追踪迁移脚本（v3.33+） |
| `upgrade_db_v435.php` | v4.35 数据库迁移脚本（开关隐藏 + 自定义指令） |

---

## 部署步骤

### 1.配置文件(必须)

打开 `secrets.php`，修改以下两个值为强密码：

```php
define('JWT_SECRET', '你的64位随机字符串');
define('CLI_PASSWORD', '你的CLI操作密码，可同上面密码一致');
```

> **警告：** 这两个值必须修改，否则存在严重安全隐患！

### 2. 上传文件

将以下文件上传到服务器目录：

```
api.php
config.php
jwt.php
key_manager.php
secrets.php
index.html
.htaccess
upgrade_db_ota.php
upgrade_db_ota_status.php
upgrade_db_v435.php
```

### 3. 初始化数据库

浏览器依次访问以下迁移脚本：

1. `http://你的域名/upgrade_db_ota.php`
2. `http://你的域名/upgrade_db_ota_status.php`
3. `http://你的域名/upgrade_db_v435.php`

执行成功后会自动删除，如未删除请在服务器后台手动删除！！

### 4. 生成第一个设备 Key

访问 `http://你的域名/`

默认账号：`en55` / `888888`（登录后进入设置请立即修改密码）

在key管理中添加新设备key进行生成
---

## API 接口

| 方法 | 路由 | 说明 |
|------|------|------|
| GET | `poll?device_id=xxx&key=xxx&wifi=xxx&rssi=xxx&ip=xxx` | ESP8266 轮询（公开） |
| POST | `login` | 登录 |
| GET | `devices` | 设备列表 |
| GET | `devices/{id}/pins` | 设备引脚配置 |
| POST | `devices/{id}/pins` | 添加引脚配置 |
| DELETE | `devices/{id}/pins/{pin}` | 删除引脚配置 |
| PUT | `devices/{id}/pins/{pin}/hide` | 隐藏开关（v4.35） |
| PUT | `devices/{id}/pins/{pin}/show` | 显示开关（v4.35） |
| POST | `devices/{id}/control` | 控制引脚 |
| GET | `devices/{id}/logs` | WiFi 日志 |
| GET | `schedules` | 指令列表（v4.35） |
| POST | `schedules` | 创建指令（v4.35） |
| PUT | `schedules/{id}/toggle` | 暂停/恢复指令（v4.35） |
| DELETE | `schedules/{id}` | 删除指令（v4.35） |
| GET | `keys` | Key 列表 |
| POST | `keys` | 生成新 Key |
| DELETE | `keys/{key}` | 删除 Key |
| PUT | `keys/{key}/disable` | 禁用 Key |
| PUT | `keys/{key}/enable` | 启用 Key |
| POST | `user/password` | 修改密码 |
| POST | `user/username` | 修改用户名 |

---

## 固件配置（config.h）

```cpp
#define WIFI_SSID     "你的WiFi名称"
#define WIFI_PASSWORD "你的WiFi密码"
#define SERVER_HOST   "你的域名"   // 替换为你的域名
#define SERVER_PORT   80
#define POLL_INTERVAL 5000                // 轮询间隔（毫秒）
#define DEVICE_KEY    "key"  // 后端生成的16位hex
#define DEVICE_ID     "esp001"            // 设备标识
```

**POLL_INTERVAL 建议：**

| 间隔 | 月流量 | 适用场景 |
|------|--------|----------|
| 2000ms | ~1.0 GB | 单设备、实时性要求高 |
| 5000ms | ~410 MB | 推荐（默认） |
| 10000ms | ~205 MB | 多设备、省流量，但延迟高，只适用于不太需要延迟的设备 |

---

## CLI Key 管理

所有 CLI 命令需要传入密码作为第一个参数：

```bash
php key_manager.php <密码> generate "备注"
php key_manager.php <密码> list
php key_manager.php <密码> delete <key>
php key_manager.php <密码> verify <key>
php key_manager.php <密码> disable <key>
php key_manager.php <密码> enable <key>
```

---

## 安全说明

本项目已包含以下安全加固：

- SQL 注入防护（PDO 预处理语句）
- 密码 bcrypt 哈希存储
- JWT 签名防篡改（hash_equals 时序安全比较）
- 设备 Key 格式校验（16位 hex）
- 登录失败速率限制（5分钟5次锁定）
- 错误信息不泄露给客户端（写入服务器日志）
- CORS 限制为实际域名
- 前端模板字符串 HTML 转义
- 敏感文件受 .htaccess 保护
- CLI 操作密码验证

**部署后务必修改 `secrets.php` 中的默认值！**
