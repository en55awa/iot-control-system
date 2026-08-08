<?php
/**
 * 物联网控制系统 - API 路由入口 v4.35（多用户隔离 + 预设 + 组合开关 + OTA服务端状态追踪 + Token销毁 + 开关隐藏 + 自定义指令）
 *
 * 接口列表：
 *   GET  poll&device_id=xxx&key=xxx&wifi=xxx&rssi=xxx&ip=xxx                  ESP8266 轮询+上报（公开，OTA状态由服务端自动追踪）
 *   GET  ota/firmware/{id}.bin                                   固件文件下载（公开，带 key 验证）
 *   POST ota/report                                              （旧版兼容）ESP8266 回报 OTA 结果，新版固件已改为 poll 中携带
 *   POST login                                                  登录
 *   POST logout                                                 退出登录（使旧 token 失效）
 *   POST register                                               注册用户（仅管理员）
 *   GET  devices                                                设备列表（权限隔离）
 *   GET  devices/{id}/pins                                      设备引脚配置
 *   POST devices/{id}/pins                                      添加引脚配置
 *   DELETE devices/{id}/pins/{pin}                              删除引脚配置
 *   POST devices/{id}/control                                   控制引脚
 *   GET  devices/{id}/logs                                      设备日志
 *   POST devices/{id}/share                                    共享设备
 *   DELETE devices/{id}/share/{userId}                         取消共享
 *   GET  devices/{id}/shares                                   查看共享列表
 *   GET  combos?device_id=xxx                                   预设列表
 *   POST combos                                                 创建预设（多引脚各设目标状态）
 *   POST combos/{id}/execute                                    执行预设
 *   DELETE combos/{id}                                          删除预设
 *   GET  switch-combos?device_id=xxx                           组合开关列表
 *   POST switch-combos                                          创建组合开关（多引脚合并为一个开关）
 *   POST switch-combos/{id}/toggle                              切换组合开关（同步开/关所有引脚）
 *   DELETE switch-combos/{id}                                   删除组合开关
 *   POST ota/upload                                             上传固件（FormData）
 *   GET  ota/status?device_id=xxx                               查询设备OTA状态
 *   DELETE ota/{id}                                              删除固件记录
 *   GET  keys                                                   Key 列表
 *   POST keys                                                   生成新 Key
 *   DELETE keys/{key}                                           删除 Key
 *   PUT  keys/{key}/disable                                     禁用 Key
 *   PUT  keys/{key}/enable                                     启用 Key
 *   PUT  devices/{id}/pins/{pin}/hide                          隐藏引脚
 *   PUT  devices/{id}/pins/{pin}/show                          显示引脚
 *   GET  schedules?device_id=xxx                               指令列表
 *   POST schedules                                             创建指令（定时开关/延时关闭/定时重启）
 *   DELETE schedules/{id}                                      删除指令
 *   PUT  schedules/{id}/toggle                                  暂停/恢复指令
 *   POST user/password                                          修改密码
 *   POST user/username                                          修改用户名
 *   GET  admin/users                                            用户列表（仅管理员）
 *   POST admin/users                                            创建用户（仅管理员）
 *   DELETE admin/users/{id}                                     删除用户（仅管理员）
 *   POST admin/users/{id}/password                              修改用户密码（仅管理员）
 *   PUT  admin/users/{id}/disable                               禁用用户（仅管理员）
 *   PUT  admin/users/{id}/enable                                启用用户（仅管理员）
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jwt.php';

$allowedOrigin = getenv('CORS_ORIGIN') ?: 'YOUR_DOMAIN_HERE';
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['route'] ?? '';
$parts = array_values(array_filter(explode('/', $path)));
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $db = getDB();

    // ===== 公开接口 =====
    if ($method === 'GET' && ($parts[0] ?? '') === 'version') {
        jsonResponse(200, 'ok', ['version' => 'v4.35']);
        exit;
    }
    if ($method === 'GET' && ($parts[0] ?? '') === 'poll') {
        handlePoll($db);
        exit;
    }
    // 公开：ESP8266 下载固件文件（通过 key 验证）
    if ($method === 'GET' && ($parts[0] ?? '') === 'ota' && ($parts[1] ?? '') === 'firmware') {
        handleOTAFirmwareDownload($db);
        exit;
    }
    if ($method === 'POST' && ($parts[0] ?? '') === 'login') {
        handleLogin($db, $body);
        exit;
    }
    // 公开：ESP8266 报告 OTA 更新结果（旧版兼容，v3.33 起由服务端自动追踪，无需设备回报）
    if ($method === 'POST' && ($parts[0] ?? '') === 'ota' && ($parts[1] ?? '') === 'report') {
        handleOTAReport($db);
        exit;
    }

    // ===== JWT 认证 =====
    $user = JWT::fromHeader();
    if ($user === null) {
        jsonError(401, '未授权，请登录');
    }
    // 检查用户状态 + 密码因子 + token 版本
    $stmt = $db->prepare('SELECT status, role, password_hash, token_version FROM users WHERE id = ?');
    $stmt->execute([$user['uid']]);
    $currentUser = $stmt->fetch();
    if (!$currentUser || $currentUser['status'] !== 'active') {
        jsonError(403, '账号已被禁用');
    }
    // 校验密码因子：token 中的 pf 必须与当前密码哈希前8位一致
    $expectedPf = substr($currentUser['password_hash'], 0, 8);
    if (($user['pf'] ?? '') !== $expectedPf) {
        jsonError(401, '密码已变更，请重新登录');
    }
    // 校验 token 版本：退出登录后旧 token 立即失效
    $expectedTv = (int)$currentUser['token_version'];
    if (($user['tv'] ?? 0) !== $expectedTv) {
        jsonError(401, '登录已失效，请重新登录');
    }
    $user['role'] = $currentUser['role'];
    $user['status'] = $currentUser['status'];

    switch ($parts[0] ?? '') {
        case 'devices': handleDevices($db, $method, $parts, $body, $user); break;
        case 'combos':  handleCombos($db, $method, $parts, $body, $user); break;
        case 'switch-combos': handleSwitchCombos($db, $method, $parts, $body, $user); break;
        case 'ota':     handleOTA($db, $method, $parts, $body, $user); break;
        case 'schedules': handleSchedules($db, $method, $parts, $body, $user); break;
        case 'keys':    handleKeys($db, $method, $parts, $body, $user); break;
        case 'user':    handleUser($db, $method, $parts, $body, $user); break;
        case 'admin':   handleAdmin($db, $method, $parts, $body, $user); break;
        case 'register': handleRegister($db, $body, $user); break;
        case 'logout':  handleLogout($db, $user); break;
        default:        jsonError(404, '接口不存在');
    }

} catch (PDOException $e) {
    error_log('DB Error [' . date('Y-m-d H:i:s') . ']: ' . $e->getMessage());
    jsonError(500, '服务器内部错误');
} catch (Throwable $e) {
    error_log('Server Error [' . date('Y-m-d H:i:s') . ']: ' . $e->getMessage());
    jsonError(500, '服务器内部错误');
}

// =====================================================
//  辅助函数
// =====================================================
function jsonResponse(int $code, string $message, array $data = []): void {
    http_response_code($code >= 400 ? $code : 200);
    header('Content-Type: application/json');
    echo json_encode(['code' => $code, 'message' => $message, 'data' => $data]);
    exit;
}
function jsonError(int $code, string $message): void {
    jsonResponse($code, $message);
}

/**
 * 检查用户是否有权限操作某设备
 * @return bool
 */
function canAccessDevice(PDO $db, string $deviceId, array $user): bool {
    if ($user['role'] === 'admin') return true;
    // 检查是否是设备所有者
    $stmt = $db->prepare('SELECT id FROM device_keys WHERE device_id = ? AND user_id = ?');
    $stmt->execute([$deviceId, $user['uid']]);
    if ($stmt->fetch()) return true;
    // 检查是否被共享
    $stmt = $db->prepare('SELECT id FROM device_shares WHERE device_id = ? AND shared_to_id = ?');
    $stmt->execute([$deviceId, $user['uid']]);
    if ($stmt->fetch()) return true;
    return false;
}

/**
 * 检查用户是否是设备所有者
 * @return bool
 */
function isDeviceOwner(PDO $db, string $deviceId, array $user): bool {
    if ($user['role'] === 'admin') return true;
    $stmt = $db->prepare('SELECT id FROM device_keys WHERE device_id = ? AND user_id = ?');
    $stmt->execute([$deviceId, $user['uid']]);
    return (bool)$stmt->fetch();
}

