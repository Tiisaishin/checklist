<?php
namespace app\common\service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * 周期计算。所有计算在用户时区内进行，返回 UTC 时间。
 *
 * 规则字段：
 *  freq        daily / weekly / monthly / yearly
 *  interval    每 N 个周期
 *  by_weekday  weekly 时的星期（1=周一 … 7=周日），逗号分隔；为空则取开始日的星期
 *  by_monthday monthly/yearly 时的日期；0=取开始日，-1=月末
 *  by_month    yearly 时的月份；0=取开始月
 *  time_of_day HH:MM
 *  start_date  Y-m-d，周期锚点
 */
class Recurrence
{
    private DateTimeZone $tz;
    private string $freq;
    private int $interval;
    private array $weekdays;
    private int $monthday;
    private int $month;
    private int $hour;
    private int $minute;
    private DateTimeImmutable $start;

    public function __construct(array $rule, string $timezone)
    {
        $this->tz       = TimeHelper::tz($timezone);
        $this->freq     = in_array($rule['freq'] ?? '', ['daily', 'weekly', 'monthly', 'yearly'], true) ? $rule['freq'] : 'daily';
        $this->interval = max(1, (int) ($rule['interval'] ?? 1));

        [$h, $m]      = array_pad(explode(':', (string) ($rule['time_of_day'] ?? '09:00')), 2, 0);
        $this->hour   = min(23, max(0, (int) $h));
        $this->minute = min(59, max(0, (int) $m));

        $startDate   = !empty($rule['start_date']) ? substr((string) $rule['start_date'], 0, 10) : (new DateTimeImmutable('now', $this->tz))->format('Y-m-d');
        $this->start = (new DateTimeImmutable($startDate, $this->tz))->setTime($this->hour, $this->minute);

        $weekdays = array_filter(array_map('intval', explode(',', (string) ($rule['by_weekday'] ?? ''))), fn ($d) => $d >= 1 && $d <= 7);
        sort($weekdays);
        $this->weekdays = $weekdays ?: [(int) $this->start->format('N')];

        $this->monthday = (int) ($rule['by_monthday'] ?? 0) ?: (int) $this->start->format('j');
        $this->month    = (int) ($rule['by_month'] ?? 0) ?: (int) $this->start->format('n');
    }

    /**
     * 固定日程：返回严格晚于 $after 的第一个发生时间（UTC）
     */
    public function nextAfter(DateTimeImmutable $after): DateTimeImmutable
    {
        $after = $after->setTimezone($this->tz);
        $next  = match ($this->freq) {
            'daily'   => $this->nextDaily($after),
            'weekly'  => $this->nextWeekly($after),
            'monthly' => $this->nextMonthly($after, 1),
            'yearly'  => $this->nextMonthly($after, 12),
        };
        return $next->setTimezone(TimeHelper::utc());
    }

    /**
     * 完成后顺延：完成时间所在日 + 一个周期
     */
    public function afterDone(DateTimeImmutable $doneAt): DateTimeImmutable
    {
        $local = $doneAt->setTimezone($this->tz)->setTime($this->hour, $this->minute);
        $spec  = match ($this->freq) {
            'daily'   => "P{$this->interval}D",
            'weekly'  => 'P' . ($this->interval * 7) . 'D',
            'monthly' => "P{$this->interval}M",
            'yearly'  => "P{$this->interval}Y",
        };
        return $local->add(new DateInterval($spec))->setTimezone(TimeHelper::utc());
    }

    private function nextDaily(DateTimeImmutable $after): DateTimeImmutable
    {
        if ($after < $this->start) {
            return $this->start;
        }
        $days = TimeHelper::daysBetween($this->start, $after);
        $k    = intdiv($days, $this->interval);
        for ($i = max(0, $k - 1); ; $i++) {
            $candidate = $this->start->modify('+' . ($i * $this->interval) . ' days')->setTime($this->hour, $this->minute);
            if ($candidate > $after) {
                return $candidate;
            }
        }
    }

    private function nextWeekly(DateTimeImmutable $after): DateTimeImmutable
    {
        // 锚点周的周一
        $weekStart = $this->start->modify('-' . ((int) $this->start->format('N') - 1) . ' days');
        $from      = $after < $this->start ? $this->start : $after;
        $weeks     = intdiv(max(0, TimeHelper::daysBetween($weekStart, $from)), 7);
        $k         = intdiv($weeks, $this->interval);

        for ($i = max(0, $k - 1); ; $i++) {
            $monday = $weekStart->modify('+' . ($i * $this->interval * 7) . ' days');
            foreach ($this->weekdays as $wd) {
                $candidate = $monday->modify('+' . ($wd - 1) . ' days')->setTime($this->hour, $this->minute);
                if ($candidate >= $this->start && $candidate > $after) {
                    return $candidate;
                }
            }
        }
    }

    /** monthly（步长 1 个月）与 yearly（步长 12 个月） */
    private function nextMonthly(DateTimeImmutable $after, int $unitMonths): DateTimeImmutable
    {
        $step      = $this->interval * $unitMonths;
        $anchorY   = (int) $this->start->format('Y');
        $anchorM   = $unitMonths === 12 ? $this->month : (int) $this->start->format('n');
        $from      = $after < $this->start ? $this->start : $after;
        $diff      = ((int) $from->format('Y') - $anchorY) * 12 + ((int) $from->format('n') - $anchorM);
        $k         = intdiv(max(0, $diff), $step);

        for ($i = max(0, $k - 1); ; $i++) {
            $total     = ($anchorM - 1) + $i * $step;
            $y         = $anchorY + intdiv($total, 12);
            $m         = $total % 12 + 1;
            $lastDay   = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m), $this->tz))->format('t');
            $day       = $this->monthday === -1 ? $lastDay : min($this->monthday, $lastDay);
            $candidate = (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $day), $this->tz))->setTime($this->hour, $this->minute);
            if ($candidate >= $this->start->setTime(0, 0) && $candidate > $after) {
                return $candidate;
            }
        }
    }
}
