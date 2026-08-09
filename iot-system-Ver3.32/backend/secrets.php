<?php
/**
 * 敏感配置文件
 * 
 * 部署后务必修改以下值，且不要将此文件提交到版本控制。
 * 已受 .htaccess 保护，禁止通过 HTTP 直接访问。
 */

// JWT 签名密钥（至少 32 字节随机字符串）
if (!defined('JWT_SECRET')) {
    define('JWT_SECRET', '请替换为你的64位随机字符串');
}

// CLI Key 管理密码
if (!defined('CLI_PASSWORD')) {
    define('CLI_PASSWORD', '请替换为你的CLI操作密码');
}
