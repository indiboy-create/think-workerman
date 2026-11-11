## ThinkPHP-PanGu - 盘古ThinkPHP开源索引!
面向 ThinkPHP 的开源工程索引：组件、框架、服务与发布工具，按能力组织，持续整理。
仓库地址：**<https://github.com/indiboy-create/ThinkPHP-PanGu/>**
在线站点：**<https://indiboy-create.github.io/ThinkPHP-PanGu/>**

# think-workerman

基于 [GatewayWorker](https://github.com/walkor/GatewayWorker) 封装的 ThinkPHP8 WebSocket 即时通讯扩展。

扩展会自动注册控制台命令、发布配置文件，并提供客户端连接、uid 绑定、心跳检测、wss 支持等基础能力。业务侧只需实现自己的消息处理逻辑即可。

## 环境要求

- PHP >= 8.0
- ThinkPHP 8
- Linux 下需要 pcntl、posix 扩展；Windows 下无需 pcntl，但必须分进程启动

## 安装

```bash
composer require youvision/think-workerman
```

安装完成后会自动在项目 `config` 目录下生成 `workerman.php`。若未自动生成，可手动复制：

```bash
cp vendor/youvision/think-workerman/src/config/workerman.php config/workerman.php
```

## 启动

### Linux

```bash
php think workerman start
php think workerman stop
php think workerman restart
php think workerman reload
php think workerman status
php think workerman connections
```

`start` 会同时启动 register、business、gateway 三类进程。生产环境建议在 `config/workerman.php` 中把 `daemonize` 设置为 `true`。

### Windows

Windows 不支持在单个进程内启动多个 Worker，需要打开三个终端分别执行，且顺序不能颠倒：

```bash
php think workerman:register
php think workerman:business
php think workerman:gateway
```

Windows 下只支持启动，停止服务请在对应终端按 `Ctrl + C`。

## 配置说明

配置位于 `config/workerman.php`，所有配置项均已在扩展内部生效。

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `register_address` | `127.0.0.1:1201` | register 注册中心地址，三个进程必须一致，仅内网使用 |
| `register_name` | `register` | register 进程名称 |
| `business_name` | `business` | business 进程名称 |
| `business_count` | `1` | business 进程数量，Windows 下固定为 1 |
| `event_handler` | `youvision\WorkermanEvents` | 业务事件类，需实现 `onConnect`、`onMessage`、`onClose` |
| `gateway_name` | `gateway` | gateway 进程名称 |
| `gateway_address` | `websocket://0.0.0.0:8201` | WebSocket 对外监听地址 |
| `gateway_count` | `1` | gateway 进程数量 |
| `gateway_lan_ip` | `127.0.0.1` | gateway 与 business 通信使用的本机网卡 IP |
| `gateway_start_port` | `4001` | gateway 内部通信起始端口，占用范围为起始端口至起始端口 + 进程数 - 1 |
| `gateway_ping_interval` | `55` | 服务端心跳发送间隔（秒） |
| `gateway_ping_not_response_limit` | `0` | 允许客户端连续不响应心跳的次数，0 表示不断开 |
| `gateway_ping_data` | `{"type":"ping"}` | 服务端下发的心跳数据，必须是 JSON 字符串 |
| `daemonize` | `false` | 是否守护进程运行，Windows 不支持 |
| `log_file` | `runtime/workerman.log` | Workerman 日志路径 |
| `ssl.is_use` | `false` | 是否启用 wss |
| `ssl.local_cert` | 空 | 证书文件路径 |
| `ssl.local_pk` | 空 | 证书私钥文件路径 |
| `ssl.verify_peer` | `false` | 是否验证客户端证书 |
| `ssl.allow_self_signed` | `false` | 是否允许自签名证书 |

需要对外开放的只有 `gateway_address` 中的端口；`register_address` 和 `gateway_start_port` 起始的内部端口仅供本机进程通信，不要暴露到公网。

## 通信协议

### 连接成功

客户端连接成功后，服务端立即下发：

```json
{"type": 11, "client_id": "7f00000108fc00000001"}
```

### 绑定 uid

客户端发送：

```json
{"type": 12, "member_id": "1001"}
```

绑定成功后服务端返回：

```json
{"type": "bind_success"}
```

绑定完成后，服务端即可通过 `member_id` 向对应客户端推送消息。

### 心跳

服务端每隔 `gateway_ping_interval` 秒下发：

```json
{"type": "ping"}
```

客户端需返回：

```json
{"type": "pong"}
```

## 服务端推送

扩展的 Service 启动时会自动把 `register_address` 同步给 Gateway 客户端，在 ThinkPHP 的控制器、模型或命令行中可直接推送：

```php
use GatewayWorker\Lib\Gateway;

// 向指定 uid 推送
Gateway::sendToUid('1001', json_encode([
    'type' => 'chat',
    'content' => '你好',
], JSON_UNESCAPED_UNICODE));

// 向所有在线客户端广播
Gateway::sendToAll(json_encode([
    'type' => 'notice',
    'content' => '系统维护通知',
], JSON_UNESCAPED_UNICODE));

// 判断 uid 是否在线
$online = Gateway::isUidOnline('1001');
```

## 自定义业务事件

将 `config/workerman.php` 中的 `event_handler` 指向项目自己的事件类即可：

```php
<?php

namespace app\event;

use GatewayWorker\Lib\Gateway;

class Chat
{
    public static function onConnect(string $client_id): void
    {
        Gateway::sendToClient($client_id, json_encode([
            'type' => 11,
            'client_id' => $client_id,
        ]));
    }

    public static function onMessage(string $client_id, string $message): void
    {
        $data = json_decode($message, true);

        // 在此处理聊天消息、转发给目标 uid 等
    }

    public static function onClose(string $client_id): void
    {
    }
}
```

## 目录结构

```text
src/
├── WorkermanEvents.php         默认事件回调
├── command/                 控制台命令
│   ├── Business.php
│   ├── Gateway.php
│   ├── Register.php
│   └── Workerman.php
├── config/workerman.php        默认配置
├── service/Workerman.php       服务注册
└── support/Worker.php       进程与 Workerman 配置封装
```

## License

MIT