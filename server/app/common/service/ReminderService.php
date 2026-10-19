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
 * 接收人：见 ChannelRouter（条目 > 分组 > 默认分组 > 全部渠道），模板、语言、时区取渠道所属用户。
 * 发送方式：按用户设置逐条发送或合并为每日汇总（见 flush）。
 */
class ReminderService
{
    /** @var array<int, User> */
    private array $users = [];
    /** @var array<int, Channel[]> */
    private array $channelsByTenant = [];
    /** @var array<int, Channel> */
    private array $channels = [];
    private ChannelRouter $router;

    private const SMART_THRESHOLD = 3;

    /**
     * 本轮待发送：channel_id => [entry, ...]
     * entry = ['kind' => 'asset'|'task', 'item' => Asset|Task, 'key' => 去重键, 'content' => 单条内容, 'actions' => 按钮]
     */
    private array $pending = [];

    public function run(?DateTimeImmutable $now = null): int
    {
        $now           = $now ?: new DateTimeImmutable('now', TimeHelper::utc());
        $this->router  = new ChannelRouter();
        $this->pending = [];

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
            ->chunk(200, function ($assets) use ($now) {
                foreach ($assets as $asset) {
                    $this->collectAsset($asset, $now);
                }
            });

        Task::where('status', 'active')
            ->whereIn('tenant_id', $tenantIds)
            ->whereNotNull('next_due_time')
            ->where('next_due_time', '<=', TimeHelper::toDb($now->modify('+366 days')))
            ->chunk(200, function ($tasks) use ($now) {
                foreach ($tasks as $task) {
                    $this->collectTask($task, $now);
                }
            });

        return $this->flush($now);
    }

