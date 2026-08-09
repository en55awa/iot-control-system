<?php
/**
 * 物联网控制系统 - 设备 Key 管理模块（PHP版）
 * 
 * 功能：
 *   1. 生成 8 字节随机 Key
 *   2. 存储到 MySQL 数据库
 *   3. CLI 工具：生成/列表/删除/禁用/启用/验证
 * 
 * 用法（需先设置 secrets.php 中的 CLI_PASSWORD）：
 *   php key_manager.php <密码> generate [备注]
 *   php key_manager.php <密码> list
 *   php key_manager.php <密码> delete <key>
 *   php key_manager.php <密码> verify <key>
 *   php key_manager.php <密码> disable <key>
 *   php key_manager.php <密码> enable <key>
 */

require_once __DIR__ . '/config.php';

/**
 * 生成新的 8 字节设备 Key
 */
function generateKey(string $remark = ''): array {
    $db = getDB();
    
    do {
        $key = strtoupper(bin2hex(random_bytes(8)));
        $stmt = $db->prepare('SELECT COUNT(*) FROM device_keys WHERE `key` = ?');
        $stmt->execute([$key]);
        $exists = $stmt->fetchColumn() > 0;
    } while ($exists);
    
    $stmt = $db->prepare('INSERT INTO device_keys (`key`, remark) VALUES (?, ?)');
    $stmt->execute([$key, $remark]);
    
    $stmt = $db->prepare('SELECT * FROM device_keys WHERE `key` = ?');
    $stmt->execute([$key]);
    return $stmt->fetch();
}

/**
 * 列出所有 Key
 */
function listKeys(): array {
    $db = getDB();
    $stmt = $db->query('SELECT `key`, remark, status, created_at FROM device_keys ORDER BY id DESC');
    return $stmt->fetchAll();
}

/**
 * 删除 Key
 */
function deleteKey(string $key): bool {
    $db = getDB();
    $stmt = $db->prepare('DELETE FROM device_keys WHERE `key` = ?');
    $stmt->execute([strtoupper(trim($key))]);
    return $stmt->rowCount() > 0;
}

/**
 * 验证 Key 是否有效（供认证脚本调用）
 */
function verifyKey(string $key): bool {
    $key = strtoupper(trim($key));
    if (strlen($key) !== 16 || !ctype_xdigit($key)) {
        return false;
    }
    
    $db = getDB();
    $stmt = $db->prepare('SELECT status FROM device_keys WHERE `key` = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    
    return $row !== false && $row['status'] === 'active';
}

/**
 * 禁用 Key
 */
function disableKey(string $key): bool {
    $db = getDB();
    $stmt = $db->prepare('UPDATE device_keys SET status = "disabled" WHERE `key` = ?');
    $stmt->execute([strtoupper(trim($key))]);
    return $stmt->rowCount() > 0;
}

/**
 * 启用 Key
 */
function enableKey(string $key): bool {
    $db = getDB();
    $stmt = $db->prepare('UPDATE device_keys SET status = "active" WHERE `key` = ?');
    $stmt->execute([strtoupper(trim($key))]);
    return $stmt->rowCount() > 0;
}

// ============== CLI 入口 ==============
if (PHP_SAPI === 'cli') {
    // CLI 操作密码验证（从 secrets.php 读取）
    $cliPassword = defined('CLI_PASSWORD') ? CLI_PASSWORD : '';
    if (!$cliPassword) {
        echo "[错误] 未设置 CLI_PASSWORD，请在 secrets.php 中配置\n";
        exit(1);
    }
    if ($argc < 2) {
        echo "用法:\n";
        echo "  php key_manager.php <密码> generate [备注]\n";
        echo "  php key_manager.php <密码> list\n";
        echo "  php key_manager.php <密码> delete <key>\n";
        echo "  php key_manager.php <密码> verify <key>\n";
        echo "  php key_manager.php <密码> disable <key>\n";
        echo "  php key_manager.php <密码> enable <key>\n";
        exit(1);
    }
    $providedPass = $argv[1];
    if (!hash_equals($cliPassword, $providedPass)) {
        echo "[错误] CLI 密码错误\n";
        exit(1);
    }
    
    if ($argc < 3) {
        echo "用法:\n";
        echo "  php key_manager.php <密码> generate [备注]\n";
        echo "  php key_manager.php <密码> list\n";
        echo "  php key_manager.php <密码> delete <key>\n";
        echo "  php key_manager.php <密码> verify <key>\n";
        echo "  php key_manager.php <密码> disable <key>\n";
        echo "  php key_manager.php <密码> enable <key>\n";
        exit(1);
    }
    
    $cmd = strtolower($argv[2]);
    
    switch ($cmd) {
        case 'generate':
            $remark = $argc > 3 ? implode(' ', array_slice($argv, 3)) : '';
            $result = generateKey($remark);
            echo "已生成新 Key:\n";
            echo "  Key:   {$result['key']}\n";
            echo "  备注:  {$result['remark']}\n";
            echo "  时间:  {$result['created_at']}\n";
            echo "\n请将此 Key 填入 ESP8266 固件的 config.h 中 DEVICE_KEY 字段\n";
            break;
            
        case 'list':
            $keys = listKeys();
            if (empty($keys)) {
                echo "暂无已注册的 Key\n";
            } else {
                printf("%-20s %-10s %-20s %s\n", 'Key', '状态', '备注', '创建时间');
                echo str_repeat('-', 70) . "\n";
                foreach ($keys as $k) {
                    printf("%-20s %-10s %-20s %s\n", $k['key'], $k['status'], $k['remark'], $k['created_at']);
                }
            }
            break;
            
        case 'delete':
            if ($argc < 4) {
                echo "请指定要删除的 Key\n";
                exit(1);
            }
            echo deleteKey($argv[3]) ? "已删除 Key: {$argv[3]}\n" : "Key 不存在: {$argv[3]}\n";
            break;
            
        case 'verify':
            if ($argc < 4) {
                echo "请指定要验证的 Key\n";
                exit(1);
            }
            echo verifyKey($argv[3]) ? "Key 有效: {$argv[3]}\n" : "Key 无效或已禁用: {$argv[3]}\n";
            break;
            
        case 'disable':
            if ($argc < 4) {
                echo "请指定要禁用的 Key\n";
                exit(1);
            }
            echo disableKey($argv[3]) ? "已禁用 Key: {$argv[3]}\n" : "Key 不存在: {$argv[3]}\n";
            break;
            
        case 'enable':
            if ($argc < 4) {
                echo "请指定要启用的 Key\n";
                exit(1);
            }
            echo enableKey($argv[3]) ? "已启用 Key: {$argv[3]}\n" : "Key 不存在: {$argv[3]}\n";
            break;
            
        default:
            echo "未知命令: {$cmd}\n";
            exit(1);
    }
}