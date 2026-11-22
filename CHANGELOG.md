## 2.0.0

### 环境

- PHP >= 8.3，ThinkPHP 8，GatewayWorker 4
- PHPUnit ^10.5 || ^11.0

### 功能

- 令牌鉴权后绑定 uid：`youvision\contract\Authenticator` 接口与 `youvision\Identity`，支持类名或 callable 鉴权器；未配置 `workerman.auth` 时拒绝所有鉴权
- 鉴权方式：握手请求头 `Authorization: Bearer`，或连接后发送 `auth` 消息
- `auth_timeout`：超时未鉴权的连接自动断开
- 令牌过期：`Identity::$expiresAt` 到期自动断开并下发 `auth_fail(expired)`，收到消息时兜底检查
- 在线续期：已鉴权连接再次发送 `auth` 续期，只允许同一 uid，否则返回 `uid_mismatch`
- 踢下线：`Push::kickUid()`、`Push::kickClient()`，客户端收到 `kicked`
- 统一消息格式 `{"type":"...","data":{...},"msg_id":"..."}`，回复带回请求的 `msg_id`，`Push` 推送自动生成 `msg_id`
- 消息路由：`workerman.routes` 按 `type` 分发到 `MessageHandler` 类、`[类名, 方法]` 或 callable，支持 `前缀.*` 通配；未匹配回复 `error(unknown_type)`，处理器异常回复 `error(handler_error)`
- `youvision\Message`：路由处理器参数，提供 `uid()`、`get()`、`reply()`、`error()`
- 事件类钩子 `onAuthed`、`onClientMessage`、`onDisconnect`，项目继承默认事件类即可
- `youvision\Push` 推送辅助类，失败只记日志返回 false
- 心跳：默认 25 秒，连续 2 个周期无消息断开
- `secret_key`：register、business、gateway 与推送方的内部通信密钥
- `max_package_size`：限制客户端单条消息大小，默认 64KB，不影响内部通信
- 默认只监听本机 `websocket://127.0.0.1:8201`，推荐由反向代理提供 wss
- 启动校验：鉴权器与路由配置错误时 business 进程启动即报错
- `php think workerman` 支持 `-d`（守护进程）与 `-g`（平滑停止 / 重启）；Windows 下 register、business、gateway 分进程启动

### 工程

- `youvision\contract\GatewayApi` 与 `WorkermanEvents::useGateway()`：事件类的 Gateway 调用可替换，`addTimer()` 可在子类中覆盖
- PHPUnit 单元测试：协议编解码、鉴权器、事件类完整流程、消息路由
- GitHub Actions：PHP 8.3 / 8.4、最低依赖版本、Windows
