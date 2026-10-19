<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\model\Invitation as InvitationModel;
use app\common\model\TenantUser;
use app\common\service\AuthContext;
use app\common\service\MemberService;

/**
 * 团队成员管理（owner / admin）
 */
class Member extends ApiController
{
    public function index()
    {
        $tid     = $this->tenantId();
        $members = TenantUser::alias('tu')
            ->join('users u', 'u.id = tu.user_id')
            ->where('tu.tenant_id', $tid)
            ->field('u.id, u.name, u.email, u.last_login_time, tu.role, tu.create_time AS join_time')
            ->orderRaw("CASE tu.role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 WHEN 'member' THEN 2 ELSE 3 END, tu.id")
            ->select()
            ->toArray();

        $invitations = [];
        if (AuthContext::isAdmin()) {
            $invitations = InvitationModel::where('tenant_id', $tid)->where('status', 'pending')
                ->where('expire_time', '>', now_utc())->order('id', 'desc')->select()->toArray();
        }

        return $this->ok([
            'members'     => $members,
            'invitations' => $invitations,
            'my_role'     => AuthContext::$role,
            'assignable'  => MemberService::assignableRoles(AuthContext::$role),
        ]);
    }

    public function invite()
    {
        $this->ensureAdmin();
        $data = $this->validated(['email' => 'require|email|max:191', 'role' => 'require|in:admin,member,viewer']);
        [$invitation, $token] = MemberService::invite(AuthContext::tenant(), AuthContext::user(), AuthContext::$role, $data['email'], $data['role']);

        return $this->ok($invitation->toArray() + ['token' => $token], lang('saved'));
    }

    public function revoke(int $id)
    {
        $this->ensureAdmin();
        InvitationModel::where('tenant_id', $this->tenantId())->where('id', $id)->where('status', 'pending')->update(['status' => 'revoked']);
        return $this->ok(null, lang('deleted'));
    }

    public function update(int $id)
    {
        $data = $this->validated(['role' => 'require|in:admin,member,viewer']);
        MemberService::changeRole(AuthContext::tenant(), AuthContext::user(), AuthContext::$role, $id, $data['role']);
        return $this->ok(null, lang('saved'));
    }

    public function delete(int $id)
    {
        MemberService::remove(AuthContext::tenant(), AuthContext::user(), AuthContext::$role, $id);
        return $this->ok(null, lang('deleted'));
    }

    public function transfer(int $id)
    {
        MemberService::transfer(AuthContext::tenant(), AuthContext::user(), AuthContext::$role, $id);
        return $this->ok(null, lang('saved'));
    }

    public function leave()
    {
        MemberService::leave(AuthContext::tenant(), AuthContext::user(), AuthContext::$role);
        return $this->ok(null, lang('ok'));
    }
}
