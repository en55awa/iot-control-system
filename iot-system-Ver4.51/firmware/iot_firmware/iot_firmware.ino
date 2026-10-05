/**
 * 物联网控制系统 - ESP8266 固件（动态引脚 + WiFi 上报 + OTA 更新 + 服务端指令 + 事件上报）
 * v4.50: 新增设备事件上报 - 开机/复位原因、WiFi连接、OTA状态等事件上报到服务端
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
 *   9. 关键事件（开机、WiFi连接、OTA等）主动上报到服务端 device_events 表
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
WiFiClient   eventWifiClient;  // 事件上报专用，避免与轮询共用导致状态冲突
HTTPClient   httpClient;

unsigned long lastPoll = 0;
unsigned long lastHeartbeat = 0;
int pollCount = 0;
int eventCount = 0;
bool otaInProgress = false;
bool lastWifiConnected = false;  // 上次 WiFi 状态，用于检测连接变化

// ========== 预分配全局缓冲区（避免循环中频繁分配内存） ==========
#define URL_BUF_SIZE    512   // URL 缓冲区
#define RESP_BUF_SIZE   1024  // 响应体缓冲区（足够容纳引脚状态 + OTA URL + CMD）
#define ENC_BUF_SIZE    256   // URL 编码缓冲区（SSID 最长 32 字节，编码后最多 96 字节）
#define EVENT_BUF_SIZE  256   // 事件 JSON 缓冲区

char urlBuf[URL_BUF_SIZE];
char respBuf[RESP_BUF_SIZE];
char encBuf[ENC_BUF_SIZE];
char eventBuf[EVENT_BUF_SIZE];

// ============== WiFi ==============
void setupWiFi() {
  Serial.printf("[WiFi] 连接 %s", WIFI_SSID);
  WiFi.mode(WIFI_STA);
  WiFi.persistent(false);           // 不写 Flash，避免磨损
  WiFi.setSleepMode(WIFI_NONE_SLEEP); // 关闭省电模式，防止间歇性断连
  WiFi.setAutoReconnect(true);      // WiFi 断开后自动重连（后台进行，不阻塞）
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  int t = 0;
  while (WiFi.status() != WL_CONNECTED && t < 20) {  // 最多等 10 秒
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

// ============== 事件上报（POST JSON，零 String 分配） ==============
// eventType: boot / wifi_connect / ota_start / ota_failed / error
// detailsJson: JSON 格式的详情字符串，可为 NULL
void reportEvent(const char* eventType, const char* detailsJson) {
  eventCount++;
  if (WiFi.status() != WL_CONNECTED) return;

  // 喂狗，防止 HTTP 阻塞触发看门狗
  ESP.wdtFeed();

  // details 做 URL 编码（写入 encBuf，用完后再还原，因为轮询也用这个缓冲区）
  int detailsEncLen = 0;
  bool hasDetails = (detailsJson != NULL && detailsJson[0] != '\0');
  if (hasDetails) {
    detailsEncLen = urlencode(detailsJson, encBuf, ENC_BUF_SIZE);
  }

  // 用 snprintf 拼接 GET URL，参数全部放 query string
  // URL 长度估算：SERVER_HOST(约30) + 固定部分(约60) + device_id(10) + key(16) + event_type(15) + details(最多150) ≈ 280，留够余量
  char eventUrl[384];
  if (hasDetails && detailsEncLen > 0) {
    snprintf(eventUrl, sizeof(eventUrl),
      "http://%s/api.php?route=event&device_id=%s&key=%s&event_type=%s&details=%s",
      SERVER_HOST, DEVICE_ID, DEVICE_KEY, eventType, encBuf);
  } else {
    snprintf(eventUrl, sizeof(eventUrl),
      "http://%s/api.php?route=event&device_id=%s&key=%s&event_type=%s",
      SERVER_HOST, DEVICE_ID, DEVICE_KEY, eventType);
  }

  HTTPClient eventHttp;
  eventHttp.begin(eventWifiClient, eventUrl);
  eventHttp.setTimeout(2000);  // 超时 2 秒，宁可失败也不阻塞太久
  eventHttp.addHeader("Accept", "application/json");

  int code = eventHttp.GET();
  if (code != 200 && code > 0) {
    Serial.printf("[Event] 上报失败(%d): %s\n", code, eventType);
  }
  eventHttp.end();

  // 再次喂狗
  ESP.wdtFeed();
}

// ============== URL 编码（写入预分配缓冲区，返回编码后的长度） ==============
int urlencode(const char* str, char* out, int outSize) {
  int len = 0;
  while (*str && len < outSize - 4) {
    unsigned char c = (unsigned char)*str++;
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') {
      out[len++] = c;
    } else {
      out[len++] = '%';
      sprintf(out + len, "%02X", (unsigned char)c);
      len += 2;
    }
  }
  out[len] = '\0';
  return len;
}

// ============== 解析并执行引脚控制（char* 版本，零内存分配） ==============
void applyPinStates(const char* body, int bodyLen) {
  if (bodyLen <= 0) return;

  int pos = 0;
  while (pos < bodyLen) {
    // 找到逗号或行尾
    int comma = pos;
    while (comma < bodyLen && body[comma] != ',') comma++;

    int pairLen = comma - pos;
    if (pairLen <= 0) {
      pos = comma + 1;
      continue;
    }

    // 找到冒号
    int colon = pos;
    while (colon < comma && body[colon] != ':') colon++;

    if (colon == comma) {  // 没有冒号，跳过
      pos = comma + 1;
      continue;
    }

    // 解析引脚号和状态（手动 atoi，避免 String 分配）
    int pin = 0;
    for (int i = pos; i < colon; i++) {
      if (body[i] >= '0' && body[i] <= '9') {
        pin = pin * 10 + (body[i] - '0');
      }
    }

    int state = 0;
    for (int i = colon + 1; i < comma; i++) {
      if (body[i] >= '0' && body[i] <= '9') {
        state = state * 10 + (body[i] - '0');
      }
    }

    pinMode(pin, OUTPUT);
    // HIGH = OFF, LOW = ON
    digitalWrite(pin, state ? LOW : HIGH);

    pos = comma + 1;
  }
}

// ============== 检查并执行服务端指令（char* 版本） ==============
void checkAndDoCommand(const char* body, int bodyLen) {
  // 查找 "CMD:" 标记
  const char* cmd = strstr(body, "CMD:");
  if (cmd == NULL) return;

  cmd += 4; // 跳过 "CMD:"
  int cmdLen = 0;
  while (cmd[cmdLen] != '\0' && cmd[cmdLen] != ',' && cmd[cmdLen] != '\r' && cmd[cmdLen] != '\n' && cmdLen < 32) {
    cmdLen++;
  }

  // 比较命令
  if (cmdLen == 6 && strncmp(cmd, "reboot", 6) == 0) {
    Serial.println("[CMD] 收到重启指令，500ms 后重启...");
    delay(500);
    ESP.restart();
  } else {
    Serial.printf("[CMD] 未知指令: %.*s\n", cmdLen, cmd);
  }
}

// ============== 检查并执行 OTA 更新（char* 版本） ==============
void checkAndDoOTA(const char* body, int bodyLen) {
  // 查找 "OTA:" 标记
  const char* ota = strstr(body, "OTA:");
  if (ota == NULL) return;

  const char* otaUrl = ota + 4;
  int urlLen = 0;
  while (otaUrl[urlLen] != '\0' && otaUrl[urlLen] != ',' && otaUrl[urlLen] != '\r' && otaUrl[urlLen] != '\n' && urlLen < URL_BUF_SIZE - 1) {
    urlLen++;
  }

  // 去掉末尾空白
  while (urlLen > 0 && (otaUrl[urlLen - 1] == ' ' || otaUrl[urlLen - 1] == '\t')) urlLen--;

  if (urlLen < 10) {
    Serial.println("[OTA] URL 无效");
    return;
  }

  // 复制到 urlBuf（复用全局缓冲区，此时已不需要原始 URL）
  strncpy(urlBuf, otaUrl, urlLen);
  urlBuf[urlLen] = '\0';

  Serial.println("\n====== OTA 更新开始 ======");
  Serial.printf("[OTA] 下载: %s\n", urlBuf);

  otaInProgress = true;

  // 上报 OTA 开始事件
  char otaStartDetails[96];
  snprintf(otaStartDetails, sizeof(otaStartDetails),
    "{\"version\":\"ota\",\"size\":%d}", (int)strlen(urlBuf));
  reportEvent("ota_start", otaStartDetails);

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
  t_httpUpdate_return ret = ESPhttpUpdate.update(updateClient, urlBuf);

  switch (ret) {
    case HTTP_UPDATE_FAILED: {
      int errCode = ESPhttpUpdate.getLastError();
      String errStr = ESPhttpUpdate.getLastErrorString();
      Serial.printf("[OTA] 更新失败 (%d): %s\n", errCode, errStr.c_str());
      Serial.println("[OTA] 服务端将在5分钟后自动判定超时失败");
      // 上报 OTA 失败事件
      char failDetails[160];
      snprintf(failDetails, sizeof(failDetails),
        "{\"error_code\":%d,\"error\":\"%s\"}", errCode, errStr.c_str());
      reportEvent("ota_failed", failDetails);
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
      delay(500);
      ESP.restart();
      break;
  }
}

// ============== HTTP 轮询 + WiFi 上报（内存优化版） ==============
void doPoll() {
  pollCount++;
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[WiFi] 未连接，等待自动重连...");
    return;
  }

  if (otaInProgress) {
    delay(100);
    return;
  }

  // 获取 SSID 并 URL 编码（写入 encBuf）
  // WiFi.SSID() 返回 String 是库限制，用完立即释放（函数结束时 ssidStr 析构）
  String ssidStr = WiFi.SSID();
  urlencode(ssidStr.c_str(), encBuf, ENC_BUF_SIZE);

  // 获取 IP（直接从 IPAddress 取字节，省掉一个 String 分配）
  IPAddress ipAddr = WiFi.localIP();
  char ipBuf[16]; // "255.255.255.255" = 15 + 1
  snprintf(ipBuf, sizeof(ipBuf), "%d.%d.%d.%d", ipAddr[0], ipAddr[1], ipAddr[2], ipAddr[3]);

  // 用 snprintf 拼接 URL，零内存碎片
  snprintf(urlBuf, URL_BUF_SIZE,
    "http://%s/api.php?route=poll&device_id=%s&key=%s&wifi=%s&rssi=%d&ip=%s",
    SERVER_HOST, DEVICE_ID, DEVICE_KEY, encBuf, WiFi.RSSI(), ipBuf);

  ESP.wdtFeed();  // HTTP 请求前喂狗

  httpClient.begin(wifiClient, urlBuf);
  httpClient.setTimeout(4000);  // 4秒超时，留足看门狗余量
  httpClient.addHeader("Accept", "text/plain");

  int code = httpClient.GET();
  int bodyLen = 0;

  if (code == 200) {
    // 读取响应体到预分配缓冲区
    WiFiClient& stream = httpClient.getStream();
    int available = stream.available();
    if (available > 0 && available < RESP_BUF_SIZE - 1) {
      bodyLen = stream.readBytes(respBuf, available);
      respBuf[bodyLen] = '\0';
      // 去掉末尾空白和换行
      while (bodyLen > 0 && (respBuf[bodyLen - 1] == '\n' || respBuf[bodyLen - 1] == '\r' || respBuf[bodyLen - 1] == ' ' || respBuf[bodyLen - 1] == '\t')) {
        respBuf[--bodyLen] = '\0';
      }

      applyPinStates(respBuf, bodyLen);
      checkAndDoCommand(respBuf, bodyLen);
      checkAndDoOTA(respBuf, bodyLen);
    }
  } else if (code > 0) {
    Serial.printf("[HTTP] 错误码: %d\n", code);
  } else {
    Serial.printf("[HTTP] 请求失败: %s\n", httpClient.errorToString(code).c_str());
  }

  httpClient.end();

  ESP.wdtFeed();  // HTTP 请求后喂狗
}

// ============== 入口 ==============
void setup() {
  Serial.begin(SERIAL_BAUD);
  Serial.printf("\n====== IoT Boot (Dynamic Pins + OTA + CMD + Events %s) ======\n", FIRMWARE_VERSION);
  Serial.printf("[ID] %s  [KEY] %.8s...\n", DEVICE_ID, DEVICE_KEY);
  Serial.printf("[Boot] 复位原因: %s\n", ESP.getResetReason().c_str());
  Serial.printf("[Boot] 可用内存: %u bytes\n", ESP.getFreeHeap());

  setupWiFi();

  // WiFi 连接后上报开机事件
  if (WiFi.status() == WL_CONNECTED) {
    lastWifiConnected = true;
    char bootDetails[192];
    snprintf(bootDetails, sizeof(bootDetails),
      "{\"reset_reason\":\"%s\",\"free_heap\":%u,\"version\":\"%s\",\"chip_id\":%u}",
      ESP.getResetReason().c_str(),
      ESP.getFreeHeap(),
      FIRMWARE_VERSION,
      ESP.getChipId());
    reportEvent("boot", bootDetails);
  }
}

void loop() {
  unsigned long now = millis();

  // 检测 WiFi 连接状态变化：断开 -> 连接 时上报事件
  bool wifiNow = (WiFi.status() == WL_CONNECTED);
  if (wifiNow && !lastWifiConnected) {
    lastWifiConnected = true;
    char connDetails[128];
    IPAddress ip = WiFi.localIP();
    snprintf(connDetails, sizeof(connDetails),
      "{\"rssi\":%d,\"ip\":\"%d.%d.%d.%d\",\"ssid\":\"%s\"}",
      WiFi.RSSI(), ip[0], ip[1], ip[2], ip[3], WiFi.SSID().c_str());
    reportEvent("wifi_connect", connDetails);
  } else if (!wifiNow && lastWifiConnected) {
    lastWifiConnected = false;
    // WiFi 断开时没法上网，无法上报事件。服务端可通过 last_seen 超时判断离线
  }

  if (now - lastPoll >= POLL_INTERVAL) {
    lastPoll = now;
    doPoll();
  }

  // 心跳：每 10 秒打印一次运行状态，方便定位卡死位置（生产环境注释掉）
  // if (now - lastHeartbeat >= 10000) {
  //   lastHeartbeat = now;
  //   Serial.printf("[HEARTBEAT] uptime=%lus heap=%u poll=%d event=%d wifi=%s\r\n",
  //     now / 1000, ESP.getFreeHeap(), pollCount, eventCount,
  //     WiFi.status() == WL_CONNECTED ? "OK" : "DOWN");
  // }
  delay(10);
}
