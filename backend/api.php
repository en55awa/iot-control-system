<?php
/**
 * 物联网控制系统 - API 路由入口 v2.0（多用户隔离）
 *
 * 接口列表：
 *   GET  poll&device_id=xxx&key=xxx&wifi=xxx&rssi=xxx&ip=xxx   ESP8266 轮询+上报（公开）
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
        jsonResponse(200, 'ok', ['version' => 'v2.10']);
        exit;
    }
    if ($method === 'GET' && ($parts[0] ?? '') === 'poll') {
        handlePoll($db);
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
    // 检查用户是否被禁用
    $stmt = $db->prepare('SELECT status, role FROM users WHERE id = ?');
    $stmt->execute([$user['uid']]);
    $currentUser = $stmt->fetch();
    if (!$currentUser || $currentUser['status'] !== 'active') {
        jsonError(403, '账号已被禁用');
    }
    $user['role'] = $currentUser['role'];
    $user['status'] = $currentUser['status'];

    switch ($parts[0] ?? '') {
        case 'devices': handleDevices($db, $method, $parts, $body, $user); break;
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

    // Token 不过期（10 年有效期）
    $token = JWT::encode([
        'uid' => $user['id'],
        'username' => $user['username'],
        'role' => $user['role']
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
