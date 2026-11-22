<?php
declare(strict_types = 1);

namespace youvision\tests\fixtures;

use youvision\contract\MessageHandler;
use youvision\Message;

final class EchoHandler implements MessageHandler
{
    public function handle(Message $message): void
    {
        $message->reply('echo_ok', ['uid' => $message->uid()] + $message->data);
    }
}

final class ChatHandlers
{
    public static function read(Message $message): void
    {
        $message->reply('chat.read_ok', ['id' => $message->get('id')]);
    }

    public function send(Message $message): void
    {
        $message->reply('chat.send_ok');
    }
}

final class BoomHandler implements MessageHandler
{
    public function handle(Message $message): void
    {
        throw new \RuntimeException('boom');
    }
}

final class NotAHandler
{
}
