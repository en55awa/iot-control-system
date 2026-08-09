<?php
/**
 * 数据库迁移 - OTA 固件更新功能
 *
 * 用法：浏览器访问 upgrade_db_ota.php
 */

require_once __DIR__ . '/config.php';

try {
    $db = getDB();

    // OTA 固件表
    $db->exec("CREATE TABLE IF NOT EXISTS ota_firmware (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(32) NOT NULL,
        filename VARCHAR(100) NOT NULL,
        version VARCHAR(20) NOT NULL DEFAULT '',
        file_size INT UNSIGNED NOT NULL DEFAULT 0,
        md5 VARCHAR(32) NOT NULL DEFAULT '',
        status ENUM('pending','updating','done','failed') NOT NULL DEFAULT 'pending',
        uploaded_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_device_status (device_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 确保 firmware 目录存在
    $firmwareDir = __DIR__ . '/firmware/';
    if (!is_dir($firmwareDir)) {
        mkdir($firmwareDir, 0755, true);
    }

    // 保护 firmware 目录：放 .htaccess 禁止直接访问
    $htaccess = $firmwareDir . '.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Deny from all\n");
    }

    echo "[成功] OTA 数据库迁移完成\n";
    echo "  - ota_firmware 表已创建\n";
    echo "  - firmware/ 目录已创建\n";
    echo "\n请删除此文件。\n";

    // 自删除
    @unlink(__FILE__);
    echo "[已自动删除] upgrade_db_ota.php\n";

} catch (PDOException $e) {
    echo "[错误] " . $e->getMessage() . "\n";
}
