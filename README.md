## ThinkPHP-PanGu - 盘古ThinkPHP开源索引!
面向 ThinkPHP 的开源工程索引：组件、框架、服务与发布工具，按能力组织，持续整理。
仓库地址：**<https://github.com/indiboy-create/ThinkPHP-PanGu/>**
在线站点：**<https://indiboy-create.github.io/ThinkPHP-PanGu/>**

# think-workerman

[![tests](https://github.com/indiboy-create/think-workerman/actions/workflows/tests.yml/badge.svg)](https://github.com/indiboy-create/think-workerman/actions/workflows/tests.yml)

基于 [GatewayWorker](https://github.com/walkor/GatewayWorker) 封装的 ThinkPHP8 WebSocket 即时通讯扩展。

扩展自动注册控制台命令、发布配置文件，并提供：

- 令牌鉴权后绑定 uid（握手请求头或 auth 消息），超时未鉴权自动断开
- 令牌过期自动断开，支持在线续期；可主动踢下线
- 心跳检测，自动清理死连接
- register / gateway / business 内部通信密钥
- 安全的推送辅助类 `youvision\Push`
- 可继承的默认事件类，业务只需覆盖钩子
- wss 支持（推荐交给 Nginx）

> 2.0 有不兼容变更，从 1.x 升级请看文末「升级指南」。

## 环境要求

- PHP >= 8.3
- ThinkPHP 8
- Linux 下需要 pcntl、posix 扩展；Windows 下无需 pcntl，但必须分进程启动（仅适合开发）

## 安装

```bash
composer require youvision/think-workerman
```

安装后会自动生成 `config/workerman.php`。若未生成，可手动复制：

```bash
cp vendor/youvision/think-workerman/src/config/workerman.php config/workerman.php
```

## 快速开始

### 1. 实现鉴权器

```php
<?php
namespace app\service;

use youvision\contract\Authenticator;
use youvision\Identity;

class WsAuth implements Authenticator
{
    public function verify(string $token, array $context): ?Identity
    {
        $claims = JwtService::decode($token);   // 与 HTTP 接口使用同一套令牌
        if (!$claims) {
            return null;                        // 拒绝
        }

        return new Identity(
            (string) $claims['user_id'],
            ['device_id' => $claims['device_id'] ?? ''],
            $claims['exp'] ?? null,             // 过期时间（Unix 时间戳），null 表示不过期
        );
    }
}
```

### 2. 修改配置

```php
// config/workerman.php
'auth'       => \app\service\WsAuth::class,
'secret_key' => env('WORKERMAN_SECRET', ''),   // .env 中设置一个随机字符串
```

也可以使用 callable：`'auth' => fn (string $token, array $ctx) => ...`。

**未配置 `auth` 时，所有绑定请求都会被拒绝。** `auth` 配置错误（类不存在、未实现接口）时 business 进程启动即报错。

> 使用 `php think optimize:config` 缓存配置时，闭包无法序列化，请使用类名。

### 3. 启动

Linux：

```bash
php think workerman start        # 前台运行
php think workerman start -d     # 守护进程
php think workerman stop [-g]    # -g 平滑停止
php think workerman restart [-d] [-g]
php think workerman reload       # 平滑重载 business 进程
php think workerman status
php think workerman connections
```

`start` 同时启动 register、business、gateway 三类进程。生产环境建议用 `-d` 或把 `daemonize` 设为 `true`，并用 systemd / supervisor 守护。

Windows（仅开发）需要三个终端按顺序执行，停止时按 `Ctrl + C`：

```bash
php think workerman:register
php think workerman:business
php think workerman:gateway
```

### 4. 推送

```php
use youvision\Push;

Push::toUid('1001', 'poke', ['seq' => 1024, 'from' => 'device-A']);
Push::toGroup('room_1', 'notice', ['content' => '你好']);
Push::toAll('notice', ['content' => '系统维护通知']);
Push::toUid('1001', 'sync.notify', ['version' => 42], 'sync-42');  // 可指定 msg_id，留空自动生成
Push::isUidOnline('1001');

// 踢下线（注销、封禁、修改密码等）
Push::kickUid('1001', 'logout');     // 断开该 uid 的全部连接
Push::kickClient($client_id);        // 断开单个连接
```

推送服务未启动或通信失败时只写日志并返回 `false`，不会打断业务请求。也可以继续直接使用 `GatewayWorker\Lib\Gateway`，扩展已自动设置好 `registerAddress` 与 `secretKey`。

## 通信协议

### 消息格式

所有消息统一为：

```json
{"type": "sync.notify", "data": {"version": 42}, "msg_id": "9f2c1a7e4b3d8065"}
```

| 字段 | 说明 |
| --- | --- |
| `type` | 消息类型，字符串，小写 |
| `data` | 业务数据，始终是对象，无数据时为 `{}` |
| `msg_id` | 可选。客户端请求带上后，服务端回复会原样带回；`Push` 主动推送时自动生成，可用于去重与回执 |

客户端发送的扁平格式（如 `{"type":"auth","token":"..."}`）仍然兼容，`type`、`msg_id` 以外的字段会被当作 `data`。

### 消息类型


| 方向 | 消息 | 说明 |
| --- | --- | --- |
| 服务端 → 客户端 | `{"type":"welcome","data":{"client_id":"..."}}` | 连接建立 |
| 客户端 → 服务端 | `{"type":"auth","data":{"token":"..."}}` | 握手时未带 Authorization 头时使用 |
| 服务端 → 客户端 | `{"type":"auth_ok","data":{"uid":"1001","expires_at":1767225600}}` | 鉴权成功，`expires_at` 为 null 表示不过期 |
| 客户端 → 服务端 | `{"type":"auth","data":{"token":"新令牌"}}` | 已鉴权连接续期，只允许同一 uid |
| 服务端 → 客户端 | `{"type":"auth_ok","data":{"uid":"1001","expires_at":...,"refreshed":true}}` | 续期成功 |
| 服务端 → 客户端 | `{"type":"auth_fail","data":{"reason":"..."}}` | 鉴权失败，见下表 |
| 服务端 → 客户端 | `{"type":"kicked","data":{"reason":"logout"}}` | 被服务端踢下线，随后断开 |
| 服务端 → 客户端 | `{"type":"ping","data":{}}` | 每 `gateway_ping_interval` 秒一次 |
| 客户端 → 服务端 | `{"type":"pong"}` | 回复心跳 |

鉴权有两种方式，任选其一：

1. **握手请求头**（推荐，非浏览器客户端）：`Authorization: Bearer <token>`，握手完成即鉴权，无需再发 auth 消息。
2. **auth 消息**（浏览器无法自定义请求头时）：收到 welcome 后发送 `{"type":"auth","data":{"token":"..."}}`。

未鉴权连接只能发送 auth / pong，其他消息一律忽略；超过 `auth_timeout` 秒仍未鉴权会被断开。

`auth_fail` 的 `reason`：

| reason | 场景 | 是否断开 |
| --- | --- | --- |
| `invalid_token` | 令牌无效或鉴权器拒绝 | 是 |
| `timeout` | `auth_timeout` 秒内未鉴权 | 是 |
| `expired` | 鉴权时令牌已过期，或连接期间令牌到期 | 是 |
| `uid_mismatch` | 续期令牌的 uid 与当前连接不同 | 否，原连接保持 |

### 令牌过期与续期

`Identity` 带 `expiresAt` 时，到期会下发 `auth_fail(expired)` 并断开。客户端建议在 `expires_at` 前（例如提前 60 秒）通过 HTTP 刷新令牌，再在当前连接上发送新的 `auth` 消息续期，无需重连。

### 客户端处理建议

- 收到 `auth_fail` 或 `kicked` 后**不要自动重连**，`expired` 应先刷新令牌，`kicked` 应提示用户
- 其他原因断开（网络、服务重启）使用指数退避重连

> 浏览器中不建议把令牌放进 URL 查询参数，可能出现在访问日志里。

## 消息路由

已鉴权连接的业务消息按 `type` 分发到处理器，无需继承事件类：

```php
// config/workerman.php
'routes' => [
    'chat.send' => \app\ws\ChatSend::class,          // 实现 MessageHandler 的类
    'chat.read' => [\app\ws\Chat::class, 'read'],     // [类名, 方法名]，静态或实例方法均可
    'sync.*'    => \app\ws\Sync::class,              // 前缀通配
],
```

```php
<?php
namespace app\ws;

use youvision\contract\MessageHandler;
use youvision\Message;
use youvision\Push;

class ChatSend implements MessageHandler
{
    public function handle(Message $message): void
    {
        $to = (string) $message->get('to', '');
        if ($to === '') {
            $message->error('bad_request', ['field' => 'to']);
            return;
        }

        Push::toUid($to, 'chat.message', [
            'from' => $message->uid(),
            'text' => (string) $message->get('text', ''),
        ]);
        $message->reply('chat.send_ok');   // 自动带回请求的 msg_id
    }
}
```

`Message` 提供：`clientId`、`type`、`data`、`msgId`、`session` 属性，以及 `uid()`、`get($key, $default)`、`reply($type, $data)`、`error($reason, $extra)`。

匹配规则：

- 精确匹配优先，其次最长的 `前缀.*` 通配；`sync.*` 匹配 `sync.ack`，不匹配 `sync`
- 路由键不区分大小写
- 处理器类通过 `app()` 创建（支持依赖注入），每个 business 进程内复用同一实例，**不要在属性中保存单次请求的状态**
- business 进程启动时会校验全部路由，类不存在、方法不存在或未实现 `MessageHandler` 时直接报错

错误响应：

| 场景 | 响应 |
| --- | --- |
| 未匹配到路由 | `{"type":"error","data":{"reason":"unknown_type","type":"原类型"}}`，可用 `route_unknown_reply => false` 关闭 |
| 处理器抛出异常 | `{"type":"error","data":{"reason":"handler_error","type":"原类型"}}`，异常写入 Workerman 日志，连接保持 |
| `$message->error()` | `{"type":"error","data":{"reason":"...","type":"原类型",...}}` |

错误响应都会带回请求的 `msg_id`。

## 自定义业务事件

继承默认事件类，只覆盖需要的钩子，鉴权、超时、心跳逻辑保持不变：

```php
<?php
namespace app\event;

use GatewayWorker\Lib\Gateway;
use youvision\Identity;
use youvision\WorkermanEvents;

class Chat extends WorkermanEvents
{
    protected static function onAuthed(string $client_id, Identity $identity): void
    {
        Gateway::joinGroup($client_id, 'user_' . $identity->uid);
    }

    // 覆盖后不再走路由表；需要保留路由时调用 parent::onClientMessage(...)
    protected static function onClientMessage(string $client_id, string $type, array $data, string $msg_id): void
    {
        // 只会收到已鉴权连接的业务消息，$data 为 data 字段
        if ($type === 'echo') {
            static::reply($client_id, 'echo_ok', $data, $msg_id);   // 带回 msg_id
        }
    }

    protected static function onDisconnect(string $client_id, array $session): void
    {
        // $session['uid']、$session['device_id'] 等
    }
}
```

```php
// config/workerman.php
'event_handler' => \app\event\Chat::class,
```

在钩子中修改连接 session 请直接写 `$_SESSION`，不要调用 `Gateway::updateSession()`，否则会被回调结束时的自动同步覆盖。

GatewayWorker 还会调用事件类中的 `onWorkerStart($worker)`、`onWorkerStop($worker)`，需要时可直接定义。覆盖 `onConnect` / `onMessage` / `onWebSocketConnect` 时请调用 `parent::` 以保留鉴权逻辑。

## 配置说明

| 配置项 | 默认值 | 说明 |
| --- | --- | --- |
| `register_address` | `127.0.0.1:1201` | register 注册中心地址，三个进程与推送方必须一致，仅内网使用 |
| `secret_key` | `env('WORKERMAN_SECRET')` | 内部通信密钥，留空不校验，生产环境务必设置 |
| `register_name` | `register` | register 进程名称 |
| `business_name` | `business` | business 进程名称 |
| `business_count` | `1` | business 进程数量，Windows 下固定为 1 |
| `event_handler` | `youvision\WorkermanEvents` | 业务事件类 |
| `routes` | `[]` | 消息路由表，见「消息路由」 |
| `route_unknown_reply` | `true` | 未匹配路由时是否回复 `error(unknown_type)` |
| `auth` | `null` | 鉴权器：`Authenticator` 类名或 callable，未配置时拒绝所有绑定 |
| `auth_timeout` | `10` | 连接后多少秒内必须完成鉴权，0 不限制 |
| `legacy_types` | `true` | 兼容 1.x 的数字类型 11 / 12（type=12 也必须带 token） |
| `gateway_name` | `gateway` | gateway 进程名称 |
| `gateway_address` | `websocket://127.0.0.1:8201` | WebSocket 监听地址，默认仅本机 |
| `gateway_count` | `1` | gateway 进程数量 |
| `gateway_lan_ip` | `127.0.0.1` | gateway 与 business 通信使用的本机网卡 IP |
| `gateway_start_port` | `4001` | gateway 内部通信起始端口 |
| `gateway_ping_interval` | `25` | 服务端心跳间隔（秒） |
| `gateway_ping_not_response_limit` | `2` | 连续多少个周期没收到客户端任何消息就断开，0 不断开 |
| `gateway_ping_data` | `{"type":"ping","data":{}}` | 心跳数据，必须是 JSON 字符串 |
| `max_package_size` | `65536` | 单条消息最大字节数 |
| `daemonize` | `false` | 是否守护进程运行，Windows 不支持 |
| `log_file` | 空（`runtime/workerman.log`） | Workerman 日志路径 |
| `ssl.*` | 关闭 | gateway 直接提供 wss 时使用；推荐交给 Nginx |

只有 gateway 端口可能需要对外；`register_address` 与 `gateway_start_port` 起的内部端口不要暴露到公网。

## 部署建议

### Nginx 提供 wss

```nginx
location /ws {
    proxy_pass http://127.0.0.1:8201;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Authorization $http_authorization;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_read_timeout 120s;
}
```

`proxy_read_timeout` 要大于心跳间隔。

### 数据库长连接

business 是常驻进程，在事件类或鉴权器里使用 `Db` 时，MySQL 空闲超时（默认 8 小时）会断开连接，请在 `config/database.php` 中开启：

```php
'break_reconnect' => true,
```

### 推送方位置

ThinkPHP（FPM）调用 `Push` / `Gateway::sendXxx` 时需要能连到 `register_address` 和 gateway 内部端口，建议与推送服务部署在同一台机器或同一内网。

## 升级指南（1.x → 2.0）

1. 实现 `Authenticator` 并配置 `workerman.auth`，否则客户端无法绑定。
2. 客户端改为发送 `{"type":"auth","token":"..."}`，或在握手时带 `Authorization: Bearer`。旧的 `{"type":12,"member_id":"..."}` 不再生效。
3. 客户端收到 `ping` 必须回复 `pong`（或在每个周期内发送任意消息），否则约 50 秒后断开。
4. 需要直接对外监听时，把 `gateway_address` 改回 `websocket://0.0.0.0:8201`。
5. 自定义事件类建议改为继承 `youvision\WorkermanEvents` 并使用钩子。
6. 设置 `secret_key`，推送方会自动使用同一个值。

### 2.0 消息格式变更

- 服务端下发的所有消息改为 `{"type","data","msg_id"}`，客户端需从 `data` 中读取字段（如 `data.client_id`、`data.reason`）
- 自定义 `gateway_ping_data` 的项目建议改为 `{"type":"ping","data":{}}`
- 子类的 `onClientMessage` 增加第四个参数 `string $msg_id`

## 测试

```bash
composer install
vendor/bin/phpunit
```

## 目录结构

```text
src/
├── WorkermanEvents.php       默认事件回调（鉴权、超时、钩子）
├── Identity.php              鉴权结果（uid、extra、expiresAt）
├── Message.php               路由消息（reply / error）
├── Push.php                  推送与踢下线辅助类
├── contract/
│   ├── Authenticator.php
│   ├── GatewayApi.php        事件类使用的 Gateway 操作（可替换）
│   └── MessageHandler.php    路由处理器接口
├── command/                  控制台命令
├── config/workerman.php      默认配置
├── service/Workerman.php     服务注册（推送方 registerAddress / secretKey）
└── support/
    ├── Auth.php              鉴权器解析与调用
    ├── GatewayAdapter.php    GatewayApi 默认实现
    ├── Router.php            消息路由
    ├── Protocol.php          消息类型与编解码
    └── Worker.php            进程与 Workerman 配置封装
tests/
├── ProtocolTest.php
├── WorkermanEventsTest.php
├── RouterTest.php
└── fixtures/                 FakeGateway、TestEvents、RouterEvents、Handlers
```

## License

MIT
