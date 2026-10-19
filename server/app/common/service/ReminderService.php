<?php
namespace app\common\service;

use app\common\model\Asset;
use app\common\model\Channel;
use app\common\model\Notification;
use app\common\model\Task;
use app\common\model\User;
use DateTimeImmutable;

/**
 * 提醒引擎：由调度器每分钟调用一次。
 *
 * 资产：在接收人时区的每日提醒时间（users.notify_time）之后，
 *       剩余天数命中提前量，或处于提醒窗口内但本周期还没提醒过，或已过期（remind_overdue）时发送，每天最多一次。
 * 检查项：在检查项自身的时间点之后，按相同规则发送。
 *
 * 接收人：资产/检查项所属租户内的有效渠道（可通过 remind_channels 指定子集），模板、语言、时区取渠道所属用户。
 */
class ReminderService
{
    /** @var array<int, User> */
    private array $users = [];
    /** @var array<int, Channel[]> */
    private array $channelsByTenant = [];

    public function run(?DateTimeImmutable $now = null): int
    {
        $now  = $now ?: new DateTimeImmutable('now', TimeHelper::utc());
        $sent = 0;

        $this->loadChannels();
        if (!$this->channelsByTenant) {
            return 0;
        }
        $tenantIds = array_keys($this->channelsByTenant);

        // 资产：最长提前 365 天
        Asset::where('status', 'active')
            ->whereIn('tenant_id', $tenantIds)
            ->whereNotNull('expire_time')
            ->where('expire_time', '<=', TimeHelper::toDb($now->modify('+366 days')))
            ->chunk(200, function ($assets) use ($now, &$sent) {
                foreach ($assets as $asset) {
                    $sent += $this->remindAsset($asset, $now);
                }
            });

        Task::where('status', 'active')
            ->whereIn('tenant_id', $tenantIds)
            ->whereNotNull('next_due_time')
            ->where('next_due_time', '<=', TimeHelper::toDb($now->modify('+366 days')))
            ->chunk(200, function ($tasks) use ($now, &$sent) {
                foreach ($tasks as $task) {
                    $sent += $this->remindTask($task, $now);
                }
            });

        return $sent;
    }

    private function loadChannels(): void
    {
        $this->channelsByTenant = [];
        $channels = Channel::where('status', 'active')->select();
        $userIds  = array_unique($channels->column('user_id'));
        if ($userIds) {
            foreach (User::whereIn('id', $userIds)->where('status', 1)->select() as $user) {
                $this->users[$user->id] = $user;
            }
        }
        foreach ($channels as $channel) {
            if (isset($this->users[$channel->user_id])) {
                $this->channelsByTenant[$channel->tenant_id][] = $channel;
            }
        }
    }

    /** @return Channel[] */
    private function recipients(int $tenantId, ?array $only): array
    {
        $list = $this->channelsByTenant[$tenantId] ?? [];
        if ($only) {
            $only = array_map('intval', $only);
            $list = array_filter($list, fn ($c) => in_array((int) $c->id, $only, true));
        }
        return $list;
    }

    private function remindAsset(Asset $asset, DateTimeImmutable $now): int
    {
        $expire = TimeHelper::fromDb($asset->expire_time);
        if (!$expire) {
            return 0;
        }
        if ($asset->ack_expire_time && $asset->ack_expire_time === $asset->expire_time) {
            return 0; // 本周期已确认处理
        }
        if ($asset->snooze_until && TimeHelper::fromDb($asset->snooze_until) > $now) {
            return 0;
        }

        $offsets = $asset->offsets();
        $sent    = 0;
        foreach ($this->recipients($asset->tenant_id, $asset->remind_channels) as $channel) {
            $user  = $this->users[$channel->user_id];
            $tz    = TimeHelper::tz($user->timezone);
            $local = $now->setTimezone($tz);

            if ($local->format('H:i') < ($user->notify_time ?: '09:00')) {
                continue;
            }

            $days   = TimeHelper::daysBetween($local, $expire->setTimezone($tz));
            $cycle  = 'a:' . $asset->id . ':' . $expire->format('YmdHi') . ':' . $channel->id;
            if (!$this->shouldRemind($days, $offsets, (bool) $asset->remind_overdue, $cycle)) {
                continue;
            }

            $content = TemplateService::render(
                TemplateService::content($user, 'asset_expire'),
                TemplateService::assetVars($asset, $user, $now)
            );
            $actions = [
                ['text' => trans('btn_renewed', [], $user->locale), 'data' => 'a:ack:' . $asset->id],
                ['text' => trans('btn_tomorrow', [], $user->locale), 'data' => 'a:snz:' . $asset->id . ':1d'],
            ];
            if ($asset->auto_detect && in_array($asset->type, Asset::DETECTABLE, true)) {
                $actions[] = ['text' => trans('btn_detect', [], $user->locale), 'data' => 'a:det:' . $asset->id];
            }

            if (NotificationService::dispatch($channel, 'asset', $asset->id, $cycle . ':' . $local->format('Ymd'), $content, $actions)) {
                $sent++;
            }
        }
        return $sent;
    }

    private function remindTask(Task $task, DateTimeImmutable $now): int
    {
        $due = TimeHelper::fromDb($task->next_due_time);
        if (!$due) {
            return 0;
        }
        if ($task->snooze_until && TimeHelper::fromDb($task->snooze_until) > $now) {
            return 0;
        }

        $offsets = $task->offsets() ?: [0];
        $sent    = 0;
        foreach ($this->recipients($task->tenant_id, $task->remind_channels) as $channel) {
            $user  = $this->users[$channel->user_id];
            $tz    = TimeHelper::tz($user->timezone);
            $local = $now->setTimezone($tz);
            $dueLocal = $due->setTimezone($tz);

            // 每天都在检查项设定的时间点之后才提醒
            if ($local->format('H:i') < $dueLocal->format('H:i')) {
                continue;
            }

            $days  = TimeHelper::daysBetween($local, $dueLocal);
            $cycle = 't:' . $task->id . ':' . $due->format('YmdHi') . ':' . $channel->id;
            if (!$this->shouldRemind($days, $offsets, (bool) $task->remind_overdue, $cycle)) {
                continue;
            }

            $content = TemplateService::render(
                TemplateService::content($user, 'task_due'),
                TemplateService::taskVars($task, $user, $now)
            );
            $actions = [
                ['text' => trans('btn_done', [], $user->locale), 'data' => 't:done:' . $task->id],
                ['text' => trans('btn_1h', [], $user->locale), 'data' => 't:snz:' . $task->id . ':1h'],
                ['text' => trans('btn_tomorrow', [], $user->locale), 'data' => 't:snz:' . $task->id . ':1d'],
            ];

            if (NotificationService::dispatch($channel, 'task', $task->id, $cycle . ':' . $local->format('Ymd'), $content, $actions)) {
                $sent++;
            }
        }
        return $sent;
    }

    /**
     * @param int    $days     距到期的自然日（负数为已过期）
     * @param int[]  $offsets  提前提醒天数（降序）
     * @param string $cycle    本周期的通知键前缀
     */
    private function shouldRemind(int $days, array $offsets, bool $overdue, string $cycle): bool
    {
        if ($days < 0) {
            return $overdue;
        }
        if (in_array($days, $offsets, true)) {
            return true;
        }
        // 新建时已经错过了较早的提醒点：窗口内首次补发一次
        $max = $offsets ? max($offsets) : 0;
        if ($days <= $max) {
            return !Notification::where('dedupe_key', 'like', $cycle . ':%')->count();
        }
        return false;
    }
}
