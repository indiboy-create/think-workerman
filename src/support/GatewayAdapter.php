<?php
declare(strict_types = 1);

namespace youvision\support;

use GatewayWorker\Lib\Gateway;
use youvision\contract\GatewayApi;

/**
 * GatewayApi 的默认实现，转发到 GatewayWorker\Lib\Gateway
 */
final class GatewayAdapter implements GatewayApi
{
    public function sendToClient(string $client_id, string $message): void
    {
        Gateway::sendToClient($client_id, $message);
    }

    public function closeClient(string $client_id, ?string $message = null): void
    {
        $message === null ? Gateway::closeClient($client_id) : Gateway::closeClient($client_id, $message);
    }

    public function bindUid(string $client_id, string $uid): void
    {
        Gateway::bindUid($client_id, $uid);
    }

    public function isOnline(string $client_id): bool
    {
        return (bool) Gateway::isOnline($client_id);
    }

    public function getUidByClientId(string $client_id): ?string
    {
        $uid = Gateway::getUidByClientId($client_id);

        return $uid === null || $uid === false || $uid === '' ? null : (string) $uid;
    }

    public function getSession(string $client_id): array
    {
        $session = Gateway::getSession($client_id);

        return is_array($session) ? $session : [];
    }
}
