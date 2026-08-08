# IoT 控制系统 v4.35

ESP8266 + PHP + MySQL HTTP Polling 架构的物联网控制平台。

**v4.35 新功能**：开关隐藏 + 自定义指令系统（定时开关 / 延时关闭 / 定时重启），并包含 v3.34/v3.35 的全部修复。

---

## 文件清单

| 文件 | 说明 |
|------|------|
| `api.php` | API 路由入口（ESP8266 轮询、登录、设备管理、指令系统） |
| `config.php` | 数据库配置，自动加载 secrets.php（含 PHP 进程超时保护） |
| `jwt.php` | JWT 认证工具（HS256） |
| `key_manager.php` | Key 生成/管理 CLI 工具 |
| `secrets.php` | **敏感配置：JWT 密钥、CLI 密码** |
| `index.html` | Web 控制面板（单页应用） |
| `.htaccess` | 保护敏感文件不被 HTTP 直接访问 |
| `upgrade_db_ota.php` | OTA 数据库迁移脚本（v3.30+） |
| `upgrade_db_ota_status.php` | OTA 状态追踪迁移脚本（v3.33+） |
| `upgrade_db_v435.php` | **v4.35 数据库迁移脚本（开关隐藏 + 指令表）** |
| `tool/resetadmin.php` | 管理员密码重置工具（v2.10+） |
| `firmware/iot_firmware/config.h` | ESP8266 固件配置（WiFi/服务器/Key） |
| `firmware/iot_firmware/iot_firmware.ino` | 固件源码（支持 `CMD:reboot` 指令） |

---

## 部署步骤

### 1. 配置文件（必须）

打开 `secrets.php`，修改以下两个值为强密码：

```php
define('JWT_SECRET', '你的64位随机字符串');       // 至少32字符
define('CLI_PASSWORD', '你的CLI操作密码，可同上面密码一致');
```

> **警告：** 这两个值必须修改，否则存在严重安全隐患！

打开 `config.php`，修改数据库连接信息：

```php
define('DB_NAME', '你的数据库名');
define('DB_USER', '你的数据库用户名');
define('DB_PASS', '你的数据库密码');
```

打开 `api.php`，修改 CORS 域名（第 56 行附近）：

```php
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://你的域名';
```

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
tool/resetadmin.php
```

### 3. 初始化数据库

先手动创建数据库与基础表（users、device_keys、device_pins、device_logs、device_shares、device_combos、device_combo_items、device_switch_combos、device_switch_combo_pins），建表 SQL 见仓库 main 分支 README。

然后浏览器**依次**访问以下迁移脚本：

1. `http://你的域名/upgrade_db_ota.php` — 创建 `ota_firmware` 表、`firmware/` 目录、`users.token_version` 字段
2. `http://你的域名/upgrade_db_ota_status.php` — 添加 `device_keys.ota_status`、`ota_sent_at` 字段
3. `http://你的域名/upgrade_db_v435.php` — 添加 `device_pins.hidden` 字段、创建 `device_schedules` 表

执行成功后脚本会自动删除，如未删除请在服务器后台手动删除！

### 4. 生成第一个设备 Key

访问 `http://你的域名/`

默认账号：`en55` / `888888`（登录后进入设置请立即修改密码）

在 key 管理中添加新设备 key 进行生成

---

## API 接口

### 公开接口

| 方法 | 路由 | 说明 |
|------|------|------|
| GET | `poll?device_id=xxx&key=xxx&wifi=xxx&rssi=xxx&ip=xxx` | ESP8266 轮询+上报（OTA 状态由服务端自动追踪） |
| GET | `version` | 获取版本号 |
| POST | `login` | 登录 |
| POST | `logout` | 退出登录（使旧 Token 失效） |
| GET | `ota/firmware/{id}.bin?key=xxx&md5=xxx` | OTA 固件下载（Key 验证） |
| POST | `ota/report` | OTA 结果回报（旧版固件兼容） |

### 认证接口（需 JWT Token）

