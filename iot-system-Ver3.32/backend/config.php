<?php
/**
 * 物联网控制系统 - 数据库配置
 */

// 加载敏感配置（JWT密钥、CLI密码等）
$secretsFile = __DIR__ . '/secrets.php';
if (file_exists($secretsFile)) {
    require_once $secretsFile;
}

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', '你的数据库名');
define('DB_USER', '你的数据库用户名');
define('DB_PASS', '你的数据库密码');
define('DB_CHARSET', 'utf8mb4');

/**
 * 获取 PDO 数据库连接（PHP 5.5 兼容）
 * @return PDO
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
    }
    return $pdo;
}