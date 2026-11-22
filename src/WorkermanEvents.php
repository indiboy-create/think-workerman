<?php
declare(strict_types = 1);

namespace youvision;

use Workerman\Timer;
use youvision\contract\GatewayApi;
use youvision\support\Auth;
use youvision\support\GatewayAdapter;
use youvision\support\Router;
use youvision\support\Protocol;

/**
 * 默认事件回调
 *
 * 流程：
 *   连接 → 下发 welcome
 *   握手请求头带 Authorization: Bearer xxx → 直接鉴权
 *   否则客户端发送 {"type":"auth","token":"xxx"} → 鉴权
 *   auth_timeout 秒内未鉴权 → 断开
 *   ping / pong 由服务端心跳处理，业务消息交给 onClientMessage
 *
 * 项目可继承本类并覆盖 onAuthed / onClientMessage / onDisconnect 钩子。
 */
class WorkermanEvents
{
    private static ?GatewayApi $gateway = null;

    /**
     * 替换 Gateway 实现（测试用），传 null 恢复默认
     */
    public static function useGateway(?GatewayApi $gateway): void
    {
        self::$gateway = $gateway;
    }

    protected static function gateway(): GatewayApi
    {
        return self::$gateway ??= new GatewayAdapter();
    }

    /**
     * 添加一次性定时器，测试中可覆盖
     */
    protected static function addTimer(float $delay, callable $fn): void
    {
        Timer::add($delay, $fn, [], false);
    }

    public static function onConnect(string $client_id): void
    {
        static::gateway()->sendToClient($client_id, Protocol::encode(Protocol::WELCOME, [
            'client_id' => $client_id,
        ]));

        $timeout = (int) static::config('auth_timeout', 10);
        if ($timeout > 0) {
            static::addTimer($timeout, static function () use ($client_id): void {
                if (static::gateway()->isOnline($client_id) && static::gateway()->getUidByClientId($client_id) === null) {
                    static::gateway()->sendToClient($client_id, Protocol::encode(Protocol::AUTH_FAIL, ['reason' => 'timeout']));
                    static::gateway()->closeClient($client_id);
                }
            });
        }
    }

    /**
     * WebSocket 握手完成时调用，$data 含 get / server / cookie 等握手信息
     */
    public static function onWebSocketConnect(string $client_id, array $data): void
    {
        $server = $data['server'] ?? [];
        $token = Auth::bearer($server['HTTP_AUTHORIZATION'] ?? null);
        if ($token === '') {
            return;
        }

        static::authenticate($client_id, $token, 'header', $server);
    }

    public static function onMessage(string $client_id, string $message): void
    {
        $parsed = Protocol::decode($message, (bool) static::config('legacy_types', true));
        if ($parsed === null) {
            return;
        }
        [$type, $data, $msgId] = $parsed;

        if ($type === Protocol::PONG) {
            return;
        }

        if ($type === Protocol::AUTH) {
            $token = isset($data['token']) && is_string($data['token']) ? $data['token'] : '';
            static::authenticate($client_id, $token, 'message', [], $msgId);

            return;
        }

        // 未鉴权的连接不处理任何业务消息；读本地 session，避免每条消息远程查询 gateway
        if (!static::isAuthed()) {
            return;
        }

        // 令牌已过期：通知后断开（定时器之外的兜底，防止定时器丢失）
        if (static::sessionExpired()) {
            static::expire($client_id);

            return;
        }

        static::onClientMessage($client_id, $type, $data, $msgId);
    }

    public static function onClose(string $client_id): void
    {
        static::onDisconnect($client_id, $_SESSION ?? []);
    }

    // ---------------------------------------------------------------- 钩子

    /**
     * 鉴权通过后调用，可在此加入分组、记录在线设备等
     */
    protected static function onAuthed(string $client_id, Identity $identity): void
    {
    }

    /**
     * 已鉴权连接的业务消息
     *
     * 默认按 config('workerman.routes') 分发；未匹配时回复 error(unknown_type)，
     * 处理器抛出异常时记录日志并回复 error(handler_error)。
     * 子类可覆盖本方法完全自行处理，或调用 parent:: 保留路由。
     *
     * $data 为消息的 data 字段；$msg_id 为客户端提供的消息 ID（未提供时为空字符串）
     */
    protected static function onClientMessage(string $client_id, string $type, array $data, string $msg_id): void
    {
        $message = new Message($client_id, $type, $data, $msg_id, $_SESSION ?? [], static::gateway());

        try {
            if (!Router::dispatch($message)) {
                if (static::config('route_unknown_reply', true)) {
                    $message->error('unknown_type');
                }
            }
        } catch (\Throwable $e) {
            static::log('处理消息 [' . $type . '] 异常：' . $e->getMessage());
            $message->error('handler_error');
        }
    }

