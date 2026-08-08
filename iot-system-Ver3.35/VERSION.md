EEEEEEEE  N    N  5555555  5555555
E         N N  N  5        5
EEEEEEEE  N  N N  5555555  5555555
E         N   NN        5        5
EEEEEEEE  N    N  5555555  5555555

# 物联网控制系统 - 版本更新日志

## 版本号策略

# 如遇到版本跳动较大，则为当时版本存在严重BUG，无法正常使用或影响严重，则会不会发布，后续版本将保留之前版本的所有更新

采用 `主版本.次版本` 双位小数格式，遵循以下递增规则：

| 更新类型 | 增量 | 示例 | 说明 |
|---------|------|------|------|
| 大更新 | +1.00 | 1.20 → 2.20 | 新增板块、重大功能重构、架构升级 |
| 中更新 | +0.10 | 2.20 → 2.30 | UI/UX 优化、新增显示元素、交互改进 |
| 小更新 | +0.01 | 2.30 → 2.31 | Bug 修复、性能优化、文案调整 |

**规则说明：**
- 大更新直接进位主版本号，次版本号保留（如 1.99 + 1.00 = 2.99）
- 中更新和小更新在次版本号上累加，允许进位（如 2.99 + 0.01 = 3.00）
- 版本号统一显示为 `vX.XX` 格式，前端与后端必须保持一致

---

## 更新历史

### v3.35
- **类型**：小更新 (+0.01)
- **内容**：UI 优化 - OTA 记录删除按钮 + 管理设备按钮配色
  - **OTA 升级记录删除按钮**：
    - 原来只有 pending/failed 状态的记录显示"取消"按钮，done 状态的记录无法删除
    - 改为所有非 updating 状态的记录都显示"删除"按钮（updating 状态设备正在更新中，不允许删除）
    - 后端 `DELETE /ota/{id}` 接口修改：从"只能删除 pending/failed"改为"允许删除 pending/failed/done，禁止 deleting updating"
    - pending/failed 删除时重置 `ota_status=0`，done 删除时不影响（已完成状态无需重置）
  - **管理设备按钮配色**：
    - 原来：灰色背景 (`var(--bg3)`) + 灰色文字 (`var(--muted)`)，不够醒目
    - 改为：主题色背景 (`var(--accent)`) + 白色文字 + 悬停上浮效果
    - 新增 `.btn-manage` CSS class 替换内联样式
- **涉及文件**：`api.php`、`index.html`
- **部署说明**：上传 `api.php` 和 `index.html` 即可，无需数据库迁移或固件更新。建议同时上传 `config.php`（含 v3.34 的 PHP 进程超时修复）

### v3.34
- **类型**：小更新 (+0.01)
- **内容**：BUG 修复 - PHP 进程卡死导致设备间歇性掉线
  - **问题背景**：ESP8266 每 5 秒轮询一次，偶尔某次请求卡住（数据库慢/网络阻塞），由于没有任何超时保护，PHP 进程会永远挂在后台，占满共享主机进程池后导致后续请求全部排队 → 设备掉线
  - **根因分析**：`config.php` 的 PDO 连接没有任何超时参数，PHP 脚本没有执行时间限制，`ignore_user_abort` 未设置导致客户端断开后脚本仍继续运行
  - **修复内容**（`config.php`）：
    - 新增 `set_time_limit(10)`：脚本最多运行 10 秒（轮询通常 <1 秒）
    - 新增 `ignore_user_abort(false)`：客户端断开时终止脚本
    - 新增 `ini_set('default_socket_timeout', 5)`：socket I/O 超时 5 秒
    - PDO DSN 新增 `connect_timeout=3`：MySQL 连接超时 3 秒
    - 连接后执行 `SET SESSION wait_timeout = 10`：空闲连接 10 秒自动断开
  - **修复内容**（`api.php`）：
    - 移除每次请求都执行的 `CREATE TABLE IF NOT EXISTS login_attempts`（5 秒一次轮询白白多一次数据库查询）
    - OTA 固件下载 `readfile()` 前放宽 `set_time_limit(60)` + 清空输出缓冲
    - OTA 固件上传前放宽 `set_time_limit(30)`
- **涉及文件**：`config.php`、`api.php`
- **部署说明**：上传更新后的 `config.php` 和 `api.php` 即可，无需数据库迁移或固件更新

