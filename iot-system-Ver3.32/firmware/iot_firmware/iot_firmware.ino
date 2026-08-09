/**
 * 物联网控制系统 - ESP8266 固件（动态引脚 + WiFi 上报 + OTA 更新）
 * 
 * 工作原理：
 *   1. 连接 WiFi
 *   2. 每隔 POLL_INTERVAL 毫秒向服务器 GET 轮询
 *   3. 服务器返回引脚配置及状态（动态，由 Web 端配置）
 *   4. 根据返回状态设置对应引脚
 *   5. 同时上报 WiFi 名称、信号强度、IP
 *   6. 若轮询响应中包含 OTA:url，则自动下载并更新固件
 *   7. OTA 更新完成后，结果（成功/失败）存入变量，下次 poll 一并回报（不再单独 POST）
 *   8. 更新成功时：先 poll 回报，再重启
 * 
 * 响应格式（纯文本，逗号分隔）：
 *   pin1:state1,pin2:state2,...,OTA:http://xxx/firmware.bin&key=xxx&md5=xxx
 *   空响应 = 无引脚配置
 * 
 * poll 请求中可携带 OTA 回报参数：
 *   &ota_id=xxx&ota_success=0/1&ota_error=xxx
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

// OTA 回报待发送（更新完成后存入，下次 poll 一并上报）
int pendingOtaId = 0;
bool pendingOtaSuccess = false;
String pendingOtaError = "";
bool needRestartAfterReport = false;

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

  // 从 URL 中提取 ota_id（格式：.../firmware/{id}.bin&key=...）
  String otaIdStr = "";
  int fwIdx = otaUrl.indexOf("firmware/");
  if (fwIdx >= 0) {
    int idStart = fwIdx + 9;  // strlen("firmware/")
    int dotBin = otaUrl.indexOf(".bin", idStart);
    if (dotBin > idStart) {
      otaIdStr = otaUrl.substring(idStart, dotBin);
    }
  }

  Serial.println("\n====== OTA 更新开始 ======");
  Serial.printf("[OTA] 下载: %s\n", otaUrl.c_str());
  Serial.printf("[OTA] ID: %s\n", otaIdStr.length() ? otaIdStr.c_str() : "(未知)");

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
      String errMsg = ESPhttpUpdate.getLastErrorString();
      Serial.printf("[OTA] 更新失败 (%d): %s\n", ESPhttpUpdate.getLastError(), errMsg.c_str());
      // 存储结果，下次 poll 一并回报
      pendingOtaId = otaIdStr.toInt();
      pendingOtaSuccess = false;
      pendingOtaError = errMsg;
      otaInProgress = false;
      break;
    }
    case HTTP_UPDATE_NO_UPDATES:
      Serial.println("[OTA] 无可用更新");
      otaInProgress = false;
      break;
    case HTTP_UPDATE_OK:
      Serial.println("[OTA] 更新成功! 下次 poll 回报后重启...");
      // 存储结果，下次 poll 一并回报，回报后重启
      pendingOtaId = otaIdStr.toInt();
      pendingOtaSuccess = true;
      pendingOtaError = "";
      needRestartAfterReport = true;
      otaInProgress = false;
      break;
  }
}

// ============== HTTP 轮询 + WiFi 上报 ==============
void doPoll() {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[WiFi] 断开，尝试重连...");
    setupWiFi();
    return;
  }

  // 如果 OTA 正在进行中，跳过普通轮询
  if (otaInProgress) {
    delay(100);
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

  // 如果有待回报的 OTA 结果，附加到 poll 请求中（一次请求搞定）
  if (pendingOtaId > 0) {
    url += "&ota_id=" + String(pendingOtaId)
         + "&ota_success=" + (pendingOtaSuccess ? "1" : "0")
         + "&ota_error=" + urlencode(pendingOtaError.c_str());
    Serial.printf("[OTA] 在 poll 中回报: id=%d success=%d\n", pendingOtaId, pendingOtaSuccess);
  }
  
  httpClient.begin(wifiClient, url);
  httpClient.setTimeout(3000);
  httpClient.addHeader("Accept", "text/plain");
  
  int code = httpClient.GET();
  
  if (code == 200) {
    String body = httpClient.getString();
    applyPinStates(body);

    // 如果本次 poll 已回报 OTA 结果，先清除并按需重启（必须在 checkAndDoOTA 之前）
    if (pendingOtaId > 0) {
      Serial.println("[OTA] 回报完成");
      bool shouldRestart = needRestartAfterReport;
      pendingOtaId = 0;
      pendingOtaSuccess = false;
      pendingOtaError = "";
      needRestartAfterReport = false;
      if (shouldRestart) {
        Serial.println("[OTA] 回报成功，500ms 后重启...");
        delay(500);
        ESP.restart();
      }
    }

    // 检查是否有新的 OTA 更新（可能在回报的同时服务端下发了新固件）
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
  Serial.println("\n====== IoT Boot (Dynamic Pins + OTA) ======");
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
