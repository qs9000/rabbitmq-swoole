<?php
// RabbitMQ 配置文件
declare(strict_types=1);

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
        // 示例队列配置
        'file_log' => [
            'name'          => 'file_log',           // 队列名称
            'exchange'      => 'amq.direct',        // 交换机名称
            'exchange_type' => 'direct',            // 交换机类型
            'routing_key'   => 'file_log',          // 路由键
            'durable'       => true,                // 是否持久化
        ],
    ],
];