    /**
     * 连接断开，$session 为该连接的 session（含 uid 与 Identity::extra）
     */
    protected static function onDisconnect(string $client_id, array $session): void
    {
    }

    // ---------------------------------------------------------------- 内部

    protected static function authenticate(string $client_id, string $token, string $source, array $server, string $msgId = ''): void
    {
        $authed = static::isAuthed();

        // 已鉴权且未提供新令牌：直接确认
        if ($authed && trim($token) === '') {
            static::gateway()->sendToClient($client_id, Protocol::encode(Protocol::AUTH_OK, ['uid' => $_SESSION['uid']], $msgId));

            return;
        }

        $identity = Auth::verify($token, [
            'client_id' => $client_id,
            'source' => $source,
            'server' => $server,
        ]);

        if ($identity === null || $identity->expired()) {
            static::gateway()->sendToClient($client_id, Protocol::encode(Protocol::AUTH_FAIL, [
                'reason' => $identity === null ? 'invalid_token' : 'expired',
            ], $msgId));
            static::gateway()->closeClient($client_id);

            return;
        }

        // 已鉴权连接刷新令牌：只允许续期同一 uid，不允许借此切换账号
        if ($authed) {
            if ((string) $_SESSION['uid'] !== $identity->uid) {
                static::gateway()->sendToClient($client_id, Protocol::encode(Protocol::AUTH_FAIL, ['reason' => 'uid_mismatch'], $msgId));

                return;
            }

            $_SESSION = ['uid' => $identity->uid, '_exp' => $identity->expiresAt] + $identity->extra + $_SESSION;
            static::scheduleExpire($client_id, $identity->expiresAt);
            static::gateway()->sendToClient($client_id, Protocol::encode(Protocol::AUTH_OK, [
                'uid' => $identity->uid,
                'expires_at' => $identity->expiresAt,
                'refreshed' => true,
            ], $msgId));

            return;
        }

        static::gateway()->bindUid($client_id, $identity->uid);
        // 直接写 $_SESSION：回调结束后由 GatewayWorker 自动同步到 gateway，
        // 同一回调内后续的 isAuthed() 判断也能立即生效
        $_SESSION = ['uid' => $identity->uid, '_exp' => $identity->expiresAt]
            + $identity->extra
            + (is_array($_SESSION ?? null) ? $_SESSION : []);
        static::scheduleExpire($client_id, $identity->expiresAt);
        static::gateway()->sendToClient($client_id, Protocol::encode(Protocol::AUTH_OK, [
            'uid' => $identity->uid,
            'expires_at' => $identity->expiresAt,
        ], $msgId));

        static::onAuthed($client_id, $identity);
    }

    /**
     * 回复当前连接，带回请求的 msg_id
     */
    protected static function reply(string $client_id, string $type, array $data = [], string $msg_id = ''): void
    {
        static::gateway()->sendToClient($client_id, Protocol::encode($type, $data, $msg_id));
    }

    /**
     * 当前连接是否已鉴权（读取本地 session，不产生远程调用）
     */
    protected static function isAuthed(): bool
    {
        return isset($_SESSION['uid']) && $_SESSION['uid'] !== '';
    }

    /**
     * 当前 session 中的令牌是否已过期
     */
    protected static function sessionExpired(): bool
    {
        $exp = $_SESSION['_exp'] ?? null;

        return is_int($exp) && $exp <= time();
    }

    /**
     * 到期时检查 session 中的过期时间，仍过期则断开。
     * 期间客户端若已续期，session 中的 _exp 会被更新，本次检查自然失效。
     */
    protected static function scheduleExpire(string $client_id, ?int $expiresAt): void
    {
        if ($expiresAt === null) {
            return;
        }

        $delay = max(1, $expiresAt - time());
        static::addTimer($delay, static function () use ($client_id): void {
            if (!static::gateway()->isOnline($client_id)) {
                return;
            }
            $session = static::gateway()->getSession($client_id);
            $exp = $session['_exp'] ?? null;
            if (is_int($exp) && $exp <= time()) {
                static::expire($client_id);
            }
        });
    }

    /**
     * 通知令牌过期并断开
     */
    protected static function expire(string $client_id): void
    {
        static::gateway()->closeClient($client_id, Protocol::encode(Protocol::AUTH_FAIL, ['reason' => 'expired']));
    }

    protected static function log(string $message): void
    {
        if (class_exists(\Workerman\Worker::class)) {
            \Workerman\Worker::log('[think-workerman] ' . $message);
        }
    }

    protected static function config(string $key, mixed $default = null): mixed
    {
        $value = function_exists('config') ? config('workerman.' . $key) : null;

        return $value === null || $value === '' ? $default : $value;
    }
}
