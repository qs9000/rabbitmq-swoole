# Think RabbitMQ Swoole

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.0-brightgreen.svg)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/thinkphp-6.0%7C8.0-blue.svg)](https://www.thinkphp.cn)

为 ThinkPHP 集成 RabbitMQ 消息队列，支持 Swoole 协程环境，提供协程安全的连接池和自动消费者进程管理。

## 特性

- 协程安全连接池（基于 Swoole\ConnectionPool）
- 自动连接泄漏检测与回收
- Worker 进程自动注册与管理
- 延迟消息支持（基于 rabbitmq_delayed_message_exchange 插件）
- 消息确认与重试机制

## 环境要求

- PHP >= 8.0
- ThinkPHP 6.0 / 8.0
- think-swoole >= 4.1
- php-amqplib >= 3.7
- Swoole >= 4.5

## 安装

```bash
composer require qs9000/rabbitmq-swoole
```

安装后，复制配置文件到项目：

```bash
cp vendor/qs9000/rabbitmq-swoole/src/Config/rabbitmq.php config/rabbitmq.php
```

## 快速开始

### 1. 配置 RabbitMQ

编辑 `config/rabbitmq.php`：

```php
return [
    'connection' => [
        'host'     => env('RABBITMQ_HOST', '127.0.0.1'),
        'port'     => (int) env('RABBITMQ_PORT', 5672),
        'user'     => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost'    => env('RABBITMQ_VHOST', '/'),
    ],

    'queue' => [
        'file_log' => [
            'name'          => 'file_log',
            'exchange'      => 'amq.direct',
            'exchange_type' => 'direct',
            'routing_key'   => 'file_log',
            'durable'       => true,
        ],
    ],
];
```

### 2. 配置 Swoole

编辑 `config/swoole.php`：

```php
return [
    // 连接池配置
    'pool' => [
        'rabbitmq' => [
            'enable'     => true,
            'max_active' => 10,
        ],
    ],

    // RabbitMQ Worker 配置
    'rabbitmq_worker' => [
        'enable' => true,
        'queues' => [
            'file_log' => \app\queue\consumer\FileLog::class,
        ],
    ],

    // 重置器（自动回收泄漏连接）
    'resetters' => [
        \RabbitMQSwoole\Resetter\RabbitMQResetter::class,
    ],
];
```

### 3. 注册事件监听

编辑 `config/event.php`：

```php
return [
    'subscribe' => [
        \RabbitMQSwoole\Listener\SwooleInitListener::class,
    ],
];
```

## 使用方法

### 发布消息

```php
use RabbitMQSwoole\Service\RabbitMQService;

// 普通消息
RabbitMQService::publish('file_log', [
    'action' => 'upload',
    'file_path' => '/path/to/file.pdf',
]);

// 延迟消息（延迟 60 秒）
RabbitMQService::publish('file_log', [
    'action' => 'cleanup',
    'file_id' => 'xxx',
], '', 60);
```

### 创建消费者

```php
<?php
namespace app\queue\consumer;

use RabbitMQSwoole\Contract\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;

class FileLog implements ConsumerInterface
{
    public function handle(AMQPMessage $message): void
    {
        $channel = $message->get('channel');
        $deliveryTag = $message->get('delivery_tag');

        try {
            $data = json_decode($message->body, true);

            // 处理业务逻辑
            $this->process($data);

            // 确认消息
            $channel->basic_ack($deliveryTag);
        } catch (\Exception $e) {
            // 处理失败，拒绝并重新入队
            $channel->basic_nack($deliveryTag, false, true);
        }
    }

    private function process(array $data): void
    {
        // 业务处理逻辑
    }
}
```

### 批量发送延迟消息

```php
use RabbitMQSwoole\Service\RabbitMQDelayService;

RabbitMQDelayService::publishDelayBatch('reminder_queue', [
    ['user_id' => 1, 'message' => '会议提醒'],
    ['user_id' => 2, 'message' => '任务到期'],
], 3600); // 延迟 1 小时
```

## 延迟队列

延迟队列基于 `rabbitmq_delayed_message_exchange` 插件实现。

### 安装插件

```bash
# 启用插件
rabbitmq-plugins enable rabbitmq_delayed_message_exchange
```

### 使用示例

```php
// 延迟 5 分钟
RabbitMQService::publish('order_queue', [
    'order_id' => '12345',
    'action' => 'auto_cancel',
], '', 300);
```

## 架构说明

```
HTTP Worker (协程)
└── RabbitMQPool (连接池)
    └── publish() 发布消息

Custom Worker (非协程)
└── consume() 长连接消费
    └── 自动重试 (指数退避)

请求结束时
└── RabbitMQResetter (回收泄漏连接)
```

## Worker 自动重试机制

```
第 1 次异常 → 等待 2 秒重试
第 2 次异常 → 等待 4 秒重试
第 3 次异常 → 等待 8 秒重试
超过 3 次 → 记录告警并退出
```

## 故障排查

### 连接获取失败

检查 RabbitMQ 服务和连接配置，确保连接池大小足够。

### 连接泄漏警告

确保每次 `getConnection()` 后都有 `releaseConnection()`，或使用 `try-finally` 包裹。

### Worker 进程崩溃

检查消费者类是否正确实现 `ConsumerInterface`，查看日志中的具体错误信息。

## License

MIT License
