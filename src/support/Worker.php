<?php
declare(strict_types = 1);

namespace youvision\support;

use GatewayWorker\BusinessWorker;
use GatewayWorker\Gateway;
use GatewayWorker\Register;
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

        return $register;
    }

    /**
     * 创建 business 进程
     */
    public static function business(): BusinessWorker
    {
        $worker = new BusinessWorker();
        $worker->registerAddress = self::registerAddress();
        $worker->name = (string) self::value('workerman.business_name', 'business');
        $worker->count = (int) self::value('workerman.business_count', 1);
        $worker->eventHandler = (string) self::value('workerman.event_handler', WorkermanEvents::class);

        return $worker;
    }

    /**
     * 创建 gateway 进程
     */
    public static function gateway(): Gateway
    {
        $address = (string) self::value('workerman.gateway_address', 'websocket://0.0.0.0:8201');
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
        $gateway->pingInterval = (int) self::value('workerman.gateway_ping_interval', 55);
        $gateway->pingNotResponseLimit = (int) self::value('workerman.gateway_ping_not_response_limit', 0);
        $gateway->pingData = (string) self::value('workerman.gateway_ping_data', '{"type":"ping"}');
        $gateway->registerAddress = self::registerAddress();

        return $gateway;
    }

    /**
     * 初始化 Workerman 全局配置
     */
    public static function boot(): void
    {
        \Workerman\Worker::$logFile = (string) self::value('workerman.log_file', runtime_path() . 'workerman.log');
        \Workerman\Worker::$daemonize = (bool) self::value('workerman.daemonize', false);
    }

    /**
     * 交给 Workerman 接管进程，action 对应 start|stop|restart|reload|status|connections
     */
    public static function run(string $action = 'start'): void
    {
        $argv = $_SERVER['argv'] ?? [];
        $argv = [$argv[0] ?? 'think', $action];

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
     * 读取配置，未配置或空字符串时使用默认值
     */
    private static function value(string $key, mixed $default = null): mixed
    {
        $value = config($key);

        return $value === null || $value === '' ? $default : $value;
    }
}