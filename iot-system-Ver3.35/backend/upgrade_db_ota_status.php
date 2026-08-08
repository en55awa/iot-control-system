<?php
/**
 * 数据库迁移 - OTA 服务端状态追踪（v3.33）
 *
 * device_keys 表新增：
 *   ota_status   TINYINT  0=正常 1=待更新 2=更新中 3=已更新 4=失败
 *   ota_sent_at  TIMESTAMP  OTA 下发时间（用于超时判断）
 *
 * 用法：浏览器访问 upgrade_db_ota_status.php
 */

require_once __DIR__ . '/config.php';

try {
    $db = getDB();

    // device_keys 表添加 ota_status 字段
    try {
        $db->exec("ALTER TABLE device_keys ADD COLUMN ota_status TINYINT NOT NULL DEFAULT 0 COMMENT 'OTA: 0=正常 1=待更新 2=更新中 3=已更新 4=失败' AFTER last_seen");
        echo "  - device_keys.ota_status 字段已添加\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "  - device_keys.ota_status 字段已存在\n";
        } else {
            throw $e;
        }
    }

    // device_keys 表添加 ota_sent_at 字段
    try {
        $db->exec("ALTER TABLE device_keys ADD COLUMN ota_sent_at TIMESTAMP NULL COMMENT 'OTA 下发时间戳' AFTER ota_status");
        echo "  - device_keys.ota_sent_at 字段已添加\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "  - device_keys.ota_sent_at 字段已存在\n";
        } else {
            throw $e;
        }
    }

    // 同步已有 ota_firmware 状态到 device_keys.ota_status
    $db->exec("UPDATE device_keys dk 
               INNER JOIN (
                   SELECT device_id, status 
                   FROM ota_firmware 
                   WHERE id IN (SELECT MAX(id) FROM ota_firmware GROUP BY device_id)
               ) latest ON dk.device_id = latest.device_id
               SET dk.ota_status = CASE latest.status
                   WHEN 'pending'  THEN 1
                   WHEN 'updating' THEN 2
                   WHEN 'done'     THEN 3
                   WHEN 'failed'   THEN 4
                   ELSE 0
               END");
    echo "  - 已同步 ota_firmware 状态到 device_keys.ota_status\n";

    echo "\n[成功] 数据库迁移完成\n";
    echo "  - device_keys.ota_status (TINYINT 0-4) 已添加\n";
    echo "  - device_keys.ota_sent_at (TIMESTAMP) 已添加\n";
    echo "  - 已有 OTA 记录状态已同步\n";
    echo "\n请删除此文件。\n";

    // 自删除
    @unlink(__FILE__);
    echo "[已自动删除] upgrade_db_ota_status.php\n";

} catch (PDOException $e) {
    echo "[错误] " . $e->getMessage() . "\n";
}
