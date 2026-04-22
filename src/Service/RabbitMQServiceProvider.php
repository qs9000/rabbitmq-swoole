<?php
declare(strict_types=1);

namespace RabbitMQSwoole\Service;

use think\Service;

/**
 * RabbitMQ 服务注册
 */
class RabbitMQServiceProvider extends Service
{
    /**
     * 注册服务
     */
    public function register(): void
    {
        // 注册多语言文件（用于命令行提示）
        $this->app->lang->load(__DIR__ . '/../lang/rabbitmq.php');
    }

    /**
     * 启动服务后执行
     */
    public function boot(): void
    {
        // 注册命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                \RabbitMQSwoole\Command\PublishConfigCommand::class,
            ]);
        }
    }
}
