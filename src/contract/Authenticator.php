<?php
declare(strict_types = 1);

namespace youvision\contract;

use youvision\Identity;

interface Authenticator
{
    /**
     * 校验令牌
     *
     * @param string $token   客户端提供的令牌（Authorization: Bearer xxx 或 auth 消息中的 token）
     * @param array  $context ['client_id' => string, 'source' => 'header'|'message', 'server' => array]
     * @return Identity|null  返回 null 表示拒绝
     */
    public function verify(string $token, array $context): ?Identity;
}
