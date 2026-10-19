<?php
namespace app\common\service;

use app\common\job\SendNotification;
use app\common\model\Channel;
use app\common\model\Notification;
use app\common\notify\DriverFactory;
use think\facade\Log;
use think\facade\Queue;
use Throwable;

class NotificationService
{
    /**
     * 创建一条通知（按 dedupe_key 幂等），并投递发送队列
     */
    public static function dispatch(Channel $channel, string $targetType, int $targetId, string $dedupeKey, string $content, array $actions = []): ?Notification
    {
        if (Notification::where('dedupe_key', $dedupeKey)->count()) {
            return null;
        }

        try {
            $notification = Notification::create([
                'tenant_id'   => $channel->tenant_id,
                'user_id'     => $channel->user_id,
                'channel_id'  => $channel->id,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'dedupe_key'  => $dedupeKey,
                'content'     => $content,
                'status'      => 'pending',
            ]);
        } catch (Throwable) {
            // 并发下唯一索引冲突，说明已经创建过
            return null;
        }

        Queue::push(SendNotification::class, ['id' => $notification->id, 'actions' => $actions], 'notify');

        return $notification;
    }

    /**
     * 只记录不发送（已合并进每日汇总），用于去重与“本周期已提醒”判断
     */
    public static function mark(Channel $channel, string $targetType, int $targetId, string $dedupeKey, string $content): void
    {
        try {
            Notification::create([
                'tenant_id'   => $channel->tenant_id,
                'user_id'     => $channel->user_id,
                'channel_id'  => $channel->id,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'dedupe_key'  => $dedupeKey,
                'content'     => $content,
                'status'      => 'merged',
            ]);
        } catch (Throwable) {
        }
    }

    public static function deliver(int $id, array $actions = []): void
    {
        $notification = Notification::find($id);
        if (!$notification || $notification->status === 'sent') {
            return;
        }

        $channel = Channel::find($notification->channel_id);
        if (!$channel || $channel->status !== 'active') {
            $notification->save(['status' => 'failed', 'error' => 'channel unavailable']);
            return;
        }

        try {
            DriverFactory::make($channel->type)->send($channel, $notification->content, $actions);
            $notification->save(['status' => 'sent', 'sent_time' => now_utc(), 'error' => '']);
            if ($channel->last_error !== '') {
                $channel->save(['last_error' => '']);
            }
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 480);
            $notification->save(['status' => 'failed', 'error' => $error]);
            $channel->save(['last_error' => $error]);
            Log::warning("notification #{$id} failed: {$error}");
        }
    }

    /** 直接发送（测试消息、即时回复），不落库 */
    public static function sendNow(Channel $channel, string $content, array $actions = []): void
    {
        DriverFactory::make($channel->type)->send($channel, $content, $actions);
    }
}
