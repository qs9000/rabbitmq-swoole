<?php
declare(strict_types=1);

namespace RabbitMQSwoole\Service;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use RabbitMQSwoole\Config\RabbitMQConfig;
use RabbitMQSwoole\Service\RabbitMQPool;
use think\facade\Log;

/**
 * RabbitMQ 服务类
 *
 * 提供消息发布和消费功能
 */
class RabbitMQService
{
    /** @var array<int, AMQPStreamConnection> 非 Swoole 环境下的进程级连接缓存（按进程 ID 区分） */
    private static array $simpleConnections = [];

    /**
     * 获取连接（协程安全）
     *
     * @return AMQPStreamConnection
     */
    public static function getConnection(): AMQPStreamConnection
    {
        // Swoole 环境下使用连接池
        if (self::isSwooleEnvironment()) {
            return RabbitMQPool::getConnection();
        }

        // 非 Swoole 环境（CLI 消费进程）使用简单连接
        // 使用进程 ID 作为键，确保多进程环境下隔离
        $pid = getmypid();
        if (isset(self::$simpleConnections[$pid]) && self::$simpleConnections[$pid]->isConnected()) {
            return self::$simpleConnections[$pid];
        }

        // 连接不存在或已失效，重建连接
        try {
            self::$simpleConnections[$pid]?->close();
        } catch (\Exception $_) {
            // 忽略关闭异常
        }
        unset(self::$simpleConnections[$pid]);

        $config = RabbitMQConfig::getConnectionConfig();
        self::$simpleConnections[$pid] = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost'],
            false,
            'AMQPLAIN',
            null,
            'en_US',
            3.0,
            130.0,
            null,
            false,
            60
        );

