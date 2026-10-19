<?php
namespace app\common\service;

use app\common\model\Asset;
use app\common\model\Task;
use DateTimeImmutable;

class AssetService
{
    /** 确认本周期已处理（已续费），不再提醒，直到到期时间变化 */
    public static function ack(Asset $asset): void
    {
        $asset->save(['ack_expire_time' => $asset->expire_time, 'snooze_until' => null]);
    }

    /** 手动续费：按续费周期顺延到期时间或使用指定时间 */
    public static function renew(Asset $asset, ?string $newExpire = null): void
    {
        if (!$newExpire) {
            $base = TimeHelper::fromDb($asset->expire_time) ?: new DateTimeImmutable('now', TimeHelper::utc());
            $next = match ($asset->renew_cycle) {
                'monthly'   => $base->modify('+1 month'),
                'quarterly' => $base->modify('+3 months'),
                'yearly'    => $base->modify('+1 year'),
                default     => null,
            };
            $newExpire = $next ? TimeHelper::toDb($next) : null;
        }
        if ($newExpire) {
            $asset->save(['expire_time' => $newExpire, 'ack_expire_time' => null, 'snooze_until' => null]);
        }
    }

    /** 稍后提醒：1h / 1d / 3d / 7d */
    public static function snoozeUntil(string $period): string
    {
        $map = ['1h' => '+1 hour', '3h' => '+3 hours', '1d' => '+1 day', '3d' => '+3 days', '7d' => '+7 days'];
        return now_utc($map[$period] ?? '+1 day');
    }

    public static function snooze(Asset|Task $item, string $period): void
    {
        $item->save(['snooze_until' => self::snoozeUntil($period)]);
    }
}
