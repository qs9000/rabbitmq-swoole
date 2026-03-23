<?php

declare(strict_types=1);

namespace RabbitMQSwoole\Service;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use RabbitMQSwoole\Config\RabbitMQConfig;
use Swoole\Coroutine;
use Swoole\ConnectionPool;
use think\facade\Log;

/**
 * RabbitMQ 连接池
 *
 * 基于 Swoole\ConnectionPool 的协程安全连接池
 */
class RabbitMQPool
{
    /** @var ConnectionPool|null */
    private static ?ConnectionPool $pool = null;

    /** @var string 协程上下文中存放借出连接列表的 key */
    private const COROUTINE_CTX_KEY = '__rabbitmq_borrowed_connections__';

    /** @var int 连接池总大小（用于统计） */
    private static int $poolSize = 0;

    /** @var int 已创建的连接总数（用于统计） */
    private static int $totalCreated = 0;

    /**
     * 初始化连接池
     *
     * @param int $max 最大连接数
     * @return void
     */
    public static function init(int $max = 10): void
    {
        if (self::$pool !== null) {
            return;
        }

        self::$poolSize = $max;
        self::$totalCreated = 0;
        $config = RabbitMQConfig::getConnectionConfig();

        // Swoole\ConnectionPool 构造函数签名：
        // __construct(callable $constructor, int $size, ?string $channel = null)
        self::$pool = new ConnectionPool(
            function () use ($config) {
                try {
                    self::$totalCreated++;
                    return new AMQPStreamConnection(
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
                } catch (\Throwable $e) {
                    self::$totalCreated--;
                    Log::error('[RabbitMQPool] 连接创建失败: ' . $e->getMessage());
                    throw $e;
                }
            },
            $max,
            null  // 不使用自定义通道
        );
    }

    /**
     * 获取连接（协程安全）
     *
     * @return AMQPStreamConnection
     */
    public static function getConnection(): AMQPStreamConnection
    {
        if (self::$pool === null) {
            self::init();
        }

        // ConnectionPool::get() 内部会无限等待直到获取到连接
        // 对于超时控制，需要通过协程包装器实现
        $connection = self::$pool->get();

        if ($connection === false) {
            throw new \RuntimeException('[RabbitMQPool] 获取连接失败');
        }

        // 验证连接是否有效
        if (!$connection->isConnected()) {
            self::$pool->put($connection);
            throw new \RuntimeException('[RabbitMQPool] 连接已失效');
        }

        self::trackConnection($connection);
        return $connection;
    }

    /**
     * 归还连接
     *
     * @param AMQPStreamConnection $connection
     * @return void
     */
    public static function returnConnection(AMQPStreamConnection $connection): void
    {
        self::untrackConnection($connection);
        if (self::$pool !== null) {
            self::$pool->put($connection);
        }
    }

    /**
     * 关闭连接池
     *
     * @return void
     */
    public static function close(): void
    {
        if (self::$pool !== null) {
            self::$pool->close();
            self::$pool = null;
        }

        // 清理当前协程的追踪记录
        $cid = Coroutine::getCid();
        if ($cid > 0) {
            $ctx = Coroutine::getContext($cid);
            unset($ctx[self::COROUTINE_CTX_KEY]);
        }

        self::$totalCreated = 0;
    }

    /**
     * 获取池状态
     *
     * @return array
     */
    public static function getStats(): array
    {
        if (self::$pool === null) {
            return ['pool' => 'not initialized'];
        }

        $cid = Coroutine::getCid();
        $borrowedInCurrent = 0;
        if ($cid > 0) {
            $ctx = Coroutine::getContext($cid);
            $list = $ctx[self::COROUTINE_CTX_KEY] ?? [];
            $borrowedInCurrent = count($list);
        }

        // 注意：Swoole\ConnectionPool 未提供获取可用连接数的公共 API
        // 这里只能显示当前协程借出的连接数，无法获取全局统计
        return [
            'pool'                  => 'active',
            'max_size'              => self::$poolSize,
            'total_created'         => self::$totalCreated,
            'borrowed_in_coroutine' => $borrowedInCurrent,
        ];
    }

    /**
     * 追踪当前协程借出的连接
     *
     * @param AMQPStreamConnection $connection
     * @return void
     */
    private static function trackConnection(AMQPStreamConnection $connection): void
    {
        $cid = Coroutine::getCid();
        if ($cid <= 0) {
            return;
        }
        $ctx = Coroutine::getContext($cid);
        $list = $ctx[self::COROUTINE_CTX_KEY] ?? [];
        $list[spl_object_id($connection)] = $connection;
        $ctx[self::COROUTINE_CTX_KEY] = $list;
    }

    /**
     * 取消追踪单个连接
     *
     * @param AMQPStreamConnection $connection
     * @return void
     */
    private static function untrackConnection(AMQPStreamConnection $connection): void
    {
        $cid = Coroutine::getCid();
        if ($cid <= 0) {
            return;
        }
        $ctx = Coroutine::getContext($cid);
        $list = $ctx[self::COROUTINE_CTX_KEY] ?? [];
        $id = spl_object_id($connection);
        unset($list[$id]);
        $ctx[self::COROUTINE_CTX_KEY] = $list;
    }

    /**
     * 回收当前协程所有未归还的连接（供 Resetter 请求结束时调用）
     *
     * @return void
     */
    public static function reclaimCurrentCoroutineConnections(): void
    {
        $cid = Coroutine::getCid();
        if ($cid <= 0 || self::$pool === null) {
            return;
        }
        $ctx = Coroutine::getContext($cid);
        $list = $ctx[self::COROUTINE_CTX_KEY] ?? [];
        foreach ($list as $connection) {
            self::$pool->put($connection);
        }
        $ctx[self::COROUTINE_CTX_KEY] = [];
    }
}
