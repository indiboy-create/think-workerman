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

    /**
     * 解析客户端消息，返回 [type, data, msg_id]；不是合法消息时返回 null
     *
     * 格式：{"type":"xxx","data":{...},"msg_id":"..."}
     * type 必须是非空字符串；data 缺省或不是对象时视为空数组
     */
    public static function decode(string $message): ?array
    {
        $raw = json_decode($message, true);
        if (!is_array($raw) || array_is_list($raw) || !isset($raw['type']) || !is_string($raw['type']) || $raw['type'] === '') {
            return null;
        }

        $data = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : [];
        $msgId = isset($raw['msg_id']) && is_scalar($raw['msg_id']) ? (string) $raw['msg_id'] : '';

        return [strtolower($raw['type']), $data, $msgId];
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
