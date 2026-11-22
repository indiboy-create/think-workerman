<?php
declare(strict_types = 1);

namespace youvision\support;

/**
 * 消息类型常量与编解码
 */
final class Protocol
{
    public const string WELCOME = 'welcome';
    public const string AUTH = 'auth';
    public const string AUTH_OK = 'auth_ok';
    public const string AUTH_FAIL = 'auth_fail';
    public const string PING = 'ping';
    public const string PONG = 'pong';
    public const string KICKED = 'kicked';
    public const string ERROR = 'error';

    /** 1.x 数字类型 → 2.x 字符串类型 */
    private const array LEGACY = [
        11 => self::WELCOME,
        12 => self::AUTH,
    ];

    /**
     * 解析客户端消息，返回 [type, data, msg_id]；不是合法消息时返回 null
     *
     * 标准格式：{"type":"xxx","data":{...},"msg_id":"..."}
     * 兼容扁平格式：{"type":"auth","token":"..."}，除 type / msg_id 外的字段视为 data
     */
    public static function decode(string $message, bool $legacy = true): ?array
    {
        $raw = json_decode($message, true);
        if (!is_array($raw) || array_is_list($raw) || !isset($raw['type']) || !is_scalar($raw['type'])) {
            return null;
        }

        $type = $raw['type'];
        if (is_int($type) || ctype_digit((string) $type)) {
            if (!$legacy) {
                return null;
            }
            $type = self::LEGACY[(int) $type] ?? null;
            if ($type === null) {
                return null;
            }
        }

        $msgId = isset($raw['msg_id']) && is_scalar($raw['msg_id']) ? (string) $raw['msg_id'] : '';

        if (array_key_exists('data', $raw)) {
            $data = is_array($raw['data']) ? $raw['data'] : [];
        } else {
            $data = $raw;
            unset($data['type'], $data['msg_id']);
        }

        return [strtolower((string) $type), $data, $msgId];
    }

    /**
     * 编码为标准格式 {"type":"xxx","data":{...}[,"msg_id":"..."]}
     *
     * data 为空时输出 {}；msg_id 为空时不输出。回复客户端请求时传入请求的 msg_id 以便客户端对应。
     */
    public static function encode(string $type, array $data = [], string $msgId = ''): string
    {
        $payload = ['type' => $type, 'data' => $data === [] ? new \stdClass() : $data];
        if ($msgId !== '') {
            $payload['msg_id'] = $msgId;
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
        $json = json_encode($payload, $flags);

        // 仍然失败（如 NAN、INF、递归过深）时退化为空 data，保证返回 string
        if ($json === false) {
            $payload['data'] = new \stdClass();
            $json = (string) json_encode($payload, $flags);
        }

        return $json;
    }

    /**
     * 生成消息 ID（服务端主动推送时可用）
     */
    public static function id(): string
    {
        return bin2hex(random_bytes(8));
    }
}