// =====================================================
//  ESP8266 轮询 + WiFi 上报
// =====================================================
function handlePoll(PDO $db): void
{
    require_once __DIR__ . '/key_manager.php';

    $deviceId = $_GET['device_id'] ?? '';
    $key = $_GET['key'] ?? '';

    if ($deviceId === '' || $key === '') {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo "";
        exit;
    }

    if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $deviceId)) {
        http_response_code(400);
        header('Content-Type: text/plain');
        echo "";
        exit;
    }

    if (!verifyKey($key)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo "";
        exit;
    }

    // ---- 读取设备 OTA 状态（在更新 last_seen 之前读取）----
    $stmt = $db->prepare('SELECT ota_status, ota_sent_at FROM device_keys WHERE device_id = ?');
    $stmt->execute([$deviceId]);
    $devRow = $stmt->fetch();

    $otaStatus = $devRow ? (int)$devRow['ota_status'] : 0;
    $otaSentAt = $devRow ? $devRow['ota_sent_at'] : null;

    // ---- 服务端 OTA 状态自动追踪 ----
    // 原理：OTA 期间 ESPhttpUpdate.update() 是阻塞调用，设备不会轮询
    // 如果在"更新中"(2)状态下收到该设备的轮询请求，说明设备已完成 OTA 并重启恢复在线
    // 直接重置为正常状态(0)，同时标记固件记录为已完成
    if ($otaStatus === 2) {
        // 设备在更新中状态下发起了轮询 → OTA 完成，恢复正常
        $stmt = $db->prepare('UPDATE device_keys SET ota_status = 0, last_seen = NOW() WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $stmt = $db->prepare("UPDATE ota_firmware SET status = 'done', updated_at = NOW() WHERE device_id = ? AND status = 'updating'");
        $stmt->execute([$deviceId]);
        $otaStatus = 0;
    } else {
        // 正常状态（0/1/3/4），更新在线时间
        $stmt = $db->prepare('UPDATE device_keys SET last_seen = NOW() WHERE device_id = ?');
        $stmt->execute([$deviceId]);
    }

    // ---- 记录 WiFi 日志（每30秒记录一次）----
    $wifiSsid = $_GET['wifi'] ?? '';
    $wifiRssi = (int)($_GET['rssi'] ?? 0);
    $ip = $_GET['ip'] ?? '';

    if ($wifiRssi !== 0 || $wifiSsid !== '' || $ip !== '') {
        $stmt = $db->prepare('SELECT id FROM device_logs WHERE device_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND) LIMIT 1');
        $stmt->execute([$deviceId]);
        if (!$stmt->fetch()) {
            $ssidToStore = $wifiSsid !== '' ? $wifiSsid : 'unknown';
            $ipToStore = $ip !== '' ? $ip : '-';
            $stmt = $db->prepare('INSERT INTO device_logs (device_id, wifi_ssid, wifi_rssi, ip) VALUES (?, ?, ?, ?)');
            $stmt->execute([$deviceId, $ssidToStore, $wifiRssi, $ipToStore]);

            // 清理旧日志，每个设备只保留最近150条
            $stmt = $db->prepare('DELETE d1 FROM device_logs d1 LEFT JOIN (SELECT id FROM device_logs WHERE device_id = ? ORDER BY id DESC LIMIT 150) d2 ON d1.id = d2.id WHERE d2.id IS NULL AND d1.device_id = ?');
            $stmt->execute([$deviceId, $deviceId]);
        }
    }

    // ---- 自定义指令：定时开关 + 延时关闭（在引脚查询前执行，确保状态变化被包含在返回中）----
    processSchedules($db, $deviceId);

    // ---- 查询该设备的所有引脚及状态 ----
    $stmt = $db->prepare('
        SELECT p.pin, p.name, COALESCE(s.state, 0) AS state
        FROM device_pins p
        LEFT JOIN device_status s ON s.device_id = p.device_id AND s.pin = p.pin
        WHERE p.device_id = ?
        ORDER BY p.sort_order, p.pin
    ');
    $stmt->execute([$deviceId]);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $result[] = (int)$row['pin'] . ':' . (int)$row['state'];
    }

    // ---- 自定义指令：定时重启（reboot）----
    // 检查是否有到期的重启指令，有则在响应中添加 CMD:reboot
    $stmt = $db->prepare("SELECT id, repeat_mode FROM device_schedules
        WHERE device_id = ? AND status = 'active' AND type = 'reboot'
        AND (
            (repeat_mode = 'once' AND execute_date = CURDATE() AND execute_at <= CURTIME())
            OR (repeat_mode = 'daily' AND execute_at <= CURTIME())
        )
        AND (last_executed_at IS NULL OR DATE(last_executed_at) < CURDATE())
        LIMIT 1
    ");
    $stmt->execute([$deviceId]);
    $rebootSched = $stmt->fetch();
    if ($rebootSched) {
        $stmt = $db->prepare('UPDATE device_schedules SET last_executed_at = NOW() WHERE id = ?');
        $stmt->execute([$rebootSched['id']]);
        if ($rebootSched['repeat_mode'] === 'once') {
            $stmt = $db->prepare("UPDATE device_schedules SET status = 'done' WHERE id = ?");
            $stmt->execute([$rebootSched['id']]);
        }
        $result[] = 'CMD:reboot';
    }

    // ---- 兼容旧版固件的 OTA 回报参数（v3.33 起由服务端自动追踪，不再需要设备回报）----
    $reportOtaId = (int)($_GET['ota_id'] ?? 0);
    if ($reportOtaId > 0 && $otaStatus === 2) {
        $reportSuccess = (int)($_GET['ota_success'] ?? -1);
        $reportError = trim($_GET['ota_error'] ?? '');
        if (in_array($reportSuccess, [0, 1], true)) {
            $stmt = $db->prepare('SELECT device_id, status FROM ota_firmware WHERE id = ?');
            $stmt->execute([$reportOtaId]);
            $fwReport = $stmt->fetch();
            if ($fwReport && $fwReport['device_id'] === $deviceId && $fwReport['status'] === 'updating') {
                $newStatus = $reportSuccess ? 'done' : 'failed';
                $stmt = $db->prepare("UPDATE ota_firmware SET status = ?, error = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newStatus, $reportError, $reportOtaId]);
                // 同步更新 device_keys.ota_status
                $otaStatusNum = $reportSuccess ? 3 : 4;
                $stmt = $db->prepare('UPDATE device_keys SET ota_status = ? WHERE device_id = ?');
                $stmt->execute([$otaStatusNum, $deviceId]);
                $otaStatus = $otaStatusNum;
            }
        }
    }

    // ---- 检查是否有待更新的 OTA 固件 ----
    // 超时兜底清理：updating 超过 5 分钟 → failed（防止设备掉线后状态卡死）
    $stmt = $db->prepare("UPDATE ota_firmware SET status = 'failed', error = '更新超时' WHERE device_id = ? AND status = 'updating' AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    $stmt->execute([$deviceId]);
    $stmt = $db->prepare('UPDATE device_keys SET ota_status = 4 WHERE device_id = ? AND ota_status = 2 AND ota_sent_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)');
    $stmt->execute([$deviceId]);

    // 只有 ota_status <= 1（正常/待更新）时才下发新固件
    if ($otaStatus <= 1) {
        $stmt = $db->prepare('SELECT id, filename, md5, file_size, version FROM ota_firmware WHERE device_id = ? AND status = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$deviceId, 'pending']);
        $ota = $stmt->fetch();
        if ($ota) {
            // 标记固件为正在更新
            $stmt2 = $db->prepare('UPDATE ota_firmware SET status = ? WHERE id = ?');
            $stmt2->execute(['updating', $ota['id']]);
            // 设置设备 OTA 状态为更新中，记录下发时间
            $stmt3 = $db->prepare('UPDATE device_keys SET ota_status = 2, ota_sent_at = NOW() WHERE device_id = ?');
            $stmt3->execute([$deviceId]);
            $result[] = 'OTA:http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/api.php?route=ota/firmware/' . $ota['id'] . '.bin' . '&key=' . $key . '&md5=' . $ota['md5'];
        }
    }

    header('Content-Type: text/plain');
    echo implode(',', $result);
    exit;
}

// =====================================================
//  登录
// =====================================================
function handleLogin(PDO $db, array $body): void
{
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    if ($username === '' || $password === '') {
        jsonError(400, '用户名和密码不能为空');
    }

    // 检查登录失败次数（基于 IP，5 分钟内 5 次失败锁定）
    $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)');
    $stmt->execute([$clientIp]);
    if ($stmt->fetchColumn() >= 5) {
        jsonError(429, '登录尝试过于频繁，请 5 分钟后再试');
    }

    $stmt = $db->prepare('SELECT id, username, password_hash, role, status, token_version FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $stmt = $db->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)');
        $stmt->execute([$clientIp, $username]);
        jsonError(401, '用户名或密码错误');
    }

    if ($user['status'] !== 'active') {
        jsonError(403, '账号已被禁用');
    }

    // 登录成功，清理该 IP 的失败记录
    $stmt = $db->prepare('DELETE FROM login_attempts WHERE ip = ?');
    $stmt->execute([$clientIp]);

    // Token 不过期（10 年有效期），pf 为密码哈希因子用于密码变更后自动失效旧 token
    // tv 为 token 版本号，退出登录后递增使旧 token 失效
    $pf = substr($user['password_hash'], 0, 8);
    $tv = (int)($user['token_version'] ?? 0);
    $token = JWT::encode([
        'uid' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'pf' => $pf,
        'tv' => $tv
    ], 10 * 365 * 86400);

    jsonResponse(200, '登录成功', [
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role']
        ]
    ]);
}

// =====================================================
//  退出登录（递增 token_version，使旧 token 失效）
// =====================================================
function handleLogout(PDO $db, array $user): void
{
    $stmt = $db->prepare('UPDATE users SET token_version = token_version + 1 WHERE id = ?');
    $stmt->execute([$user['uid']]);
    jsonResponse(200, '已退出登录');
}

// =====================================================
//  注册用户（仅管理员）
// =====================================================
function handleRegister(PDO $db, array $body, array $user): void
{
    if ($user['role'] !== 'admin') {
        jsonError(403, '仅管理员可以创建用户');
    }

    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if ($username === '' || $password === '') {
        jsonError(400, '用户名和密码不能为空');
    }
    if (strlen($username) < 3) {
        jsonError(400, '用户名至少3位');
    }
    if (strlen($password) < 6) {
        jsonError(400, '密码至少6位');
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        jsonError(409, '用户名已存在');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (username, password_hash, role, status) VALUES (?, ?, ?, ?)');
    $stmt->execute([$username, $hash, 'user', 'active']);

    jsonResponse(201, '用户已创建', ['username' => $username]);
}

// =====================================================
//  设备相关
// =====================================================
function handleDevices(PDO $db, string $method, array $parts, array $body, array $user): void
{
    // GET /devices - 设备列表（权限隔离）
    if ($method === 'GET' && count($parts) === 1) {
        if ($user['role'] === 'admin') {
            // 管理员看所有设备
            $stmt = $db->query('
                SELECT dk.id, dk.device_id, dk.`key`, dk.remark, dk.status, dk.last_seen, dk.created_at, dk.user_id,
                       u.username as owner_name,
                       (SELECT COUNT(*) FROM device_pins WHERE device_id = dk.device_id) AS pin_count,
                       IF(dk.last_seen IS NULL, 0, IF(dk.last_seen > DATE_SUB(NOW(), INTERVAL 15 SECOND), 1, 0)) AS online
                FROM device_keys dk
                LEFT JOIN users u ON u.id = dk.user_id
                ORDER BY dk.id DESC
            ');
        } else {
            // 普通用户看自己的 + 共享给自己的
            $stmt = $db->prepare('
                SELECT dk.id, dk.device_id, dk.`key`, dk.remark, dk.status, dk.last_seen, dk.created_at, dk.user_id,
                       u.username as owner_name,
                       (SELECT COUNT(*) FROM device_pins WHERE device_id = dk.device_id) AS pin_count,
                       IF(dk.last_seen IS NULL, 0, IF(dk.last_seen > DATE_SUB(NOW(), INTERVAL 15 SECOND), 1, 0)) AS online,
                       0 AS is_shared
                FROM device_keys dk
                LEFT JOIN users u ON u.id = dk.user_id
                WHERE dk.user_id = ?
                UNION
                SELECT dk.id, dk.device_id, dk.`key`, dk.remark, dk.status, dk.last_seen, dk.created_at, dk.user_id,
                       u.username as owner_name,
                       (SELECT COUNT(*) FROM device_pins WHERE device_id = dk.device_id) AS pin_count,
                       IF(dk.last_seen IS NULL, 0, IF(dk.last_seen > DATE_SUB(NOW(), INTERVAL 15 SECOND), 1, 0)) AS online,
                       1 AS is_shared
                FROM device_keys dk
                JOIN device_shares s ON s.device_id = dk.device_id
                LEFT JOIN users u ON u.id = dk.user_id
                WHERE s.shared_to_id = ?
                ORDER BY id DESC
            ');
            $stmt->execute([$user['uid'], $user['uid']]);
        }
        jsonResponse(200, 'ok', $stmt->fetchAll());
    }

    if (count($parts) < 3) {
        jsonError(404, '设备接口不存在');
    }

    $deviceId = $parts[1];
    $subAction = $parts[2];

    if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $deviceId)) {
        jsonError(400, '设备ID格式无效');
    }

    // GET /devices/{id}/pins - 获取设备引脚配置
    if ($method === 'GET' && $subAction === 'pins') {
        if (!canAccessDevice($db, $deviceId, $user)) {
            jsonError(403, '无权访问此设备');
        }
        $stmt = $db->prepare('
            SELECT p.pin, p.name, p.sort_order, p.hidden, COALESCE(s.state, 0) AS state, s.updated_at
            FROM device_pins p
            LEFT JOIN device_status s ON s.device_id = p.device_id AND s.pin = p.pin
            WHERE p.device_id = ?
            ORDER BY p.sort_order, p.pin
        ');
        $stmt->execute([$deviceId]);
        jsonResponse(200, 'ok', $stmt->fetchAll());
    }

    // POST /devices/{id}/pins - 添加引脚配置
    if ($method === 'POST' && $subAction === 'pins') {
        if (!isDeviceOwner($db, $deviceId, $user)) {
            jsonError(403, '仅设备所有者可以配置引脚');
        }
        $pin = (int)($body['pin'] ?? -1);
        $name = trim($body['name'] ?? '');
        $sortOrder = (int)($body['sort_order'] ?? 0);

        if ($pin < 0 || $pin > 255) {
            jsonError(400, '引脚号无效（0-255）');
        }
        if ($name === '') {
            jsonError(400, '请输入引脚名称');
        }

        $stmt = $db->prepare('SELECT id FROM device_keys WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        if (!$stmt->fetch()) {
            jsonError(404, '设备不存在');
        }

        try {
            $stmt = $db->prepare('INSERT INTO device_pins (device_id, pin, name, sort_order) VALUES (?, ?, ?, ?)');
            $stmt->execute([$deviceId, $pin, $name, $sortOrder]);

            $stmt = $db->prepare('INSERT INTO device_status (device_id, pin, state) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE pin=pin');
            $stmt->execute([$deviceId, $pin]);

            jsonResponse(201, '引脚已添加', ['pin' => $pin, 'name' => $name]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                jsonError(409, '该设备已配置此引脚');
            }
            throw $e;
        }
    }

    // DELETE /devices/{id}/pins/{pin} - 删除引脚配置
    if ($method === 'DELETE' && $subAction === 'pins' && count($parts) === 4) {
        if (!isDeviceOwner($db, $deviceId, $user)) {
            jsonError(403, '仅设备所有者可以删除引脚');
        }
        $pin = (int)$parts[3];

        $stmt = $db->prepare('DELETE FROM device_pins WHERE device_id = ? AND pin = ?');
        $stmt->execute([$deviceId, $pin]);

        if ($stmt->rowCount() > 0) {
            $stmt = $db->prepare('DELETE FROM device_status WHERE device_id = ? AND pin = ?');
            $stmt->execute([$deviceId, $pin]);
            jsonResponse(200, '引脚已删除');
        }
        jsonError(404, '引脚不存在');
    }

    // PUT /devices/{id}/pins/{pin}/hide - 隐藏引脚
    if ($method === 'PUT' && $subAction === 'pins' && count($parts) === 5 && $parts[4] === 'hide') {
        if (!isDeviceOwner($db, $deviceId, $user)) {
            jsonError(403, '仅设备所有者可以操作');
        }
        $pin = (int)$parts[3];
        $stmt = $db->prepare('UPDATE device_pins SET hidden = 1 WHERE device_id = ? AND pin = ?');
        $stmt->execute([$deviceId, $pin]);
        if ($stmt->rowCount() > 0 || $stmt->rowCount() === 0) {
            // 检查引脚是否存在
            $stmt = $db->prepare('SELECT id FROM device_pins WHERE device_id = ? AND pin = ? AND hidden = 1');
            $stmt->execute([$deviceId, $pin]);
            if ($stmt->fetch()) jsonResponse(200, '已隐藏');
        }
        jsonError(404, '引脚不存在');
    }

    // PUT /devices/{id}/pins/{pin}/show - 显示引脚
    if ($method === 'PUT' && $subAction === 'pins' && count($parts) === 5 && $parts[4] === 'show') {
        if (!isDeviceOwner($db, $deviceId, $user)) {
            jsonError(403, '仅设备所有者可以操作');
        }
        $pin = (int)$parts[3];
        $stmt = $db->prepare('UPDATE device_pins SET hidden = 0 WHERE device_id = ? AND pin = ?');
        $stmt->execute([$deviceId, $pin]);
        $stmt = $db->prepare('SELECT id FROM device_pins WHERE device_id = ? AND pin = ? AND hidden = 0');
        $stmt->execute([$deviceId, $pin]);
        if ($stmt->fetch()) jsonResponse(200, '已显示');
        jsonError(404, '引脚不存在');
    }

    // POST /devices/{id}/control - 控制引脚
    if ($method === 'POST' && $subAction === 'control') {
        if (!canAccessDevice($db, $deviceId, $user)) {
            jsonError(403, '无权控制此设备');
        }
        $pin = (int)($body['pin'] ?? -1);
        $state = (int)($body['state'] ?? -1);

        if ($pin < 0) { jsonError(400, '引脚号无效'); }
        if (!in_array($state, [0, 1], true)) { jsonError(400, '状态无效，只能是 0(OFF) 或 1(ON)'); }

        $stmt = $db->prepare('SELECT status FROM device_keys WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $device = $stmt->fetch();
        if (!$device) { jsonError(404, '设备不存在'); }
        if ($device['status'] !== 'active') { jsonError(403, '设备已禁用'); }

        $stmt = $db->prepare('SELECT name FROM device_pins WHERE device_id = ? AND pin = ?');
        $stmt->execute([$deviceId, $pin]);
        $pinInfo = $stmt->fetch();
        if (!$pinInfo) { jsonError(404, '该设备未配置此引脚'); }

        $stmt = $db->prepare('INSERT INTO device_status (device_id, pin, state) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE state = ?');
        $stmt->execute([$deviceId, $pin, $state, $state]);

        jsonResponse(200, '指令已写入', [
            'device_id' => $deviceId,
            'pin' => $pin,
            'name' => $pinInfo['name'],
            'state' => $state,
            'state_name' => $state ? 'ON' : 'OFF'
        ]);
    }

    // GET /devices/{id}/logs - 设备日志
    if ($method === 'GET' && $subAction === 'logs') {
        if (!canAccessDevice($db, $deviceId, $user)) {
            jsonError(403, '无权查看此设备日志');
        }
        $limit = min((int)($_GET['limit'] ?? 50), 50);

        $stmt = $db->prepare('
            SELECT wifi_ssid, wifi_rssi, ip, created_at
            FROM device_logs
            WHERE device_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ');
        $stmt->execute([$deviceId, $limit]);
        jsonResponse(200, 'ok', $stmt->fetchAll());
    }

    // POST /devices/{id}/share - 共享设备给其他用户
    if ($method === 'POST' && $subAction === 'share') {
        if (!isDeviceOwner($db, $deviceId, $user)) {
            jsonError(403, '仅设备所有者可以共享设备');
        }
        $targetUsername = trim($body['username'] ?? '');
        if ($targetUsername === '') {
            jsonError(400, '请输入目标用户名');
        }

        // 查找目标用户
        $stmt = $db->prepare('SELECT id, username FROM users WHERE username = ? AND status = "active"');
        $stmt->execute([$targetUsername]);
        $targetUser = $stmt->fetch();
        if (!$targetUser) {
            jsonError(404, '用户不存在或已被禁用');
        }
        if ($targetUser['id'] == $user['uid']) {
            jsonError(400, '不能共享给自己');
        }

        // 检查是否已共享
        $stmt = $db->prepare('SELECT id FROM device_shares WHERE device_id = ? AND shared_to_id = ?');
        $stmt->execute([$deviceId, $targetUser['id']]);
        if ($stmt->fetch()) {
            jsonError(409, '已共享给该用户');
        }

        $stmt = $db->prepare('INSERT INTO device_shares (device_id, owner_id, shared_to_id) VALUES (?, ?, ?)');
        $stmt->execute([$deviceId, $user['uid'], $targetUser['id']]);

        jsonResponse(201, '共享成功', [
            'device_id' => $deviceId,
            'shared_to' => $targetUser['username']
        ]);
    }

    // DELETE /devices/{id}/share/{userId} - 取消共享
    if ($method === 'DELETE' && $subAction === 'share' && count($parts) === 4) {
        if (!isDeviceOwner($db, $deviceId, $user)) {
            jsonError(403, '仅设备所有者可以取消共享');
        }
        $sharedToId = (int)$parts[3];

        $stmt = $db->prepare('DELETE FROM device_shares WHERE device_id = ? AND shared_to_id = ? AND owner_id = ?');
        $stmt->execute([$deviceId, $sharedToId, $user['uid']]);

        if ($stmt->rowCount() > 0) {
            jsonResponse(200, '共享已取消');
        }
        jsonError(404, '共享记录不存在');
    }

    // GET /devices/{id}/shares - 查看共享列表
    if ($method === 'GET' && $subAction === 'shares') {
        if (!isDeviceOwner($db, $deviceId, $user)) {
            jsonError(403, '仅设备所有者可以查看共享列表');
        }
        $stmt = $db->prepare('
            SELECT s.id, s.shared_to_id, u.username as shared_to_name, s.created_at
            FROM device_shares s
            JOIN users u ON u.id = s.shared_to_id
            WHERE s.device_id = ?
            ORDER BY s.created_at DESC
        ');
        $stmt->execute([$deviceId]);
        jsonResponse(200, 'ok', $stmt->fetchAll());
    }

    jsonError(404, '设备接口不存在');
}

// =====================================================
//  Key 管理
// =====================================================
function handleKeys(PDO $db, string $method, array $parts, array $body, array $user): void
{
    require_once __DIR__ . '/key_manager.php';

    if ($method === 'GET' && count($parts) === 1) {
        if ($user['role'] === 'admin') {
            $keys = listKeys();
        } else {
            // 普通用户只看自己的 Key
            $stmt = $db->prepare('SELECT `key`, remark, status, created_at FROM device_keys WHERE user_id = ? ORDER BY id DESC');
            $stmt->execute([$user['uid']]);
            $keys = $stmt->fetchAll();
        }
        jsonResponse(200, 'ok', $keys);
    }
    if ($method === 'POST' && count($parts) === 1) {
        $remark = trim($body['remark'] ?? '');
        $deviceId = trim($body['device_id'] ?? '');
        if ($deviceId === '') {
            jsonError(400, '设备ID不能为空');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $deviceId)) {
            jsonError(400, '设备ID格式无效，只允许英文、数字、下划线和短横线（1-32位）');
        }
        $result = generateKey($remark);
        // 绑定到当前用户和设备ID
        $stmt = $db->prepare('UPDATE device_keys SET user_id = ?, device_id = ? WHERE `key` = ?');
        $stmt->execute([$user['uid'], $deviceId, $result['key']]);
        $result['user_id'] = $user['uid'];
        $result['device_id'] = $deviceId;
        jsonResponse(201, 'Key 已生成', $result);
    }
    if ($method === 'DELETE' && count($parts) === 2) {
        // 只能删除自己的 Key（管理员除外）
        if ($user['role'] !== 'admin') {
            $stmt = $db->prepare('SELECT id FROM device_keys WHERE `key` = ? AND user_id = ?');
            $stmt->execute([$parts[1], $user['uid']]);
            if (!$stmt->fetch()) {
                jsonError(403, '只能删除自己的 Key');
            }
        }
        if (deleteKey($parts[1])) { jsonResponse(200, 'Key 已删除'); }
        jsonError(404, 'Key 不存在');
    }
    if ($method === 'PUT' && count($parts) === 3 && $parts[2] === 'disable') {
        if ($user['role'] !== 'admin') {
            $stmt = $db->prepare('SELECT id FROM device_keys WHERE `key` = ? AND user_id = ?');
            $stmt->execute([$parts[1], $user['uid']]);
            if (!$stmt->fetch()) {
                jsonError(403, '只能禁用自己创建的 Key');
            }
        }
        if (disableKey($parts[1])) { jsonResponse(200, 'Key 已禁用'); }
        jsonError(404, 'Key 不存在');
    }
    if ($method === 'PUT' && count($parts) === 3 && $parts[2] === 'enable') {
        if ($user['role'] !== 'admin') {
            $stmt = $db->prepare('SELECT id FROM device_keys WHERE `key` = ? AND user_id = ?');
            $stmt->execute([$parts[1], $user['uid']]);
            if (!$stmt->fetch()) {
                jsonError(403, '只能启用自己创建的 Key');
            }
        }
        if (enableKey($parts[1])) { jsonResponse(200, 'Key 已启用'); }
        jsonError(404, 'Key 不存在');
    }
    jsonError(404, 'Key 接口不存在');
}

// =====================================================
//  用户管理
// =====================================================
function handleUser(PDO $db, string $method, array $parts, array $body, array $user): void
{
    if ($method === 'POST' && count($parts) === 2 && $parts[1] === 'password') {
        $oldPassword = $body['old_password'] ?? '';
        $newPassword = $body['new_password'] ?? '';
        if (strlen($newPassword) < 6) { jsonError(400, '新密码至少6位'); }
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['uid']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($oldPassword, $row['password_hash'])) { jsonError(401, '原密码错误'); }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $user['uid']]);
        jsonResponse(200, '密码已修改');
    }
    if ($method === 'POST' && count($parts) === 2 && $parts[1] === 'username') {
        $newUsername = trim($body['new_username'] ?? '');
        $password = $body['password'] ?? '';

        if ($newUsername === '' || $password === '') { jsonError(400, '请填写完整'); }
        if (strlen($newUsername) < 3) { jsonError(400, '用户名至少3位'); }

        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['uid']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) { jsonError(401, '密码错误'); }

        $stmt = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $stmt->execute([$newUsername, $user['uid']]);
        if ($stmt->fetch()) { jsonError(409, '用户名已被使用'); }

        $stmt = $db->prepare('UPDATE users SET username = ? WHERE id = ?');
        $stmt->execute([$newUsername, $user['uid']]);
        jsonResponse(200, '用户名已修改');
    }
    jsonError(404, '用户接口不存在');
}

// =====================================================
//  管理员接口
// =====================================================
function handleAdmin(PDO $db, string $method, array $parts, array $body, array $user): void
{
    if ($user['role'] !== 'admin') {
        jsonError(403, '仅管理员可以访问');
    }

    $subAction = $parts[1] ?? '';

    // GET /admin/users - 用户列表
    if ($method === 'GET' && $subAction === 'users' && count($parts) === 2) {
        $stmt = $db->query('SELECT id, username, role, status, created_at FROM users ORDER BY id DESC');
        jsonResponse(200, 'ok', $stmt->fetchAll());
    }

    // POST /admin/users - 创建用户
    if ($method === 'POST' && $subAction === 'users' && count($parts) === 2) {
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        $role = $body['role'] ?? 'user';

        if ($username === '' || $password === '') {
            jsonError(400, '用户名和密码不能为空');
        }
        if (strlen($username) < 3) {
            jsonError(400, '用户名至少3位');
        }
        if (strlen($password) < 6) {
            jsonError(400, '密码至少6位');
        }
        if (!in_array($role, ['admin', 'user'], true)) {
            jsonError(400, '角色无效');
        }

        $stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            jsonError(409, '用户名已存在');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare('INSERT INTO users (username, password_hash, role, status) VALUES (?, ?, ?, ?)');
        $stmt->execute([$username, $hash, $role, 'active']);

        jsonResponse(201, '用户已创建', ['id' => (int)$db->lastInsertId(), 'username' => $username, 'role' => $role]);
    }

    if (count($parts) < 3) {
        jsonError(404, '管理员接口不存在');
    }

    $targetUserId = (int)$parts[2];
    if ($targetUserId <= 0) {
        jsonError(400, '用户ID无效');
    }
    // 不能操作自己
    if ($targetUserId === $user['uid']) {
        jsonError(400, '不能操作自己的账号');
    }

    // DELETE /admin/users/{id} - 删除用户
    if ($method === 'DELETE' && $subAction === 'users' && count($parts) === 3) {
        $stmt = $db->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$targetUserId]);
        if ($stmt->rowCount() > 0) {
            jsonResponse(200, '用户已删除');
        }
        jsonError(404, '用户不存在');
    }

    // POST /admin/users/{id}/password - 修改用户密码
    if ($method === 'POST' && $subAction === 'users' && count($parts) === 4 && $parts[3] === 'password') {
        $newPassword = $body['new_password'] ?? '';
        if (strlen($newPassword) < 6) {
            jsonError(400, '密码至少6位');
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $targetUserId]);
        if ($stmt->rowCount() > 0) {
            jsonResponse(200, '密码已修改');
        }
        jsonError(404, '用户不存在');
    }

    // PUT /admin/users/{id}/disable - 禁用用户
    if ($method === 'PUT' && $subAction === 'users' && count($parts) === 4 && $parts[3] === 'disable') {
        $stmt = $db->prepare('UPDATE users SET status = "disabled" WHERE id = ?');
        $stmt->execute([$targetUserId]);
        if ($stmt->rowCount() > 0) {
            jsonResponse(200, '用户已禁用');
        }
        jsonError(404, '用户不存在');
    }

    // PUT /admin/users/{id}/enable - 启用用户
    if ($method === 'PUT' && $subAction === 'users' && count($parts) === 4 && $parts[3] === 'enable') {
        $stmt = $db->prepare('UPDATE users SET status = "active" WHERE id = ?');
        $stmt->execute([$targetUserId]);
        if ($stmt->rowCount() > 0) {
            jsonResponse(200, '用户已启用');
        }
        jsonError(404, '用户不存在');
    }

    jsonError(404, '管理员接口不存在');
}

