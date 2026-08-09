<?php
/**
 * 数据库迁移 - v4.35 自定义指令 + 开关隐藏
 *
 * 变更：
 *   1. device_pins 添加 hidden 字段（0=显示, 1=隐藏）
 *   2. device_status 确保 updated_at 字段为 ON UPDATE CURRENT_TIMESTAMP（延时关闭依赖此字段）
 *   3. 新建 device_schedules 表（定时开关/延时关闭/定时重启）
 *
 * 用法：浏览器访问 upgrade_db_v435.php
 */

require_once __DIR__ . '/config.php';

try {
    $db = getDB();

    // 1. device_pins 添加 hidden 字段
    try {
        $db->exec("ALTER TABLE device_pins ADD COLUMN hidden TINYINT NOT NULL DEFAULT 0 AFTER sort_order");
        echo "  - device_pins.hidden 字段已添加\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "  - device_pins.hidden 字段已存在\n";
        } else {
            throw $e;
        }
    }

    // 2. device_status 确保 updated_at 字段为 ON UPDATE CURRENT_TIMESTAMP
    try {
        $db->exec("ALTER TABLE device_status MODIFY COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        echo "  - device_status.updated_at 已设置为 ON UPDATE CURRENT_TIMESTAMP\n";
    } catch (PDOException $e) {
        // 如果 updated_at 列不存在则添加
        try {
            $db->exec("ALTER TABLE device_status ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            echo "  - device_status.updated_at 字段已添加\n";
        } catch (PDOException $e2) {
            echo "  - device_status.updated_at 跳过: " . $e2->getMessage() . "\n";
        }
    }

    // 3. 新建 device_schedules 表
    $db->exec("CREATE TABLE IF NOT EXISTS device_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(32) NOT NULL,
        type ENUM('timer','delay_off','reboot') NOT NULL,
        target_type ENUM('pin','switch_combo') NOT NULL DEFAULT 'pin',
        target_id INT NOT NULL DEFAULT 0,
        target_state TINYINT NOT NULL DEFAULT 0,
        execute_at TIME NOT NULL DEFAULT '00:00:00',
        execute_date DATE NULL DEFAULT NULL,
        delay_seconds INT NOT NULL DEFAULT 0,
        repeat_mode ENUM('once','daily') NOT NULL DEFAULT 'once',
        status ENUM('active','paused','done') NOT NULL DEFAULT 'active',
        last_executed_at TIMESTAMP NULL DEFAULT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_device_status (device_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  - device_schedules 表已创建\n";

    echo "\n[成功] v4.35 数据库迁移完成\n";
    echo "  - device_pins.hidden（开关隐藏）\n";
    echo "  - device_status.updated_at（延时关闭依赖）\n";
    echo "  - device_schedules（定时开关/延时关闭/定时重启）\n";
    echo "\n请删除此文件。\n";

    // 自删除
    @unlink(__FILE__);
    echo "[已自动删除] upgrade_db_v435.php\n";

} catch (PDOException $e) {
    echo "[错误] " . $e->getMessage() . "\n";
}
