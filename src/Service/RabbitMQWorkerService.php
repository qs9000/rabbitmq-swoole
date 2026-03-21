<?php
declare(strict_types=1);

namespace RabbitMQSwoole\Service;

use PhpAmqpLib\Message\AMQPMessage;
use RabbitMQSwoole\Config\RabbitMQConfig;
use RabbitMQSwoole\Service\RabbitMQService;
use think\App;
use think\facade\Log;
use think\swoole\Manager;

/**
 * RabbitMQ Worker 进程服务
 *
 * 管理 RabbitMQ 消费者 Worker 进程的注册和启动
 */
class RabbitMQWorkerService
{
    protected App $app;
    protected Manager $manager;

    public function __construct(App $app, Manager $manager)
    {
        $this->app = $app;
        $this->manager = $manager;
    }

    /**
     * 注册消费者 Worker 进程
     * 在 Swoole 服务启动时调用
     *
     * @return void
     */
    public function register(): void
    {
        $workerConfig = RabbitMQConfig::getWorkerConfig();

        if (empty($workerConfig['enable'])) {
            return;
        }

        $queues = $workerConfig['queues'];
        if (empty($queues)) {
            return;
        }

        foreach ($queues as $queueName => $consumerClass) {
            $this->manager->addWorker(function () use ($queueName, $consumerClass) {
                // Worker 进程异常重试机制
                $retryCount = 0;
                $maxRetries = 3;

                while ($retryCount < $maxRetries) {
                    Log::info("[RabbitMQ] Worker 启动，消费队列: {$queueName}" . ($retryCount > 0 ? " (重试 #{$retryCount})" : ""));
                    try {
                        if (!class_exists($consumerClass)) {
                            throw new \RuntimeException("Consumer class not found: {$consumerClass}");
                        }

                        $consumer = new $consumerClass();

                        if (!method_exists($consumer, 'handle')) {
                            throw new \RuntimeException("Consumer class must have handle() method: {$consumerClass}");
                        }

                        // 注意：Custom Worker 是非协程进程，可以直接调用 consume()
                        // RabbitMQService::isSwooleEnvironment() 需要额外判断协程上下文
                        RabbitMQService::consume($queueName, fn(AMQPMessage $message) => $consumer->handle($message));
                        // 正常退出（收到信号）
                        break;
                    } catch (\Exception $e) {
                        $retryCount++;
                        Log::error("[RabbitMQ] Worker 异常退出 ({$queueName}): " . $e->getMessage() . " (重试 {$retryCount}/{$maxRetries})");

                        if ($retryCount < $maxRetries) {
                            // 等待后重试，避免快速重试造成雪崩
                            sleep(min(pow(2, $retryCount), 30)); // 指数退避，最多 30 秒
                        } else {
                            // 超过最大重试次数，记录告警并退出
                            Log::error("[RabbitMQ] Worker 超过最大重试次数 ({$queueName})，进程退出");
                            break;
                        }
                    }
                }
            }, "rabbitmq:{$queueName}");
        }
    }
}