        return self::$simpleConnections[$pid];
    }

    /**
     * 释放连接回池
     *
     * @param AMQPStreamConnection|null $connection
     * @return void
     */
    public static function releaseConnection(?AMQPStreamConnection $connection): void
    {
        if (self::isSwooleEnvironment()) {
            RabbitMQPool::returnConnection($connection);
        }
    }

    /**
     * 发布消息（协程安全）
     *
     * @param string $queueName 队列名称
     * @param array $message 消息内容
     * @param string $routingKey 路由键
     * @param int $delaySeconds 延迟时间（秒），0表示立即发送
     * @return bool
     */
    public static function publish(string $queueName, array $message, string $routingKey = '', int $delaySeconds = 0): bool
    {
        // 如果有延迟时间，使用延迟队列服务
        if ($delaySeconds > 0) {
            return RabbitMQDelayService::publishDelay($queueName, $message, $delaySeconds);
        }

        $queueConfig = RabbitMQConfig::getQueueConfig($queueName);

        if (empty($queueConfig)) {
            Log::error("RabbitMQ 队列配置不存在: {$queueName}");
            return false;
        }

        $exchangeName = $queueConfig['exchange'] ?? 'amq.direct';
        $exchangeType = $queueConfig['exchange_type'] ?? 'direct';
        $durable = $queueConfig['durable'] ?? true;
        $routingKey = $routingKey ?: ($queueConfig['routing_key'] ?? $queueName);

        $connection = null;
        $channel = null;

        try {
            $connection = self::getConnection();
            $channel = $connection->channel();

            // 声明交换机
            $channel->exchange_declare($exchangeName, $exchangeType, false, $durable, false);

            // 声明队列
            $channel->queue_declare($queueConfig['name'], false, $durable, false, false);

            // 绑定队列到交换机
            $channel->queue_bind($queueConfig['name'], $exchangeName, $routingKey);

            // 创建持久化消息
            $msg = new AMQPMessage(
                json_encode($message, JSON_UNESCAPED_UNICODE),
                [
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'content_type' => 'application/json',
                ]
            );

            $channel->basic_publish($msg, $exchangeName, $routingKey);

            return true;
        } catch (\PhpAmqpLib\Exception\AMQPExceptionInterface $e) {
            Log::error("[RabbitMQ] AMQP 错误: [{$queueName}] " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            Log::error("[RabbitMQ] 未知错误: [{$queueName}] " . $e->getMessage());
            return false;
        } finally {
            // 关闭通道（channel->close() 内部已做幂等保护）
            try {
                $channel?->close();
            } catch (\Exception $_) {
                // channel 关闭异常，连接可能已损坏，不归还到连接池
                if (self::isSwooleEnvironment() && $connection !== null) {
                    try {
                        $connection->close();
                    } catch (\Exception $_) {
                        // 忽略关闭异常
                    }
                    $connection = null;
                }
            }
            self::releaseConnection($connection);
        }
    }

    /**
     * 消费消息（用于 Worker 进程或独立 CLI 进程）
     *
     * @param string $queueName 队列名称
     * @param callable $callback 消息处理回调
     * @return void
     */
    public static function consume(string $queueName, callable $callback): void
    {
        $queueConfig = RabbitMQConfig::getQueueConfig($queueName);

        if (empty($queueConfig)) {
            throw new \RuntimeException("队列配置不存在: {$queueName}");
        }

        $exchangeName = $queueConfig['exchange'] ?? 'amq.direct';
        $exchangeType = $queueConfig['exchange_type'] ?? 'direct';
        $durable = $queueConfig['durable'] ?? true;
        $routingKey = $queueConfig['routing_key'] ?? $queueName;

        // 消费者使用独立长连接（强制使用简单连接，不检查环境）
        $connection = self::forceSimpleConnection();
        $channel = null;

        try {
            // 验证连接是否真正可用（防止静态变量残留的失效连接）
            if (!$connection->isConnected()) {
                throw new \RuntimeException('连接已失效，无法启动消费者');
            }
            $channel = $connection->channel();

            // 声明交换机和队列
            $channel->exchange_declare($exchangeName, $exchangeType, false, $durable, false);
            $channel->queue_declare($queueConfig['name'], false, $durable, false, false);
            $channel->queue_bind($queueConfig['name'], $exchangeName, $routingKey);

            // QoS: prefetch_size=0(无限制), prefetch_count=1(单条处理), global=false(仅当前channel)
            $channel->basic_qos(0, 1, false);

            $channel->basic_consume(
                $queueConfig['name'],
                '',
                false,
                false,
                false,
                false,
                $callback
            );

            Log::info("[RabbitMQ] 开始消费队列: {$queueConfig['name']}");

            // 信号处理（仅 CLI）
            if (function_exists('pcntl_signal')) {
                $stop = false;
                pcntl_signal(SIGTERM, function () use (&$stop) {
                    $stop = true;
                });
                pcntl_signal(SIGINT, function () use (&$stop) {
                    $stop = true;
                });
                // 启用异步信号处理（PHP 7.1+）
                if (function_exists('pcntl_async_signals')) {
                    pcntl_async_signals(true);
                }

                // 使用非阻塞超时以便及时响应信号
                while (!$stop) {
                    try {
                        $channel->wait(null, true, 5); // 5秒超时
                    } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                        // 超时正常，继续循环检查信号
                    }
                }
            } else {
                while (true) {
                    try {
                        $channel->wait(null, true, 5); // 5秒超时
                    } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
                        // 超时正常，继续循环
                    }
                }
            }
        } finally {
            // 先关闭 channel，再关闭 connection
            try {
                $channel?->close();
            } catch (\Exception $_) {
                // 忽略 channel 关闭异常
            }
            try {
                $connection->close();
            } catch (\Exception $_) {
                // 忽略连接关闭异常
            }
        }
    }

    /**
     * 检测是否在 Swoole 协程环境中
     *
     * 注意：Swoole 的 Custom Worker 进程虽然加载了 swoole 扩展，但不在协程上下文
     *
     * @return bool
     */
    protected static function isSwooleEnvironment(): bool
    {
        return extension_loaded('swoole')
            && class_exists(\Swoole\Coroutine::class)
            && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * 强制使用简单连接（不使用连接池）
     * 用于 Worker 进程，确保在协程上下文中也能正确使用
     *
     * @return AMQPStreamConnection
     */
    protected static function forceSimpleConnection(): AMQPStreamConnection
    {
        $pid = getmypid();
        if (isset(self::$simpleConnections[$pid]) && self::$simpleConnections[$pid]->isConnected()) {
            return self::$simpleConnections[$pid];
        }

        // 连接不存在或已失效，重建连接
        try {
            self::$simpleConnections[$pid]?->close();
        } catch (\Exception $_) {
            // 忽略关闭异常
        }
        unset(self::$simpleConnections[$pid]);

        $config = RabbitMQConfig::getConnectionConfig();
        self::$simpleConnections[$pid] = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost'],
            false,
            'AMQPLAIN',
            null,
            'en_US',
            10.0,
            10.0,
            null,
            true,
            60
        );

        return self::$simpleConnections[$pid];
    }
}
