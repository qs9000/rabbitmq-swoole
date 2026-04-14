<?php
declare(strict_types=1);

namespace RabbitMQSwoole\Service;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use RabbitMQSwoole\Config\RabbitMQConfig;
use think\facade\Log;

/**
 * RabbitMQ 延迟队列服务
 *
 * 基于死信队列（DLX）实现延迟消息功能
 */
class RabbitMQDelayService
{
    /** @var string 延迟队列后缀 */
    private const DELAY_QUEUE_SUFFIX = '.delay';

    /** @var string 死信交换机后缀 */
    private const DLX_SUFFIX = '.dlx';

    /**
     * 发布延迟消息
     *
     * @param string $queueName 目标队列名称
     * @param array $message 消息内容
     * @param int $delaySeconds 延迟时间（秒）
     * @return bool
     */
    public static function publishDelay(string $queueName, array $message, int $delaySeconds): bool
    {
        if ($delaySeconds <= 0) {
            return RabbitMQService::publish($queueName, $message);
        }

        $queueConfig = RabbitMQConfig::getQueueConfig($queueName);
        if (empty($queueConfig)) {
            Log::error("[RabbitMQDelay] 队列配置不存在: {$queueName}");
            return false;
        }

        $connection = null;
        $channel = null;

        try {
            $connection = RabbitMQService::getConnection();
            $channel = $connection->channel();

            $exchangeName = $queueConfig['exchange'] ?? 'amq.direct';
            $exchangeType = $queueConfig['exchange_type'] ?? 'direct';
            $durable = $queueConfig['durable'] ?? true;
            $routingKey = $queueConfig['routing_key'] ?? $queueName;

            // 声明目标交换机和队列
            $channel->exchange_declare($exchangeName, $exchangeType, false, $durable, false);
            $channel->queue_declare($queueConfig['name'], false, $durable, false, false);
            $channel->queue_bind($queueConfig['name'], $exchangeName, $routingKey);

            // 声明死信交换机
            $dlxName = $exchangeName . self::DLX_SUFFIX;
            $channel->exchange_declare($dlxName, 'direct', false, $durable, false);

            // 声明延迟队列
            $delayQueueName = $queueConfig['name'] . self::DELAY_QUEUE_SUFFIX;
            $delayRoutingKey = $routingKey . self::DELAY_QUEUE_SUFFIX;

            $queueArgs = [
                'x-message-ttl' => $delaySeconds * 1000,
                'x-dead-letter-exchange' => $dlxName,
                'x-dead-letter-routing-key' => $routingKey,
            ];

            $channel->queue_declare($delayQueueName, false, $durable, false, false, false, $queueArgs);
            $channel->queue_bind($delayQueueName, $dlxName, $delayRoutingKey);
            $channel->queue_bind($queueConfig['name'], $dlxName, $routingKey);

            // 创建消息
            $msgBody = json_encode([
                'data' => $message,
                '_delay_meta' => [
                    'original_queue' => $queueName,
                    'delay_seconds' => $delaySeconds,
                    'published_at' => date('Y-m-d H:i:s'),
                    'execute_at' => date('Y-m-d H:i:s', strtotime("+{$delaySeconds} seconds")),
                ],
            ], JSON_UNESCAPED_UNICODE);

            $msg = new AMQPMessage($msgBody, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json',
            ]);

            $channel->basic_publish($msg, $dlxName, $delayRoutingKey);

            Log::info("[RabbitMQDelay] 延迟消息已发送", [
                'queue' => $queueName,
                'delay_seconds' => $delaySeconds,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("[RabbitMQDelay] 错误: [{$queueName}] " . $e->getMessage());
            return false;
        } finally {
            try {
                $channel?->close();
            } catch (\Exception $_) {
            }
            RabbitMQService::releaseConnection($connection);
        }
    }

    /**
     * 批量发布延迟消息
     *
     * @param string $queueName 目标队列名称
     * @param array $messages 消息数组
     * @param int $delaySeconds 延迟时间（秒）
     * @return array
     */
    public static function publishDelayBatch(string $queueName, array $messages, int $delaySeconds): array
    {
        $results = ['success' => 0, 'failed' => 0, 'total' => count($messages)];

        foreach ($messages as $message) {
            if (self::publishDelay($queueName, $message, $delaySeconds)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }
}
