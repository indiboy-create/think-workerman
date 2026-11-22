<?php
declare(strict_types = 1);

namespace youvision\support;

use youvision\contract\Authenticator;
use youvision\Identity;

/**
 * 解析并调用 config('workerman.auth') 配置的鉴权器
 */
class Auth
{
    private static mixed $resolved = null;

    /**
     * 校验令牌；未配置鉴权器、令牌为空或鉴权器异常时一律返回 null
     */
    public static function verify(string $token, array $context = []): ?Identity
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        try {
            $auth = self::resolve();
        } catch (\Throwable $e) {
            self::log('鉴权器配置错误：' . $e->getMessage());

            return null;
        }

        if ($auth === null) {
            self::log('未配置 workerman.auth，已拒绝绑定请求');

            return null;
        }

        try {
            $identity = $auth instanceof Authenticator
                ? $auth->verify($token, $context)
                : $auth($token, $context);
        } catch (\Throwable $e) {
            self::log('鉴权器异常：' . $e->getMessage());

            return null;
        }

        return $identity instanceof Identity ? $identity : null;
    }

    /**
     * 从 Authorization 请求头中取出 Bearer 令牌
     */
    public static function bearer(?string $header): string
    {
        if ($header === null) {
            return '';
        }

        return preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m) ? $m[1] : '';
    }

    /**
     * 启动时校验鉴权器配置，配置错误直接抛出异常，避免 business 进程运行后才发现
     *
     * @return bool 是否已配置鉴权器
     */
    public static function check(): bool
    {
        return self::resolve() !== null;
    }

    /**
     * 测试或运行期替换鉴权器
     */
    public static function using(Authenticator|callable|null $auth): void
    {
        self::$resolved = $auth;
    }

    private static function resolve(): Authenticator|\Closure|null
    {
        if (self::$resolved !== null) {
            return self::$resolved instanceof Authenticator
                ? self::$resolved
                : \Closure::fromCallable(self::$resolved);
        }

        $cfg = function_exists('config') ? config('workerman.auth') : null;
        if ($cfg === null || $cfg === '') {
            return null;
        }

        if (is_string($cfg) && class_exists($cfg)) {
            $obj = function_exists('app') ? app($cfg) : new $cfg();
            if (!$obj instanceof Authenticator) {
                throw new \RuntimeException($cfg . ' 必须实现 ' . Authenticator::class);
            }
            self::$resolved = $obj;

            return $obj;
        }

        if (is_callable($cfg)) {
            self::$resolved = $cfg;

            return \Closure::fromCallable($cfg);
        }

        throw new \RuntimeException('workerman.auth 必须是 Authenticator 类名或 callable');
    }

    private static function log(string $message): void
    {
        if (class_exists(\Workerman\Worker::class)) {
            \Workerman\Worker::log('[think-workerman] ' . $message);
        }
    }
}