// =====================================================
//  预设管理
// =====================================================
function handleCombos(PDO $db, string $method, array $parts, array $body, array $user): void
{
    // GET /combos?device_id=xxx - 获取设备的预设列表
    if ($method === 'GET' && count($parts) === 1) {
        $deviceId = $_GET['device_id'] ?? '';
        if ($deviceId === '') {
            jsonError(400, '缺少 device_id 参数');
        }
        if (!canAccessDevice($db, $deviceId, $user)) {
            jsonError(403, '无权访问此设备');
        }
        $stmt = $db->prepare('
            SELECT c.id, c.device_id, c.name, c.created_at,
                   (SELECT GROUP_CONCAT(CONCAT(ci.pin, ":", ci.state) SEPARATOR ",") FROM device_combo_items ci WHERE ci.combo_id = c.id) AS items
            FROM device_combos c
            WHERE c.device_id = ?
            ORDER BY c.id DESC
        ');
        $stmt->execute([$deviceId]);
        $combos = $stmt->fetchAll();
        // 解析 items 为数组
        foreach ($combos as &$combo) {
            $items = [];
            if ($combo['items']) {
                foreach (explode(',', $combo['items']) as $item) {
                    [$pin, $state] = explode(':', $item);
                    $items[] = ['pin' => (int)$pin, 'state' => (int)$state];
                }
            }
            $combo['items'] = $items;
        }
        unset($combo);
        jsonResponse(200, 'ok', $combos);
    }

    // POST /combos - 创建预设
    if ($method === 'POST' && count($parts) === 1) {
        $deviceId = trim($body['device_id'] ?? '');
        $name = trim($body['name'] ?? '');
        $items = $body['items'] ?? [];

        if ($deviceId === '' || $name === '') {
            jsonError(400, '设备ID和预设名称不能为空');
        }
        if (!canAccessDevice($db, $deviceId, $user)) {
            jsonError(403, '无权操作此设备');
        }
        if (!is_array($items) || count($items) === 0) {
            jsonError(400, '至少选择一个引脚');
        }

        // 验证引脚是否属于该设备
        $stmt = $db->prepare('SELECT pin FROM device_pins WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $validPins = array_column($stmt->fetchAll(), 'pin');

        foreach ($items as $item) {
            if (!in_array((int)$item['pin'], array_map('intval', $validPins), true)) {
                jsonError(400, '引脚 ' . $item['pin'] . ' 不属于该设备');
            }
        }

        // 创建预设
        $stmt = $db->prepare('INSERT INTO device_combos (device_id, user_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deviceId, $user['uid'], $name]);
        $comboId = (int)$db->lastInsertId();

        // 创建预设项
        $stmt = $db->prepare('INSERT INTO device_combo_items (combo_id, pin, state) VALUES (?, ?, ?)');
        foreach ($items as $item) {
            $stmt->execute([$comboId, (int)$item['pin'], (int)$item['state']]);
        }

        jsonResponse(201, '预设已创建', ['id' => $comboId, 'name' => $name]);
    }

    // POST /combos/{id}/execute - 执行预设
    if ($method === 'POST' && count($parts) === 3 && $parts[2] === 'execute') {
        $comboId = (int)$parts[1];

        // 获取预设信息
        $stmt = $db->prepare('SELECT device_id FROM device_combos WHERE id = ?');
        $stmt->execute([$comboId]);
        $combo = $stmt->fetch();
        if (!$combo) {
            jsonError(404, '预设不存在');
        }

        $deviceId = $combo['device_id'];
        if (!canAccessDevice($db, $deviceId, $user)) {
            jsonError(403, '无权操作此设备');
        }

        // 检查设备是否启用
        $stmt = $db->prepare('SELECT status FROM device_keys WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $device = $stmt->fetch();
        if (!$device) {
            jsonError(404, '设备不存在');
        }
        if ($device['status'] !== 'active') {
            jsonError(403, '设备已禁用');
        }

        // 获取预设项
        $stmt = $db->prepare('SELECT pin, state FROM device_combo_items WHERE combo_id = ?');
        $stmt->execute([$comboId]);
        $items = $stmt->fetchAll();

        $results = [];
        foreach ($items as $item) {
            $pin = (int)$item['pin'];
            $state = (int)$item['state'];

            // 验证引脚存在
            $stmt = $db->prepare('SELECT name FROM device_pins WHERE device_id = ? AND pin = ?');
            $stmt->execute([$deviceId, $pin]);
            $pinInfo = $stmt->fetch();
            if (!$pinInfo) {
                continue;
            }

            // 写入状态
            $stmt = $db->prepare('INSERT INTO device_status (device_id, pin, state) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE state = ?');
            $stmt->execute([$deviceId, $pin, $state, $state]);

            $results[] = ['pin' => $pin, 'name' => $pinInfo['name'], 'state' => $state];
        }

        jsonResponse(200, '预设已执行', [
            'combo_id' => $comboId,
            'device_id' => $deviceId,
            'results' => $results
        ]);
    }

    // DELETE /combos/{id} - 删除预设
    if ($method === 'DELETE' && count($parts) === 2) {
        $comboId = (int)$parts[1];

        // 获取预设信息验证权限
        $stmt = $db->prepare('SELECT device_id, user_id FROM device_combos WHERE id = ?');
        $stmt->execute([$comboId]);
        $combo = $stmt->fetch();
        if (!$combo) {
            jsonError(404, '预设不存在');
        }

        // 管理员或创建者可删除
        if ($user['role'] !== 'admin' && $combo['user_id'] != $user['uid']) {
            jsonError(403, '只能删除自己创建的预设');
        }

        $stmt = $db->prepare('DELETE FROM device_combo_items WHERE combo_id = ?');
        $stmt->execute([$comboId]);

        $stmt = $db->prepare('DELETE FROM device_combos WHERE id = ?');
        $stmt->execute([$comboId]);

        jsonResponse(200, '预设已删除');
    }

    jsonError(404, '预设接口不存在');
}

// =====================================================
//  组合开关（多个引脚合并为一个开关，同步开/关）
// =====================================================
function handleSwitchCombos(PDO $db, string $method, array $parts, array $body, array $user): void
{
    // GET /switch-combos?device_id=xxx - 获取设备的组合开关列表
    if ($method === 'GET' && count($parts) === 1) {
        $deviceId = $_GET['device_id'] ?? '';
        if ($deviceId === '') {
            jsonError(400, '缺少 device_id 参数');
        }
        if (!canAccessDevice($db, $deviceId, $user)) {
            jsonError(403, '无权访问此设备');
        }
        $stmt = $db->prepare('
            SELECT sc.id, sc.device_id, sc.name, sc.created_at,
                   (SELECT GROUP_CONCAT(pin SEPARATOR ",") FROM device_switch_combo_pins WHERE switch_combo_id = sc.id) AS pins
            FROM device_switch_combos sc
            WHERE sc.device_id = ?
            ORDER BY sc.id DESC
        ');
        $stmt->execute([$deviceId]);
        $switchCombos = $stmt->fetchAll();

        // 获取所有引脚当前状态
        $stmt = $db->prepare('SELECT pin, COALESCE(state, 0) AS state FROM device_status WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $statusMap = [];
        foreach ($stmt->fetchAll() as $s) {
            $statusMap[(int)$s['pin']] = (int)$s['state'];
        }

        foreach ($switchCombos as &$sc) {
            $pinList = [];
            $allOn = true;
            if ($sc['pins']) {
                foreach (explode(',', $sc['pins']) as $pin) {
                    $pinInt = (int)$pin;
                    $pinList[] = $pinInt;
                    if (empty($statusMap[$pinInt])) {
                        $allOn = false;
                    }
                }
            }
            $sc['pins'] = $pinList;
            $sc['all_on'] = $allOn && count($pinList) > 0;
        }
        unset($sc);

        jsonResponse(200, 'ok', $switchCombos);
    }

    // POST /switch-combos - 创建组合开关
    if ($method === 'POST' && count($parts) === 1) {
        $deviceId = trim($body['device_id'] ?? '');
        $name = trim($body['name'] ?? '');
        $pins = $body['pins'] ?? [];

        if ($deviceId === '' || $name === '') {
            jsonError(400, '设备ID和组合开关名称不能为空');
        }
        if (!canAccessDevice($db, $deviceId, $user)) {
            jsonError(403, '无权操作此设备');
        }
        if (!is_array($pins) || count($pins) < 2) {
            jsonError(400, '组合开关至少需要选择2个引脚');
        }

        // 验证引脚是否属于该设备
        $stmt = $db->prepare('SELECT pin FROM device_pins WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $validPins = array_map('intval', array_column($stmt->fetchAll(), 'pin'));

        foreach ($pins as $pin) {
            if (!in_array((int)$pin, $validPins, true)) {
                jsonError(400, '引脚 ' . $pin . ' 不属于该设备');
            }
        }

        // 创建组合开关
        $stmt = $db->prepare('INSERT INTO device_switch_combos (device_id, user_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deviceId, $user['uid'], $name]);
        $switchComboId = (int)$db->lastInsertId();

        // 关联引脚
        $stmt = $db->prepare('INSERT INTO device_switch_combo_pins (switch_combo_id, pin) VALUES (?, ?)');
        foreach ($pins as $pin) {
            $stmt->execute([$switchComboId, (int)$pin]);
        }

        jsonResponse(201, '组合开关已创建', ['id' => $switchComboId, 'name' => $name]);
    }

    // POST /switch-combos/{id}/toggle - 切换组合开关（同步开/关所有引脚）
    if ($method === 'POST' && count($parts) === 3 && $parts[2] === 'toggle') {
        $switchComboId = (int)$parts[1];
        $state = isset($body['state']) ? (int)$body['state'] : -1;

        if ($state !== 0 && $state !== 1) {
            jsonError(400, 'state 参数必须为 0 或 1');
        }

        // 获取组合开关信息
        $stmt = $db->prepare('SELECT device_id, user_id FROM device_switch_combos WHERE id = ?');
        $stmt->execute([$switchComboId]);
        $sc = $stmt->fetch();
        if (!$sc) {
            jsonError(404, '组合开关不存在');
        }

        $deviceId = $sc['device_id'];
        if (!canAccessDevice($db, $deviceId, $user)) {
            jsonError(403, '无权操作此设备');
        }

        // 检查设备是否启用
        $stmt = $db->prepare('SELECT status FROM device_keys WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $device = $stmt->fetch();
        if (!$device) {
            jsonError(404, '设备不存在');
        }
        if ($device['status'] !== 'active') {
            jsonError(403, '设备已禁用');
        }

        // 获取关联引脚
        $stmt = $db->prepare('SELECT pin FROM device_switch_combo_pins WHERE switch_combo_id = ?');
        $stmt->execute([$switchComboId]);
        $pinRows = $stmt->fetchAll();

        $results = [];
        foreach ($pinRows as $row) {
            $pin = (int)$row['pin'];

            // 验证引脚存在
            $stmt = $db->prepare('SELECT name FROM device_pins WHERE device_id = ? AND pin = ?');
            $stmt->execute([$deviceId, $pin]);
            $pinInfo = $stmt->fetch();
            if (!$pinInfo) {
                continue;
            }

            // 写入状态
            $stmt = $db->prepare('INSERT INTO device_status (device_id, pin, state) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE state = ?');
            $stmt->execute([$deviceId, $pin, $state, $state]);

            $results[] = ['pin' => $pin, 'name' => $pinInfo['name'], 'state' => $state];
        }

        jsonResponse(200, $state ? '已全部开启' : '已全部关闭', [
            'switch_combo_id' => $switchComboId,
            'device_id' => $deviceId,
            'state' => $state,
            'results' => $results
        ]);
    }

    // DELETE /switch-combos/{id} - 删除组合开关
    if ($method === 'DELETE' && count($parts) === 2) {
        $switchComboId = (int)$parts[1];

        $stmt = $db->prepare('SELECT device_id, user_id FROM device_switch_combos WHERE id = ?');
        $stmt->execute([$switchComboId]);
        $sc = $stmt->fetch();
        if (!$sc) {
            jsonError(404, '组合开关不存在');
        }

        // 管理员或创建者可删除
        if ($user['role'] !== 'admin' && $sc['user_id'] != $user['uid']) {
            jsonError(403, '只能删除自己创建的组合开关');
        }

        $stmt = $db->prepare('DELETE FROM device_switch_combo_pins WHERE switch_combo_id = ?');
        $stmt->execute([$switchComboId]);

        $stmt = $db->prepare('DELETE FROM device_switch_combos WHERE id = ?');
        $stmt->execute([$switchComboId]);

        jsonResponse(200, '组合开关已删除');
    }

    jsonError(404, '组合开关接口不存在');
}

// =====================================================
//  OTA 固件更新
// =====================================================

/**
 * 公开接口：ESP8266 下载固件文件
 * GET /ota/firmware/{id}.bin?key=xxx&md5=xxx
 */
function handleOTAFirmwareDownload(PDO $db): void
{
    require_once __DIR__ . '/key_manager.php';

    $key = $_GET['key'] ?? '';
    if (!verifyKey($key)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    // 从路径中提取 ID（格式：ota/firmware/{id}.bin）
    $path = $_GET['route'] ?? '';
    if (!preg_match('/ota\/firmware\/(\d+)\.bin$/', $path, $m)) {
        http_response_code(400);
        echo 'Bad request';
        exit;
    }
    $otaId = (int)$m[1];

    $stmt = $db->prepare('SELECT * FROM ota_firmware WHERE id = ?');
    $stmt->execute([$otaId]);
    $firmware = $stmt->fetch();
    if (!$firmware) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }

    // 安全校验：请求方的 Key 必须属于该固件目标设备
    // 防止其他设备或未授权用户下载别人的固件
    $stmt = $db->prepare('SELECT id FROM device_keys WHERE `key` = ? AND device_id = ? AND status = "active"');
    $stmt->execute([strtoupper(trim($key)), $firmware['device_id']]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo 'Forbidden: key does not match target device';
        exit;
    }

    // 只允许下载 pending 或 updating 状态的固件，防止重复下载已完成/失败的记录
    if (!in_array($firmware['status'], ['pending', 'updating'], true)) {
        http_response_code(410);
        echo 'Gone: firmware status is ' . $firmware['status'];
        exit;
    }

    $filePath = __DIR__ . '/firmware/' . $firmware['filename'];
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    // 返回固件文件
    // 放宽时间限制：ESP8266 下载固件可能需要较长时间（1MB 文件在慢网络下可能需要 30 秒）
    set_time_limit(60);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $firmware['filename'] . '"');
    header('Content-Length: ' . filesize($filePath));
    header('X-MD5: ' . $firmware['md5']);
    // 清空输出缓冲，防止内存堆积
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    readfile($filePath);
    exit;
}

/**
 * OTA 管理接口（需 JWT 认证）
 * POST /ota/upload        - 上传固件（FormData）
 * GET  /ota/status        - 查询设备OTA状态
 * DELETE /ota/{id}        - 取消待更新的固件
 */
function handleOTA(PDO $db, string $method, array $parts, array $body, array $user): void
{
    // POST /ota/upload - 上传固件
    if ($method === 'POST' && ($parts[1] ?? '') === 'upload' && count($parts) === 2) {
        // 放宽时间限制：文件上传可能需要较长时间
        set_time_limit(30);
        if (empty($_FILES['firmware'])) {
            jsonError(400, '请选择固件文件');
        }

        $file = $_FILES['firmware'];
        // FormData 上传时 device_id/version 从 $_POST 获取
        $deviceId = trim($_POST['device_id'] ?? $body['device_id'] ?? '');
        $version = trim($_POST['version'] ?? $body['version'] ?? '');

        if ($deviceId === '') {
            jsonError(400, '设备ID不能为空');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $deviceId)) {
            jsonError(400, '设备ID格式无效');
        }
        if (!canAccessDevice($db, $deviceId, $user)) {
            jsonError(403, '无权操作此设备');
        }

        // 验证设备存在且启用
        $stmt = $db->prepare('SELECT status FROM device_keys WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $device = $stmt->fetch();
        if (!$device) {
            jsonError(404, '设备不存在');
        }
        if ($device['status'] !== 'active') {
            jsonError(403, '设备已禁用');
        }

        // 验证文件后缀
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'bin') {
            jsonError(400, '仅支持 .bin 格式的固件文件');
        }

        // 验证文件大小（ESP8266 最大 1MB 固件）
        if ($file['size'] > 1048576) {
            jsonError(400, '固件文件过大，最大支持 1MB');
        }
        if ($file['size'] < 1024) {
            jsonError(400, '固件文件过小，请检查文件是否正确');
        }

        // 清除该设备之前的 pending 和 failed 记录
        $stmt = $db->prepare('DELETE FROM ota_firmware WHERE device_id = ? AND status IN (?, ?)');
        $stmt->execute([$deviceId, 'pending', 'failed']);

        // 生成安全文件名
        $safeFilename = $deviceId . '_' . time() . '.bin';
        $targetPath = __DIR__ . '/firmware/' . $safeFilename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            jsonError(500, '文件保存失败');
        }

        $md5 = md5_file($targetPath);
        $fileSize = filesize($targetPath);

        // 写入数据库
        $stmt = $db->prepare('INSERT INTO ota_firmware (device_id, filename, version, file_size, md5, status, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$deviceId, $safeFilename, $version, $fileSize, $md5, 'pending', $user['uid']]);
        $otaId = (int)$db->lastInsertId();

        // 设置设备 OTA 状态为待更新（状态 1）
        $stmt = $db->prepare('UPDATE device_keys SET ota_status = 1 WHERE device_id = ?');
        $stmt->execute([$deviceId]);

        jsonResponse(201, '固件已上传，设备将在下次轮询时自动更新', [
            'id' => $otaId,
            'device_id' => $deviceId,
            'version' => $version,
            'file_size' => $fileSize,
            'md5' => $md5,
            'filename' => $safeFilename
        ]);
    }

    // GET /ota/status - 查询设备OTA状态
    if ($method === 'GET' && ($parts[1] ?? '') === 'status' && count($parts) === 2) {
        $deviceId = $_GET['device_id'] ?? '';
        if ($deviceId === '') {
            jsonError(400, '缺少 device_id 参数');
        }
        if (!canAccessDevice($db, $deviceId, $user)) {
            jsonError(403, '无权访问此设备');
        }

        // 超时兜底：查询时也检查 OTA 超时（防止设备掉线后前端看不到失败状态）
        $stmt = $db->prepare("UPDATE ota_firmware SET status = 'failed', error = '更新超时' WHERE device_id = ? AND status = 'updating' AND updated_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        $stmt->execute([$deviceId]);
        $stmt = $db->prepare('UPDATE device_keys SET ota_status = 4 WHERE device_id = ? AND ota_status = 2 AND ota_sent_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)');
        $stmt->execute([$deviceId]);

        // 读取设备当前 OTA 状态（0=正常 1=待更新 2=更新中 3=已更新 4=失败）
        $stmt = $db->prepare('SELECT ota_status FROM device_keys WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $devRow = $stmt->fetch();
        $otaStatus = $devRow ? (int)$devRow['ota_status'] : 0;

        $stmt = $db->prepare('SELECT id, device_id, version, filename, file_size, md5, status, error, created_at, updated_at FROM ota_firmware WHERE device_id = ? ORDER BY id DESC LIMIT 5');
        $stmt->execute([$deviceId]);
        $records = $stmt->fetchAll();

        // 格式化文件大小
        foreach ($records as &$r) {
            $r['file_size_text'] = $r['file_size'] >= 1024
                ? round($r['file_size'] / 1024, 1) . ' KB'
                : $r['file_size'] . ' B';
        }
        unset($r);

        jsonResponse(200, 'ok', ['ota_status' => $otaStatus, 'records' => $records]);
    }

    // DELETE /ota/{id} - 删除固件记录（pending/failed/done 均可删除，updating 不允许）
    if ($method === 'DELETE' && count($parts) === 2) {
        $otaId = (int)$parts[1];

        $stmt = $db->prepare('SELECT device_id, filename, status, uploaded_by FROM ota_firmware WHERE id = ?');
        $stmt->execute([$otaId]);
        $firmware = $stmt->fetch();
        if (!$firmware) {
            jsonError(404, '固件记录不存在');
        }

        // 权限检查：管理员或上传者
        if ($user['role'] !== 'admin' && $firmware['uploaded_by'] != $user['uid']) {
            jsonError(403, '只能删除自己上传的固件');
        }

        // 不允许删除 updating 状态的记录（设备正在更新中）
        if ($firmware['status'] === 'updating') {
            jsonError(400, '设备正在更新中，无法删除');
        }

        // 删除文件
        $filePath = __DIR__ . '/firmware/' . $firmware['filename'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        $stmt = $db->prepare('DELETE FROM ota_firmware WHERE id = ?');
        $stmt->execute([$otaId]);

        // pending/failed 状态删除时重置 ota_status（done 状态删除不影响，因为 ota_status 已为 0）
        if (in_array($firmware['status'], ['pending', 'failed'], true)) {
            $stmt = $db->prepare('UPDATE device_keys SET ota_status = 0 WHERE device_id = ?');
            $stmt->execute([$firmware['device_id']]);
        }

        jsonResponse(200, '固件已取消');
    }

    jsonError(404, 'OTA接口不存在');
}

// =====================================================
//  ESP8266 回报 OTA 更新结果（公开接口，Key 验证）
//  POST ota/report  body: ota_id, success(0/1), device_id, key, error
// =====================================================
function handleOTAReport(PDO $db): void
{
    require_once __DIR__ . '/key_manager.php';

    // 兼容 JSON body 与 form-data 两种提交方式
    $raw = json_decode(file_get_contents('php://input'), true) ?: [];
    $key = $raw['key'] ?? $_POST['key'] ?? '';
    if (!verifyKey($key)) {
        jsonError(403, '无效的 Key');
    }

    $otaId = (int)($raw['ota_id'] ?? $_POST['ota_id'] ?? 0);
    $success = (int)($raw['success'] ?? $_POST['success'] ?? -1);
    $error = trim($raw['error'] ?? $_POST['error'] ?? '');
    $deviceId = trim($raw['device_id'] ?? $_POST['device_id'] ?? '');

    if ($otaId <= 0) {
        jsonError(400, '缺少 ota_id');
    }
    if (!in_array($success, [0, 1], true)) {
        jsonError(400, 'success 参数无效（0 或 1）');
    }

    $stmt = $db->prepare('SELECT device_id, status FROM ota_firmware WHERE id = ?');
    $stmt->execute([$otaId]);
    $fw = $stmt->fetch();
    if (!$fw) {
        jsonError(404, '固件记录不存在');
    }
    // 校验设备归属，防止伪造
    if ($deviceId !== '' && $fw['device_id'] !== $deviceId) {
        jsonError(403, '设备不匹配');
    }
    // 校验 Key 必须属于该设备，防止用其他设备的 Key 伪造回报
    if ($deviceId !== '') {
        $stmt = $db->prepare('SELECT id FROM device_keys WHERE `key` = ? AND device_id = ? AND status = "active"');
        $stmt->execute([strtoupper(trim($key)), $deviceId]);
        if (!$stmt->fetch()) {
            jsonError(403, 'Key 与设备不匹配');
        }
    }

    $newStatus = $success ? 'done' : 'failed';
    $stmt = $db->prepare("UPDATE ota_firmware SET status = ?, error = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newStatus, $error, $otaId]);

    // 同步更新 device_keys.ota_status（v3.33 兼容旧版固件回报）
    if ($deviceId !== '') {
        $otaStatusNum = $success ? 3 : 4;
        $stmt = $db->prepare('UPDATE device_keys SET ota_status = ? WHERE device_id = ?');
        $stmt->execute([$otaStatusNum, $deviceId]);
    }

    jsonResponse(200, $success ? '更新成功' : '更新失败已记录');
}

// =====================================================
//  自定义指令：定时开关 + 延时关闭（轮询时自动执行）
// =====================================================
function processSchedules(PDO $db, string $deviceId): void
{
    // ===== 1. 定时开关（timer）：检查到期指令，更新引脚状态 =====
    $stmt = $db->prepare("SELECT id, target_type, target_id, target_state, repeat_mode
        FROM device_schedules
        WHERE device_id = ? AND status = 'active' AND type = 'timer'
        AND (
            (repeat_mode = 'once' AND execute_date = CURDATE() AND execute_at <= CURTIME())
            OR (repeat_mode = 'daily' AND execute_at <= CURTIME())
        )
        AND (last_executed_at IS NULL OR DATE(last_executed_at) < CURDATE())
    ");
    $stmt->execute([$deviceId]);
    $timers = $stmt->fetchAll();

    foreach ($timers as $t) {
        $state = (int)$t['target_state'];
        if ($t['target_type'] === 'pin') {
            $stmt = $db->prepare('INSERT INTO device_status (device_id, pin, state) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE state = ?');
            $stmt->execute([$deviceId, (int)$t['target_id'], $state, $state]);
        } else {
            // switch_combo：更新组合开关的所有引脚
            $stmt = $db->prepare('SELECT pin FROM device_switch_combo_pins WHERE switch_combo_id = ?');
            $stmt->execute([(int)$t['target_id']]);
            $pins = $stmt->fetchAll();
            foreach ($pins as $p) {
                $stmt = $db->prepare('INSERT INTO device_status (device_id, pin, state) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE state = ?');
                $stmt->execute([$deviceId, (int)$p['pin'], $state, $state]);
            }
        }
        // 标记已执行
        $stmt = $db->prepare('UPDATE device_schedules SET last_executed_at = NOW() WHERE id = ?');
        $stmt->execute([$t['id']]);
        if ($t['repeat_mode'] === 'once') {
            $stmt = $db->prepare("UPDATE device_schedules SET status = 'done' WHERE id = ?");
            $stmt->execute([$t['id']]);
        }
    }

    // ===== 2. 延时关闭（delay_off）：检查已打开的开关是否超时 =====
    $stmt = $db->prepare("SELECT id, target_type, target_id, delay_seconds
        FROM device_schedules
        WHERE device_id = ? AND status = 'active' AND type = 'delay_off'
    ");
    $stmt->execute([$deviceId]);
    $delayOffs = $stmt->fetchAll();

    foreach ($delayOffs as $d) {
        $delaySec = (int)$d['delay_seconds'];
        if ($delaySec <= 0) continue;

        if ($d['target_type'] === 'pin') {
            // 单个引脚：检查是否已开且超过延时
            $stmt = $db->prepare('SELECT state, updated_at FROM device_status WHERE device_id = ? AND pin = ?');
            $stmt->execute([$deviceId, (int)$d['target_id']]);
            $st = $stmt->fetch();
            if ($st && (int)$st['state'] === 1) {
                $elapsed = time() - strtotime($st['updated_at']);
                if ($elapsed >= $delaySec) {
                    $stmt = $db->prepare('INSERT INTO device_status (device_id, pin, state) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE state = 0');
                    $stmt->execute([$deviceId, (int)$d['target_id']]);
                }
            }
        } else {
            // 组合开关：检查所有引脚，任一已开且最早的变更时间超过延时 → 全部关闭
            $stmt = $db->prepare('SELECT pin FROM device_switch_combo_pins WHERE switch_combo_id = ?');
            $stmt->execute([(int)$d['target_id']]);
            $pins = $stmt->fetchAll();
            $anyOn = false;
            $oldestTime = time();
            foreach ($pins as $p) {
                $stmt = $db->prepare('SELECT state, updated_at FROM device_status WHERE device_id = ? AND pin = ?');
                $stmt->execute([$deviceId, (int)$p['pin']]);
                $st = $stmt->fetch();
                if ($st && (int)$st['state'] === 1) {
                    $anyOn = true;
                    $changeTime = strtotime($st['updated_at']);
                    if ($changeTime < $oldestTime) $oldestTime = $changeTime;
                }
            }
            if ($anyOn && (time() - $oldestTime) >= $delaySec) {
                foreach ($pins as $p) {
                    $stmt = $db->prepare('INSERT INTO device_status (device_id, pin, state) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE state = 0');
                    $stmt->execute([$deviceId, (int)$p['pin']]);
                }
            }
        }
    }
}

// =====================================================
//  自定义指令管理（需 JWT 认证）
//  GET    /schedules?device_id=xxx    指令列表
//  POST   /schedules                  创建指令
//  DELETE /schedules/{id}             删除指令
//  PUT    /schedules/{id}/toggle      暂停/恢复
// =====================================================
function handleSchedules(PDO $db, string $method, array $parts, array $body, array $user): void
{
    // GET /schedules?device_id=xxx
    if ($method === 'GET' && count($parts) === 1) {
        $deviceId = $_GET['device_id'] ?? '';
        if ($deviceId === '') jsonError(400, '缺少 device_id 参数');
        if (!canAccessDevice($db, $deviceId, $user)) jsonError(403, '无权访问此设备');

        $stmt = $db->prepare('SELECT * FROM device_schedules WHERE device_id = ? ORDER BY id DESC');
        $stmt->execute([$deviceId]);
        $schedules = $stmt->fetchAll();

        // 关联引脚/组合开关名称，方便前端显示
        foreach ($schedules as &$s) {
            if ($s['target_type'] === 'pin') {
                $stmt = $db->prepare('SELECT name FROM device_pins WHERE device_id = ? AND pin = ?');
                $stmt->execute([$deviceId, (int)$s['target_id']]);
                $pinInfo = $stmt->fetch();
                $s['target_name'] = $pinInfo ? $pinInfo['name'] : 'GPIO' . $s['target_id'];
            } else if ($s['target_type'] === 'switch_combo') {
                $stmt = $db->prepare('SELECT name FROM device_switch_combos WHERE id = ?');
                $stmt->execute([(int)$s['target_id']]);
                $comboInfo = $stmt->fetch();
                $s['target_name'] = $comboInfo ? $comboInfo['name'] : '组合开关#' . $s['target_id'];
            } else {
                $s['target_name'] = '';
            }
        }
        unset($s);

        jsonResponse(200, 'ok', $schedules);
    }

    // POST /schedules - 创建指令
    if ($method === 'POST' && count($parts) === 1) {
        $deviceId = trim($body['device_id'] ?? '');
        $type = trim($body['type'] ?? '');
        $targetType = trim($body['target_type'] ?? 'pin');
        $targetId = (int)($body['target_id'] ?? 0);
        $targetState = (int)($body['target_state'] ?? 0);
        $executeAt = trim($body['execute_at'] ?? '00:00');
        $executeDate = trim($body['execute_date'] ?? '');
        $delaySeconds = (int)($body['delay_seconds'] ?? 0);
        $repeatMode = trim($body['repeat_mode'] ?? 'once');

        if ($deviceId === '') jsonError(400, '缺少 device_id');
        if (!canAccessDevice($db, $deviceId, $user)) jsonError(403, '无权操作此设备');
        if (!in_array($type, ['timer', 'delay_off', 'reboot'], true)) jsonError(400, '指令类型无效');
        if (!in_array($repeatMode, ['once', 'daily'], true)) jsonError(400, '重复模式无效');

        // 类型特定校验
        if ($type === 'timer') {
            if (!in_array($targetType, ['pin', 'switch_combo'], true)) jsonError(400, '目标类型无效');
            if ($targetId <= 0) jsonError(400, '目标无效');
            if (!in_array($targetState, [0, 1], true)) jsonError(400, '目标状态无效');
            if ($repeatMode === 'once' && $executeDate === '') jsonError(400, '单次任务需要指定日期');
        } else if ($type === 'delay_off') {
            if (!in_array($targetType, ['pin', 'switch_combo'], true)) jsonError(400, '目标类型无效');
            if ($targetId <= 0) jsonError(400, '目标无效');
            if ($delaySeconds < 1) jsonError(400, '延时秒数必须大于0');
            $repeatMode = 'daily'; // delay_off 持续生效
        } else if ($type === 'reboot') {
            $targetType = 'pin'; // reboot 不需要目标
            $targetId = 0;
            $targetState = 0;
            if ($repeatMode === 'once' && $executeDate === '') jsonError(400, '单次任务需要指定日期');
        }

        // 校验目标存在性
        if ($type !== 'reboot' && $targetId > 0) {
            if ($targetType === 'pin') {
                $stmt = $db->prepare('SELECT id FROM device_pins WHERE device_id = ? AND pin = ?');
                $stmt->execute([$deviceId, $targetId]);
                if (!$stmt->fetch()) jsonError(400, '引脚不存在');
            } else {
                $stmt = $db->prepare('SELECT id FROM device_switch_combos WHERE id = ? AND device_id = ?');
                $stmt->execute([$targetId, $deviceId]);
                if (!$stmt->fetch()) jsonError(400, '组合开关不存在');
            }
        }

        // 格式化时间
        if (strlen($executeAt) === 5) $executeAt .= ':00'; // HH:MM → HH:MM:SS
        $executeDateSql = ($repeatMode === 'once' && $executeDate) ? $executeDate : null;

        $stmt = $db->prepare('INSERT INTO device_schedules
            (device_id, type, target_type, target_id, target_state, execute_at, execute_date, delay_seconds, repeat_mode, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "active", ?)
        ');
        $stmt->execute([$deviceId, $type, $targetType, $targetId, $targetState, $executeAt, $executeDateSql, $delaySeconds, $repeatMode, $user['uid']]);

        $schedId = (int)$db->lastInsertId();
        $typeText = ['timer' => '定时开关', 'delay_off' => '延时关闭', 'reboot' => '定时重启'];
        jsonResponse(201, $typeText[$type] . ' 指令已创建', ['id' => $schedId]);
    }

    // DELETE /schedules/{id}
    if ($method === 'DELETE' && count($parts) === 2) {
        $schedId = (int)$parts[1];
        $stmt = $db->prepare('SELECT device_id, created_by FROM device_schedules WHERE id = ?');
        $stmt->execute([$schedId]);
        $sched = $stmt->fetch();
        if (!$sched) jsonError(404, '指令不存在');
        if (!canAccessDevice($db, $sched['device_id'], $user)) jsonError(403, '无权操作');

        $stmt = $db->prepare('DELETE FROM device_schedules WHERE id = ?');
        $stmt->execute([$schedId]);
        jsonResponse(200, '指令已删除');
    }

    // PUT /schedules/{id}/toggle - 暂停/恢复
    if ($method === 'PUT' && count($parts) === 3 && $parts[2] === 'toggle') {
        $schedId = (int)$parts[1];
        $stmt = $db->prepare('SELECT device_id, status FROM device_schedules WHERE id = ?');
        $stmt->execute([$schedId]);
        $sched = $stmt->fetch();
        if (!$sched) jsonError(404, '指令不存在');
        if (!canAccessDevice($db, $sched['device_id'], $user)) jsonError(403, '无权操作');

        $newStatus = $sched['status'] === 'active' ? 'paused' : 'active';
        $stmt = $db->prepare('UPDATE device_schedules SET status = ? WHERE id = ?');
        $stmt->execute([$newStatus, $schedId]);
        jsonResponse(200, $newStatus === 'active' ? '已恢复' : '已暂停');
    }

    jsonError(404, '指令接口不存在');
}
