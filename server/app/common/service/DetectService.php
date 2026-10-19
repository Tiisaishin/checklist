<?php
namespace app\common\service;

use app\common\detector\DetectResult;
use app\common\detector\DomainDetector;
use app\common\detector\SslDetector;
use app\common\job\DetectAsset;
use app\common\model\Asset;
use app\common\model\Tenant;
use think\facade\Queue;
use Throwable;

/**
 * 自动探测：
 *  - 按剩余天数自适应安排下次探测（离到期越近越频繁），不低于套餐的最小间隔
 *  - 失败按 1h / 3h / 6h / 12h 退避重试，连续失败 3 次发出通知
 *  - 对比前后结果产生事件：续费成功、DNS 变化、状态异常、证书更换、证书异常、自动续期失败
 */
class DetectService
{
    /** 首次失败后的重试间隔（分钟） */
    private const BACKOFF = [60, 180, 360, 720];
    private const FAIL_NOTIFY_AT = 3;

    public static function detect(Asset $asset): bool
    {
        if (!in_array($asset->type, Asset::DETECTABLE, true) || $asset->identifier === '') {
            return false;
        }

        $prevMeta   = (array) $asset->detect_meta;
        $prevOk     = $asset->detect_status === 'ok';
        $prevExpire = $asset->expire_time;

        try {
            $result = $asset->type === 'domain'
                ? (new DomainDetector())->detect($asset->identifier)
                : (new SslDetector())->detect($asset->identifier);
        } catch (Throwable $e) {
            self::onFailure($asset, $e->getMessage());
            return false;
        }

        $data = [
            'detect_status'    => 'ok',
            'detect_error'     => '',
            'detect_meta'      => $result->meta,
            'last_detect_time' => now_utc(),
            'fail_count'       => 0,
        ];
        if ($result->provider !== '' && $asset->provider === '') {
            $data['provider'] = mb_substr($result->provider, 0, 128);
        }
        $expireChanged = $result->expireTime && $result->expireTime !== $prevExpire;
        if ($expireChanged) {
            // 到期时间变化视为新周期：清除确认与延后状态
            $data['expire_time']     = $result->expireTime;
            $data['ack_expire_time'] = null;
            $data['snooze_until']    = null;
        }

        $events = $asset->type === 'domain'
            ? self::domainEvents($result, $prevMeta, $prevOk, $prevExpire)
            : self::sslEvents($result, $prevMeta, $prevOk, $prevExpire, $data);

        $data['next_detect_time'] = self::nextTime($asset, $result->expireTime ?: $prevExpire, $data['detect_meta']);
        $asset->save($data);

        foreach ($events as [$type, $payload]) {
            EventService::fire($asset, $type, $payload);
        }
        return true;
    }

    private static function onFailure(Asset $asset, string $error): void
    {
        $fails = (int) $asset->fail_count + 1;
        $wait  = self::BACKOFF[min($fails - 1, count(self::BACKOFF) - 1)];
        $asset->save([
            'detect_status'    => 'failed',
            'detect_error'     => mb_substr($error, 0, 480),
            'last_detect_time' => now_utc(),
            'fail_count'       => $fails,
            'next_detect_time' => now_utc("+{$wait} minutes"),
        ]);
        if ($fails === self::FAIL_NOTIFY_AT) {
            EventService::fire($asset, 'detect_failed', ['error' => mb_substr($error, 0, 200), 'fail_count' => $fails]);
        }
    }

    /** @return array<int, array{0:string, 1:array}> */
    private static function domainEvents(DetectResult $r, array $prevMeta, bool $prevOk, ?string $prevExpire): array
    {
        $events = [];
        // 续费成功：之前探测成功过，且到期时间延后超过 1 天
        if ($prevOk && $prevExpire && $r->expireTime && strtotime($r->expireTime) > strtotime($prevExpire) + 86400) {
            $events[] = ['domain_renewed', ['old_expire' => $prevExpire, 'new_expire' => $r->expireTime]];
        }
        // DNS 服务器变化
        $old = self::sorted((array) ($prevMeta['nameservers'] ?? []));
        $new = self::sorted((array) ($r->meta['nameservers'] ?? []));
        if ($prevOk && $old && $new && $old !== $new) {
            $events[] = ['ns_changed', ['old' => $old, 'new' => $new]];
        }
        // 出现新的异常状态
        $abn     = DomainDetector::abnormalStatuses((array) ($r->meta['status'] ?? []));
        $prevAbn = DomainDetector::abnormalStatuses((array) ($prevMeta['status'] ?? []));
        if ($abn && $abn !== $prevAbn) {
            $days     = $r->expireTime ? (int) floor((strtotime($r->expireTime) - time()) / 86400) : null;
            $events[] = ['domain_status', ['statuses' => $abn, 'phase' => DomainDetector::phase($days, $abn)]];
        }
        return $events;
    }

