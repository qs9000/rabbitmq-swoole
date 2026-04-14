<?php
declare(strict_types=1);

namespace RabbitMQSwoole\Contract;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * RabbitMQ 延迟消息消费者接口
 *
 * 用于处理延迟消息，可以获取延迟元数据
 */
interface DelayConsumerInterface extends ConsumerInterface
{
    /**
     * 处理延迟消息
     *
     * @param AMQPMessage $message RabbitMQ 消息对象
     * @param array $delayMeta 延迟元数据
     * @return void
     */
    public function handleDelay(AMQPMessage $message, array $delayMeta): void;
}
