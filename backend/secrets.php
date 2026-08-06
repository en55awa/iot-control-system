<?php
/**
 * 敏感配置文件
 * 
 * 部署后务必修改以下值，且不要将此文件提交到版本控制。
 * 已受 .htaccess 保护，禁止通过 HTTP 直接访问。
 */

// JWT 签名密钥（至少 32 字节随机字符串）
if (!defined('JWT_SECRET')) {
    define('JWT_SECRET', 'YOUR_JWT_SECRET_HERE_AT_LEAST_32_CHARS_LONG');
}

// CLI Key 管理密码
if (!defined('CLI_PASSWORD')) {
    define('CLI_PASSWORD', 'YOUR_JWT_SECRET_HERE_AT_LEAST_32_CHARS_LONG');
}
