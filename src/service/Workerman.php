<?php
declare(strict_types = 1);

namespace youvision\service;

use GatewayWorker\Lib\Gateway;
use think\Service;
use youvision\command\Business;
use youvision\command\Gateway as GatewayCommand;
use youvision\command\Register;
use youvision\command\Workerman as WorkermanCommand;
use youvision\support\Worker;

class Workerman extends Service
{
    /**
     * 配置推送客户端并注册扩展提供的控制台命令
     */
    public function boot(): void
    {
        Gateway::$registerAddress = Worker::registerAddress();
        Gateway::$secretKey = Worker::secretKey();

        $this->commands([
            WorkermanCommand::class,
            Register::class,
            Business::class,
            GatewayCommand::class,
        ]);
    }
}
