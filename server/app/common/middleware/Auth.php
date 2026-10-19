<?php
namespace app\common\middleware;

use app\common\exception\ApiException;
use app\common\model\Tenant;
use app\common\model\TenantUser;
use app\common\service\AuthContext;
use app\common\service\AuthService;
use Closure;
use think\facade\Lang;
use think\Request;
use think\Response;

/**
 * Bearer Token 鉴权 + 租户解析（X-Tenant-Id 或用户当前租户）
 */
class Auth
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('authorization', '');
        $token  = str_starts_with($header, 'Bearer ') ? substr($header, 7) : '';
        $user   = AuthService::userByToken(trim($token));
        if (!$user) {
            throw new ApiException(lang('unauthorized'), 401);
        }

        // 未显式指定语言时，使用用户设置
        if (!$request->header('think-lang') && $user->locale && $user->locale !== Lang::getLangSet()) {
            Lang::switchLangSet($user->locale);
        }

        $tenantId   = (int) ($request->header('x-tenant-id') ?: $user->current_tenant_id);
        $membership = TenantUser::where('tenant_id', $tenantId)->where('user_id', $user->id)->find();
        if (!$membership) {
            // 回落到第一个可用租户
            $membership = TenantUser::where('user_id', $user->id)->order('id')->find();
        }
        $tenant = $membership ? Tenant::find($membership->tenant_id) : null;
        if (!$tenant || !$tenant->status) {
            throw new ApiException(lang('tenant_unavailable'), 403);
        }
        if ((int) $user->current_tenant_id !== (int) $tenant->id) {
            $user->save(['current_tenant_id' => $tenant->id]);
        }

        AuthContext::$user   = $user;
        AuthContext::$tenant = $tenant;
        AuthContext::$role   = (string) $membership->role;
        AuthContext::$token  = trim($token);

        if (!AuthContext::canWrite() && !in_array($request->method(), ['GET', 'HEAD'], true) && !str_starts_with($request->pathinfo(), 'auth/')) {
            throw new ApiException(lang('forbidden'), 403);
        }

        return $next($request);
    }
}
