<?php
declare(strict_types = 1);

namespace youvision;

use GatewayWorker\Lib\Gateway;

class WorkermanEvents
{
    /**
     * 客户端连接成功后下发 client_id
     */
    public static function onConnect(string $client_id): void
    {
        Gateway::sendToClient($client_id, json_encode([
            'type' => 11,
            'client_id' => $client_id,
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 处理客户端消息，type=12 时将当前连接绑定到 member_id
     */
    public static function onMessage(string $client_id, string $message): void
    {
        $data = json_decode($message, true);

        if (!is_array($data) || !isset($data['type']) || !is_scalar($data['type'])) {
            return;
        }

        if ((int) $data['type'] !== 12 || empty($data['member_id']) || !is_scalar($data['member_id'])) {
            return;
        }

        Gateway::bindUid($client_id, (string) $data['member_id']);

        Gateway::sendToClient($client_id, json_encode([
            'type' => 'bind_success',
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 客户端断开连接
     */
    public static function onClose(string $client_id): void
    {
    }
}