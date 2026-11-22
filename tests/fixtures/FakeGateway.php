<?php
declare(strict_types = 1);

namespace youvision\tests\fixtures;

use youvision\contract\GatewayApi;

/**
 * 内存版 Gateway：记录发送、关闭、绑定，session 可在测试中预置
 */
final class FakeGateway implements GatewayApi
{
    /** @var array<int, array{string, array}> [client_id, 解码后的消息] */
    public array $sent = [];

    /** @var array<string, ?array> client_id => 关闭时附带的消息（解码后） */
    public array $closed = [];

    /** @var array<string, string> client_id => uid */
    public array $bound = [];

    /** @var array<string, array> */
    public array $sessions = [];

    /** @var array<string, bool> */
    public array $online = [];

    public function sendToClient(string $client_id, string $message): void
    {
        $this->sent[] = [$client_id, json_decode($message, true)];
    }

    public function closeClient(string $client_id, ?string $message = null): void
    {
        $this->closed[$client_id] = $message === null ? null : json_decode($message, true);
        $this->online[$client_id] = false;
    }

    public function bindUid(string $client_id, string $uid): void
    {
        $this->bound[$client_id] = $uid;
    }

    public function isOnline(string $client_id): bool
    {
        return $this->online[$client_id] ?? true;
    }

    public function getUidByClientId(string $client_id): ?string
    {
        return $this->bound[$client_id] ?? null;
    }

    public function getSession(string $client_id): array
    {
        return $this->sessions[$client_id] ?? [];
    }

    /**
     * 最后一条发给 client_id 的消息
     */
    public function last(string $client_id = 'c1'): ?array
    {
        for ($i = count($this->sent) - 1; $i >= 0; $i--) {
            if ($this->sent[$i][0] === $client_id) {
                return $this->sent[$i][1];
            }
        }

        return null;
    }

    /**
     * 发给 client_id 的全部消息类型
     *
     * @return string[]
     */
    public function types(string $client_id = 'c1'): array
    {
        $types = [];
        foreach ($this->sent as [$id, $msg]) {
            if ($id === $client_id) {
                $types[] = $msg['type'] ?? '';
            }
        }

        return $types;
    }
}
