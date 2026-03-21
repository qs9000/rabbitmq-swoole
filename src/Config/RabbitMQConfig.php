<?php
declare(strict_types=1);

namespace RabbitMQSwoole\Config;

use think\facade\Config;

/**
 * RabbitMQ 配置读取类
 *
 * 提供统一的配置访问接口
 */
class RabbitMQConfig
{
    /**
     * 获取 RabbitMQ 配置
     *
     * @param string $key 配置键名
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        return Config::get('rabbitmq.' . $key, $default);
    }

    /**
     * 获取 RabbitMQ 基础配置
     *
     * @return array
     */
    public static function getConnectionConfig(): array
    {
        return self::get('connection', [
            'host' => '127.0.0.1',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
        ]);
    }

    /**
     * 获取队列配置
     *
     * @param string $queueName 队列名称
     * @return array
     */
    public static function getQueueConfig(string $queueName): array
    {
        $config = self::get('queue.' . $queueName, []);
        if (empty($config)) {
            Log::warning("[RabbitMQConfig] 队列配置不存在或为空: {$queueName}");
        }
        return $config;
    }

    /**
     * 获取 Swoole Worker 配置
     *
     * @return array
     */
    public static function getWorkerConfig(): array
    {
        return Config::get('swoole.rabbitmq_worker', []);
    }

    /**
     * 获取连接池配置
     *
     * @return array
     */
    public static function getPoolConfig(): array
    {
        return Config::get('swoole.pool.rabbitmq', []);
    }
}
