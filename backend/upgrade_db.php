<?php
/**
 * 数据库升级脚本：多用户隔离 v2.0
 * 
 * 运行一次后删除
 */
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDB();

    // 1. users 表添加 role 和 status 字段
    echo "[1/5] 升级 users 表...\n";
    try {
        $db->exec("ALTER TABLE users ADD COLUMN role ENUM('admin','user') NOT NULL DEFAULT 'user' AFTER password_hash");
        echo "    + role 字段已添加\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "    ~ role 字段已存在\n";
        } else {
            throw $e;
        }
    }
    try {
        $db->exec("ALTER TABLE users ADD COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active' AFTER role");
        echo "    + status 字段已添加\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "    ~ status 字段已存在\n";
        } else {
            throw $e;
        }
    }
    // 将第一个用户设为管理员
    $stmt = $db->prepare("UPDATE users SET role = 'admin', status = 'active' WHERE id = 1");
    $stmt->execute();
    echo "    ~ 用户 ID=1 已设为管理员\n";

    // 2. device_keys 表添加 user_id 字段
    echo "[2/5] 升级 device_keys 表...\n";
    try {
        $db->exec("ALTER TABLE device_keys ADD COLUMN user_id INT NOT NULL DEFAULT 1 AFTER `key`");
        echo "    + user_id 字段已添加\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "    ~ user_id 字段已存在\n";
        } else {
            throw $e;
        }
    }
    // 添加外键
    try {
        $db->exec("ALTER TABLE device_keys ADD CONSTRAINT fk_device_keys_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
        echo "    + 外键已添加\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "    ~ 外键已存在\n";
        } else {
            echo "    ! 外键添加失败（可能已存在）: " . $e->getMessage() . "\n";
        }
    }

    // 3. 创建 device_shares 表
    echo "[3/5] 创建 device_shares 表...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS device_shares (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(32) NOT NULL,
        owner_id INT NOT NULL,
        shared_to_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_share (device_id, shared_to_id),
        KEY idx_device (device_id),
        KEY idx_owner (owner_id),
        KEY idx_shared (shared_to_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "    + device_shares 表已创建\n";

    // 4. 确保 login_attempts 表存在
    echo "[4/5] 检查 login_attempts 表...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip VARCHAR(45) NOT NULL,
        username VARCHAR(50) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ip_time (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "    + login_attempts 表已确认\n";

    echo "\n[5/5] 数据库升级完成！\n";
    echo "请删除本文件。\n";

} catch (PDOException $e) {
    echo "[错误] " . $e->getMessage() . "\n";
}
