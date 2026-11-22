<?php
declare(strict_types = 1);

namespace youvision\tests\fixtures;

use youvision\WorkermanEvents;

/**
 * 不覆盖 onClientMessage，走默认路由
 */
class RouterEvents extends WorkermanEvents
{
    /** @var array<string, mixed> */
    public static array $config = [];

    protected static function addTimer(float $delay, callable $fn): void
    {
    }

    protected static function config(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    protected static function log(string $message): void
    {
    }
}
