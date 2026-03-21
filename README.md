# Think RabbitMQ Swoole

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-brightgreen.svg)](https://www.php.net)
[![Swoole](https://img.shields.io/badge/swoole-%3E%3D4.5-orange.svg)](https://www.swoole.com)
[![ThinkPHP](https://img.shields.io/badge/thinkphp-6.x-blue.svg)](https://www.thinkphp.cn)

为 ThinkPHP 8.x 集成 RabbitMQ 消息队列，支持 Swoole 协程环境，提供协程安全的连接池管理和自动消费者进程管理。

## ✨ 特性

- ✅ **协程安全连接池** - 基于 `Swoole\ConnectionPool` 的连接池，协程间隔离
- ✅ **自动连接泄漏检测** - 请求结束时自动检测并回收未归还的连接
- ✅ **Worker 进程管理** - 自动注册和管理 RabbitMQ 消费者 Worker 进程
- ✅ **类型安全** - 完整的类型声明和接口约束
- ✅ **配置驱动** - 灵活的配置管理，支持环境变量
- ✅ **多环境适配** - 自动识别 Swoole 协程环境和 CLI 环境

## 📦 安装

```bash
composer require qs9000/rabbitmq-swoole
```

## 🚀 快速开始

### 1. 发布配置文件

将扩展包的配置文件复制到你的项目：

```bash
cp vendor/qs9000/rabbitmq-swoole/src/Config/rabbitmq.php config/rabbitmq.php
```

### 2. 配置 RabbitMQ 连接

编辑 `config/rabbitmq.php`：

```php
return [
    // RabbitMQ 连接配置
    'connection' => [
        'host'     => env('RABBITMQ_HOST', '127.0.0.1'),
        'port'     => (int) env('RABBITMQ_PORT', 5672),
        'user'     => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost'    => env('RABBITMQ_VHOST', '/'),
    ],

    // 队列配置
    'queue' => [
        'file_log' => [
            'name'          => 'file.log.queue',      // 队列名称
            'exchange'      => 'file.log.exchange',   // 交换机名称
            'exchange_type' => 'direct',               // 交换机类型
            'routing_key'   => 'file.log',             // 路由键
            'durable'       => true,                   // 是否持久化
        ],
    ],
];
```

### 3. 配置 Swoole

编辑 `config/swoole.php`，添加连接池和 Worker 配置：

```php
return [
    // 连接池配置
    'pool' => [
        'rabbitmq' => [
            'enable'     => true,    // 是否启用连接池
            'max_active' => 10,      // 最大连接数
        ],
    ],

    // RabbitMQ Worker 进程配置
    'rabbitmq_worker' => [
        'enable' => true,  // 是否启用 Worker 进程
        'queues' => [
            'file_log' => \app\queue\consumer\FileLog::class,
        ],
    ],

    // 重置器配置
    'resetters' => [
        \RabbitMQSwoole\Resetter\RabbitMQResetter::class,
    ],
];
```

### 4. 注册事件监听器

编辑 `config/event.php`：

```php
return [
    'listen' => [
        // ...
    ],
    'subscribe' => [
        \RabbitMQSwoole\Listener\SwooleInitListener::class,
    ],
];
```

## 📝 使用方法

### 发布消息

```php
use RabbitMQSwoole\Service\RabbitMQService;

// 发布消息到队列
RabbitMQService::publish('file_log', [
    'action' => 'upload',
    'tenant_id' => 'xxx',
    'user_id' => 'xxx',
    'file_path' => '/path/to/file.pdf',
]);
```

### 创建消费者

创建消费者类，实现 `ConsumerInterface` 接口：

```php
<?php
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
            Log::info('处理文件日志消息', $data);

            // 确认消息
            $channel->basic_ack($deliveryTag);
        } catch (\Exception $e) {
            Log::error('处理消息失败', [
                'error' => $e->getMessage(),
                'data' => $message->body,
            ]);

            // 拒绝消息并重新入队
            $channel->basic_nack($deliveryTag, false, true);
        }
    }
}
```

### 独立 CLI 消费进程（可选）

如果不想使用 Swoole Worker，也可以独立启动消费者进程：

```php
// 创建 console 命令
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
            ->setDescription('启动 RabbitMQ 消费者进程');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('开始消费队列...');

        RabbitMQService::consume('file_log', function ($message) {
            $consumer = new FileLog();
            $consumer->handle($message);
        });
    }
}
```

启动命令：

```bash
php think rabbitmq:consume
```

## 🏗️ 架构设计

```
┌─────────────────────────────────────────────────────────────┐
│                      ThinkPHP + Swoole                       │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌──────────────┐      ┌─────────────────────────────────┐  │
│  │ HTTP Worker  │─────▶│   RabbitMQService::publish()    │  │
│  │   (协程)      │      │   ┌─────────────────────────┐  │  │
│  └──────────────┘      │   │   RabbitMQPool (连接池)   │  │
│                        │   └─────────────────────────┘  │  │
│                        │          ↓                      │  │
│                        │   AMQPStreamConnection          │  │
│                        └─────────────────────────────────┘  │
│                                                               │
│  ┌──────────────────┐      ┌─────────────────────────────────┐│
│  │ Custom Worker    │─────▶│  RabbitMQService::consume()     ││
│  │   (非协程)        │      │  - 长连接监听                   ││
│  │  - 消息消费       │      │  - 信号处理 (SIGTERM/SIGINT)   ││
│  │  - 自动重启       │      │  - 指数退避重试                 ││
│  └──────────────────┘      └─────────────────────────────────┘│
│                                                               │
│  ┌──────────────────────────────────────────────────────────┐│
│  │ SwooleInitListener                                       ││
│  │   - swoole.init: 注册 Worker 进程                        ││
│  │   - swoole.workerStart: 初始化连接池                    ││
│  │   - swoole.beforeWorkerStop: 关闭连接池                 ││
│  └──────────────────────────────────────────────────────────┘│
│                                                               │
│  ┌──────────────────────────────────────────────────────────┐│
│  │ RabbitMQResetter (请求结束)                              ││
│  │   - 检测连接泄漏                                         ││
│  │   - 自动回收未归还连接                                   ││
│  └──────────────────────────────────────────────────────────┘│
│                                                               │
└─────────────────────────────────────────────────────────────┘
```

## 📋 配置说明

### 连接池配置

| 配置项 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| enable | bool | true | 是否启用连接池 |
| max_active | int | 10 | 最大连接数 |

### Worker 进程配置

| 配置项 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| enable | bool | false | 是否启用 Worker 进程 |
| queues | array | [] | 队列与消费者映射 |

### 队列配置

| 配置项 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| name | string | 是 | 队列名称 |
| exchange | string | 是 | 交换机名称 |
| exchange_type | string | 否 | 交换机类型 (direct/topic/fanout) |
| routing_key | string | 否 | 路由键 |
| durable | bool | 否 | 是否持久化 |

## 🔒 最佳实践

### 1. 消息确认

```php
public function handle(AMQPMessage $message): void
{
    $channel = $message->get('channel');
    $deliveryTag = $message->get('delivery_tag');

    try {
        // 处理业务逻辑
        $this->processMessage($message);

        // 处理成功，确认消息
        $channel->basic_ack($deliveryTag);
    } catch (\Exception $e) {
        // 处理失败，拒绝消息并重新入队
        $channel->basic_nack($deliveryTag, false, true);
        throw $e;
    }
}
```

### 2. 连接释放

```php
$connection = RabbitMQService::getConnection();
// 使用连接...
// 自动释放（推荐）
RabbitMQService::releaseConnection($connection);
```

### 3. 错误重试

Worker 进程内置了自动重试机制，支持指数退避：

```
第1次异常 → 等待 2 秒重试
第2次异常 → 等待 4 秒重试
第3次异常 → 等待 8 秒重试
超过3次 → 记录告警并退出（Swoole 会自动重启进程）
```

### 4. 连接泄漏监控

开启日志监控连接泄漏：

```php
// config/swoole.php
'log' => [
    'level' => \think\facade\Log::DEBUG,
],
```

每次请求结束时会记录连接池状态，如果检测到连接泄漏会输出警告：

```
[RabbitMQResetter] 检测到连接泄漏 {
    "pool": "active",
    "max_size": 10,
    "total_created": 5,
    "borrowed_in_coroutine": 1
}
```

## 🐛 故障排查

### 问题 1：连接获取失败

**错误信息**：`[RabbitMQPool] 获取连接失败`

**解决方案**：
1. 检查 RabbitMQ 服务是否运行
2. 检查连接配置是否正确
3. 增加连接池大小 (`max_active`)

### 问题 2：连接泄漏

**错误信息**：`[RabbitMQResetter] 检测到连接泄漏`

**解决方案**：
1. 确保每次 `getConnection()` 后都有对应的 `releaseConnection()`
2. 检查异常处理中是否有未释放的连接
3. 使用 `try-finally` 确保连接释放

### 问题 3：Worker 进程崩溃

**错误信息**：`[RabbitMQ] Worker 异常退出`

**解决方案**：
1. 检查消费者类是否正确实现 `ConsumerInterface`
2. 查看详细错误日志
3. 检查 RabbitMQ 队列配置是否正确

### 问题 4：自动加载失败

**错误信息**：`Class 'RabbitMQSwoole\...' not found`

**解决方案**：
```bash
composer dump-autoload
php think clear
```

## 📊 性能优化

### 1. 连接池调优

根据实际并发量调整连接池大小：

```php
// 高并发场景
'max_active' => 50,

// 低并发场景
'max_active' => 5,
```

### 2. QoS 设置

消费者 QoS (Quality of Service) 设置已在 `consume()` 方法中默认配置为单条处理：

```php
$channel->basic_qos(0, 1, false);
```

可根据需要调整预取数量。

### 3. 心跳配置

连接心跳默认设置为 60 秒：

```php
heartbeat: 60,
```

根据网络状况调整此值。

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

## 📄 License

MIT License

## 🔗 相关链接

- [RabbitMQ 官方文档](https://www.rabbitmq.com/documentation.html)
- [php-amqplib 文档](https://github.com/php-amqplib/php-amqplib)
- [Swoole 文档](https://wiki.swoole.com/)
- [ThinkPHP 8.x 文档](https://doc.thinkphp.cn/v8_0)
