<?php
namespace app\common\service;

use app\common\detector\DomainDetector;
use app\common\detector\SslDetector;
use app\common\job\DetectAsset;
use app\common\model\Asset;
use app\common\model\Plan;
use app\common\model\Tenant;
use think\facade\Queue;
use Throwable;

class DetectService
{
    /**
     * 立即探测并更新资产，返回是否成功
     */
    public static function detect(Asset $asset): bool
    {
        if (!in_array($asset->type, Asset::DETECTABLE, true) || $asset->identifier === '') {
            return false;
        }

        try {
            $result = $asset->type === 'domain'
                ? (new DomainDetector())->detect($asset->identifier)
                : (new SslDetector())->detect($asset->identifier);
        } catch (Throwable $e) {
            $asset->save([
                'detect_status'    => 'failed',
                'detect_error'     => mb_substr($e->getMessage(), 0, 480),
                'last_detect_time' => now_utc(),
            ]);
            return false;
        }

        $data = [
            'detect_status'    => 'ok',
            'detect_error'     => '',
            'detect_meta'      => $result->meta,
            'last_detect_time' => now_utc(),
        ];
        if ($result->provider !== '' && $asset->provider === '') {
            $data['provider'] = mb_substr($result->provider, 0, 128);
        }
        if ($result->expireTime && $result->expireTime !== $asset->expire_time) {
            // 到期时间延后视为已续费：清除确认与延后状态，进入新周期
            $data['expire_time']     = $result->expireTime;
            $data['ack_expire_time'] = null;
            $data['snooze_until']    = null;
        }
        $asset->save($data);

        return true;
    }

    /**
     * 按套餐的探测间隔，把到期需要探测的资产投递到队列
     */
    public static function dispatchDue(int $limit = 200): int
    {
        $intervals = Plan::column('detect_interval', 'id');
        $plans     = Tenant::column('plan_id', 'id');
        $count     = 0;

        $assets = Asset::where('status', 'active')
            ->where('auto_detect', 1)
            ->whereIn('type', Asset::DETECTABLE)
            ->where(function ($q) {
                $q->whereNull('last_detect_time')->whereOr('last_detect_time', '<=', now_utc('-30 minutes'));
            })
            ->order('last_detect_time', 'asc')
            ->limit($limit * 3)
            ->select();

        foreach ($assets as $asset) {
            $interval = max(30, (int) ($intervals[$plans[$asset->tenant_id] ?? 0] ?? 1440));
            if ($asset->last_detect_time && $asset->last_detect_time > now_utc("-{$interval} minutes")) {
                continue;
            }
            // 先占位，避免任务还在队列中时被重复投递
            Asset::where('id', $asset->id)->update(['last_detect_time' => now_utc()]);
            Queue::push(DetectAsset::class, ['id' => $asset->id], 'detect');
            if (++$count >= $limit) {
                break;
            }
        }
        return $count;
    }
}
