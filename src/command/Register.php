<?php
declare(strict_types = 1);

namespace youvision\command;

use think\console\Command;
use youvision\support\Worker;

class Register extends Command
{
    public function configure(): void
    {
        $this->setName('workerman:register')
            ->setDescription('Windows 下单独启动 register 注册中心');
    }

    public function handle(): int
    {
        $this->output->info('register 注册中心启动');

        Worker::boot();
        Worker::register();
        Worker::run();

        return 0;
    }
}