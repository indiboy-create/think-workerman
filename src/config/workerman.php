<?php
/**
 * youvision/think-workerman 配置文件
 * 安装扩展后会自动发布到项目 config/workerman.php
 */
return [
    // register 注册中心监听地址，register、business、gateway 三个进程必须保持一致
    // 该地址只用于内网通信，无需对公网开放
    'register_address' => '127.0.0.1:1201',

    // register 进程名称，用于进程列表展示
    'register_name' => 'register',

    // business 进程名称，用于进程列表展示
    'business_name' => 'business',

    // business 进程数量，Windows 下固定为 1，Linux 下可按 CPU 核数调整
    'business_count' => 1,

    // 业务事件回调类，必须实现 onConnect、onMessage、onClose 三个静态方法
    // 可替换为项目自己的事件类，用于接收聊天消息并完成转发
    'event_handler' => \youvision\WorkermanEvents::class,

    // gateway 进程名称，用于进程列表展示
    'gateway_name' => 'gateway',

    // WebSocket 对外监听地址
    // websocket://0.0.0.0:8201 表示监听所有网卡的 8201 端口
    // 使用 SSL 时该地址保持不变，仅开启下方 ssl.is_use
    'gateway_address' => 'websocket://0.0.0.0:8201',

    // gateway 进程数量，大于 1 时内部通信端口从 gateway_start_port 开始依次递增
    'gateway_count' => 1,

    // gateway 与 business 通信使用的本机网卡 IP，多机部署时填写内网 IP
    'gateway_lan_ip' => '127.0.0.1',

    // gateway 内部通信起始端口，实际占用范围为
    // gateway_start_port 至 gateway_start_port + gateway_count - 1
    'gateway_start_port' => 4001,

    // 服务端心跳发送间隔（秒）
    // 客户端收到 {"type":"ping"} 后应返回 {"type":"pong"}
    'gateway_ping_interval' => 55,

    // 允许客户端连续不响应心跳的次数，0 表示不主动断开连接，建议保持 0
    'gateway_ping_not_response_limit' => 0,

    // 服务端下发的心跳数据，必须是 JSON 字符串
    'gateway_ping_data' => '{"type":"ping"}',

    // 是否以守护进程方式运行，Linux 建议开启，Windows 不支持守护进程
    'daemonize' => false,

    // Workerman 日志文件路径，留空则使用 runtime/workerman.log
    'log_file' => runtime_path() . 'workerman.log',

    // SSL 配置，仅在需要 wss 时开启
    'ssl' => [
        // 是否启用 SSL（wss）
        'is_use' => false,

        // 证书文件路径，一般为 .crt 或 .pem 文件
        'local_cert' => '',

        // 证书私钥文件路径，一般为 .key 文件
        'local_pk' => '',

        // 是否验证客户端证书，通常保持 false
        'verify_peer' => false,

        // 是否允许自签名证书
        'allow_self_signed' => false,
    ],
];