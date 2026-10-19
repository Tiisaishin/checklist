<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\exception\ApiException;
use app\common\model\Tenant;
use app\common\model\User;
use app\common\service\AuthContext;
use app\common\service\MemberService;

/**
 * 邀请链接：查看（无需登录）与接受（需登录）
 */
class Invitation extends ApiController
{
    public function show(string $token)
    {
        $invitation = MemberService::findByToken($token);
        if (!$invitation || !$invitation->isValid()) {
            throw new ApiException(lang('invite_invalid'), 410);
        }
        $inviter = User::find($invitation->inviter_id);

        return $this->ok([
            'tenant'       => (string) Tenant::where('id', $invitation->tenant_id)->value('name'),
            'inviter'      => $inviter?->name ?: $inviter?->email,
            'email'        => $invitation->email,
            'role'         => $invitation->role,
            'expire_time'  => $invitation->expire_time,
            'has_account'  => (bool) User::where('email', $invitation->email)->count(),
        ]);
    }

    public function accept(string $token)
    {
        $tenant = MemberService::accept($token, AuthContext::user());
        return $this->ok(['tenant_id' => $tenant->id, 'tenant' => $tenant->name]);
    }
}
