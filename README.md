# IoT 控制系统

ESP8266 + PHP + MySQL HTTP Polling 架构的物联网控制平台。

支持多用户隔离、设备共享、组合开关、预设、OTA 固件更新等功能。

---

## 版本分支

每个版本是一个独立的 Git 分支，切换分支即可获取对应版本的完整代码：

| 分支 | 版本 | 关键特性 | 适用场景 |
|------|------|---------|---------|
| [`v1.01`](../../tree/v1.01) | v1.01 | 单用户、安全审计修复 | 入门体验、单设备控制 |
| [`v2.10`](../../tree/v2.10) | v2.10 | 多用户隔离、设备共享、管理员系统 | 多人协作、多设备管理 |
| [`v3.20`](../../tree/v3.20) | v3.20 | 组合开关、预设功能、日/夜主题 | 批量控制、场景联动 |
| [`v3.22`](../../tree/v3.22) | v3.22 | Token 与密码绑定安全修复 | 安全加固版 |
| [`v3.30`](../../tree/v3.30) | v3.30 | OTA 固件更新、Web 端远程刷机 | 远程固件维护 |
| [`v3.31`](../../tree/v3.31) | v3.31 | JWT 退出登录销毁、Token 版本校验 | **推荐部署版本** |

> **建议新用户直接使用 `v3.31` 分支。**

### 如何获取某个版本

```bash
# 克隆仓库后切换到对应分支
git clone https://github.com/en55awa/iot-control-system.git
cd iot-control-system
git checkout v3.31

# 或只下载某个分支
git clone -b v3.31 https://github.com/en55awa/iot-control-system.git
```

---

## 分支内目录结构

每个分支的文件结构如下：

```
├── backend/
│   ├── api.php                ← API 路由入口
│   ├── config.php             ← 数据库配置（加载 secrets.php）
│   ├── jwt.php                ← JWT 认证工具（HS256）
│   ├── key_manager.php        ← Key 生成/管理 CLI 工具
│   ├── secrets.php            ← 【需配置】JWT 密钥、CLI 密码
│   ├── index.html             ← Web 控制面板（单页应用）
│   ├── .htaccess              ← 敏感文件保护
│   ├── upgrade_db*.php        ← 数据库升级脚本（按版本不同）
│   └── tool/
│       └── resetadmin.php     ← 管理员密码重置工具（v2.10+）
├── firmware/
│   └── iot_firmware/
│       ├── config.h           ← 【需配置】ESP8266 配置
│       └── iot_firmware.ino   ← 固件源码
├── README.md                  ← 版本说明
└── VERSION.md                 ← 版本更新日志（v2.10+）
```

---

## 版本说明

| 版本 | 关键特性 | 适用场景 |
|------|---------|---------|
| v1.01 | 单用户、安全审计修复 | 入门体验、单设备控制 |
| v2.10 | 多用户隔离、设备共享、管理员系统 | 多人协作、多设备管理 |
| v3.20 | 组合开关、预设功能 | 批量控制、场景联动 |
| v3.22 | Token 与密码绑定安全修复 | 安全加固版 |
| v3.30 | OTA 固件更新、Web 端远程刷机 | 远程固件维护 |
| v3.31 | JWT 退出登录销毁、Token 版本校验 | **推荐部署版本** |

> **建议新用户直接使用 v3.31。** 如需从旧版升级，请阅读下方「版本升级路径」章节。

---

## 环境要求

- **Web 服务器**：Apache（需支持 `.htaccess`）或 Nginx（需手动转换规则）
- **PHP**：≥ 5.5（需 PDO MySQL 扩展、bcrypt 支持）
- **MySQL**：≥ 5.5（建议 5.7+）
- **ESP8266 开发环境**：Arduino IDE + ESP8266 Arduino Core
- **共享主机**兼容（无需 MQTT、无需 WebSocket）

---

## 需要配置的占位符清单

本项目已将所有敏感信息替换为占位符。**部署前必须修改以下文件：**

### 1. `backend/secrets.php`（所有版本）

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

### 2. `backend/config.php`（所有版本）

```php
// 当前占位符：
define('DB_NAME', 'YOUR_DB_NAME');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');
```

**修改为你的 MySQL 数据库信息：**
```php
define('DB_NAME', '你的数据库名');
define('DB_USER', '你的数据库用户名');
define('DB_PASS', '你的数据库密码');
```

