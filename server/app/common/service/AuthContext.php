<?php
namespace app\common\service;

use app\common\model\Tenant;
use app\common\model\User;

/**
 * 当前请求的登录用户 / 租户上下文
 */
class AuthContext
{
    public static ?User $user = null;
    public static ?Tenant $tenant = null;
    public static string $role = '';

    public static function user(): User
    {
        return self::$user;
    }

    public static function tenant(): Tenant
    {
        return self::$tenant;
    }

    public static function tenantId(): int
    {
        return (int) self::$tenant?->id;
    }

    public static function userId(): int
    {
        return (int) self::$user?->id;
    }

    public static function canWrite(): bool
    {
        return self::$role !== 'viewer';
    }

    public static function isAdmin(): bool
    {
        return in_array(self::$role, ['owner', 'admin'], true);
    }
}
