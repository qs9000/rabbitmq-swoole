<?php
declare(strict_types=1);

namespace RabbitMQSwoole\Command;

use think\console\Command;
use think\console\input\Option;

/**
 * 发布 RabbitMQ 配置文件
 */
class PublishConfigCommand extends Command
{
    protected $name = 'rabbitmq:publish';
    protected $description = '发布 RabbitMQ 配置文件到应用 config 目录';

    protected function configure(): void
    {
        $this->setName($this->name)
            ->setDescription($this->description)
            ->addOption('force', 'f', Option::VALUE_NONE, '强制覆盖现有配置');
    }

    protected function execute($input, $output): int
    {
        $sourceFile = __DIR__ . '/../Config/rabbitmq.php';
        $targetFile = $this->app->getRootPath() . 'config/rabbitmq.php';

        // 检查源文件
        if (!file_exists($sourceFile)) {
            $output->error('配置文件源文件不存在: ' . $sourceFile);
            return 1;
        }

        // 检查目标文件
        if (file_exists($targetFile) && !$input->getOption('force')) {
            $output->info('配置文件已存在，使用 --force 强制覆盖');
            return 1;
        }

        // 复制文件
        if (!is_dir(dirname($targetFile))) {
            mkdir(dirname($targetFile), 0755, true);
        }

        $content = file_get_contents($sourceFile);
        // 移除 declare(strict_types=1); 以避免重复声明
        $content = preg_replace('/^\s*<\?php\s*\n\s*declare\(strict_types=1\);\s*\n/im', '', $content);

        file_put_contents($targetFile, $content);

        $output->info('RabbitMQ 配置文件已发布: ' . $targetFile);

        return 0;
    }
}