### 3. `firmware/iot_firmware/config.h`（所有版本）

```cpp
// 当前占位符：
#define WIFI_SSID     "YOUR_WIFI_SSID"
#define WIFI_PASSWORD "YOUR_WIFI_PASSWORD"
#define SERVER_HOST   "YOUR_SERVER_HOST"
#define DEVICE_KEY    "YOUR_DEVICE_KEY_HERE"
```

**修改为：**
```cpp
#define WIFI_SSID     "你的WiFi名称"
#define WIFI_PASSWORD "你的WiFi密码"
#define SERVER_HOST   "example.com"           // 你的域名（不带 http://）
#define DEVICE_KEY    "a1b2c3d4e5f6a1b2"      // 后端生成的16位hex
```

### 4. `backend/api.php` CORS 配置（所有版本）

```php
// 当前占位符：
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'YOUR_DOMAIN_HERE';
```

**修改为你的域名：**
```php
$allowedOrigin = getenv('CORS_ORIGIN') ?: 'http://你的域名';
```

> 如果前端和后端部署在同一域名下，也可以改为 `'*'`（仅测试环境推荐）。
> 生产环境建议通过服务器环境变量设置 `CORS_ORIGIN`。

### 5. `backend/tool/resetadmin.php`（v2.10+）

```php
// 当前默认值：
define('RESET_KEY', 'passwd');
```

**修改为一个随机字符串：**
```php
define('RESET_KEY', '你的随机密钥');
```

> 此文件仅在重置管理员密码时使用，使用后自动删除。

---

## 部署步骤（全新安装）

以 **v3.31** 为例，其他版本步骤相同。

### 第一步：创建数据库

在 MySQL 中创建数据库（可通过 phpMyAdmin 或命令行）：

```sql
CREATE DATABASE iot_system DEFAULT CHARSET utf8mb4;
CREATE USER 'iot_user'@'localhost' IDENTIFIED BY '你的密码';
GRANT ALL PRIVILEGES ON iot_system.* TO 'iot_user'@'localhost';
FLUSH PRIVILEGES;
```

### 第二步：创建数据表

项目未包含 `init_db.php`，需手动执行以下 SQL：

```sql
USE iot_system;

-- 用户表
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 设备Key表
CREATE TABLE IF NOT EXISTS device_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(16) NOT NULL UNIQUE,
    device_id VARCHAR(32) DEFAULT '',
    user_id INT NOT NULL DEFAULT 1,
    remark VARCHAR(100) DEFAULT '',
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    last_seen DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 引脚配置表
CREATE TABLE IF NOT EXISTS device_pins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(32) NOT NULL,
    pin INT NOT NULL,
    name VARCHAR(50) DEFAULT '',
    sort_order INT DEFAULT 0,
    UNIQUE KEY uk_device_pin (device_id, pin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 设备日志表
CREATE TABLE IF NOT EXISTS device_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(32) NOT NULL,
    wifi_rssi INT DEFAULT NULL,
    wifi_bssid VARCHAR(20) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_device_time (device_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 登录尝试表
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    username VARCHAR(50) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 设备共享表（v2.0+）
CREATE TABLE IF NOT EXISTS device_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(32) NOT NULL,
    owner_id INT NOT NULL,
    shared_to_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_share (device_id, shared_to_id),
    KEY idx_device (device_id),
    KEY idx_shared (shared_to_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 预设表（v3.0+）
CREATE TABLE IF NOT EXISTS device_combos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(32) NOT NULL,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_device (device_id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 预设项表（v3.0+）
CREATE TABLE IF NOT EXISTS device_combo_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    combo_id INT NOT NULL,
    pin INT NOT NULL,
    state TINYINT NOT NULL DEFAULT 0,
    KEY idx_combo (combo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 组合开关表（v3.2+）
CREATE TABLE IF NOT EXISTS device_switch_combos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(32) NOT NULL,
    user_id INT NOT NULL,
    name VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_device (device_id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 组合开关联动引脚表（v3.2+）
CREATE TABLE IF NOT EXISTS device_switch_combo_pins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    switch_combo_id INT NOT NULL,
    pin INT NOT NULL,
    KEY idx_switch (switch_combo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 插入默认管理员（用户名: en55，密码: 888888）
INSERT INTO users (username, password_hash, role, status)
VALUES ('en55', '$2y$10$N9qo8uLOickgx2ZMRZoMy.Mrq9qOJ4JqKqXmQJqKqXmQJqKqXmQJq', 'admin', 'active');
```

