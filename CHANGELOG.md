## 2.0.0

### 不兼容变更

- 最低要求 PHP 8.3（`Identity`、`Message` 改为 readonly 类，`Protocol` 使用类型化常量）；PHPUnit 要求 ^10.5 || ^11.0
- 绑定 uid 必须经过鉴权：需在 `workerman.auth` 配置鉴权器，未配置时所有绑定请求都被拒绝；不再接受裸 `member_id`
- `gateway_address` 默认改为 `websocket://127.0.0.1:8201`，默认只监听本机，由反向代理提供 wss
- 心跳默认值改为 `gateway_ping_interval = 25`、`gateway_ping_not_response_limit = 2`，客户端必须回复 pong
- 消息类型统一为字符串：`welcome`、`auth`、`auth_ok`、`auth_fail`、`ping`、`pong`；`legacy_types` 开启时仍识别 11 / 12
- 消息格式统一为 `{"type":"...","data":{...},"msg_id":"..."}`：服务端下发的业务字段全部放入 `data`（无数据时为 `{}`），客户端需从 `data` 读取；客户端发送的扁平格式仍兼容
- `onClientMessage` 增加第四个参数 `string $msg_id`，覆盖该钩子的子类需同步修改签名
- `gateway_ping_data` 默认改为 `{"type":"ping","data":{}}`

### 新增

- `youvision\contract\Authenticator` 接口与 `youvision\Identity`，支持类名或 callable 鉴权器
- 握手阶段读取 `Authorization: Bearer` 直接鉴权（`onWebSocketConnect`），也可连上后发送 `auth` 消息
- `auth_timeout`：超时未鉴权的连接自动断开
- `secret_key`：register、business、gateway 与推送方的内部通信密钥
- `max_package_size`：限制单条消息大小，默认 64KB
- `youvision\Push` 推送辅助类，失败只记日志返回 false
- 事件类钩子 `onAuthed`、`onClientMessage`、`onDisconnect`，项目继承默认事件类即可
- `php think workerman` 支持 `-d`（守护进程）与 `-g`（平滑停止 / 重启）
- PHPUnit 单元测试，覆盖协议编解码、鉴权器、事件类完整流程（连接、超时、鉴权、续期、过期、业务消息、断开）
- GitHub Actions：PHP 8.3 / 8.4、最低依赖版本、Windows 上运行 PHPUnit
- `youvision\contract\GatewayApi` 与 `WorkermanEvents::useGateway()`：事件类的 Gateway 调用可替换，便于测试；`addTimer()` 可在子类中覆盖
- `Identity` 新增 `expiresAt`（Unix 时间戳），到期自动断开连接并下发 `{"type":"auth_fail","reason":"expired"}`；收到消息时也会兜底检查
- 已鉴权连接可再次发送 `auth` 消息续期令牌，只允许同一 uid，切换账号返回 `uid_mismatch`
- `auth_ok` 返回 `expires_at`；续期成功时额外带 `refreshed: true`
- `Push::kickUid()`、`Push::kickClient()`：主动断开连接，客户端收到 `{"type":"kicked","reason":...}`
- `msg_id`：服务端回复会带回客户端请求的 `msg_id`；`Push` 推送方法新增可选参数 `$msg_id`，留空时自动生成
- `WorkermanEvents::reply()`：回复当前连接并带回 `msg_id`；`Protocol::id()` 生成消息 ID
- 消息路由：`workerman.routes` 按 `type` 分发到 `MessageHandler` 类、`[类名, 方法]` 或 callable，支持 `前缀.*` 通配（精确匹配优先，最长前缀优先）；business 启动时校验全部路由
- `youvision\Message`：路由处理器参数，提供 `uid()`、`get()`、`reply()`、`error()`
- 未匹配路由回复 `error(unknown_type)`（`route_unknown_reply` 可关闭）；处理器异常记日志并回复 `error(handler_error)`，连接不断开

### 修复

- `log_file` 默认值不再在配置文件中调用 `runtime_path()`
- 鉴权状态改为读取本地 `$_SESSION`，不再每条消息远程调用 `Gateway::getUidByClientId()`；鉴权成功后直接写 `$_SESSION`（子类在 `onAuthed` 中修改 session 也请直接写 `$_SESSION`，不要调用 `Gateway::updateSession()`）
- 鉴权器配置错误（类不存在、未实现接口）在 business 进程启动时即报错；运行期解析异常只记日志并拒绝鉴权，不再导致进程崩溃重启
- `max_package_size` 仅作用于客户端连接，不再修改全局 `TcpConnection::$defaultMaxPackageSize`，避免影响 gateway 与 business 的内部通信
- `Protocol::encode()` 对非法 UTF-8 做替换，编码仍失败时退化为仅含 `type` 的消息，不再抛出 `TypeError`

## 1.0.0

- 基于 GatewayWorker 封装 ThinkPHP8 WebSocket 即时通讯扩展
- 支持 Linux 下 `php think workerman` 单命令启动全部进程
- 支持 Windows 下 register、business、gateway 分进程启动
- 支持客户端连接下发 client_id、绑定 member_id、心跳检测
- 支持 wss 及证书配置
- 自动发布 `config/workerman.php` 配置文件
