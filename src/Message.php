<?php
declare(strict_types = 1);

namespace youvision;

use youvision\contract\GatewayApi;
use youvision\support\Protocol;

/**
 * 路由到处理器的一条已鉴权客户端消息
 */
final readonly class Message
{
    public function __construct(
        public string $clientId,
        public string $type,
        public array $data,
        public string $msgId,
        public array $session,
        private GatewayApi $gateway,
    ) {
    }

    /**
     * 当前连接的 uid
     */
    public function uid(): string
    {
        return (string) ($this->session['uid'] ?? '');
    }

    /**
     * 读取 data 中的字段
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * 回复当前连接，自动带回 msg_id
     */
    public function reply(string $type, array $data = []): void
    {
        $this->gateway->sendToClient($this->clientId, Protocol::encode($type, $data, $this->msgId));
    }

    /**
     * 回复错误：{"type":"error","data":{"reason":...,"type":原消息类型,...}}
     */
    public function error(string $reason, array $extra = []): void
    {
        $this->reply(Protocol::ERROR, ['reason' => $reason, 'type' => $this->type] + $extra);
    }
}