    /**
     * 按用户的提醒方式发送：
     *  single 逐条；digest 合并为一条；smart（默认）本轮不超过 3 条逐条，否则合并
     */
    private function flush(DateTimeImmutable $now): int
    {
        $sent = 0;
        foreach ($this->pending as $channelId => $entries) {
            $channel = $this->channels[$channelId];
            $user    = $this->users[$channel->user_id];
            $mode    = in_array($user->notify_mode, ['single', 'digest', 'smart'], true) ? $user->notify_mode : 'smart';

            if ($mode === 'single' || ($mode === 'smart' && count($entries) <= self::SMART_THRESHOLD)) {
                foreach ($entries as $e) {
                    if (NotificationService::dispatch($channel, $e['kind'], (int) $e['item']->id, $e['key'], $e['content'], $e['actions'])) {
                        $sent++;
                    }
                }
                continue;
            }

            // 合并：每个条目只记录不发送，另发一条汇总
            foreach ($entries as $e) {
                NotificationService::mark($channel, $e['kind'], (int) $e['item']->id, $e['key'], $e['content']);
            }
            $content = TemplateService::render(TemplateService::content($user, 'digest'), TemplateService::digestVars($user, $entries, $now));
            $actions = [];
            $appUrl  = (string) config('app.app_url');
            if (preg_match('#^https?://#', $appUrl) && !preg_match('#//(localhost|127\.|0\.0\.0\.0)#', $appUrl)) {
                $actions[] = ['text' => trans('btn_open_app', [], $user->locale), 'url' => $appUrl . '/assets'];
            }
            $key = 'd:' . $channelId . ':' . $now->setTimezone(TimeHelper::tz($user->timezone))->format('YmdHi') . ':' . md5(implode('|', array_column($entries, 'key')));
            if (NotificationService::dispatch($channel, 'digest', 0, $key, $content, $actions)) {
                $sent++;
            }
        }
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
                $this->channels[(int) $channel->id]            = $channel;
            }
        }
    }

    /** @return Channel[] */
    private function recipients(Asset|Task $item): array
    {
        $tenantId = (int) $item->tenant_id;
        $list     = $this->channelsByTenant[$tenantId] ?? [];
        $only     = $this->router->resolve($tenantId, (int) $item->group_id, $item->remind_channels);
        if ($only !== null) {
            $list = array_filter($list, fn ($c) => in_array((int) $c->id, $only, true));
        }
        return $list;
    }

    private function collectAsset(Asset $asset, DateTimeImmutable $now): void
    {
        $expire = TimeHelper::fromDb($asset->expire_time);
        if (!$expire) {
            return;
        }
        if ($asset->ack_expire_time && $asset->ack_expire_time === $asset->expire_time) {
            return; // 本周期已确认处理
        }
        if ($asset->snooze_until && TimeHelper::fromDb($asset->snooze_until) > $now) {
            return;
        }

        $offsets = $asset->offsets();
        $scene   = TemplateService::sceneForAsset($asset);
        foreach ($this->recipients($asset) as $channel) {
            $user  = $this->users[$channel->user_id];
            $tz    = TimeHelper::tz($user->timezone);
            $local = $now->setTimezone($tz);

            if ($local->format('H:i') < ($user->notify_time ?: '09:00')) {
                continue;
            }

            $days  = TimeHelper::daysBetween($local, $expire->setTimezone($tz));
            $cycle = 'a:' . $asset->id . ':' . $expire->format('YmdHi') . ':' . $channel->id;
            if (!$this->shouldRemind($days, $offsets, (bool) $asset->remind_overdue, $cycle)) {
                continue;
            }

            $content = TemplateService::render(TemplateService::content($user, $scene), TemplateService::assetVars($asset, $user, $now));
            $actions = [];
            if ($asset->url && preg_match('#^https?://#i', $asset->url)) {
                $actions[] = ['text' => trans('btn_renew_url', [], $user->locale), 'url' => $asset->url];
            } elseif ($asset->type === 'ssl') {
                $actions[] = ['text' => trans('btn_open_site', [], $user->locale), 'url' => 'https://' . $asset->identifier];
            }
            $actions[] = ['text' => trans('btn_renewed', [], $user->locale), 'data' => 'a:ack:' . $asset->id];
            $actions[] = ['text' => trans('btn_tomorrow', [], $user->locale), 'data' => 'a:snz:' . $asset->id . ':1d'];
            if ($asset->auto_detect && in_array($asset->type, Asset::DETECTABLE, true)) {
                $actions[] = ['text' => trans('btn_detect', [], $user->locale), 'data' => 'a:det:' . $asset->id];
            }

            $this->pending[(int) $channel->id][] = [
                'kind' => 'asset', 'item' => $asset, 'key' => $cycle . ':' . $local->format('Ymd'), 'content' => $content, 'actions' => $actions,
            ];
        }
    }

    private function collectTask(Task $task, DateTimeImmutable $now): void
    {
        $due = TimeHelper::fromDb($task->next_due_time);
        if (!$due) {
            return;
        }
        if ($task->snooze_until && TimeHelper::fromDb($task->snooze_until) > $now) {
            return;
        }

        $offsets = $task->offsets() ?: [0];
        foreach ($this->recipients($task) as $channel) {
            $user     = $this->users[$channel->user_id];
            $tz       = TimeHelper::tz($user->timezone);
            $local    = $now->setTimezone($tz);
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

            $content = TemplateService::render(TemplateService::content($user, 'task_due'), TemplateService::taskVars($task, $user, $now));
            $actions = [
                ['text' => trans('btn_done', [], $user->locale), 'data' => 't:done:' . $task->id],
                ['text' => trans('btn_1h', [], $user->locale), 'data' => 't:snz:' . $task->id . ':1h'],
                ['text' => trans('btn_tomorrow', [], $user->locale), 'data' => 't:snz:' . $task->id . ':1d'],
            ];

            $this->pending[(int) $channel->id][] = [
                'kind' => 'task', 'item' => $task, 'key' => $cycle . ':' . $local->format('Ymd'), 'content' => $content, 'actions' => $actions,
            ];
        }
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
