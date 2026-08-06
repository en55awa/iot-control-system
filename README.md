# IoT 控制系统 v3.31

ESP8266 + PHP + MySQL HTTP Polling 架构的物联网控制平台。

支持多用户隔离、设备共享、组合开关、预设、OTA 固件更新等功能。

---

## 文件清单

| 文件 | 说明 |
|------|------|
| `backend/api.php` | API 路由入口（ESP8266 轮询、登录、设备管理、OTA） |
| `backend/config.php` | 数据库配置，自动加载 secrets.php |
| `backend/jwt.php` | JWT 认证工具（HS256） |
| `backend/key_manager.php` | Key 生成/管理 CLI 工具 |
| `backend/secrets.php` | **【需配置】JWT 密钥、CLI 密码** |
| `backend/index.html` | Web 控制面板（单页应用） |
| `backend/.htaccess` | 保护敏感文件不被 HTTP 直接访问 |
| `backend/upgrade_db_v3.php` | 数据库迁移脚本（v2.10 → v3.20，创建预设/组合开关表） |
| `backend/upgrade_db_ota.php` | 数据库迁移脚本（v3.20+ → v3.31，创建 OTA 表、firmware 目录、token_version 字段） |
| `backend/tool/resetadmin.php` | 管理员密码重置工具（使用后自动删除） |
| `firmware/iot_firmware/config.h` | **【需配置】ESP8266 配置** |
| `firmware/iot_firmware/iot_firmware.ino` | ESP8266 固件源码（含 OTA 支持） |
| `VERSION.md` | 版本更新日志 |

---

## 需要配置的占位符清单

本项目已将所有敏感信息替换为占位符。**部署前必须修改以下文件：**

### 1. `backend/secrets.php`

```php
// 当前占位符：
define('JWT_SECRET', 'YOUR_JWT_SECRET_HERE_AT_LEAST_32_CHARS_LONG');
define('CLI_PASSWORD', 'YOUR_JWT_SECRET_HERE_AT_LEAST_32_CHARS_LONG');
```

**修改为：**
```php
define('JWT_SECRET', '你的64位随机字符串');       // 至少32字符
define('CLI_PASSWORD', '你的CLI操作密码');         // 可与上面一致
```

> 生成随机字符串：在浏览器控制台执行 `crypto.randomUUID().replace(/-/g,'')` 两次拼接即可得到64位。

### 2. `backend/config.php`

```php
// 当前占位符：
define('DB_NAME', 'YOUR_DB_NAME');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');
```

**修改为你的 MySQL 数据库信息。**

### 3. `firmware/iot_firmware/config.h`

```cpp
// 当前占位符：
#define WIFI_SSID     "YOUR_WIFI_SSID"
#define WIFI_PASSWORD "YOUR_WIFI_PASSWORD"
#define SERVER_HOST   "YOUR_SERVER_HOST"
#define DEVICE_KEY    "YOUR_DEVICE_KEY_HERE"
```

**修改为你的 WiFi、服务器域名和设备 Key。**

### 4. `backend/api.php` CORS 配置

```php
// 当前占位符：
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'YOUR_DOMAIN_HERE';
```

**修改为你的域名：**
```php
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://你的域名';
```

### 5. `backend/tool/resetadmin.php`

```php
// 当前默认值：
define('RESET_KEY', 'passwd');
```

**修改为一个随机字符串。** 此文件仅在重置管理员密码时使用，使用后自动删除。

---

## 数据库迁移脚本

本项目包含两个数据库迁移脚本，根据你的升级起点选择执行：

### `upgrade_db_v3.php` — v2.10 → v3.20 迁移

创建预设和组合开关相关的 4 张表：

| 表名 | 用途 |
|------|------|
| `device_combos` | 预设表 |
| `device_combo_items` | 预设项表（每个预设包含哪些引脚及目标状态） |
| `device_switch_combos` | 组合开关表 |
| `device_switch_combo_pins` | 组合开关联动引脚表 |

**适用场景**：从 v2.10 升级到 v3.31 时，需要先执行此脚本。

> 脚本使用 `CREATE TABLE IF NOT EXISTS`，已存在的表会自动跳过，可安全重复执行。执行后自动删除。

### `upgrade_db_ota.php` — v3.20+ → v3.31 迁移

- 创建 `ota_firmware` 表（记录 OTA 固件更新状态：pending/updating/done/failed）
- 创建 `firmware/` 目录用于存储固件文件
- 在 `firmware/` 目录放置 `.htaccess` 禁止直接 HTTP 访问
- 添加 `ota_firmware.error` 字段记录更新失败原因（v3.31+）
- 添加 `users.token_version` 字段用于 JWT 退出销毁（v3.31+）

