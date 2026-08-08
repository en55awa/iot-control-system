<?php
/**
 * 物联网控制系统 - 数据库配置
 */

// 加载敏感配置（JWT密钥、CLI密码等）
$secretsFile = __DIR__ . '/secrets.php';
if (file_exists($secretsFile)) {
    require_once $secretsFile;
}

// ===== 防止 PHP 进程卡死（核心修复）=====
// ESP8266 每 5 秒轮询一次，如果某次请求卡住（数据库慢/网络阻塞），
// 没有超时保护的话 PHP 进程会永远挂在那里，占满共享主机进程池，
// 导致后续请求全部排队 → 设备掉线。
set_time_limit(10);                    // 脚本最多运行 10 秒（轮询通常 <1 秒）
ignore_user_abort(false);              // 客户端断开时终止脚本（防止 readfile 在连接关闭后继续阻塞）
ini_set('default_socket_timeout', 5);  // socket I/O 超时 5 秒（file_get_contents/readfile 等）

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'YOUR_DB_NAME');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');
define('DB_CHARSET', 'utf8mb4');

/**
 * 获取 PDO 数据库连接（PHP 5.5 兼容）
 * @return PDO
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        // connect_timeout=3：MySQL 连接超时 3 秒，防止数据库无响应时无限等待
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s;connect_timeout=3', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
        // 空闲连接 10 秒后自动断开，防止僵尸连接占用数据库连接数
        $pdo->exec('SET SESSION wait_timeout = 10');
    }
    return $pdo;
}