    /** @return array<int, array{0:string, 1:array}> */
    private static function sslEvents(DetectResult $r, array $prevMeta, bool $prevOk, ?string $prevExpire, array &$data): array
    {
        $events = [];
        $meta   = $r->meta;
        // 证书更换
        if ($prevOk && !empty($prevMeta['serial']) && ($meta['serial'] ?? '') !== $prevMeta['serial']) {
            $events[] = ['ssl_renewed', ['old_expire' => $prevExpire, 'new_expire' => $r->expireTime, 'issuer' => $meta['issuer_cn'] ?? '']];
        }
        // 证书问题（与上次不同才通知）
        $issue = (string) ($meta['issue'] ?? '');
        if ($issue !== '' && $issue !== (string) ($prevMeta['issue'] ?? '')) {
            $events[] = ['ssl_error', ['issue' => $issue, 'error' => $meta['verify_error'] ?? '']];
        }
        // 短期证书剩余不到 20 天还没更换：自动续期大概率失败（每张证书只提醒一次）
        $days = $r->expireTime ? (strtotime($r->expireTime) - time()) / 86400 : null;
        if (!empty($meta['short_lived']) && $days !== null && $days >= 0 && $days < 20) {
            $meta['renew_stuck_serial'] = $meta['serial'] ?? '';
            if (($prevMeta['renew_stuck_serial'] ?? '') !== $meta['renew_stuck_serial']) {
                $events[] = ['ssl_renew_stuck', ['issuer' => $meta['issuer_cn'] ?? '']];
            }
        }
        $data['detect_meta'] = $meta;
        return $events;
    }

    /**
     * 下次探测时间（分钟）：
     *  域名：>60 天 7 天；30–60 天 3 天；7–30 天 1 天；0–7 天 12 小时；已过期 6 小时
     *  SSL：>30 天 1 天；7–30 天 12 小时；<7 天、已过期或有问题 6 小时
     */
    public static function nextTime(Asset $asset, ?string $expire, array $meta = []): string
    {
        $days = $expire ? (strtotime($expire) - time()) / 86400 : null;
        if ($asset->type === 'domain') {
            $minutes = match (true) {
                $days === null => 1440,
                $days > 60     => 7 * 1440,
                $days > 30     => 3 * 1440,
                $days > 7      => 1440,
                $days >= 0     => 720,
                default        => 360,
            };
        } else {
            $minutes = match (true) {
                $days === null || !empty($meta['issue']) => 360,
                $days > 30 => 1440,
                $days > 7  => 720,
                default    => 360,
            };
        }
        $minutes = max($minutes, self::planInterval((int) $asset->tenant_id));
        return now_utc("+{$minutes} minutes");
    }

    /** 套餐允许的最小探测间隔（分钟） */
    private static function planInterval(int $tenantId): int
    {
        static $cache = [];
        if (!isset($cache[$tenantId])) {
            $tenant           = Tenant::find($tenantId);
            $cache[$tenantId] = max(30, (int) ($tenant ? QuotaService::plan($tenant)?->detect_interval : 1440));
        }
        return $cache[$tenantId];
    }

    private static function sorted(array $list): array
    {
        $list = array_values(array_unique(array_map(fn ($v) => strtolower(rtrim((string) $v, '.')), $list)));
        sort($list);
        return $list;
    }

    /**
     * 把到了探测时间的资产投递到队列（从未探测过的优先）
     */
    public static function dispatchDue(int $limit = 100): int
    {
        $ids = Asset::where('status', 'active')
            ->where('auto_detect', 1)
            ->whereIn('type', Asset::DETECTABLE)
            ->where(function ($q) {
                $q->whereNull('next_detect_time')->whereOr('next_detect_time', '<=', now_utc());
            })
            ->orderRaw('CASE WHEN last_detect_time IS NULL THEN 0 ELSE 1 END, next_detect_time ASC')
            ->limit($limit)
            ->column('id');

        foreach ($ids as $id) {
            // 先占位 30 分钟，避免任务还在队列中时被重复投递
            Asset::where('id', $id)->update(['next_detect_time' => now_utc('+30 minutes')]);
            Queue::push(DetectAsset::class, ['id' => $id], 'detect');
        }
        return count($ids);
    }
}