| 方法 | 路由 | 说明 |
|------|------|------|
| GET | `devices` | 设备列表 |
| GET | `devices/{id}/pins` | 引脚配置 |
| POST | `devices/{id}/pins` | 添加引脚 |
| DELETE | `devices/{id}/pins/{pin}` | 删除引脚 |
| PUT | `devices/{id}/pins/{pin}/hide` | **隐藏开关（v4.35）** |
| PUT | `devices/{id}/pins/{pin}/show` | **显示开关（v4.35）** |
| POST | `devices/{id}/control` | 控制引脚 |
| GET | `devices/{id}/logs` | WiFi 日志 |
| POST | `devices/{id}/share` | 共享设备 |
| DELETE | `devices/{id}/share/{userId}` | 取消共享 |
| GET | `devices/{id}/shares` | 共享列表 |
| GET | `combos` | 预设列表 |
| POST | `combos` | 创建预设 |
| POST | `combos/{id}/execute` | 执行预设 |
| DELETE | `combos/{id}` | 删除预设 |
| GET | `switch-combos` | 组合开关列表 |
| POST | `switch-combos` | 创建组合开关 |
| POST | `switch-combos/{id}/toggle` | 切换组合开关 |
| DELETE | `switch-combos/{id}` | 删除组合开关 |
| GET | `schedules` | **指令列表（v4.35）** |
| POST | `schedules` | **创建指令（v4.35：timer / delay_off / reboot）** |
| PUT | `schedules/{id}/toggle` | **暂停/恢复指令（v4.35）** |
| DELETE | `schedules/{id}` | **删除指令（v4.35）** |
| GET | `keys` | Key 列表 |
| POST | `keys` | 生成新 Key |
| DELETE | `keys/{key}` | 删除 Key |
| PUT | `keys/{key}/disable` | 禁用 Key |
| PUT | `keys/{key}/enable` | 启用 Key |
| POST | `user/password` | 修改密码 |
| POST | `user/username` | 修改用户名 |
| GET | `admin/users` | 用户列表（管理员） |
| POST | `admin/users` | 创建用户（管理员） |
| DELETE | `admin/users/{id}` | 删除用户（管理员） |
| POST | `admin/users/{id}/password` | 重置用户密码（管理员） |
| PUT | `admin/users/{id}/disable` | 禁用用户（管理员） |
| PUT | `admin/users/{id}/enable` | 启用用户（管理员） |
| GET | `ota` | OTA 记录列表 |
| POST | `ota/upload` | 上传固件 |
| GET | `ota/status` | OTA 状态（含超时兜底检查） |
| DELETE | `ota/{id}` | 删除/取消 OTA 记录 |

---

## 固件配置（config.h）

```cpp
#define WIFI_SSID     "你的WiFi名称"
#define WIFI_PASSWORD "你的WiFi密码"
#define SERVER_HOST   "你的域名"      // 不带 http://
#define SERVER_PORT   80
#define POLL_INTERVAL 5000            // 轮询间隔（毫秒）
#define DEVICE_KEY    "后端生成的16位hex"
#define DEVICE_ID     "esp001"        // 设备标识
```

**POLL_INTERVAL 建议：**

| 间隔 | 月流量 | 适用场景 |
|------|--------|----------|
| 2000ms | ~1.0 GB | 单设备、实时性要求高 |
| 5000ms | ~410 MB | 推荐（默认） |
| 10000ms | ~205 MB | 多设备、省流量，但延迟高 |

> **v4.35 固件必须重新编译烧录**：新增 `CMD:reboot` 指令解析，配合服务端定时重启功能。

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
- JWT 签名防篡改（hash_equals 时序安全比较）+ Token 与密码绑定（v3.22）
- 退出登录销毁 Token（v3.31）
- 设备 Key 格式校验（16位 hex）
- 登录失败速率限制（5分钟5次锁定）
- 错误信息不泄露给客户端（写入服务器日志）
- CORS 限制为实际域名
- 前端模板字符串 HTML 转义
- 敏感文件受 .htaccess 保护
- CLI 操作密码验证
- PHP 进程超时保护（v3.34，防止设备间歇性掉线）

**部署后务必修改 `secrets.php` 中的默认值！**

---

## 版本升级（v3.35 → v4.35）

1. 备份现有数据库和文件
2. 上传 v4.35 的所有文件（覆盖）
3. 浏览器访问 `http://你的域名/upgrade_db_v435.php`（新增 `device_pins.hidden` 字段、创建 `device_schedules` 表）
4. 执行成功后脚本自动删除
5. 重新编译并烧录固件（新增 `CMD:reboot` 指令解析，必须更新）
