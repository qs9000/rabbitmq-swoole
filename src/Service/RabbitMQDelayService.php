<?php
declare(strict_types=1);

namespace RabbitMQSwoole\Service;

use PhpAmqpLib\Message\AMQPMessage;
use RabbitMQSwoole\Config\RabbitMQConfig;
use think\facade\Log;

/**
 * RabbitMQ 延迟队列服务
 *
 * 基于 rabbitmq_delayed_message_exchange 插件实现延迟消息功能
 * 插件地址: https://github.com/rabbitmq/rabbitmq-delayed-message-exchange
 */
class RabbitMQDelayService
{
    /** @var string 延迟交换机类型 */
    private const DELAY_EXCHANGE_TYPE = 'x-delayed-message';

    /** @var string 延迟交换机参数 */
    private const DELAY_EXCHANGE_ARG = 'x-delayed-type';

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
            $durable = $queueConfig['durable'] ?? true;
            $routingKey = $queueConfig['routing_key'] ?? $queueName;

            // 声明延迟交换机（使用插件类型）
            $exchangeArgs = [
                self::DELAY_EXCHANGE_ARG => ['S', $queueConfig['exchange_type'] ?? 'direct'],
            ];

            $channel->exchange_declare(
                $exchangeName,
                self::DELAY_EXCHANGE_TYPE,
                false,
                $durable,
                false,
                false,
                false,
                $exchangeArgs
            );

            // 声明队列
            $channel->queue_declare($queueConfig['name'], false, $durable, false, false);
            $channel->queue_bind($queueConfig['name'], $exchangeName, $routingKey);

            // 创建消息，添加延迟头
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
                'application_headers' => [
                    'x-delay' => ['I', $delaySeconds * 1000], // 毫秒
                ],
            ]);

            $channel->basic_publish($msg, $exchangeName, $routingKey);

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

    /**
     * 声明延迟交换机
     *
     * @param string $exchangeName 交换机名称
     * @param string $exchangeType 交换机类型 (direct/topic/fanout)
     * @param bool $durable 是否持久化
     * @return bool
     */
    public static function declareDelayExchange(string $exchangeName, string $exchangeType = 'direct', bool $durable = true): bool
    {
        $connection = null;
        $channel = null;

        try {
            $connection = RabbitMQService::getConnection();
            $channel = $connection->channel();

            $exchangeArgs = [
                self::DELAY_EXCHANGE_ARG => ['S', $exchangeType],
            ];

            $channel->exchange_declare(
                $exchangeName,
                self::DELAY_EXCHANGE_TYPE,
                false,
                $durable,
                false,
                false,
                false,
                $exchangeArgs
            );

            Log::info("[RabbitMQDelay] 延迟交换机已声明", [
                'exchange' => $exchangeName,
                'type' => $exchangeType,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("[RabbitMQDelay] 声明交换机失败: " . $e->getMessage());
            return false;
        } finally {
            try {
                $channel?->close();
            } catch (\Exception $_) {
            }
            RabbitMQService::releaseConnection($connection);
        }
    }
}
