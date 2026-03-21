<?php
declare(strict_types=1);

namespace RabbitMQSwoole\Contract;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * RabbitMQ 消费者接口
 *
 * 所有消费者类必须实现此接口
 */
interface ConsumerInterface
{
    /**
     * 处理消息
     *
     * @param AMQPMessage $message RabbitMQ 消息对象
     * @return void
     */
    public function handle(AMQPMessage $message): void;
}
