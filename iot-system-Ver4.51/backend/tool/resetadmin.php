<?php
/**
 * 管理员密码重置工具
 *
 * 用途：忘记管理员密码时，将第一个管理员账号的密码重置为 88888888
 *
 * 用法：
 *   1. 命令行：php resetadmin.php
 *   2. 浏览器（服务器本地）：http://localhost/resetadmin.php
 *   3. 浏览器（远程带密钥）：http://your-domain/resetadmin.php?key=passwd
 *
 * 安全提示：
 *   - 使用完毕后务必删除此文件
 *   - 重置成功后立即登录并修改密码
 */

require_once __DIR__ . '/config.php';

// 远程访问密钥（建议修改为一个随机字符串“passwd”）
define('RESET_KEY', 'passwd');

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$isCli = PHP_SAPI === 'cli';
$isLocal = in_array($clientIp, ['127.0.0.1', '::1', 'localhost'], true);
$hasKey = isset($_GET['key']) && $_GET['key'] === RESET_KEY;

if (!$isCli && !$isLocal && !$hasKey) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "[403] 禁止访问。\n\n";
    echo "如需远程重置，请访问：\n";
    echo "  resetadmin.php?key=" . RESET_KEY . "\n\n";
    echo "或在服务器命令行执行：\n";
    echo "  php resetadmin.php\n";
    exit;
}

try {
    $db = getDB();

    // 查找管理员账号
    $stmt = $db->query('SELECT id, username FROM users WHERE role = "admin" ORDER BY id ASC LIMIT 1');
    $admin = $stmt->fetch();

    if (!$admin) {
        echo "[错误] 未找到管理员账号\n";
        exit(1);
    }

    $newPassword = '88888888';
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->execute([$hash, $admin['id']]);

    if ($stmt->rowCount() > 0) {
        echo "[成功] 管理员密码已重置\n";
        echo "  用户名: {$admin['username']}\n";
        echo "  新密码: {$newPassword}\n";
        echo "\n请立即登录并修改密码。\n";

        // 自删除
        $self = __FILE__;
        @unlink($self);
        echo "[已自动删除本重置文件] resetadmin.php\n";
    } else {
        echo "[警告] 密码未变更\n";
    }
} catch (PDOException $e) {
    echo "[错误] 数据库操作失败: " . $e->getMessage() . "\n";
    exit(1);
}
