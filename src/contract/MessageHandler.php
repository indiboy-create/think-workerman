<?php
declare(strict_types = 1);

namespace youvision\contract;

use youvision\Message;

interface MessageHandler
{
    /**
     * 处理一条已鉴权的客户端消息，需要回复时调用 $message->reply()
     */
    public function handle(Message $message): void;
}
