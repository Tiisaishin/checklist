<?php
namespace app\common\service;

use app\common\exception\ApiException;
use app\common\model\User;
use app\common\model\VaultItem;
use app\common\model\VaultLog;
use InvalidArgumentException;
use think\db\BaseQuery;
use think\facade\Cache;

/**
 * 保险箱：
 *  - 敏感字段整体加密，每个空间使用独立派生密钥
 *  - 查看明文 / 验证码前需要用登录密码解锁，解锁仅对当前登录令牌有效 10 分钟
 *  - 只读角色不能查看明文；私密条目只有创建人可见
 */
class VaultService
{
    public const UNLOCK_TTL = 600;

    private static function context(int $tenantId): string
    {
        return 'vault:' . $tenantId;
    }

    public static function encryptSecret(int $tenantId, array $secret): string
    {
        return Crypto::encrypt(json_encode($secret, JSON_UNESCAPED_UNICODE), self::context($tenantId));
    }

    public static function decryptSecret(VaultItem $item): array
    {
        $raw = (string) $item->getData('secret');
        if ($raw === '') {
            return [];
        }
        $plain = Crypto::decrypt($raw, self::context((int) $item->tenant_id));
        return $plain ? (json_decode($plain, true) ?: []) : [];
    }

    /** 清洗前端提交的敏感字段 */
    public static function normalizeSecret(array $input): array
    {
        $fields = [];
        foreach ((array) ($input['fields'] ?? []) as $f) {
            $label = mb_substr(trim((string) ($f['label'] ?? '')), 0, 64);
            $value = mb_substr((string) ($f['value'] ?? ''), 0, 4000);
            if ($label !== '' || $value !== '') {
                $fields[] = ['label' => $label, 'value' => $value, 'secret' => !empty($f['secret'])];
            }
        }
        $secret = [
            'username' => mb_substr((string) ($input['username'] ?? ''), 0, 255),
            'password' => mb_substr((string) ($input['password'] ?? ''), 0, 4000),
            'totp'     => trim((string) ($input['totp'] ?? '')),
            'notes'    => mb_substr((string) ($input['notes'] ?? ''), 0, 10000),
            'fields'   => array_slice($fields, 0, 30),
        ];
        if ($secret['totp'] !== '') {
            try {
                Totp::parse($secret['totp']);
            } catch (InvalidArgumentException) {
                throw new ApiException(lang('invalid_totp'), 422);
            }
        }
        return $secret;
    }

    /** 当前用户可见的条目 */
    public static function visible(int $tenantId, int $userId): BaseQuery
    {
        return VaultItem::where('tenant_id', $tenantId)->where(function ($q) use ($userId) {
            $q->where('visibility', 'team')->whereOr('user_id', $userId);
        });
    }

    public static function canEdit(VaultItem $item, string $role, int $userId): bool
    {
        if ($item->visibility === 'private') {
            return $item->user_id === $userId;
        }
        return $role !== 'viewer';
    }

    public static function canReveal(string $role): bool
    {
        return $role !== 'viewer';
    }

    // ---------- 解锁 ----------

    private static function unlockKey(string $token): string
    {
        return 'vault:unlock:' . hash('sha256', $token);
    }

    public static function unlock(User $user, string $token, string $password): int
    {
        $failKey = 'vault:fail:' . $user->id;
        if ((int) Cache::get($failKey, 0) >= 5) {
            throw new ApiException(lang('too_many_attempts'), 429);
        }
        if (!$user->checkPassword($password)) {
            Cache::set($failKey, (int) Cache::get($failKey, 0) + 1, 600);
            throw new ApiException(lang('password_wrong'), 422);
        }
        Cache::delete($failKey);
        $expire = time() + self::UNLOCK_TTL;
        Cache::set(self::unlockKey($token), $expire, self::UNLOCK_TTL);
        return self::UNLOCK_TTL;
    }

    public static function lock(string $token): void
    {
        Cache::delete(self::unlockKey($token));
    }

    /** 剩余解锁秒数，0 表示已锁定 */
    public static function remaining(string $token): int
    {
        $expire = (int) Cache::get(self::unlockKey($token), 0);
        return max(0, $expire - time());
    }

    public static function ensureUnlocked(string $token): void
    {
        if (self::remaining($token) <= 0) {
            throw new ApiException(lang('vault_locked'), 423);
        }
    }

    public static function log(VaultItem $item, int $userId, string $action, string $detail = '', string $ip = ''): void
    {
        VaultLog::create([
            'tenant_id'   => $item->tenant_id,
            'item_id'     => $item->id,
            'user_id'     => $userId,
            'action'      => $action,
            'detail'      => mb_substr($detail, 0, 64),
            'ip'          => mb_substr($ip, 0, 64),
            'create_time' => now_utc(),
        ]);
    }
}
