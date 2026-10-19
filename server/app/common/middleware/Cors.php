<?php
namespace app\common\middleware;

use Closure;
use think\Request;
use think\Response;

/**
 * 跨域（开发时前端与后端分离部署）
 */
class Cors
{
    public function handle(Request $request, Closure $next): Response
    {
        $headers = [
            'Access-Control-Allow-Origin'  => $request->header('origin') ?: '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Tenant-Id, Think-Lang, X-Requested-With',
            'Access-Control-Max-Age'       => '86400',
        ];

        if ($request->method(true) === 'OPTIONS') {
            return Response::create('', 'html', 204)->header($headers);
        }

        return $next($request)->header($headers);
    }
}
