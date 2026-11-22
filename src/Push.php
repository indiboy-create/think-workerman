<?php
declare(strict_types = 1);

namespace youvision;

use GatewayWorker\Lib\Gateway;
use youvision\support\Protocol;

/**
 * 在 ThinkPHP 控制器、模型、命令中推送消息
 *
 * 推送失败（例如推送服务未启动）只记录日志并返回 false，不会抛出异常打断业务请求。
 */
class Push
{
    /*
     * 以下推送方法的 $msg_id 留空时自动生成，便于客户端去重与回执
     */

    public static function toUid(string|array $uid, string $type, array $data = [], string $msg_id = ''): bool
    {
        return self::call(static fn () => Gateway::sendToUid($uid, Protocol::encode($type, $data, $msg_id ?: Protocol::id())));
    }

    public static function toClient(string $client_id, string $type, array $data = [], string $msg_id = ''): bool
    {
        return self::call(static fn () => Gateway::sendToClient($client_id, Protocol::encode($type, $data, $msg_id ?: Protocol::id())));
    }

    public static function toGroup(string|array $group, string $type, array $data = [], array $exclude = [], string $msg_id = ''): bool
    {
        return self::call(static fn () => Gateway::sendToGroup($group, Protocol::encode($type, $data, $msg_id ?: Protocol::id()), $exclude));
    }

    public static function toAll(string $type, array $data = [], string $msg_id = ''): bool
    {
        return self::call(static fn () => Gateway::sendToAll(Protocol::encode($type, $data, $msg_id ?: Protocol::id())));
    }

    /**
     * 断开某个 uid 的全部连接（注销、封禁、修改密码等场景）
     *
     * 客户端会先收到 {"type":"kicked","reason":...}，然后连接被关闭。
     * 推送服务未启动时返回 false。
     */
    public static function kickUid(string $uid, string $reason = 'kicked'): bool
    {
        return self::call(static function () use ($uid, $reason): void {
            $message = Protocol::encode(Protocol::KICKED, ['reason' => $reason]);
            foreach ((array) Gateway::getClientIdByUid($uid) as $client_id) {
                Gateway::closeClient($client_id, $message);
            }
        });
    }

    /**
     * 断开单个连接
     */
    public static function kickClient(string $client_id, string $reason = 'kicked'): bool
    {
        return self::call(static fn () => Gateway::closeClient(
            $client_id,
            Protocol::encode(Protocol::KICKED, ['reason' => $reason]),
        ));
    }

    public static function isUidOnline(string $uid): bool
    {
        try {
            return (bool) Gateway::isUidOnline($uid);
        } catch (\Throwable $e) {
            self::log($e);

            return false;
        }
    }

    private static function call(callable $fn): bool
    {
        try {
            $fn();

            return true;
        } catch (\Throwable $e) {
            self::log($e);

            return false;
        }
    }

    private static function log(\Throwable $e): void
    {
        if (class_exists(\think\facade\Log::class)) {
            try {
                \think\facade\Log::warning('[think-workerman] 推送失败：' . $e->getMessage());

                return;
            } catch (\Throwable) {
            }
        }
        error_log('[think-workerman] 推送失败：' . $e->getMessage());
    }
}
