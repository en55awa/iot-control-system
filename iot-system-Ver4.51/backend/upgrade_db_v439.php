<?php
/**
 * 数据库迁移 - v4.48 登录记录功能增强
 *
 * 变更：
 *   1. login_attempts 表扩展：新增 user_id、password_hash、status 字段，从纯失败记录表升级为全量登录日志表
 *   2. 新建 tokenless_logs 表（免密登录记录：Token 认证成功）
 *
 * 用法：浏览器访问 upgrade_db_v439.php
 */

require_once __DIR__ . '/config.php';

try {
    $db = getDB();

    // 1. login_attempts 表添加新字段（从失败记录表升级为全量登录日志表）
    try {
        $db->exec("ALTER TABLE login_attempts ADD COLUMN user_id INT DEFAULT NULL AFTER username");
        echo "  - login_attempts.user_id 字段已添加\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "  - login_attempts.user_id 字段已存在\n";
        } else {
            throw $e;
        }
    }

    try {
        $db->exec("ALTER TABLE login_attempts ADD COLUMN password_hash VARCHAR(255) DEFAULT NULL AFTER user_id");
        echo "  - login_attempts.password_hash 字段已添加\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "  - login_attempts.password_hash 字段已存在\n";
        } else {
            throw $e;
        }
    }

    try {
        $db->exec("ALTER TABLE login_attempts ADD COLUMN status ENUM('success','failed','blocked','disabled') NOT NULL DEFAULT 'failed' AFTER password_hash");
        echo "  - login_attempts.status 字段已添加\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "  - login_attempts.status 字段已存在\n";
        } else {
            throw $e;
        }
    }

    try {
        $db->exec("ALTER TABLE login_attempts ADD INDEX idx_user (user_id)");
        echo "  - login_attempts.idx_user 索引已添加\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "  - login_attempts.idx_user 索引已存在\n";
        } else {
            throw $e;
        }
    }

    try {
        $db->exec("ALTER TABLE login_attempts ADD INDEX idx_status (status)");
        echo "  - login_attempts.idx_status 索引已添加\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'already exists') !== false) {
            echo "  - login_attempts.idx_status 索引已存在\n";
        } else {
            throw $e;
        }
    }

    // 2. 新建 tokenless_logs 表（免密登录记录）
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS tokenless_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                username VARCHAR(50) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "  - tokenless_logs 表已创建\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "  - tokenless_logs 表已存在\n";
        } else {
            throw $e;
        }
    }

    echo "\n数据库迁移完成！\n";

    // 执行完成后自动删除自身
    $selfFile = __FILE__;
    @unlink($selfFile);

} catch (PDOException $e) {
    echo "数据库错误: " . $e->getMessage() . "\n";
    exit(1);
}
