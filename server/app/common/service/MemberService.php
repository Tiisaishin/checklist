<?php
namespace app\common\service;

use app\common\exception\ApiException;
use app\common\model\Channel;
use app\common\model\Invitation;
use app\common\model\Tenant;
use app\common\model\TenantUser;
use app\common\model\User;
use think\facade\Db;

/**
 * 团队成员与邀请
 *
 * 角色：owner > admin > member > viewer
 *  - owner 可以邀请/设置 admin、member、viewer，可转让所有权，不能退出（需先转让）
 *  - admin 只能邀请/管理 member、viewer
 */
class MemberService
{
    public static function assignableRoles(string $actorRole): array
    {
        return match ($actorRole) {
            'owner' => ['admin', 'member', 'viewer'],
            'admin' => ['member', 'viewer'],
            default => [],
        };
    }

    /** 当前操作人能否管理目标成员 */
    public static function canManage(string $actorRole, string $targetRole): bool
    {
        return in_array($targetRole, self::assignableRoles($actorRole), true);
    }

    /**
     * 创建邀请，返回 [邀请记录, 明文 token]。同一邮箱的旧邀请会被作废。
     */
    public static function invite(Tenant $tenant, User $inviter, string $actorRole, string $email, string $role): array
    {
        $email = strtolower(trim($email));
        if (!in_array($role, self::assignableRoles($actorRole), true)) {
            throw new ApiException(lang('forbidden'), 403);
        }

        $existing = User::where('email', $email)->find();
        if ($existing && TenantUser::where('tenant_id', $tenant->id)->where('user_id', $existing->id)->count()) {
            throw new ApiException(lang('already_member'), 422);
        }

        Invitation::where('tenant_id', $tenant->id)->where('email', $email)->where('status', 'pending')->update(['status' => 'revoked']);
        self::ensureSeat($tenant);

        $plain      = bin2hex(random_bytes(20));
        $invitation = Invitation::create([
            'tenant_id'   => $tenant->id,
            'inviter_id'  => $inviter->id,
            'email'       => $email,
            'role'        => $role,
            'token'       => hash('sha256', $plain),
            'status'      => 'pending',
            'expire_time' => now_utc('+' . Invitation::TTL_DAYS . ' days'),
        ]);

        return [$invitation, $plain];
    }

    /** 成员数 + 未过期的待接受邀请 不能超过套餐上限 */
    public static function ensureSeat(Tenant $tenant): void
    {
        $limit = (int) (QuotaService::plan($tenant)?->max_members ?? 1);
        if ($limit < 0) {
            return;
        }
        $used = TenantUser::where('tenant_id', $tenant->id)->count()
            + Invitation::where('tenant_id', $tenant->id)->where('status', 'pending')->where('expire_time', '>', now_utc())->count();
        if ($used >= $limit) {
            throw new ApiException(lang('quota_exceeded', ['resource' => lang('resource_members'), 'limit' => $limit]), 403);
        }
    }

    public static function findByToken(string $plain): ?Invitation
    {
        return $plain === '' ? null : Invitation::where('token', hash('sha256', $plain))->find();
    }

    /**
     * 接受邀请：登录账号的邮箱必须与邀请邮箱一致
     */
    public static function accept(string $plain, User $user): Tenant
    {
        $invitation = self::findByToken($plain);
        if (!$invitation || !$invitation->isValid()) {
            throw new ApiException(lang('invite_invalid'), 410);
        }
        if (strtolower($user->email) !== strtolower($invitation->email)) {
            throw new ApiException(lang('invite_email_mismatch', ['email' => $invitation->email]), 403);
        }
        $tenant = Tenant::find($invitation->tenant_id);
        if (!$tenant || !$tenant->status) {
            throw new ApiException(lang('tenant_unavailable'), 403);
        }

        Db::transaction(function () use ($invitation, $user, $tenant) {
            if (!TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->count()) {
                TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $invitation->role]);
            }
            $invitation->save(['status' => 'accepted', 'accept_user_id' => $user->id, 'accept_time' => now_utc()]);
            $user->save(['current_tenant_id' => $tenant->id]);
        });

        return $tenant;
    }

    public static function changeRole(Tenant $tenant, User $actor, string $actorRole, int $userId, string $role): void
    {
        $member = self::member($tenant, $userId);
        if ($userId === (int) $actor->id || !self::canManage($actorRole, $member->role) || !in_array($role, self::assignableRoles($actorRole), true)) {
            throw new ApiException(lang('forbidden'), 403);
        }
        TenantUser::where('id', $member->id)->update(['role' => $role, 'update_time' => now_utc()]);
    }

    public static function remove(Tenant $tenant, User $actor, string $actorRole, int $userId): void
    {
        $member = self::member($tenant, $userId);
        if ($userId === (int) $actor->id || !self::canManage($actorRole, $member->role)) {
            throw new ApiException(lang('forbidden'), 403);
        }
        self::detach($tenant, $userId);
    }

    public static function leave(Tenant $tenant, User $user, string $role): void
    {
        if ($role === 'owner') {
            throw new ApiException(lang('owner_cannot_leave'), 403);
        }
        self::detach($tenant, (int) $user->id);
    }

    /** 转让所有权：目标成为 owner，原 owner 降为 admin */
    public static function transfer(Tenant $tenant, User $owner, string $actorRole, int $userId): void
    {
        if ($actorRole !== 'owner' || $userId === (int) $owner->id) {
            throw new ApiException(lang('forbidden'), 403);
        }
        self::member($tenant, $userId);
        Db::transaction(function () use ($tenant, $owner, $userId) {
            TenantUser::where('tenant_id', $tenant->id)->where('user_id', $userId)->update(['role' => 'owner', 'update_time' => now_utc()]);
            TenantUser::where('tenant_id', $tenant->id)->where('user_id', $owner->id)->update(['role' => 'admin', 'update_time' => now_utc()]);
            $tenant->save(['owner_id' => $userId]);
        });
    }

    /** 移出空间：同时删除该成员在此空间的通知渠道，避免继续收到提醒 */
    private static function detach(Tenant $tenant, int $userId): void
    {
        foreach (Channel::where('tenant_id', $tenant->id)->where('user_id', $userId)->select() as $channel) {
            ChannelService::delete($channel);
        }
        TenantUser::where('tenant_id', $tenant->id)->where('user_id', $userId)->delete();
        User::where('id', $userId)->where('current_tenant_id', $tenant->id)->update(['current_tenant_id' => 0]);
    }

    private static function member(Tenant $tenant, int $userId): TenantUser
    {
        $member = TenantUser::where('tenant_id', $tenant->id)->where('user_id', $userId)->find();
        if (!$member) {
            throw new ApiException(lang('not_found'), 404);
        }
        return $member;
    }
}
