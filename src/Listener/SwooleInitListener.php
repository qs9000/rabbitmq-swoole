<?php

declare(strict_types=1);

namespace RabbitMQSwoole\Listener;

use RabbitMQSwoole\Config\RabbitMQConfig;
use RabbitMQSwoole\Service\RabbitMQPool;
use RabbitMQSwoole\Service\RabbitMQWorkerService;
use think\Event;

/**
 * Swoole 初始化事件监听器
 *
 * 负责 RabbitMQ Worker 注册和连接池初始化
 */
class SwooleInitListener
{
    private static bool $workerRegistered = false;

    /**
     * 订阅 Swoole 事件
     *
     * @param Event $event
     * @return void
     */
    public function subscribe(Event $event): void
    {
        $app = app();
        // Swoole 初始化时注册 RabbitMQ Worker（仅一次）
        // 这个事件在服务启动时触发，HTTP、RPC 等所有服务模式都会触发
        $event->listen('swoole.init', function () use ($app) {
            if (self::$workerRegistered) {
                return;
            }
            self::$workerRegistered = true;

            $app->log->info('[SwooleInitListener] Swoole 初始化，注册 RabbitMQ Worker');
            // 获取 Manager 实例

            $manager = $app->make('think\swoole\Manager');

            if (!$manager) {
                $app->log->warning('[SwooleInitListener] 未找到 Manager 实例，跳过 RabbitMQ Worker 注册');
                return;
            }

            // 创建并注册 RabbitMQ Worker Service
            $rabbitmqWorkerService = new RabbitMQWorkerService($app, $manager);
            $rabbitmqWorkerService->register();
        });

        // 仅在 HTTP Worker 进程启动时初始化连接池
        // Custom Worker 进程不使用连接池，应跳过
        $event->listen('swoole.workerStart', function () use ($app) {
            // 检测是否在协程上下文（HTTP Worker 在协程中处理请求）
            // Custom Worker 是非协程进程，getCid() 返回 -1
            if (\Swoole\Coroutine::getCid() === -1) {
                $app->log->info('[SwooleInitListener] 检测到非协程 Worker，跳过连接池初始化');
                return;
            }

            $poolConfig = RabbitMQConfig::getPoolConfig();
            if (empty($poolConfig['enable'])) {
                $app->log->info('[SwooleInitListener] RabbitMQ 连接池未启用');
                return;
            }

            RabbitMQPool::close(); // 防止 Worker 重启时残留
            $maxSize = (int) ($poolConfig['max_active'] ?? 10);
            $app->log->info('[SwooleInitListener] HTTP Worker 启动，初始化 RabbitMQ 连接池', [
                'max_size' => $maxSize,
            ]);
            RabbitMQPool::init($maxSize);
        });

        // Worker 停止前关闭连接池
        $event->listen('swoole.beforeWorkerStop', function () use ($app) {
            // 仅在协程上下文中关闭连接池
            if (\Swoole\Coroutine::getCid() !== -1) {
                $app->log->info('[SwooleInitListener] HTTP Worker 停止，关闭 RabbitMQ 连接池');
                RabbitMQPool::close();
            }
        });
    }
}