### v3.33
- **类型**：小更新 (+0.01)
- **内容**：BUG 修复 - OTA 状态追踪改为服务端自动检测
  - **问题背景**：v3.32 的 OTA 回报机制依赖 ESP8266 在更新完成后通过 poll 参数回报结果，但 ESP8266 写完 Flash 后 WiFi/HTTP 状态不稳定，经常无法发送 poll，导致更新成功却显示失败
  - **解决方案**：OTA 状态完全由服务端通过轮询间隙自动追踪，固件无需任何回报
  - **核心原理**：
    - OTA 期间 `ESPhttpUpdate.update()` 是阻塞调用，设备不会发起轮询
    - 如果在"更新中"(2)状态下收到该设备的轮询请求 → 说明设备已完成 OTA 并重启恢复在线
    - 直接重置为正常状态(0)，标记固件记录为已完成
    - 5 分钟内未恢复轮询 → OTA 失败（状态 4）
  - **数据库变更**：
    - `device_keys` 表新增 `ota_status` (TINYINT: 0=正常 1=待更新 2=更新中 3=已更新 4=失败)
    - `device_keys` 表新增 `ota_sent_at` (TIMESTAMP: OTA 下发时间，用于超时判断)
    - 迁移脚本：`upgrade_db_ota_status.php`（访问后自动执行并删除）
  - **服务端变更**（`api.php`）：
    - `handlePoll`：在更新 `last_seen` 前读取 `ota_status`；若状态为 2（更新中）且设备发起轮询 → 说明 OTA 完成设备已重启 → 重置为 0，标记固件为 done
    - `handleOTA`（上传）：设置 `device_keys.ota_status = 1`
    - `handleOTA`（取消）：重置 `device_keys.ota_status = 0`
    - `ota/status`：返回 `{ota_status: N, records: [...]}`，查询时也做超时兜底检查
    - `handleOTAReport`：保留旧版兼容，同步更新 `device_keys.ota_status`
  - **固件变更**（`iot_firmware.ino`）：
    - **删除**：EEPROM 持久化代码（`saveOtaResultToEEPROM`/`loadOtaResultFromEEPROM`）
    - **删除**：poll 中的 OTA 回报参数（`ota_id`/`ota_success`/`ota_error`）
    - **删除**：`pendingOtaId`/`pendingOtaSuccess`/`pendingOtaError`/`needRestartAfterReport` 变量
    - **简化**：OTA 成功后直接 `ESP.restart()`，无需先 poll 回报
    - **简化**：OTA 失败后继续正常轮询，服务端 5 分钟后自动判定超时
    - 代码从 327 行精简至 221 行
  - **前端变更**（`index.html`）：
    - `loadOTAStatus` 适配新响应格式 `{ota_status, records}`
    - 新增 OTA 状态横幅：在记录列表上方显示当前设备 OTA 状态（待更新/更新中/已更新/更新失败）
  - **向后兼容**：
    - 旧版固件（v3.32）的 poll 回报参数仍被处理（`ota_id`/`ota_success`/`ota_error`）
    - `POST ota/report` 接口保留
    - 旧固件升级到新固件后自动获得新机制
- **涉及文件**：`api.php`、`index.html`、`iot_firmware.ino`、`upgrade_db_ota_status.php`（新增）
- **部署说明**：
  1. 上传 `upgrade_db_ota_status.php` 到服务器并访问一次执行数据库迁移（自动删除）
  2. 上传更新后的 `api.php` 和 `index.html`
  3. 重新编译并烧录 `iot_firmware.ino` 到 ESP8266（固件大幅简化，必须更新）

### v3.32
- **类型**：小更新 (+0.01)
- **内容**：BUG 修复 - OTA 状态显示 + 回报机制优化
  - **前端 BUG 修复**：设备详情页 OTA 状态只显示"更新中"不刷新
    - 原因：`loadOTAStatus` 仅在打开设备详情时调用一次，5 秒定时器只刷新引脚状态
    - 修复：将 OTA 状态刷新加入 `refreshPinStates` 定时任务，每 5 秒自动刷新
    - 现在"待更新 → 更新中 → 已完成/失败"状态会实时变化
  - **OTA 回报机制优化**：ESP8266 不再单独发 POST 回报，改为在 poll 请求中携带
    - 原方案：更新完成后单独 POST `ota/report` 回报结果（两次 HTTP 请求）
    - 新方案：更新完成后存入变量 + EEPROM，下次 poll 时通过 URL 参数 `ota_id`、`ota_success`、`ota_error` 一并回报（一次请求搞定）
    - 更新成功时：先 poll 回报结果，再重启（确保服务端收到状态）
    - 更新失败时：下次 poll 自动携带失败信息
    - **EEPROM 持久化**：OTA 成功结果同时写入 EEPROM，防止 ESP8266 在写完 flash 后因 WiFi/HTTP 状态不稳定无法发 poll 而看门狗重启
    - 重启后 `setup()` 从 EEPROM 读取待回报结果，第一次 poll 自动携带，然后清除
    - `POST ota/report` 接口保留作为旧版固件兼容，不再推荐使用
    - 服务端 `handlePoll` 新增 OTA 回报参数解析与状态更新逻辑
