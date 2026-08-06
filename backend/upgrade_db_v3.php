<?php
/**
 * 数据库迁移 v2.0 → v3.0
 * 新增预设功能 + 组合开关功能
 *
 * 用法：浏览器访问 upgrade_db_v3.php
 */

require_once __DIR__ . '/config.php';

try {
    $db = getDB();

    // 预设表
    $db->exec("CREATE TABLE IF NOT EXISTS device_combos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(32) NOT NULL,
        user_id INT NOT NULL,
        name VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_device (device_id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 预设项表（一个预设包含多个引脚及目标状态）
    $db->exec("CREATE TABLE IF NOT EXISTS device_combo_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        combo_id INT NOT NULL,
        pin INT NOT NULL,
        state TINYINT NOT NULL DEFAULT 0,
        KEY idx_combo (combo_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 组合开关表（多个引脚合并为一个开关，同步开/关）
    $db->exec("CREATE TABLE IF NOT EXISTS device_switch_combos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(32) NOT NULL,
        user_id INT NOT NULL,
        name VARCHAR(50) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_device (device_id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 组合开关联动的引脚表
    $db->exec("CREATE TABLE IF NOT EXISTS device_switch_combo_pins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        switch_combo_id INT NOT NULL,
        pin INT NOT NULL,
        KEY idx_switch (switch_combo_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo "[成功] v3.0 数据库迁移完成\n";
    echo "  - device_combos 表已创建（预设）\n";
    echo "  - device_combo_items 表已创建（预设项）\n";
    echo "  - device_switch_combos 表已创建（组合开关）\n";
    echo "  - device_switch_combo_pins 表已创建（组合开关联动引脚）\n";
    echo "\n请删除此文件。\n";

    // 自删除
    @unlink(__FILE__);
    echo "[已自动删除] upgrade_db_v3.php\n";

} catch (PDOException $e) {
    echo "[错误] " . $e->getMessage() . "\n";
}