> **注意：** 上面的密码哈希是示例占位符。请通过 PHP 生成正确的 bcrypt 哈希：
> ```php
> <?php echo password_hash('888888', PASSWORD_DEFAULT); ?>
> ```
> 将输出的哈希值替换到上面的 SQL 中。或者部署后使用 `tool/resetadmin.php` 重置密码。

### 第三步：修改配置文件

按照上方「需要配置的占位符清单」修改以下文件：
1. `backend/secrets.php` — JWT 密钥、CLI 密码
2. `backend/config.php` — 数据库连接信息
3. `backend/api.php` — CORS 域名（第44行附近）
4. `backend/tool/resetadmin.php` — 重置密钥（v2.10+）

### 第四步：上传文件

将 `backend/` 目录下所有文件上传到服务器 Web 目录：

```
api.php
config.php
jwt.php
key_manager.php
secrets.php
index.html
.htaccess
upgrade_db_v3.php（如有）
upgrade_db_ota.php（v3.30+）
tool/resetadmin.php（如有）
```

### 第五步：执行数据库迁移（v3.30+）

浏览器访问 `http://你的域名/upgrade_db_ota.php`，脚本会自动：
- 创建 `ota_firmware` 表（OTA 固件管理）
- 创建 `firmware/` 目录并设置 `.htaccess` 保护
- 添加 `users.token_version` 字段（JWT 退出销毁，v3.31+）

执行成功后脚本自动删除，如未删除请手动删除。

### 第六步：登录并配置

1. 访问 `http://你的域名/`
2. 默认账号：`en55` / `888888`
3. **立即修改密码**（进入用户设置）
4. 在 Key 管理中生成新设备 Key
5. 将生成的 Key 填入 `config.h`

### 第七步：编译并烧录固件

1. 用 Arduino IDE 打开 `firmware/iot_firmware/iot_firmware.ino`
2. 修改 `config.h` 中的 WiFi、服务器、Key 配置
3. 选择开发板：`NodeMCU 1.0 (ESP-12E Module)`
4. Flash Size 设置为 `4M (FS:2MB, OTA:~1019KB)` 或一致
5. 编译并烧录到 ESP8266

---

## 版本升级路径

### v1.01 → v2.10

1. 备份现有数据库和文件
2. 上传 v2.10 的所有文件（覆盖）
3. 浏览器访问 `http://你的域名/upgrade_db.php`
4. 脚本会自动添加 `role`、`status`、`user_id` 字段，创建 `device_shares` 表
5. 执行成功后脚本自动删除，如未删除请手动删除

### v2.10 → v3.20 / v3.22

1. 备份现有数据库和文件
2. 上传 v3.20/v3.22 的所有文件（覆盖）
3. 浏览器访问 `http://你的域名/upgrade_db_v3.php`
4. 脚本会自动创建预设表和组合开关表
5. 执行成功后脚本自动删除，如未删除请手动删除

### v3.20 → v3.22

直接覆盖文件即可，无需数据库迁移（仅 `api.php` 变更）。

### v3.22 → v3.30

1. 备份现有数据库和文件
2. 上传 v3.30 的所有文件（覆盖）
3. 浏览器访问 `http://你的域名/upgrade_db_ota.php`
4. 脚本会自动创建 `ota_firmware` 表和 `firmware/` 目录
5. 执行成功后脚本自动删除，如未删除请手动删除
6. 需通过串口重新刷入带 OTA 支持的固件（`iot_firmware.ino` 已更新）
7. **固件导出注意**：Arduino IDE 中 "工具 → Flash Size" 设置必须与实际烧录时一致

### v3.30 → v3.31

1. 备份现有数据库和文件
2. 上传 v3.31 的所有文件（覆盖）
3. 重新访问 `http://你的域名/upgrade_db_ota.php`（脚本已更新，会添加 `users.token_version` 字段）
4. 执行成功后脚本自动删除
5. 已登录用户的旧 token 会因 `tv` 字段缺失而失效，需重新登录

> **跨版本升级**：从 v3.22 直接升级到 v3.31 也是安全的。`upgrade_db_ota.php` 使用幂等操作（`CREATE TABLE IF NOT EXISTS`、`try/catch` 处理 `ALTER TABLE`），会一次性完成所有数据库迁移。

---

