<?php
/**
 * 数据库迁移脚本 - v4.50
 * 功能：创建设备事件表 device_events + device_keys 表加 firmware_version 字段
 * 执行方式：上传到服务器后浏览器访问一次，自动执行后自毁
 */

require_once __DIR__ . '/config.php';

try {
    $db = getDB();

    // 1. 创建设备事件表
    $db->exec("
        CREATE TABLE IF NOT EXISTS device_events (
            id INT(11) NOT NULL AUTO_INCREMENT,
            device_id VARCHAR(32) NOT NULL COMMENT '设备ID',
            event_type VARCHAR(32) NOT NULL COMMENT '事件类型：boot/restart/wifi_connect/wifi_disconnect/ota_start/ota_success/ota_failed/pin_change',
            details TEXT DEFAULT NULL COMMENT '事件详情（JSON格式）',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_device_time (device_id, created_at),
            KEY idx_event_type (event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设备事件日志表'
    ");

    echo "1. device_events 表已创建\n";

    // 2. device_keys 表加 firmware_version 字段
    $check = $db->query("SHOW COLUMNS FROM device_keys LIKE 'firmware_version'");
    if (!$check->fetch()) {
        $db->exec("ALTER TABLE device_keys ADD COLUMN firmware_version VARCHAR(20) NOT NULL DEFAULT '' COMMENT '固件版本号' AFTER `status`");
        echo "2. device_keys.firmware_version 字段已添加\n";
    } else {
        echo "2. device_keys.firmware_version 字段已存在，跳过\n";
    }

    echo "\n数据库迁移完成！\n";

    // 自毁
    @unlink(__FILE__);
    echo "\n脚本已自毁\n";

} catch (PDOException $e) {
    die("迁移失败: " . $e->getMessage());
}