- **涉及文件**：`api.php`、`index.html`、`iot_firmware.ino`
- **部署说明**：
  1. 上传更新后的 `api.php` 和 `index.html`
  2. 重新编译并烧录 `iot_firmware.ino` 到 ESP8266
  3. 无需数据库迁移

### v3.31
- **类型**：小更新 (+0.01)
- **内容**：安全修复 - JWT 退出登录销毁
  - 新增 `POST logout` 接口，退出登录时递增 `token_version`
  - JWT payload 新增 `tv`（token version）字段
  - 每次请求校验 token 中的 `tv` 与数据库 `token_version` 是否一致
  - 退出登录后旧 token 立即失效，无法继续使用
  - 前端 `logout()` 改为先调用服务端退出接口再清除本地状态
  - 数据库 `users` 表新增 `token_version` 字段（INT, 默认0）
  - **BUG 修复**：登录 SQL 查询遗漏 `token_version` 字段，导致退出后无法重新登录（token 中 tv 始终为 0，与递增后的数据库值不匹配）
  - **OTA 状态管理修复**：修复更新失败后状态卡死、无法重试的问题
    - `handlePoll` 新增超时清理：`updating` 状态超过 5 分钟自动转为 `failed`，避免状态卡死
    - 新增 `POST ota/report` 公开接口（Key 验证），接收 ESP8266 回报的更新结果（成功→done / 失败→failed）
    - ESP8266 更新成功/失败均向服务器回报状态，失败时附带错误信息
    - `ota_firmware` 表新增 `error` 字段记录失败原因
    - OTA 状态查询接口返回 `error` 字段供前端展示
  - **OTA 下载鉴权加固**：修复固件下载接口未校验设备归属的漏洞
    - 下载固件时校验请求方 Key 必须属于该固件的目标设备（防止其他设备下载别人的固件）
    - 限制只有 `pending`/`updating` 状态的固件才能下载，防止重复下载已完成/失败的固件
    - OTA 回报接口同样校验 Key 必须属于该设备，防止伪造回报
- **涉及文件**：`api.php`、`index.html`、`upgrade_db_ota.php`、`iot_firmware.ino`
- **部署说明**：
  1. 重新访问 `upgrade_db_ota.php` 执行数据库迁移（添加 `token_version` 和 `ota_firmware.error` 字段）
  2. 上传更新后的 `api.php` 和 `index.html`
  3. 重新编译并烧录 `iot_firmware.ino` 到 ESP8266（支持状态回报）
  4. 已登录用户的旧 token 会因 `tv` 字段缺失而失效，需重新登录

### v3.30
- **类型**：大更新 (+1.00)
- **内容**：新增 OTA 固件更新功能
  - Web 端上传 .bin 固件文件，支持版本号标注和上传进度显示
  - ESP8266 轮询时自动检测待更新固件，下载并刷入
  - 固件文件存储在服务器 `firmware/` 目录（.htaccess 禁止直接访问）
  - 数据库新增 `ota_firmware` 表记录更新状态（pending/updating/done/failed）
  - 固件下载接口通过 Key 验证，无需 JWT
  - 设备所有者可上传/取消固件，共享用户仅可查看更新记录
  - ESP8266 固件新增 `ESP8266httpUpdate` 库支持，含串口进度输出
  - OTA 更新期间暂停普通轮询，更新完成后自动重启
