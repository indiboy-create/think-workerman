<?php
declare(strict_types = 1);

namespace youvision\command;

use think\console\Command;
use youvision\support\Worker;

class Business extends Command
{
    public function configure(): void
    {
        $this->setName('workerman:business')
            ->setDescription('Windows 下单独启动 business 业务进程');
    }

    public function handle(): int
    {
        $this->output->info('business 业务进程启动');

        Worker::boot();
        Worker::business();
        Worker::run();

        return 0;
    }
}