<?php
/**
 * 物联网控制系统 - API 路由入口（HTTP Polling 模式）
 * 
 * 接口列表：
 *   GET  poll&device_id=xxx&key=xxx&wifi=xxx&rssi=xxx&ip=xxx   ESP8266 轮询+上报（公开）
 *   POST login                                                  登录
 *   GET  devices                                                  设备列表
 *   GET  devices/{id}/pins                                        设备引脚配置
 *   POST devices/{id}/pins                                        添加引脚配置
 *   DELETE devices/{id}/pins/{pin}                                删除引脚配置
 *   POST devices/{id}/control                                     控制引脚
 *   GET  devices/{id}/logs                                        设备日志（WiFi数据）
 *   GET  keys                                                     Key 列表
 *   POST keys                                                     生成新 Key
 *   DELETE keys/{key}                                             删除 Key
 *   PUT  keys/{key}/disable                                       禁用 Key
 *   PUT  keys/{key}/enable                                        启用 Key
 *   POST user/password                                            修改密码
 *   POST user/username                                            修改用户名
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
        // 忽略，表可能已存在或权限问题
    }
    
    // ===== 公开接口 =====
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
    
    switch ($parts[0] ?? '') {
        case 'devices': handleDevices($db, $method, $parts, $body); break;
        case 'keys':    handleKeys($db, $method, $parts, $body); break;
        case 'user':    handleUser($db, $method, $parts, $body, $user); break;
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
    
    // 只要 rssi 不为 0 就记录日志（wifi 或 ip 为空时存默认值）
    if ($wifiRssi !== 0 || $wifiSsid !== '' || $ip !== '') {
        $stmt = $db->prepare('SELECT id FROM device_logs WHERE device_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND) LIMIT 1');
        $stmt->execute([$deviceId]);
        if (!$stmt->fetch()) {
            $ssidToStore = $wifiSsid !== '' ? $wifiSsid : 'unknown';
            $ipToStore = $ip !== '' ? $ip : '-';
            $stmt = $db->prepare('INSERT INTO device_logs (device_id, wifi_ssid, wifi_rssi, ip) VALUES (?, ?, ?, ?)');
            $stmt->execute([$deviceId, $ssidToStore, $wifiRssi, $ipToStore]);
            
            // 清理旧日志，每个设备只保留最近150条（使用 JOIN 避免子查询性能问题）
            $stmt = $db->prepare('DELETE d1 FROM device_logs d1 LEFT JOIN (SELECT id FROM device_logs WHERE device_id = ? ORDER BY id DESC LIMIT 150) d2 ON d1.id = d2.id WHERE d2.id IS NULL AND d1.device_id = ?');
            $stmt->execute([$deviceId, $deviceId]);
        }
    }
    
    // ---- 查询该设备的所有引脚及状态 ----
    // 从 device_pins 获取配置，JOIN device_status 获取当前状态
    $stmt = $db->prepare('
        SELECT p.pin, p.name, COALESCE(s.state, 0) AS state
        FROM device_pins p
        LEFT JOIN device_status s ON s.device_id = p.device_id AND s.pin = p.pin
        WHERE p.device_id = ?
        ORDER BY p.sort_order, p.pin
    ');
    $stmt->execute([$deviceId]);
    $rows = $stmt->fetchAll();
    
    // 格式: pin1:state1,pin2:state2,...
    // 例如: 0:1,2:0,5:1
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
    
    $stmt = $db->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        // 记录失败登录
        $stmt = $db->prepare('INSERT INTO login_attempts (ip, username) VALUES (?, ?)');
        $stmt->execute([$clientIp, $username]);
        jsonError(401, '用户名或密码错误');
    }
    
    // 登录成功，清理该 IP 的失败记录
    $stmt = $db->prepare('DELETE FROM login_attempts WHERE ip = ?');
    $stmt->execute([$clientIp]);
    
    $token = JWT::encode([
        'uid' => $user['id'],
        'username' => $user['username']
    ], 7 * 86400);
    
    jsonResponse(200, '登录成功', ['token' => $token]);
}

// =====================================================
//  设备相关
// =====================================================
function handleDevices(PDO $db, string $method, array $parts, array $body): void
{
    // GET /devices - 设备列表（含在线状态）
    if ($method === 'GET' && count($parts) === 1) {
        $stmt = $db->query('
            SELECT dk.id, dk.device_id, dk.`key`, dk.remark, dk.status, dk.last_seen, dk.created_at,
                   (SELECT COUNT(*) FROM device_pins WHERE device_id = dk.device_id) AS pin_count,
                   IF(dk.last_seen IS NULL, 0, IF(dk.last_seen > DATE_SUB(NOW(), INTERVAL 10 SECOND), 1, 0)) AS online
            FROM device_keys dk
            ORDER BY dk.id DESC
        ');
        jsonResponse(200, 'ok', $stmt->fetchAll());
    }
    
    if (count($parts) < 3) {
        jsonError(404, '设备接口不存在');
    }
    
    $deviceId = $parts[1];
    $subAction = $parts[2];
    
    // GET /devices/{id}/pins - 获取设备引脚配置
    if ($method === 'GET' && $subAction === 'pins') {
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
        $pin = (int)($body['pin'] ?? -1);
        $name = trim($body['name'] ?? '');
        $sortOrder = (int)($body['sort_order'] ?? 0);
        
        if ($pin < 0 || $pin > 255) {
            jsonError(400, '引脚号无效（0-255）');
        }
        if ($name === '') {
            jsonError(400, '请输入引脚名称');
        }
        
        // 检查设备存在
        $stmt = $db->prepare('SELECT id FROM device_keys WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        if (!$stmt->fetch()) {
            jsonError(404, '设备不存在');
        }
        
        try {
            $stmt = $db->prepare('INSERT INTO device_pins (device_id, pin, name, sort_order) VALUES (?, ?, ?, ?)');
            $stmt->execute([$deviceId, $pin, $name, $sortOrder]);
            
            // 同时初始化状态为 OFF
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
        $pin = (int)$parts[3];
        
        $stmt = $db->prepare('DELETE FROM device_pins WHERE device_id = ? AND pin = ?');
        $stmt->execute([$deviceId, $pin]);
        
        if ($stmt->rowCount() > 0) {
            // 同时删除状态记录
            $stmt = $db->prepare('DELETE FROM device_status WHERE device_id = ? AND pin = ?');
            $stmt->execute([$deviceId, $pin]);
            jsonResponse(200, '引脚已删除');
        }
        jsonError(404, '引脚不存在');
    }
    
    // POST /devices/{id}/control - 控制引脚
    if ($method === 'POST' && $subAction === 'control') {
        $pin = (int)($body['pin'] ?? -1);
        $state = (int)($body['state'] ?? -1);
        
        if ($pin < 0) { jsonError(400, '引脚号无效'); }
        if (!in_array($state, [0, 1], true)) { jsonError(400, '状态无效，只能是 0(OFF) 或 1(ON)'); }
        
        // 验证设备有效
        $stmt = $db->prepare('SELECT status FROM device_keys WHERE device_id = ?');
        $stmt->execute([$deviceId]);
        $device = $stmt->fetch();
        if (!$device) { jsonError(404, '设备不存在'); }
        if ($device['status'] !== 'active') { jsonError(403, '设备已禁用'); }
        
        // 验证引脚已配置
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
    
    // GET /devices/{id}/logs - 设备日志（WiFi 数据）
    if ($method === 'GET' && $subAction === 'logs') {
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
    
    jsonError(404, '设备接口不存在');
}

// =====================================================
//  Key 管理
// =====================================================
function handleKeys(PDO $db, string $method, array $parts, array $body): void
{
    require_once __DIR__ . '/key_manager.php';
    
    if ($method === 'GET' && count($parts) === 1) {
        $keys = listKeys();
        jsonResponse(200, 'ok', $keys);
    }
    if ($method === 'POST' && count($parts) === 1) {
        $remark = $body['remark'] ?? '';
        $deviceId = $body['device_id'] ?? null;
        $result = generateKey($remark);
        if ($deviceId) {
            $stmt = $db->prepare('UPDATE device_keys SET device_id = ? WHERE `key` = ?');
            $stmt->execute([$deviceId, $result['key']]);
            $result['device_id'] = $deviceId;
        }
        jsonResponse(201, 'Key 已生成', $result);
    }
    if ($method === 'DELETE' && count($parts) === 2) {
        if (deleteKey($parts[1])) { jsonResponse(200, 'Key 已删除'); }
        jsonError(404, 'Key 不存在');
    }
    if ($method === 'PUT' && count($parts) === 3 && $parts[2] === 'disable') {
        if (disableKey($parts[1])) { jsonResponse(200, 'Key 已禁用'); }
        jsonError(404, 'Key 不存在');
    }
    if ($method === 'PUT' && count($parts) === 3 && $parts[2] === 'enable') {
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
        if ($newUsername === '' || strlen($newUsername) < 3) { jsonError(400, '用户名至少3位'); }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $newUsername)) { jsonError(400, '用户名只能包含字母、数字和下划线'); }
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['uid']]); $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) { jsonError(401, '密码错误'); }
        $stmt = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
        $stmt->execute([$newUsername, $user['uid']]);
        if ($stmt->fetch()) { jsonError(409, '该用户名已被使用'); }
        $stmt = $db->prepare('UPDATE users SET username = ? WHERE id = ?');
        $stmt->execute([$newUsername, $user['uid']]);
        jsonResponse(200, '用户名已修改', ['username' => $newUsername]);
    }
    jsonError(404, '用户接口不存在');
}

// =====================================================
function jsonResponse(int $code, string $message, $data = null): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['code' => $code, 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonError(int $code, string $message): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['code' => $code, 'message' => $message, 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}