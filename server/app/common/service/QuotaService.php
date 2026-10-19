<?php
namespace app\common\service;

use app\common\exception\ApiException;
use app\common\model\Asset;
use app\common\model\Channel;
use app\common\model\Plan;
use app\common\model\Task;
use app\common\model\Tenant;
use app\common\model\TenantUser;
use app\common\model\VaultItem;

/**
 * 套餐配额
 */
class QuotaService
{
    /** 当前有效套餐：过期后回落到默认套餐 */
    public static function plan(Tenant $tenant): ?Plan
    {
        $plan = Plan::find($tenant->plan_id);
        if (!$plan || ($tenant->plan_expire_time && $tenant->plan_expire_time < now_utc())) {
            $plan = Plan::defaultPlan();
        }
        return $plan;
    }

    public static function usage(Tenant $tenant): array
    {
        return [
            'domains'  => Asset::where('tenant_id', $tenant->id)->where('type', 'domain')->count(),
            'ssl'      => Asset::where('tenant_id', $tenant->id)->where('type', 'ssl')->count(),
            'assets'   => Asset::where('tenant_id', $tenant->id)->whereNotIn('type', ['domain', 'ssl'])->count(),
            'tasks'    => Task::where('tenant_id', $tenant->id)->count(),
            'channels' => Channel::where('tenant_id', $tenant->id)->count(),
            'members'  => TenantUser::where('tenant_id', $tenant->id)->count(),
            'vault'    => VaultItem::where('tenant_id', $tenant->id)->count(),
        ];
    }

    /** 资产类型对应的配额：域名、SSL 证书、其他资产分开计算 */
    public static function resourceForType(string $type): string
    {
        return match ($type) {
            'domain' => 'domains',
            'ssl'    => 'ssl',
            default  => 'assets',
        };
    }

    /** 剩余可用数量，-1 表示不限 */
    public static function remaining(Tenant $tenant, string $resource): int
    {
        $limit = (int) (self::plan($tenant)?->{'max_' . $resource} ?? 0);
        if ($limit < 0) {
            return -1;
        }
        return max(0, $limit - (self::usage($tenant)[$resource] ?? 0));
    }

    /**
     * @param string $resource domains / ssl / assets / tasks / channels / members / vault
     * @param int    $count    本次要新增的数量
     */
    public static function ensure(Tenant $tenant, string $resource, int $count = 1): void
    {
        $left = self::remaining($tenant, $resource);
        if ($left >= 0 && $count > $left) {
            $limit = (int) self::plan($tenant)?->{'max_' . $resource};
            throw new ApiException(lang('quota_exceeded', ['resource' => lang('resource_' . $resource), 'limit' => $limit]), 403);
        }
    }
}
