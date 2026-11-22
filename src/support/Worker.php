<?php
declare(strict_types = 1);

namespace youvision\support;

use GatewayWorker\BusinessWorker;
use GatewayWorker\Gateway;
use GatewayWorker\Register;
use Workerman\Connection\TcpConnection;
use youvision\WorkermanEvents;

class Worker
{
    /**
     * 创建 register 进程
     */
    public static function register(): Register
    {
        $register = new Register('text://' . self::registerAddress());
        $register->name = (string) self::value('workerman.register_name', 'register');
        $register->secretKey = self::secretKey();

        return $register;
    }

    /**
     * 创建 business 进程
     */
    public static function business(): BusinessWorker
    {
        // 启动前校验鉴权器，配置错误时立即失败，而不是在运行期反复崩溃重启
        if (!Auth::check()) {
            \Workerman\Worker::log('[think-workerman] 未配置 workerman.auth，所有鉴权请求都会被拒绝');
        }

        // 启动前校验路由表，处理器不存在或类型不对时立即失败
        Router::check();

        $worker = new BusinessWorker();
        $worker->registerAddress = self::registerAddress();
        $worker->name = (string) self::value('workerman.business_name', 'business');
        $worker->count = (int) self::value('workerman.business_count', 1);
        $worker->eventHandler = (string) self::value('workerman.event_handler', WorkermanEvents::class);
        $worker->secretKey = self::secretKey();

        return $worker;
    }

    /**
     * 创建 gateway 进程
     */
    public static function gateway(): Gateway
    {
        $address = (string) self::value('workerman.gateway_address', 'websocket://127.0.0.1:8201');
        $useSsl = (bool) self::value('workerman.ssl.is_use', false);
        $context = [];

        if ($useSsl) {
            $context['ssl'] = [
                'local_cert' => (string) self::value('workerman.ssl.local_cert', ''),
                'local_pk' => (string) self::value('workerman.ssl.local_pk', ''),
                'verify_peer' => (bool) self::value('workerman.ssl.verify_peer', false),
                'allow_self_signed' => (bool) self::value('workerman.ssl.allow_self_signed', false),
            ];
        }

        $gateway = new Gateway($address, $context);

        if ($useSsl) {
            $gateway->transport = 'ssl';
        }

        $gateway->name = (string) self::value('workerman.gateway_name', 'gateway');
        $gateway->count = (int) self::value('workerman.gateway_count', 1);
        $gateway->lanIp = (string) self::value('workerman.gateway_lan_ip', '127.0.0.1');
        $gateway->startPort = (int) self::value('workerman.gateway_start_port', 4001);
        $gateway->pingInterval = (int) self::value('workerman.gateway_ping_interval', 25);
        $gateway->pingNotResponseLimit = (int) self::value('workerman.gateway_ping_not_response_limit', 2);
        $gateway->pingData = (string) self::value('workerman.gateway_ping_data', '{"type":"ping","data":{}}');
        $gateway->registerAddress = self::registerAddress();
        $gateway->secretKey = self::secretKey();

        // 只限制客户端连接的包大小；不修改全局默认值，避免影响 gateway 与 business 的内部通信
        $max = (int) self::value('workerman.max_package_size', 65536);
        if ($max > 0) {
            $gateway->onConnect = static function (TcpConnection $connection) use ($max): void {
                $connection->maxPackageSize = $max;
            };
        }

        return $gateway;
    }

    /**
     * 初始化 Workerman 全局配置
     */
    public static function boot(): void
    {
        $logFile = (string) self::value('workerman.log_file', '');
        \Workerman\Worker::$logFile = $logFile !== '' ? $logFile : runtime_path() . 'workerman.log';
        \Workerman\Worker::$daemonize = (bool) self::value('workerman.daemonize', false);

        if (self::secretKey() === '') {
            \Workerman\Worker::log('[think-workerman] 未设置 workerman.secret_key，内部通信未做校验');
        }
    }

    /**
     * 交给 Workerman 接管进程
     *
     * @param string   $action start|stop|restart|reload|status|connections
     * @param string[] $flags  透传给 Workerman 的参数，如 -d（守护进程）、-g（平滑停止/重启）
     */
    public static function run(string $action = 'start', array $flags = []): void
    {
        $argv = $_SERVER['argv'] ?? [];
        $argv = array_merge([$argv[0] ?? 'think', $action], array_values($flags));

        $_SERVER['argv'] = $argv;
        $GLOBALS['argv'] = $argv;

        \Workerman\Worker::runAll();
    }

    /**
     * register 注册中心地址
     */
    public static function registerAddress(): string
    {
        return (string) self::value('workerman.register_address', '127.0.0.1:1201');
    }

    /**
     * 内部通信密钥
     */
    public static function secretKey(): string
    {
        return (string) self::value('workerman.secret_key', '');
    }

    /**
     * 读取配置，未配置或空字符串时使用默认值
     */
    private static function value(string $key, mixed $default = null): mixed
    {
        $value = config($key);

        return $value === null || $value === '' ? $default : $value;
    }
}
