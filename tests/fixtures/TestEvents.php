<?php
declare(strict_types = 1);

namespace youvision\tests\fixtures;

use youvision\Identity;
use youvision\WorkermanEvents;

/**
 * 测试用事件类：记录钩子调用、捕获定时器、配置由测试指定
 */
class TestEvents extends WorkermanEvents
{
    /** @var array<string, mixed> */
    public static array $config = [];

    /** @var array<int, array{float, callable}> */
    public static array $timers = [];

    /** @var array<int, array{string, mixed}> */
    public static array $hooks = [];

    public static function reset(): void
    {
        self::$config = [];
        self::$timers = [];
        self::$hooks = [];
    }

    /**
     * 依次执行已捕获的定时器
     */
    public static function fireTimers(): void
    {
        $timers = self::$timers;
        self::$timers = [];
        foreach ($timers as [, $fn]) {
            $fn();
        }
    }

    protected static function addTimer(float $delay, callable $fn): void
    {
        self::$timers[] = [$delay, $fn];
    }

    protected static function config(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    protected static function onAuthed(string $client_id, Identity $identity): void
    {
        self::$hooks[] = ['authed', $identity->uid];
    }

    protected static function onClientMessage(string $client_id, string $type, array $data, string $msg_id): void
    {
        self::$hooks[] = ['message', [$type, $data, $msg_id]];

        if ($type === 'echo') {
            static::reply($client_id, 'echo_ok', $data, $msg_id);
        }
    }

    protected static function onDisconnect(string $client_id, array $session): void
    {
        self::$hooks[] = ['disconnect', $session];
    }
}
