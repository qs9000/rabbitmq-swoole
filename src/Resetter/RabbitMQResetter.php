<?php
declare(strict_types=1);

namespace RabbitMQSwoole\Resetter;

use think\App;
use think\facade\Log;
use think\swoole\contract\ResetterInterface;
use think\swoole\Sandbox;
use RabbitMQSwoole\Service\RabbitMQPool;

/**
 * RabbitMQ 连接重置器
 *
 * 在每次请求结束时清理协程级的连接泄漏
 */
class RabbitMQResetter implements ResetterInterface
{
    /**
     * 每次请求结束后调用，清理协程级的连接泄漏
     *
     * @param App $app 应用实例
     * @param Sandbox $sandbox 沙箱实例
     * @return void
     */
    public function handle(App $app, Sandbox $sandbox): void
    {
        // 检查当前协程是否有未归还的连接
        if (class_exists(RabbitMQPool::class)) {
            $stats = RabbitMQPool::getStats();

            // 如果请求结束时有借出连接，说明存在泄漏
            if ($stats['borrowed_in_coroutine'] > 0) {
                Log::warning('[RabbitMQResetter] 检测到连接泄漏', $stats);
                // 自动回收泄漏的连接
                RabbitMQPool::reclaimCurrentCoroutineConnections();
            } else {
                Log::debug('[RabbitMQResetter] 连接池状态', $stats);
            }
        }
    }
}
