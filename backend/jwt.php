<?php
/**
 * JWT 工具类（无外部依赖）
 * 
 * 实现 HS256 签名的 JWT，足够用于本项目。
 * JWT 结构: header.payload.signature
 * 
 * payload 示例: {"uid":1,"exp":1690000000}
 */

require_once __DIR__ . '/config.php';

class JWT
{
    // 密钥从 secrets.php 的常量读取，部署后务必修改 secrets.php
    private static function getSecret(): string
    {
        if (defined('JWT_SECRET') && strlen(JWT_SECRET) >= 32) {
            return JWT_SECRET;
        }
        // Fallback：仅在开发环境使用，生产环境必须配置 secrets.php
        return 'iot-system-fallback-secret-do-not-use-in-production-64chars-long!!';
    }
    
    /**
     * 生成 JWT
     * @param array $payload 数据
     * @param int $ttl 有效期秒数
     * @return string
     */
    public static function encode(array $payload, int $ttl = 86400): string
    {
        $payload['iat'] = time();
        $payload['exp'] = time() + $ttl;
        
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        
        $base64Header  = self::base64UrlEncode(json_encode($header));
        $base64Payload = self::base64UrlEncode(json_encode($payload));
        
        $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, self::getSecret(), true);
        $base64Signature = self::base64UrlEncode($signature);
        
        return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
    }
    
    /**
     * 验证并解码 JWT
     * @param string $token
     * @return array|null 失败返回 null
     */
    public static function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        [$base64Header, $base64Payload, $base64Signature] = $parts;
        
        // 验证签名
        $expectedSignature = self::base64UrlEncode(
            hash_hmac('sha256', $base64Header . '.' . $base64Payload, self::getSecret(), true)
        );
        
        if (!hash_equals($expectedSignature, $base64Signature)) {
            return null;
        }
        
        $payload = json_decode(self::base64UrlDecode($base64Payload), true);
        if (!is_array($payload)) {
            return null;
        }
        
        // 验证过期时间
        if (isset($payload['exp']) && time() > $payload['exp']) {
            return null;
        }
        
        return $payload;
    }
    
    /**
     * 从 Authorization Header 提取并验证 JWT
     * @return array|null
     */
    public static function fromHeader(): ?array
    {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        
        if (strpos($auth, 'Bearer ') !== 0) {
            return null;
        }
        
        $token = substr($auth, 7);
        return self::decode($token);
    }
    
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}