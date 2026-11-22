<?php
/**
 * youvision/think-workerman 配置文件
 * 安装扩展后会自动发布到项目 config/workerman.php
 */
return [
    // register 注册中心监听地址，register、business、gateway 三个进程必须保持一致
    // 该地址只用于内网通信，不要对公网开放
    'register_address' => '127.0.0.1:1201',

    // 内部通信密钥：register、business、gateway 与 Gateway::sendXxx 推送方必须一致
    // 留空表示不校验，生产环境务必设置一个随机字符串
    'secret_key' => env('WORKERMAN_SECRET', ''),

    // register 进程名称，用于进程列表展示
    'register_name' => 'register',

    // business 进程名称，用于进程列表展示
    'business_name' => 'business',

    // business 进程数量，Windows 下固定为 1，Linux 下可按 CPU 核数调整
    'business_count' => 1,

    // 业务事件回调类，建议继承 \youvision\WorkermanEvents 并覆盖 onAuthed / onClientMessage 等钩子
    'event_handler' => \youvision\WorkermanEvents::class,

    // 消息路由：已鉴权连接的业务消息按 type 分发
    // 值可以是实现 \youvision\contract\MessageHandler 的类名、[类名, 方法名] 或 callable
    // 支持 "前缀.*" 通配，精确匹配优先，例如：
    //   'chat.send' => \app\ws\ChatSend::class,
    //   'chat.read' => [\app\ws\Chat::class, 'read'],
    //   'sync.*'    => \app\ws\Sync::class,
    'routes' => [],

    // 未匹配到路由时是否回复 {"type":"error","data":{"reason":"unknown_type",...}}
    'route_unknown_reply' => true,

    // 鉴权器：实现 \youvision\contract\Authenticator 的类名，或 callable
    // 签名：fn(string $token, array $context): ?\youvision\Identity
    // 返回 null 表示拒绝。未配置时所有绑定请求都会被拒绝
    'auth' => null,

    // 连接建立后必须在多少秒内完成鉴权，超时断开；0 表示不限制（不建议）
    'auth_timeout' => 10,


    // gateway 进程名称，用于进程列表展示
    'gateway_name' => 'gateway',

    // WebSocket 监听地址
    // 默认只监听本机，由 Nginx 等反向代理提供 wss；需要直接对外时改为 websocket://0.0.0.0:8201
    'gateway_address' => 'websocket://127.0.0.1:8201',

    // gateway 进程数量，大于 1 时内部通信端口从 gateway_start_port 开始依次递增
    'gateway_count' => 1,

    // gateway 与 business 通信使用的本机网卡 IP，多机部署时填写内网 IP
    'gateway_lan_ip' => '127.0.0.1',

    // gateway 内部通信起始端口，实际占用范围为
    // gateway_start_port 至 gateway_start_port + gateway_count - 1
    'gateway_start_port' => 4001,

    // 服务端心跳发送间隔（秒）
    'gateway_ping_interval' => 25,

    // 客户端连续多少个心跳周期内没有发送任何消息就断开
    // 客户端收到 {"type":"ping"} 回复 {"type":"pong"} 即可；0 表示不断开（会残留死连接，不建议）
    'gateway_ping_not_response_limit' => 2,

    // 服务端下发的心跳数据，必须是 JSON 字符串
    'gateway_ping_data' => '{"type":"ping","data":{}}',

    // 单条消息最大字节数，超过会断开连接。客户端只发鉴权与心跳时 64KB 足够
    'max_package_size' => 65536,

    // 是否以守护进程方式运行，Linux 建议开启，Windows 不支持守护进程
    'daemonize' => false,

    // Workerman 日志文件路径，留空则使用 runtime/workerman.log
    'log_file' => '',

    // SSL 配置，仅在 gateway 直接对外提供 wss 时开启；使用反向代理时保持关闭
    'ssl' => [
        'is_use' => false,
        'local_cert' => '',
        'local_pk' => '',
        'verify_peer' => false,
        'allow_self_signed' => false,
    ],
];