## 固件配置详解（config.h）

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

## API 接口

### 公开接口

| 方法 | 路由 | 说明 |
|------|------|------|
| GET | `poll?device_id=xxx&key=xxx&wifi=xxx&rssi=xxx&ip=xxx` | ESP8266 轮询+上报 |
| POST | `login` | 登录 |
| POST | `logout` | 退出登录，使旧 Token 失效（v3.31+） |
| GET | `version` | 获取版本号（v2.10+） |
| GET | `ota/firmware?device_id=xxx&key=xxx` | 固件下载（Key 认证，v3.30+） |

### 认证接口（需 JWT Token）

| 方法 | 路由 | 说明 |
|------|------|------|
| GET | `devices` | 设备列表 |
| GET | `devices/{id}/pins` | 引脚配置 |
| POST | `devices/{id}/pins` | 添加引脚 |
| DELETE | `devices/{id}/pins/{pin}` | 删除引脚 |
| POST | `devices/{id}/control` | 控制引脚 |
| GET | `devices/{id}/logs` | WiFi 日志 |
| POST | `devices/{id}/share` | 共享设备（v2.0+） |
| DELETE | `devices/{id}/share/{userId}` | 取消共享（v2.0+） |
| GET | `devices/{id}/shares` | 共享列表（v2.0+） |
| GET | `combos?device_id=xxx` | 预设列表（v3.0+） |
| POST | `combos` | 创建预设（v3.0+） |
| POST | `combos/{id}/execute` | 执行预设（v3.0+） |
| DELETE | `combos/{id}` | 删除预设（v3.0+） |
| GET | `switch-combos?device_id=xxx` | 组合开关列表（v3.2+） |
| POST | `switch-combos` | 创建组合开关（v3.2+） |
| POST | `switch-combos/{id}/toggle` | 切换组合开关（v3.2+） |
| DELETE | `switch-combos/{id}` | 删除组合开关（v3.2+） |
| POST | `devices/{id}/ota/upload` | 上传 OTA 固件（v3.30+） |
| GET | `devices/{id}/ota/status` | 查看 OTA 更新状态（v3.30+） |
| DELETE | `devices/{id}/ota/cancel` | 取消待更新固件（v3.30+） |
| GET | `keys` | Key 列表 |
| POST | `keys` | 生成新 Key |
| DELETE | `keys/{key}` | 删除 Key |
| PUT | `keys/{key}/disable` | 禁用 Key |
| PUT | `keys/{key}/enable` | 启用 Key |
| POST | `user/password` | 修改密码 |
| POST | `user/username` | 修改用户名 |
| GET | `admin/users` | 用户列表（管理员，v2.0+） |
| POST | `admin/users` | 创建用户（管理员，v2.0+） |
| DELETE | `admin/users/{id}` | 删除用户（管理员，v2.0+） |
| POST | `admin/users/{id}/password` | 重置用户密码（管理员，v2.0+） |
| PUT | `admin/users/{id}/disable` | 禁用用户（管理员，v2.0+） |
| PUT | `admin/users/{id}/enable` | 启用用户（管理员，v2.0+） |

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

## 管理员密码重置工具（v2.10+）

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
- Token 与密码绑定（v3.22+，密码变更后旧 Token 自动失效）
- JWT 退出登录销毁（v3.31+，退出后旧 Token 立即失效）
- 设备 Key 格式校验（16位 hex）
- 登录失败速率限制（5分钟5次锁定）
- 错误信息不泄露给客户端（写入服务器日志）
- CORS 限制
- 前端 XSS 转义
- 敏感文件受 `.htaccess` 保护
- CLI 操作密码验证

**部署检查清单：**

- [ ] `secrets.php` 中的 JWT_SECRET 和 CLI_PASSWORD 已修改
- [ ] `config.php` 中的数据库信息已填写
- [ ] `api.php` 中的 CORS 域名已修改
- [ ] `config.h` 中的 WiFi 和服务器信息已填写
- [ ] `tool/resetadmin.php` 中的 RESET_KEY 已修改（v2.10+）
- [ ] 默认管理员密码已修改
- [ ] `.htaccess` 在服务器上生效
- [ ] 升级脚本（`upgrade_db*.php`）执行后已删除

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
- **前端**：纯 HTML/CSS/JS 单页应用，无构建工具
- **后端**：单文件 API（`api.php`），PHP 5.5+ 兼容

---

## License

MIT
