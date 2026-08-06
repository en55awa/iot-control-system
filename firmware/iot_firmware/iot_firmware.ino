/**
 * 物联网控制系统 - ESP8266 固件（动态引脚 + WiFi 上报）
 * 
 * 工作原理：
 *   1. 连接 WiFi
 *   2. 每隔 POLL_INTERVAL 毫秒向服务器 GET 轮询
 *   3. 服务器返回引脚配置及状态（动态，由 Web 端配置）
 *   4. 根据返回状态设置对应引脚
 *   5. 同时上报 WiFi 名称、信号强度、IP
 * 
 * 响应格式（纯文本，逗号分隔）：
 *   pin1:state1,pin2:state2,...
 *   例如: 0:1,2:0,5:1
 *   空响应 = 无引脚配置
 * 
 * 引脚逻辑：HIGH = OFF，LOW = ON（继电器模块常见接法）
 * 
 * 依赖库（Arduino IDE 内置，无需额外安装）
 */

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include "config.h"

WiFiClient   wifiClient;
HTTPClient   httpClient;

unsigned long lastPoll = 0;

// ============== WiFi ==============
void setupWiFi() {
  Serial.printf("[WiFi] 连接 %s", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  int t = 0;
  while (WiFi.status() != WL_CONNECTED && t < 40) {
    delay(500);
    Serial.print(".");
    t++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf(" OK IP:%s RSSI:%d\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
  } else {
    Serial.println("\n[WiFi] 失败,5s后重试");
  }
}

// ============== 解析并执行引脚控制 ==============
void applyPinStates(String body) {
  body.trim();
  if (body.length() == 0) return;

  int pos = 0;
  while (pos < body.length()) {
    int comma = body.indexOf(',', pos);
    String pair = (comma == -1) ? body.substring(pos) : body.substring(pos, comma);
    pos = (comma == -1) ? body.length() : comma + 1;

    int colon = pair.indexOf(':');
    if (colon < 1) continue;

    int pin = pair.substring(0, colon).toInt();
    int state = pair.substring(colon + 1).toInt();

    // 确保引脚已初始化为输出模式
    pinMode(pin, OUTPUT);
    // HIGH = OFF, LOW = ON
    digitalWrite(pin, state ? LOW : HIGH);
  }
}

// ============== HTTP 轮询 + WiFi 上报 ==============
void doPoll() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[WiFi] 断开，尝试重连...");
    setupWiFi();
    return;
  }

  // 构建带 WiFi 信息的 URL
  String ssid = WiFi.SSID();
  String ip = WiFi.localIP().toString();
  String url = String("http://") + SERVER_HOST + "/api.php?route=poll"
    + "&device_id=" + DEVICE_ID
    + "&key=" + DEVICE_KEY
    + "&wifi=" + urlencode(ssid.c_str())
    + "&rssi=" + String(WiFi.RSSI())
    + "&ip=" + ip;
  
  httpClient.begin(wifiClient, url);
  httpClient.setTimeout(3000);
  httpClient.addHeader("Accept", "text/plain");
  
  int code = httpClient.GET();
  
  if (code == 200) {
    String body = httpClient.getString();
    applyPinStates(body);
  } else if (code > 0) {
    Serial.printf("[HTTP] 错误码: %d\n", code);
  } else {
    Serial.printf("[HTTP] 请求失败: %s\n", httpClient.errorToString(code).c_str());
  }
  
  httpClient.end();
}

// ============== URL 编码（RFC 3986） ==============
String urlencode(const char* str) {
  String encoded = "";
  while (*str) {
    char c = *str++;
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') {
      encoded += c;
    } else {
      char buf[4];
      sprintf(buf, "%%%02X", (unsigned char)c);
      encoded += buf;
    }
  }
  return encoded;
}

// ============== 入口 ==============
void setup() {
  Serial.begin(SERIAL_BAUD);
  Serial.println("\n====== IoT Boot (Dynamic Pins) ======");
  Serial.printf("[ID] %s  [KEY] %.8s...\n", DEVICE_ID, DEVICE_KEY);
  setupWiFi();
}

void loop() {
  unsigned long now = millis();
  if (now - lastPoll >= POLL_INTERVAL) {
    lastPoll = now;
    doPoll();
  }
  delay(10);
}