- **涉及文件**：`api.php`、`index.html`、`iot_firmware.ino`、`upgrade_db_ota.php`（新增）
- **部署说明**：
  1. 上传 `upgrade_db_ota.php` 到服务器并访问一次执行数据库迁移（自动删除）
  2. 上传更新后的 `api.php` 和 `index.html`
  3. 确保服务器 `firmware/` 目录可写
  4. 使用新的 `.ino` 固件编译并刷入 ESP8266（需先通过串口刷入一次带 OTA 支持的固件）
  5. **固件导出注意**：Arduino IDE 中 "工具 → Flash Size" 的设置必须与实际烧录时一致，否则 OTA 会报 107 错误
  6. **固件制作**：Arduino IDE 编译后，菜单 "草图 → 导出编译后的二进制文件" 生成 .bin 文件

### v3.22
- **类型**：小更新 (+0.01)
- **内容**：安全修复 - Token 与密码绑定
  - JWT payload 新增 `pf`（password factor）字段，取密码哈希前8位
  - 每次验证 token 时比对数据库中的密码哈希，不一致则拒绝并返回 401
  - 密码被修改后，该用户所有旧 token 立即失效，需重新登录
  - 涵盖：用户自行改密码、管理员改他人密码两种场景
- **涉及文件**：`api.php`

### v3.21
- **类型**：小更新 (+0.01)
- **内容**：UI 优化 + BUG 修复
  - 修复组合开关状态不会随普通引脚同步刷新的 BUG
  - 组合开关移至普通开关区域混合排列，美观统一
  - 组合开关使用带渐变边框的卡片样式区分
  - 新增日/夜主题切换功能（SVG 矢量动画背景，无图片）
    - 夜晚：星空闪烁 + 弯月呼吸光晕
    - 白天：太阳旋转光芒 + 云朵循环飘动
    - 主题偏好自动保存至 localStorage
    - 所有 UI 元素 0.3s 平滑过渡动画
  - 主题切换按钮位于用户名左侧
  - 响应式适配电脑端和手机端
- **涉及文件**：`index.html`、`api.php`

### v3.20
- **类型**：中更新 (+0.10)
- **内容**：新增组合开关功能 + 预设更名
  - 新增"组合开关"：将多个引脚的开关合并为一个虚拟开关，操作这一个开关即可同步控制所有关联引脚（开则全开，关则全关）
  - 将原"组合控制"更名为"预设"，与"组合开关"功能区分
  - 数据库新增 `device_switch_combos` 和 `device_switch_combo_pins` 表
  - 权限：设备所有者可创建/删除组合开关，共享用户可切换
- **涉及文件**：`api.php`、`index.html`、`upgrade_db_v3.php`

### v3.10
- **类型**：大更新 (+1.00)
- **内容**：新增预设功能（原"组合控制"）
  - 可将多个引脚组合为一个预设，一键执行各引脚的预定义状态（开/关）
  - 每个预设可自定义每个引脚的目标状态
  - 支持创建、执行、删除预设
  - 权限：设备所有者可创建/删除预设，共享用户可执行预设
- **涉及文件**：`api.php`、`index.html`、`upgrade_db_v3.php`（新增）

### v2.10
- **类型**：中更新 (+0.10)
- **内容**：1.新增前后端版本号显示，右上角实时展示前端与后端版本
            2.增添一个tool工具夹，增加一个resetadmin.php工具，用于重置管理员密码
- **涉及文件**：`api.php`、`index.html`

### v2.00
- **类型**：大更新 (+1.00)
- **内容**：多用户隔离系统 v2.0
  - 用户隔离：每个用户拥有独立的设备 KEY 和设备列表
  - 设备共享：支持将设备共享给其他用户（输入用户名即可）
  - 管理员账户：可查看所有设备、创建/删除/禁用用户、重置密码
  - Token 不过期：登录后长期有效，除非手动退出
- **涉及文件**：`api.php`（完全重写）、`index.html`（完全重写）、`upgrade_db.php`（新增）

### v1.01
- **类型**：小更新 (+0.01)
- **内容**：安全审计修复
  - JWT 密钥从环境变量读取，不再硬编码
  - CORS 限制为 en55.fun 域名
  - 错误信息脱敏，不暴露数据库结构
  - 登录失败 5 次后锁定 5 分钟
  - 敏感文件 `.htaccess` 保护
  - 前端动态内容 XSS 转义
- **备份**：`iot-system-Ver1.01.zip`

### v1.00
- **类型**：初始版本
- **内容**：物联网控制系统基础版
  - ESP8266 HTTP 轮询架构
  - JWT 用户认证 + 8 字节设备 KEY 认证
  - 动态 GPIO 引脚配置
  - WiFi 信号数据上报与记录
  - 在线/离线状态指示
