<?php
namespace app;

use app\common\exception\ApiException;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 统一 JSON 异常输出
 */
class ExceptionHandle extends Handle
{
    protected $ignoreReport = [
        ApiException::class,
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    public function render($request, Throwable $e): Response
    {
        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        [$status, $message, $data] = match (true) {
            $e instanceof ApiException       => [$e->getStatus(), $e->getMessage(), $e->getData()],
            $e instanceof ValidateException  => [422, (string) (is_array($e->getError()) ? current($e->getError()) : $e->getError()), is_array($e->getError()) ? $e->getError() : null],
            $e instanceof ModelNotFoundException,
            $e instanceof DataNotFoundException => [404, lang('not_found'), null],
            $e instanceof HttpException      => [$e->getStatusCode(), $e->getMessage() ?: 'HTTP ' . $e->getStatusCode(), null],
            default                          => [500, $this->app->isDebug() ? $e->getMessage() : lang('server_error'), null],
        };

        $body = ['code' => $status, 'message' => $message, 'data' => $data];
        if ($status === 500 && $this->app->isDebug()) {
            $body['trace'] = explode("\n", $e->getTraceAsString());
        }

        return json($body, $status);
    }
}
