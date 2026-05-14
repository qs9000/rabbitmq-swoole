<?php

namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use InvalidArgumentException;
use ErrorException;
use Throwable;
use RabbitMQSwoole\Service\RabbitMQService;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 需要显示详细错误信息的异常类列表
     * @var array
     */
    protected $showErrorMsg = [
        InvalidArgumentException::class,
    ];

    /**
     * 是否处于 Swoole 协程上下文（缓存）
     * @var bool|null
     */
    private $inSwooleCoroutine = null;

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        if ($this->isIgnoreReport($exception)) {
            return;
        }

        // 在 Swoole 非协程上下文（如启动阶段）只记录文件日志，避免 RabbitMQ 报错
        if (!$this->isSwooleWorkerReady()) {
            parent::report($exception);
            return;
        }

        $this->logException($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request   $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        if ($request->isJson()) {
            return $this->renderJsonResponse($request, $e);
        }

        return parent::render($request, $e);
    }

    /**
     * 记录异常日志到 RabbitMQ（降级到文件）
     *
     * @access protected
     * @param  Throwable $exception
     * @return void
     */
    protected function logException(Throwable $exception): void
    {
        $data = $this->buildLogData($exception);

        try {
            app()->make(RabbitMQService::class)->publish('system_log', $data);
        } catch (Throwable $e) {
            // 降级：记录到框架日志，如果框架日志也失败则 fallback 到 error_log
            try {
                $this->app->log->error('RabbitMQ日志发送失败: ' . $e->getMessage());
                parent::report($exception); // 记录原始异常到文件
            } catch (Throwable $logError) {
                error_log('Exception logging failed: ' . $logError->getMessage());
            }
        }
    }

    /**
     * 构建发送到 RabbitMQ 的日志数据
     *
     * @param Throwable $exception
     * @return array
     */
    protected function buildLogData(Throwable $exception): array
    {
        // 截取消息（多字节安全）
        $message = $exception->getMessage();
        $maxLength = 225;
        if (mb_strlen($message, 'UTF-8') > $maxLength) {
            $message = mb_substr($message, 0, $maxLength, 'UTF-8') . '...[truncated]';
        }

        // 安全获取 request 对象（可能在 CLI 或非 HTTP 环境）
        $request = $this->getSafeRequest();

        return [
            'log_type'    => 'errorlog',
            'tenant_id'   => $request?->tenant_id ?? '未知',
            'time'        => date('Y-m-d H:i:s'),
            'request_id'  => $request?->traceId ?? '未知',
            'module'      => 'lms',
            'level'       => $this->getErrorLevelName($exception),
            'code'        => $exception->getCode(),
            'message'     => $message,
            'file'        => $exception->getFile(),
            'line'        => $exception->getLine(),
            // 脱敏堆栈：只保留文件、行号、类、方法，移除 args，并限制 10 条
            'trace'       => array_slice(array_map(function ($frame) {
                return [
                    'file'     => $frame['file'] ?? '',
                    'line'     => $frame['line'] ?? 0,
                    'class'    => $frame['class'] ?? '',
                    'type'     => $frame['type'] ?? '',
                    'function' => $frame['function'] ?? '',
                ];
            }, $exception->getTrace()), 0, 10),
        ];
    }

    /**
     * 安全获取当前请求对象
     *
     * @return \think\Request|null
     */
    protected function getSafeRequest(): ?\think\facade\Request
    {
        try {
            return request();
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 判断当前是否处于 Swoole 协程上下文（Worker 已就绪）
     *
     * @return bool
     */
    protected function isSwooleWorkerReady(): bool
    {
        if (!extension_loaded('swoole')) {
            return true;
        }

        if ($this->inSwooleCoroutine === null) {
            $this->inSwooleCoroutine = \Swoole\Coroutine::getCid() > 0;
        }
        return $this->inSwooleCoroutine;
    }

    /**
     * 渲染 JSON 格式的异常响应
     *
     * @access protected
     * @param  \think\Request $request
     * @param  Throwable $e
     * @return Response
     */
    protected function renderJsonResponse($request, Throwable $e): Response
    {
        $isDebug = $this->app->isDebug();
        $data = $this->convertExceptionToArray($e);

        $response = [
            'code' => 0,
            'msg'  => $data['message'],
            'time' => time(),
            'data' => $isDebug ? ['exception' => $data] : null,
        ];

        $statusCode = $e instanceof HttpException ? $e->getStatusCode() : 500;
        return json($response)->code($statusCode);
    }

    /**
     * 将异常转换为数组（兼容部署模式下的错误信息控制）
     *
     * @access protected
     * @param  Throwable $exception
     * @return array
     */
    protected function convertExceptionToArray(Throwable $exception): array
    {
        if ($this->app->isDebug()) {
            return parent::convertExceptionToArray($exception);
        }

        $showErrorMsg = $this->isShowErrorMsg($exception);
        if ($showErrorMsg || $this->app->config->get('app.show_error_msg', false)) {
            $message = $this->getMessage($exception);
        } else {
            $message = $this->app->config->get('app.error_message', '系统繁忙，请稍后再试');
        }

        return [
            'code'    => $this->getCode($exception),
            'message' => $message,
        ];
    }

    /**
     * 判断是否需要显示详细错误信息
     *
     * @access protected
     * @param  Throwable $exception
     * @return bool
     */
    protected function isShowErrorMsg(Throwable $exception): bool
    {
        foreach ($this->showErrorMsg as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }
        return false;
    }

    /**
     * 获取错误级别的可读名称
     *
     * @param Throwable $exception
     * @return string
     */
    protected function getErrorLevelName(Throwable $exception): string
    {
        if (!$exception instanceof ErrorException) {
            return 'Custom Exception';
        }

        $severity = $exception->getSeverity();
        $levels = [
            E_ERROR             => 'Fatal Error',
            E_WARNING           => 'Warning',
            E_PARSE             => 'Parse Error',
            E_NOTICE            => 'Notice',
            E_CORE_ERROR        => 'Core Error',
            E_CORE_WARNING      => 'Core Warning',
            E_COMPILE_ERROR     => 'Compile Error',
            E_COMPILE_WARNING   => 'Compile Warning',
            E_USER_ERROR        => 'User Error',
            E_USER_WARNING      => 'User Warning',
            E_USER_NOTICE       => 'User Notice',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED        => 'Deprecated',
            E_USER_DEPRECATED   => 'User Deprecated',
        ];
        return $levels[$severity] ?? 'Unknown Error (' . $severity . ')';
    }

    /**
     * 获取异常代码（兼容）
     *
     * @param Throwable $exception
     * @return int
     */
    protected function getCode(Throwable $exception): int
    {
        $code = $exception->getCode();
        return is_int($code) ? $code : (int) $code;
    }
}
