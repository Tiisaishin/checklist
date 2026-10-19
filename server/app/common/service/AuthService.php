<?php
namespace app\common\service;

use app\common\exception\ApiException;
use app\common\model\Group;
use app\common\model\Plan;
use app\common\model\Tenant;
use app\common\model\TenantUser;
use app\common\model\User;
use app\common\model\UserToken;
use think\facade\Cache;
use think\facade\Db;

class AuthService
{
    public const TOKEN_TTL_DAYS = 30;

    public static function register(string $email, string $password, string $name = '', string $locale = '', string $timezone = ''): User
    {
        $email = strtolower(trim($email));
        if (User::where('email', $email)->count()) {
            throw new ApiException(lang('email_taken'), 422);
        }

        $locale   = in_array($locale, config('app.locales'), true) ? $locale : (string) config('lang.default_lang');
        $timezone = TimeHelper::isValidTimezone($timezone) ? $timezone : (string) config('app.default_user_timezone');
        $name     = trim($name) ?: strstr($email, '@', true);

        return Db::transaction(function () use ($email, $password, $name, $locale, $timezone) {
            $user = User::create([
                'email'    => $email,
                'password' => $password,
                'name'     => mb_substr($name, 0, 64),
                'locale'   => $locale,
                'timezone' => $timezone,
                'status'   => 1,
            ]);

            $plan   = Plan::defaultPlan();
            $tenant = Tenant::create([
                'name'     => trans('personal_space', ['name' => $user->name], $locale),
                'owner_id' => $user->id,
                'plan_id'  => $plan?->id ?? 0,
                'status'   => 1,
            ]);
            TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);
            Group::defaultFor((int) $tenant->id, $locale);
            $user->save(['current_tenant_id' => $tenant->id]);

            return $user;
        });
    }

    public static function login(string $email, string $password, string $ip, string $ua): array
    {
        $email = strtolower(trim($email));
        $key   = 'login:fail:' . md5($email . '|' . $ip);
        if ((int) Cache::get($key, 0) >= 5) {
            throw new ApiException(lang('too_many_attempts'), 429);
        }

        $user = User::where('email', $email)->find();
        if (!$user || !$user->checkPassword($password)) {
            Cache::set($key, (int) Cache::get($key, 0) + 1, 600);
            throw new ApiException(lang('login_failed'), 401);
        }
        if (!$user->status) {
            throw new ApiException(lang('account_disabled'), 403);
        }
        Cache::delete($key);

        $user->save(['last_login_time' => now_utc(), 'last_login_ip' => $ip]);

        return ['token' => self::issueToken($user, $ip, $ua), 'user' => $user];
    }

    public static function issueToken(User $user, string $ip = '', string $ua = ''): string
    {
        $plain = bin2hex(random_bytes(24));
        UserToken::create([
            'user_id'     => $user->id,
            'token'       => hash('sha256', $plain),
            'expire_time' => now_utc('+' . self::TOKEN_TTL_DAYS . ' days'),
            'ip'          => mb_substr($ip, 0, 64),
            'user_agent'  => mb_substr($ua, 0, 255),
        ]);
        return $plain;
    }

    public static function userByToken(string $plain): ?User
    {
        if ($plain === '') {
            return null;
        }
        $token = UserToken::where('token', hash('sha256', $plain))->find();
        if (!$token || $token->expire_time < now_utc()) {
            return null;
        }
        // 降低写库频率：每 10 分钟更新一次最后使用时间
        if (!$token->last_used_time || $token->last_used_time < now_utc('-10 minutes')) {
            $token->save(['last_used_time' => now_utc()]);
        }
        $user = User::find($token->user_id);
        return $user && $user->status ? $user : null;
    }

    public static function revoke(string $plain): void
    {
        UserToken::where('token', hash('sha256', $plain))->delete();
    }

    /** 用户可访问的租户列表 */
    public static function memberships(User $user): array
    {
        return TenantUser::alias('tu')
            ->join('tenants t', 't.id = tu.tenant_id')
            ->where('tu.user_id', $user->id)
            ->where('t.status', 1)
            ->field('t.id, t.name, t.plan_id, tu.role')
            ->order('t.id')
            ->select()
            ->toArray();
    }
}
