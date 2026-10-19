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
            'assets'   => Asset::where('tenant_id', $tenant->id)->count(),
            'tasks'    => Task::where('tenant_id', $tenant->id)->count(),
            'channels' => Channel::where('tenant_id', $tenant->id)->count(),
            'members'  => TenantUser::where('tenant_id', $tenant->id)->count(),
            'vault'    => VaultItem::where('tenant_id', $tenant->id)->count(),
        ];
    }

    /**
     * @param string $resource assets / tasks / channels / members / vault
     */
    public static function ensure(Tenant $tenant, string $resource): void
    {
        $plan  = self::plan($tenant);
        $limit = (int) ($plan?->{'max_' . $resource} ?? 0);
        if ($limit < 0) {
            return;
        }
        $used = self::usage($tenant)[$resource] ?? 0;
        if ($used >= $limit) {
            throw new ApiException(lang('quota_exceeded', ['resource' => lang('resource_' . $resource), 'limit' => $limit]), 403);
        }
    }
}
