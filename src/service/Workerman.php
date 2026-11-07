<?php
declare(strict_types = 1);

namespace youvision\service;

use GatewayWorker\Lib\Gateway;
use think\Service;
use youvision\command\Business;
use youvision\command\Gateway as GatewayCommand;
use youvision\command\Register;
use youvision\command\Workerman as WorkermanCommand;

class Workerman extends Service
{
    /**
     * 注册扩展提供的控制台命令
     */
    public function boot(): void
    {
        Gateway::$registerAddress = (string) (config('workerman.register_address') ?: '127.0.0.1:1201');

        $this->commands([
            WorkermanCommand::class,
            Register::class,
            Business::class,
            GatewayCommand::class,
        ]);
    }
}