# Think RabbitMQ Swoole

为 ThinkPHP 6.x/8.x 集成 RabbitMQ 消息队列，支持 Swoole 协程环境，提供协程安全的连接池管理和自动消费者进程管理。

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.0-brightgreen.svg)](https://www.php.net)
[![Packagist](https://img.shields.io/packagist/v/qs9000/rabbitmq-swoole.svg)](https://packagist.org/packages/qs9000/rabbitmq-swoole)

## 特性

- **协程安全** - 基于 Swoole 连接池，支持多协程并发
- **自动管理** - Worker 进程自动注册和重启
- **连接复用** - 高效连接池管理，避免频繁创建连接
- **类型安全** - 完整的类型声明
- **环境适配** - 自动识别 Swoole 协程和 CLI 环境

## 安装

```bash
composer require qs9000/rabbitmq-swoole
```

## 快速开始

### 1. 发布配置

```bash
cp vendor/qs9000/rabbitmq-swoole/src/Config/rabbitmq.php config/rabbitmq.php
```

### 2. 配置 RabbitMQ

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
            'name'          => 'file.log.queue',
            'exchange'      => 'file.log.exchange',
            'exchange_type' => 'direct',
            'routing_key'   => 'file.log',
            'durable'       => true,
        ],
    ],
];
```

### 3. 配置 Swoole

编辑 `config/swoole.php`：

```php
return [
    'pool' => [
        'rabbitmq' => [
            'enable'     => true,
            'max_active' => 10,
        ],
    ],

    'rabbitmq_worker' => [
        'enable' => true,
        'queues' => [
            'file_log' => \app\queue\consumer\FileLog::class,
        ],
    ],

    'resetters' => [
        \RabbitMQSwoole\Resetter\RabbitMQResetter::class,
    ],
];
```

### 4. 注册监听器

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

RabbitMQService::publish('file_log', [
    'action' => 'upload',
    'file_path' => '/path/to/file.pdf',
]);
```

### 创建消费者

```php
namespace app\queue\consumer;

use RabbitMQSwoole\Contract\ConsumerInterface;
use PhpAmqpLib\Message\AMQPMessage;
use think\facade\Log;

class FileLog implements ConsumerInterface
{
    public function handle(AMQPMessage $message): void
    {
        $channel = $message->get('channel');
        $deliveryTag = $message->get('delivery_tag');

        try {
            $data = json_decode($message->body, true);
            // 处理业务逻辑
            Log::info('处理消息', $data);

            // 确认消息
            $channel->basic_ack($deliveryTag);
        } catch (\Exception $e) {
            Log::error('处理失败', ['error' => $e->getMessage()]);
            // 拒绝并重新入队
            $channel->basic_nack($deliveryTag, false, true);
        }
    }
}
```

### 独立消费进程

创建命令文件 `app/command/RabbitMQConsumer.php`：

```php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use RabbitMQSwoole\Service\RabbitMQService;
use app\queue\consumer\FileLog;

class RabbitMQConsumer extends Command
{
    protected function configure()
    {
        $this->setName('rabbitmq:consume')
            ->addArgument('queue')
            ->setDescription('启动 RabbitMQ 消费者进程');
    }

    protected function execute(Input $input, Output $output)
    {
        $queue = $input->getArgument('queue');
        $consumerClass = config('swoole.rabbitmq_worker.queues.' . $queue);

        if (!$consumerClass) {
            $output->error("队列 {$queue} 未配置");
            return;
        }

        $consumer = new $consumerClass();
        RabbitMQService::consume($queue, [$consumer, 'handle']);
    }
}
```

启动：

```bash
php think rabbitmq:consume file_log
```

## 架构说明

```
┌─────────────────────────────────────────────────────┐
│              ThinkPHP + Swoole                      │
├─────────────────────────────────────────────────────┤
│                                                      │
│  HTTP Worker (协程) → RabbitMQPool → RabbitMQ       │
│     └─ publish() 使用连接池获取连接                   │
│                                                      │
│  Custom Worker (非协程) → RabbitMQService            │
│     └─ consume() 使用长连接监听消息                   │
│     └─ 支持自动重启和指数退避重试                     │
│                                                      │
│  SwooleInitListener                                 │
│     └─ 初始化连接池和注册 Worker 进程                 │
│                                                      │
│  RabbitMQResetter                                    │
│     └─ 请求结束时自动回收未归还的连接                 │
│                                                      │
└─────────────────────────────────────────────────────┘
```

## 配置说明

### 连接池配置

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| enable | bool | true | 是否启用连接池 |
| max_active | int | 10 | 最大连接数 |

### Worker 配置

| 参数 | 类型 | 默认值 | 说明 |
|------|------|--------|------|
| enable | bool | false | 是否启用 Worker 进程 |
| queues | array | [] | 队列与消费者映射 |

### 队列配置

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| name | string | 是 | 队列名称 |
| exchange | string | 是 | 交换机名称 |
| exchange_type | string | 否 | 交换机类型 |
| routing_key | string | 否 | 路由键 |
| durable | bool | 否 | 是否持久化 |

## 故障排查

### 连接失败

检查 RabbitMQ 服务和连接配置。

### Worker 进程崩溃

查看日志确认消费者类实现和队列配置。

### 连接泄漏

确保每次 `getConnection()` 后都有 `releaseConnection()`，或使用 `publish()` 方法自动管理。

## License

MIT License