**适用场景**：从 v3.20/v3.22 升级到 v3.31 时执行。

> 脚本使用幂等操作（`CREATE TABLE IF NOT EXISTS`、目录存在检查），可安全重复执行。执行后自动删除。

---

## 部署步骤

### 全新安装

#### 第一步：创建数据库

```sql
CREATE DATABASE iot_system DEFAULT CHARSET utf8mb4;
CREATE USER 'iot_user'@'localhost' IDENTIFIED BY '你的密码';
GRANT ALL PRIVILEGES ON iot_system.* TO 'iot_user'@'localhost';
FLUSH PRIVILEGES;
```

#### 第二步：创建数据表

项目未包含 `init_db.php`，需手动执行建表 SQL。请参考主仓库 README 中的完整 SQL，或从已有的 v2.10/v3.20 数据库直接升级。

#### 第三步：修改配置文件

按照上方「需要配置的占位符清单」修改以下文件：
1. `backend/secrets.php` — JWT 密钥、CLI 密码
2. `backend/config.php` — 数据库连接信息
3. `backend/api.php` — CORS 域名
4. `backend/tool/resetadmin.php` — 重置密钥
5. `firmware/iot_firmware/config.h` — WiFi、服务器、Key

#### 第四步：上传文件

将 `backend/` 目录下所有文件上传到服务器 Web 目录。

#### 第五步：登录并配置

1. 访问 `http://你的域名/`
2. 默认账号：`en55` / `888888`
3. **立即修改密码**（进入用户设置）
4. 在 Key 管理中生成新设备 Key
5. 将生成的 Key 填入 `config.h`

#### 第六步：编译并烧录固件

1. 用 Arduino IDE 打开 `firmware/iot_firmware/iot_firmware.ino`
2. 修改 `config.h` 中的 WiFi、服务器、Key 配置
3. 选择开发板：`NodeMCU 1.0 (ESP-12E Module)`
4. Flash Size 设置为 `4M (FS:2MB, OTA:~1019KB)` 或一致
5. 编译并烧录到 ESP8266
6. **固件导出**：Arduino IDE 菜单「草图 → 导出编译后的二进制文件」生成 .bin 文件，用于后续 OTA 更新

> **OTA 注意**：Flash Size 设置必须与实际烧录时一致，否则 OTA 会报 107 错误。需先通过串口刷入一次带 OTA 支持的固件，后续可通过 Web 端上传 .bin 文件进行远程更新。

### 从旧版本升级

#### v1.01 → v3.31

1. 备份现有数据库和文件
2. 上传 v3.31 的所有文件（覆盖）
3. 依次执行数据库迁移：
   - 浏览器访问 `http://你的域名/upgrade_db_v3.php`（创建预设/组合开关表）
   - 浏览器访问 `http://你的域名/upgrade_db_ota.php`（创建 OTA 表和 firmware 目录）
4. 两个脚本执行成功后均会自动删除
5. 通过串口重新刷入带 OTA 支持的固件

#### v2.10 → v3.31

1. 备份现有数据库和文件
2. 上传 v3.31 的所有文件（覆盖）
3. 依次执行：
   - `http://你的域名/upgrade_db_v3.php`（如已执行过可跳过）
   - `http://你的域名/upgrade_db_ota.php`
4. 通过串口重新刷入带 OTA 支持的固件

#### v3.20 / v3.22 → v3.31

1. 备份现有数据库和文件
2. 上传 v3.31 的所有文件（覆盖）
3. 浏览器访问 `http://你的域名/upgrade_db_ota.php`
4. 脚本会自动创建 `ota_firmware` 表和 `firmware/` 目录
5. 执行成功后脚本自动删除
6. 通过串口重新刷入带 OTA 支持的固件

> **跨版本安全**：两个迁移脚本均使用幂等操作，可安全重复执行，不会破坏已有数据。

---

## API 接口

### 公开接口

| 方法 | 路由 | 说明 |
|------|------|------|
| GET | `poll?device_id=xxx&key=xxx&wifi=xxx&rssi=xxx&ip=xxx` | ESP8266 轮询+上报 |
| POST | `login` | 登录 |
| POST | `logout` | 退出登录（v3.31+，使旧 token 失效） |
| GET | `version` | 获取版本号 |
| GET | `ota/firmware/{id}.bin?key=xxx` | 固件下载（Key 认证，v3.30+） |
| POST | `ota/report` | ESP8266 回报 OTA 结果（Key 认证，v3.31+） |

### 认证接口（需 JWT Token）

