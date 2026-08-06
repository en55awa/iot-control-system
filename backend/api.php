<?php
/**
 * 物联网控制系统 - API 路由入口 v3.30（多用户隔离 + 预设 + 组合开关 + OTA）
 *
 * 接口列表：
 *   GET  poll&device_id=xxx&key=xxx&wifi=xxx&rssi=xxx&ip=xxx   ESP8266 轮询+上报（公开）
 *   GET  ota/firmware/{id}.bin                                   固件文件下载（公开，带 key 验证）
 *   POST login                                                  登录
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
 *   DELETE ota/{id}                                              取消待更新的固件
 *   GET  keys                                                   Key 列表
 *   POST keys                                                   生成新 Key
 *   DELETE keys/{key}                                           删除 Key
 *   PUT  keys/{key}/disable                                     禁用 Key
 *   PUT  keys/{key}/enable                                      启用 Key
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

$allowedOrigin = getenv('CORS_ORIGIN') ?: 'YOUR_DOMAIN_HERE'; // 替换为你的域名，如 http://example.com
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

    // 向后兼容：确保 login_attempts 表存在
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            username VARCHAR(50) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ip_time (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {
    }

    // ===== 公开接口 =====
    if ($method === 'GET' && ($parts[0] ?? '') === 'version') {
        jsonResponse(200, 'ok', ['version' => 'v3.30']);
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

    // ===== JWT 认证 =====
    $user = JWT::fromHeader();
    if ($user === null) {
        jsonError(401, '未授权，请登录');
    }
    // 检查用户状态 + 密码因子（密码变更后旧 token 自动失效）
    $stmt = $db->prepare('SELECT status, role, password_hash FROM users WHERE id = ?');
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
    $user['role'] = $currentUser['role'];
    $user['status'] = $currentUser['status'];

    switch ($parts[0] ?? '') {
        case 'devices': handleDevices($db, $method, $parts, $body, $user); break;
        case 'combos':  handleCombos($db, $method, $parts, $body, $user); break;
        case 'switch-combos': handleSwitchCombos($db, $method, $parts, $body, $user); break;
        case 'ota':     handleOTA($db, $method, $parts, $body, $user); break;
        case 'keys':    handleKeys($db, $method, $parts, $body, $user); break;
        case 'user':    handleUser($db, $method, $parts, $body, $user); break;
        case 'admin':   handleAdmin($db, $method, $parts, $body, $user); break;
        case 'register': handleRegister($db, $body, $user); break;
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

    // ---- 更新设备最后在线时间 ----
    $stmt = $db->prepare('UPDATE device_keys SET last_seen = NOW() WHERE device_id = ?');
    $stmt->execute([$deviceId]);

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

    // ---- 检查是否有待更新的OTA固件 ----
    $stmt = $db->prepare('SELECT id, filename, md5, file_size, version FROM ota_firmware WHERE device_id = ? AND status = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$deviceId, 'pending']);
    $ota = $stmt->fetch();
    if ($ota) {
        // 标记为正在更新
        $stmt2 = $db->prepare('UPDATE ota_firmware SET status = ? WHERE id = ?');
        $stmt2->execute(['updating', $ota['id']]);
        $result[] = 'OTA:http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/api.php?route=ota/firmware/' . $ota['id'] . '.bin' . '&key=' . $key . '&md5=' . $ota['md5'];
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

    $stmt = $db->prepare('SELECT id, username, password_hash, role, status FROM users WHERE username = ?');
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
    $pf = substr($user['password_hash'], 0, 8);
    $token = JWT::encode([
        'uid' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role'],
        'pf' => $pf
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
                       IF(dk.last_seen IS NULL, 0, IF(dk.last_seen > DATE_SUB(NOW(), INTERVAL 10 SECOND), 1, 0)) AS online
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
                       IF(dk.last_seen IS NULL, 0, IF(dk.last_seen > DATE_SUB(NOW(), INTERVAL 10 SECOND), 1, 0)) AS online,
                       0 AS is_shared
                FROM device_keys dk
                LEFT JOIN users u ON u.id = dk.user_id
                WHERE dk.user_id = ?
                UNION
                SELECT dk.id, dk.device_id, dk.`key`, dk.remark, dk.status, dk.last_seen, dk.created_at, dk.user_id,
                       u.username as owner_name,
                       (SELECT COUNT(*) FROM device_pins WHERE device_id = dk.device_id) AS pin_count,
                       IF(dk.last_seen IS NULL, 0, IF(dk.last_seen > DATE_SUB(NOW(), INTERVAL 10 SECOND), 1, 0)) AS online,
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
            SELECT p.pin, p.name, p.sort_order, COALESCE(s.state, 0) AS state, s.updated_at
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

    $filePath = __DIR__ . '/firmware/' . $firmware['filename'];
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    // 返回固件文件
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $firmware['filename'] . '"');
    header('Content-Length: ' . filesize($filePath));
    header('X-MD5: ' . $firmware['md5']);
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

        $stmt = $db->prepare('SELECT id, device_id, version, filename, file_size, md5, status, created_at, updated_at FROM ota_firmware WHERE device_id = ? ORDER BY id DESC LIMIT 5');
        $stmt->execute([$deviceId]);
        $records = $stmt->fetchAll();

        // 格式化文件大小
        foreach ($records as &$r) {
            $r['file_size_text'] = $r['file_size'] >= 1024
                ? round($r['file_size'] / 1024, 1) . ' KB'
                : $r['file_size'] . ' B';
        }
        unset($r);

        jsonResponse(200, 'ok', $records);
    }

    // DELETE /ota/{id} - 取消待更新的固件
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

        // 只能删除 pending 或 failed 状态的
        if (!in_array($firmware['status'], ['pending', 'failed'], true)) {
            jsonError(400, '只能取消待更新或失败的固件任务');
        }

        // 删除文件
        $filePath = __DIR__ . '/firmware/' . $firmware['filename'];
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        $stmt = $db->prepare('DELETE FROM ota_firmware WHERE id = ?');
        $stmt->execute([$otaId]);

        jsonResponse(200, '固件已取消');
    }

    jsonError(404, 'OTA接口不存在');
}
