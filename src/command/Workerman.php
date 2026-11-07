<?php
declare(strict_types = 1);

namespace youvision\command;

use think\console\Command;
use think\console\input\Argument;
use youvision\support\Worker;

class Workerman extends Command
{

    public function configure(): void
    {
        $this->setName('workerman')
            ->addArgument('action', Argument::OPTIONAL, 'start|stop|restart|reload|status|connections', 'start')
            ->setDescription('Linux 下启动 WebSocket 服务，register、business、gateway 同时运行');
    }

    public function handle(): int
    {
        $action = (string) $this->input->getArgument('action');
        $actions = [
            'start' => 'WebSocket 服务启动',
            'stop' => 'WebSocket 服务停止',
            'restart' => 'WebSocket 服务重启',
            'reload' => 'WebSocket 服务平滑重启',
            'status' => '查看 WebSocket 服务状态',
            'connections' => '查看 WebSocket 当前连接',
        ];

        if (!isset($actions[$action])) {
            $this->output->error('无效的命令[' . $action . ']，可选：' . implode('|', array_keys($actions)));

            return 1;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $this->output->error('Windows 下请分别执行 workerman:register、workerman:business、workerman:gateway');

            return 1;
        }

        $this->output->info($actions[$action]);

        Worker::boot();
        Worker::register();
        Worker::business();
        Worker::gateway();
        Worker::run($action);

        return 0;
    }
}