| 方法 | 路由 | 说明 |
|------|------|------|
| GET | `devices` | 设备列表 |
| GET | `devices/{id}/pins` | 引脚配置 |
| POST | `devices/{id}/pins` | 添加引脚 |
| DELETE | `devices/{id}/pins/{pin}` | 删除引脚 |
| POST | `devices/{id}/control` | 控制引脚 |
| GET | `devices/{id}/logs` | WiFi 日志 |
| POST | `devices/{id}/share` | 共享设备 |
| DELETE | `devices/{id}/share/{userId}` | 取消共享 |
| GET | `devices/{id}/shares` | 共享列表 |
| POST | `ota/upload` | 上传 OTA 固件（v3.30+） |
| GET | `ota/status?device_id=xxx` | 查看 OTA 更新状态（v3.30+） |
| DELETE | `ota/{id}` | 取消待更新固件（v3.30+） |
| GET | `combos?device_id=xxx` | 预设列表 |
| POST | `combos` | 创建预设 |
| POST | `combos/{id}/execute` | 执行预设 |
| DELETE | `combos/{id}` | 删除预设 |
| GET | `switch-combos?device_id=xxx` | 组合开关列表 |
| POST | `switch-combos` | 创建组合开关 |
| POST | `switch-combos/{id}/toggle` | 切换组合开关 |
| DELETE | `switch-combos/{id}` | 删除组合开关 |
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

---

## 固件配置（config.h）

```cpp
// WiFi 配置
#define WIFI_SSID     "你的WiFi名称"
#define WIFI_PASSWORD "你的WiFi密码"

// 服务器配置
#define SERVER_HOST   "example.com"        // 域名（不带 http://）
#define SERVER_PORT   80                   // HTTP 端口
#define POLL_INTERVAL 5000                 // 轮询间隔（毫秒）

// 设备认证
#define DEVICE_KEY    "a1b2c3d4e5f6a1b2"  // 16位hex，后端生成
#define DEVICE_ID     "esp001"            // 设备标识（自定义）

// 调试
#define SERIAL_BAUD   9600
```

**POLL_INTERVAL 流量参考：**

| 间隔 | 月流量（单设备） | 适用场景 |
|------|----------------|---------|
| 2000ms | ~1.0 GB | 实时性要求高 |
| 5000ms | ~410 MB | 推荐（默认） |
| 10000ms | ~205 MB | 省流量、延迟可接受 |

---

## CLI Key 管理

所有 CLI 命令需要传入密码（`secrets.php` 中 `CLI_PASSWORD` 的值）作为第一个参数：

```bash
php key_manager.php <密码> generate "备注"
php key_manager.php <密码> list
php key_manager.php <密码> delete <key>
php key_manager.php <密码> verify <key>
php key_manager.php <密码> disable <key>
php key_manager.php <密码> enable <key>
```

---

## 管理员密码重置工具

忘记管理员密码时使用 `tool/resetadmin.php`：

```bash
# 方式1：命令行（服务器本地）
php resetadmin.php

# 方式2：浏览器本地访问
http://localhost/tool/resetadmin.php

# 方式3：浏览器远程访问（需密钥）
http://你的域名/tool/resetadmin.php?key=你的RESET_KEY
```

执行成功后密码重置为 `88888888`，文件自动删除。

---

## 安全说明

本项目已包含以下安全加固：

- SQL 注入防护（PDO 预处理语句）
- 密码 bcrypt 哈希存储
- JWT 签名防篡改（`hash_equals` 时序安全比较）
- Token 与密码绑定（密码变更后旧 Token 自动失效）
- JWT 退出销毁（退出登录后旧 Token 立即失效，v3.31+）
- 设备 Key 格式校验（16位 hex）
- 登录失败速率限制（5分钟5次锁定）
- 错误信息不泄露给客户端（写入服务器日志）
- CORS 限制
- 前端 XSS 转义
- 敏感文件受 `.htaccess` 保护
- CLI 操作密码验证
- 固件目录 `.htaccess` 禁止直接访问（v3.30+）

---

## 技术架构

```
┌─────────────┐      HTTP Polling       ┌──────────────┐
│   ESP8266   │ ←─────────────────────→ │  PHP + MySQL  │
│  (Arduino)  │    每 5 秒轮询一次       │  (共享主机)    │
└─────────────┘                        └──────┬───────┘
                                              │
                                       ┌──────┴───────┐
                                       │  index.html   │
                                       │ (Web 控制面板) │
                                       └──────────────┘
```

- **通信方式**：HTTP Polling（非 MQTT、非 WebSocket），兼容所有共享主机
- **认证体系**：JWT（用户端）+ 16位hex Key（设备端）
- **OTA 更新**：Web 端上传 .bin 固件，ESP8266 轮询时自动检测并下载刷入
- **前端**：纯 HTML/CSS/JS 单页应用，无构建工具
- **后端**：单文件 API（`api.php`），PHP 5.5+ 兼容

---

## License

MIT
