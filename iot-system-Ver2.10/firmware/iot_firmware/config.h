#ifndef CONFIG_H
#define CONFIG_H

// ============== WiFi 配置 ==============
#define WIFI_SSID     "YOUR_WIFI_SSID"
#define WIFI_PASSWORD "YOUR_WIFI_PASSWORD"

// ============== 服务器配置 ==============
// 共享主机不支持 MQTT，改用 HTTP Polling
#define SERVER_HOST   "YOUR_SERVER_HOST"     // 如 "example.com"（不要 https://）
#define SERVER_PORT   80                   // HTTP 端口（共享主机通常用80）
#define POLL_INTERVAL 5000                 // 轮询间隔 毫秒（5秒=5000，可调2000-10000）

// ============== 设备认证 Key（8字节）=============
#define DEVICE_KEY    "YOUR_DEVICE_KEY_HERE"   // 16字符hex，由后端生成

// ============== 设备信息 ==============
#define DEVICE_ID     "esp001"

// ============== 调试 ==============
#define SERIAL_BAUD   9600

#endif