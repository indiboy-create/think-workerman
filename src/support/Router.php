<?php
declare(strict_types = 1);

namespace youvision\support;

use youvision\contract\MessageHandler;
use youvision\Message;

/**
 * 按消息 type 分发到处理器
 *
 * config('workerman.routes') 示例：
 *   'chat.send'  => \app\ws\ChatSend::class,          // 实现 MessageHandler 的类
 *   'chat.read'  => [\app\ws\Chat::class, 'read'],     // [类名, 方法]，方法签名 fn(Message): void
 *   'sync.*'     => \app\ws\Sync::class,              // 前缀通配，精确匹配优先
 */
class Router
{
    /** @var array<string, mixed>|null 运行期替换的路由表 */
    private static ?array $routes = null;

    /** @var array<string, \Closure> 已解析的处理器 */
    private static array $resolved = [];

    /**
     * 分发消息；没有匹配的路由时返回 false
     */
    public static function dispatch(Message $message): bool
    {
        $key = self::match($message->type);
        if ($key === null) {
            return false;
        }

        $handler = self::$resolved[$key] ??= self::resolve($key, self::routes()[$key]);
        $handler($message);

        return true;
    }

    /**
     * 启动时校验全部路由，配置错误直接抛出异常
     */
    public static function check(): int
    {
        $routes = self::routes();
        foreach ($routes as $type => $target) {
            self::$resolved[$type] = self::resolve((string) $type, $target);
        }

        return count($routes);
    }

    /**
     * 测试或运行期替换路由表，传 null 恢复读取配置
     */
    public static function using(?array $routes): void
    {
        self::$routes = $routes === null ? null : self::normalize($routes);
        self::$resolved = [];
    }

    /**
     * 精确匹配优先，其次最长的 "前缀.*" 通配
     */
    public static function match(string $type): ?string
    {
        $routes = self::routes();
        if (isset($routes[$type])) {
            return $type;
        }

        $best = null;
        foreach (array_keys($routes) as $key) {
            $key = (string) $key;
            if (!str_ends_with($key, '.*')) {
                continue;
            }
            $prefix = substr($key, 0, -1); // 保留点号，"sync.*" → "sync."
            if (str_starts_with($type, $prefix) && ($best === null || strlen($key) > strlen($best))) {
                $best = $key;
            }
        }

        return $best;
    }

    /**
     * @return array<string, mixed>
     */
    private static function routes(): array
    {
        if (self::$routes !== null) {
            return self::$routes;
        }

        $cfg = function_exists('config') ? config('workerman.routes') : null;

        return self::$routes = is_array($cfg) ? self::normalize($cfg) : [];
    }

    private static function normalize(array $routes): array
    {
        $out = [];
        foreach ($routes as $type => $target) {
            $out[strtolower((string) $type)] = $target;
        }

        return $out;
    }

    private static function resolve(string $type, mixed $target): \Closure
    {
        if (is_string($target) && class_exists($target)) {
            $obj = self::make($target);
            if (!$obj instanceof MessageHandler) {
                throw new \RuntimeException("路由 [$type]：$target 必须实现 " . MessageHandler::class);
            }

            return \Closure::fromCallable([$obj, 'handle']);
        }

        if (is_array($target) && count($target) === 2 && is_string($target[0]) && is_string($target[1])) {
            [$class, $method] = $target;
            if (!class_exists($class)) {
                throw new \RuntimeException("路由 [$type]：类 $class 不存在");
            }
            if (!method_exists($class, $method)) {
                throw new \RuntimeException("路由 [$type]：方法 $class::$method 不存在");
            }
            $ref = new \ReflectionMethod($class, $method);

            return $ref->isStatic()
                ? \Closure::fromCallable([$class, $method])
                : \Closure::fromCallable([self::make($class), $method]);
        }

        if (is_callable($target)) {
            return \Closure::fromCallable($target);
        }

        throw new \RuntimeException("路由 [$type]：处理器必须是 MessageHandler 类名、[类名, 方法] 或 callable");
    }

    private static function make(string $class): object
    {
        return function_exists('app') ? app($class) : new $class();
    }
}
