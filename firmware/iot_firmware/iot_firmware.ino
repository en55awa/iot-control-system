/**
 * 物联网控制系统 - ESP8266 固件（动态引脚 + WiFi 上报 + OTA 更新 + 服务端指令）
 * v4.35: 新增服务端指令解析（CMD:reboot 定时重启），开关隐藏，自定义指令（定时开关/延时关闭/定时重启）
 * 
 * 工作原理：
 *   1. 连接 WiFi
 *   2. 每隔 POLL_INTERVAL 毫秒向服务器 GET 轮询
 *   3. 服务器返回引脚配置及状态（动态，由 Web 端配置）
 *   4. 根据返回状态设置对应引脚
 *   5. 同时上报 WiFi 名称、信号强度、IP
 *   6. 若轮询响应中包含 OTA:url，则自动下载并更新固件
 *   7. OTA 更新成功后直接重启，服务端通过轮询间隙自动检测更新结果
 *   8. 若轮询响应中包含 CMD:reboot，则按服务端指令执行重启（定时重启功能）
 * 
 * 响应格式（纯文本，逗号分隔）：
 *   pin1:state1,pin2:state2,...,OTA:http://xxx/firmware.bin&key=xxx&md5=xxx
 *   空响应 = 无引脚配置
 * 
 * 引脚逻辑：HIGH = OFF，LOW = ON（继电器模块常见接法）
 * 
 * 依赖库（Arduino IDE 内置，无需额外安装）
 */

#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>
#include <ESP8266httpUpdate.h>
#include "config.h"

WiFiClient   wifiClient;
HTTPClient   httpClient;

unsigned long lastPoll = 0;
bool otaInProgress = false;

// ============== WiFi ==============
void setupWiFi() {
  Serial.printf("[WiFi] 连接 %s", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.persistent(false);           // 不写 Flash，避免磨损
  WiFi.setSleepMode(WIFI_NONE_SLEEP); // 关闭省电模式，防止间歇性断连
  WiFi.setAutoReconnect(true);      // WiFi 断开后自动重连（后台进行，不阻塞）
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  int t = 0;
  while (WiFi.status() != WL_CONNECTED && t < 20) {  // 最多等 10 秒（原 20 秒太长）
    delay(500);
    Serial.print(".");
    t++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf(" OK IP:%s RSSI:%d\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
  } else {
    Serial.println("\n[WiFi] 首次连接失败，自动重连已开启，将继续尝试...");
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

// ============== 检查并执行 OTA 更新 ==============
void checkAndDoOTA(String body) {
  // 查找 OTA:url 标记
  int otaIdx = body.indexOf("OTA:");
  if (otaIdx == -1) return;

  String otaUrl = body.substring(otaIdx + 4);
  // URL 中可能包含逗号后面的内容（不会，OTA 是最后一项），但以防万一
  int commaIdx = otaUrl.indexOf(',');
  if (commaIdx > 0) {
    otaUrl = otaUrl.substring(0, commaIdx);
  }
  otaUrl.trim();

  if (otaUrl.length() < 10) {
    Serial.println("[OTA] URL 无效");
    return;
  }

  Serial.println("\n====== OTA 更新开始 ======");
  Serial.printf("[OTA] 下载: %s\n", otaUrl.c_str());

  otaInProgress = true;

  // 设置回调
  ESPhttpUpdate.onStart([]() {
    Serial.println("[OTA] 开始写入 Flash...");
  });
  ESPhttpUpdate.onEnd([]() {
    Serial.println("\n[OTA] 写入完成!");
  });
  ESPhttpUpdate.onProgress([](unsigned int progress, unsigned int total) {
    static unsigned int lastPercent = 100;
    unsigned int percent = progress / (total / 100);
    if (percent != lastPercent) {
      lastPercent = percent;
      Serial.printf("[OTA] 进度: %u%%\n", percent);
    }
  });
  ESPhttpUpdate.onError([](int err) {
    Serial.printf("[OTA] 错误[%d]: %s\n", err, ESPhttpUpdate.getLastErrorString().c_str());
  });

  WiFiClient updateClient;
  t_httpUpdate_return ret = ESPhttpUpdate.update(updateClient, otaUrl);

  switch (ret) {
    case HTTP_UPDATE_FAILED: {
      Serial.printf("[OTA] 更新失败 (%d): %s\n", ESPhttpUpdate.getLastError(), ESPhttpUpdate.getLastErrorString().c_str());
      Serial.println("[OTA] 服务端将在5分钟后自动判定超时失败");
      otaInProgress = false;
      break;
    }
    case HTTP_UPDATE_NO_UPDATES:
      Serial.println("[OTA] 无可用更新");
      otaInProgress = false;
      break;
    case HTTP_UPDATE_OK:
      Serial.println("[OTA] 更新成功! 500ms 后重启...");
      Serial.println("[OTA] 服务端将通过轮询间隙自动检测重启并标记成功");
      // 直接重启，无需回报（服务端通过轮询间隙 >= 10秒自动判断 OTA 成功）
      delay(500);
      ESP.restart();
      break;
  }
}

// ============== 检查并执行服务端指令 ==============
void checkAndDoCommand(String body) {
  // 查找 CMD: 标记
  int cmdIdx = body.indexOf("CMD:");
  if (cmdIdx == -1) return;

  String cmd = body.substring(cmdIdx + 4);
  // 截取到逗号或行尾
  int commaIdx = cmd.indexOf(',');
  if (commaIdx > 0) {
    cmd = cmd.substring(0, commaIdx);
  }
  cmd.trim();

  if (cmd == "reboot") {
    Serial.println("[CMD] 收到重启指令，500ms 后重启...");
    delay(500);
    ESP.restart();
  } else {
    Serial.printf("[CMD] 未知指令: %s\n", cmd.c_str());
  }
}

// ============== HTTP 轮询 + WiFi 上报 ==============
void doPoll() {
  if (WiFi.status() != WL_CONNECTED) {
    // WiFi 断开：自动重连已在后台进行，跳过本次轮询等待下次
    Serial.println("[WiFi] 未连接，等待自动重连...");
    return;
  }

  // 如果 OTA 正在进行中，跳过普通轮询
  if (otaInProgress) {
    delay(100);
    return;
  }

  // 构建带 WiFi 信息的 URL（v3.33: 不再需要 ota_id/ota_success 参数）
  String ssid = WiFi.SSID();
  String ip = WiFi.localIP().toString();
  String url = String("http://") + SERVER_HOST + "/api.php?route=poll"
    + "&device_id=" + DEVICE_ID
    + "&key=" + DEVICE_KEY
    + "&wifi=" + urlencode(ssid.c_str())
    + "&rssi=" + String(WiFi.RSSI())
    + "&ip=" + ip;

  httpClient.begin(wifiClient, url);
  httpClient.setTimeout(5000);  // 5 秒超时（原 3 秒对共享主机太短）
  httpClient.addHeader("Accept", "text/plain");
  
  int code = httpClient.GET();
  
  if (code == 200) {
    String body = httpClient.getString();
    applyPinStates(body);
    // 检查是否有服务端指令（如定时重启）
    checkAndDoCommand(body);
    // 检查是否有 OTA 更新
    checkAndDoOTA(body);
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
  Serial.println("\n====== IoT Boot (Dynamic Pins + OTA + CMD v4.35) ======");
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
