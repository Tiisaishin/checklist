<?php
namespace app\common\middleware;

use app\common\exception\ApiException;
use app\common\service\AuthContext;
use Closure;
use think\Request;
use think\Response;

class SuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!AuthContext::$user?->is_super) {
            throw new ApiException(lang('forbidden'), 403);
        }
        return $next($request);
    }
}
