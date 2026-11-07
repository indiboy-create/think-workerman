<?php
declare(strict_types = 1);

namespace youvision\command;

use think\console\Command;
use youvision\support\Worker;

class Gateway extends Command
{
    public function configure(): void
    {
        $this->setName('workerman:gateway')
            ->setDescription('Windows 下单独启动 gateway WebSocket 网关');
    }

    public function handle(): int
    {
        $this->output->info('gateway WebSocket 网关启动');

        Worker::boot();
        Worker::gateway();
        Worker::run();

        return 0;
    }
}