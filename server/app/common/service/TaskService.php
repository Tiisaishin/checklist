<?php
namespace app\common\service;

use app\common\model\Task;
use app\common\model\TaskRun;
use app\common\model\User;
use DateTimeImmutable;

class TaskService
{
    public static function recurrence(Task $task, string $timezone): Recurrence
    {
        return new Recurrence([
            'freq'        => $task->freq,
            'interval'    => $task->interval,
            'by_weekday'  => $task->by_weekday,
            'by_monthday' => $task->by_monthday,
            'by_month'    => $task->by_month,
            'time_of_day' => $task->time_of_day,
            'start_date'  => $task->start_date,
        ], $timezone);
    }

    /** 新建或修改规则后重新计算下一次到期 */
    public static function computeInitialDue(Task $task, string $timezone): string
    {
        $now = new DateTimeImmutable('now', TimeHelper::utc());
        // 允许当天稍早的时间点：从“今天 0 点”开始找第一个发生时间
        $after = $now->setTimezone(TimeHelper::tz($timezone))->setTime(0, 0)->modify('-1 second');
        return TimeHelper::toDb(self::recurrence($task, $timezone)->nextAfter($after));
    }

    /**
     * 标记完成并推进到下一周期
     */
    public static function complete(Task $task, User $user, string $note = '', string $source = 'web'): Task
    {
        $now = new DateTimeImmutable('now', TimeHelper::utc());
        $due = TimeHelper::fromDb($task->next_due_time);
        $tz  = self::ownerTimezone($task, $user);

        TaskRun::create([
            'tenant_id' => $task->tenant_id,
            'task_id'   => $task->id,
            'user_id'   => $user->id,
            'due_time'  => $task->next_due_time,
            'done_time' => TimeHelper::toDb($now),
            'note'      => $note,
            'source'    => $source,
        ]);

        $rule = self::recurrence($task, $tz);
        if ($task->mode === 'after_done') {
            $next = $rule->afterDone($now);
        } else {
            // 提前完成：跳到本次之后；逾期完成：跳到现在之后
            $next = $rule->nextAfter($due && $due > $now ? $due : $now);
        }

        $task->save([
            'last_done_time' => TimeHelper::toDb($now),
            'next_due_time'  => TimeHelper::toDb($next),
            'snooze_until'   => null,
        ]);

        return $task;
    }

    /** 周期按创建者时区计算 */
    public static function ownerTimezone(Task $task, ?User $fallback = null): string
    {
        $tz = User::where('id', $task->user_id)->value('timezone');
        return $tz ?: ($fallback?->timezone ?: (string) config('app.default_user_timezone'));
    }
}
