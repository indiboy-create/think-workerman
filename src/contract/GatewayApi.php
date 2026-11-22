<?php
declare(strict_types = 1);

namespace youvision\contract;

/**
 * 事件类用到的 Gateway 操作。默认实现转发到 GatewayWorker\Lib\Gateway，
 * 测试时可替换为内存实现。
 */
interface GatewayApi
{
    public function sendToClient(string $client_id, string $message): void;

    public function closeClient(string $client_id, ?string $message = null): void;

    public function bindUid(string $client_id, string $uid): void;

    public function isOnline(string $client_id): bool;

    public function getUidByClientId(string $client_id): ?string;

    public function getSession(string $client_id): array;
}
