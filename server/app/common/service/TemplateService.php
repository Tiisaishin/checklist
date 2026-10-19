<?php
namespace app\common\service;

use app\common\detector\DomainDetector;
use app\common\model\Asset;
use app\common\model\Group;
use app\common\model\Task;
use app\common\model\Template;
use app\common\model\User;
use DateTimeImmutable;

/**
 * 通知模板：用户自定义 > 语言默认模板。
 *
 * 语法：
 *  {name}                 变量（值会做 HTML 转义）
 *  {?var}……{/var}         var 非空时才显示中间内容
 *  {!var}……{/var}         var 为空时才显示中间内容
 *  一行里的变量全部为空时，整行自动省略
 * 模板本身可以使用 Telegram 支持的 HTML：<b> <i> <u> <s> <code> <pre> <a href="">
 */
class TemplateService
{
    /** 场景分组（用于界面展示） */
    public const GROUPS = [
        'domain'  => ['domain_expire', 'domain_renewed', 'ns_changed', 'domain_status'],
        'ssl'     => ['ssl_expire', 'ssl_renewed', 'ssl_error', 'ssl_renew_stuck'],
        'general' => ['asset_expire', 'task_due', 'digest', 'detect_failed'],
    ];

    /** 监控事件场景（预览时取真实事件数据） */
    public const EVENT_SCENES = ['domain_renewed', 'ns_changed', 'domain_status', 'ssl_renewed', 'ssl_error', 'ssl_renew_stuck', 'detect_failed'];

    /** 有「简洁」预设的场景 */
    private const SIMPLE_PRESETS = ['domain_expire', 'ssl_expire', 'asset_expire', 'task_due', 'digest'];

    public const VARIABLES = [
        'domain_expire'   => ['name', 'expire_date', 'weekday', 'days_left', 'status_text', 'level_icon', 'level_text', 'phase_text', 'provider', 'nameservers', 'price', 'url', 'group', 'notes'],
        'ssl_expire'      => ['name', 'host', 'port', 'expire_date', 'weekday', 'days_left', 'status_text', 'level_icon', 'level_text', 'issuer', 'san', 'issue_text', 'renew_tip', 'group', 'notes'],
        'asset_expire'    => ['name', 'type', 'identifier', 'provider', 'expire_date', 'weekday', 'days_left', 'status_text', 'level_icon', 'level_text', 'price', 'url', 'notes', 'group'],
        'task_due'        => ['title', 'description', 'due_time', 'weekday', 'days_left', 'status_text', 'level_icon', 'frequency', 'group', 'steps'],
        'digest'          => ['count', 'overdue_count', 'date', 'items'],
        'domain_renewed'  => ['name', 'old_expire', 'new_expire', 'provider', 'group'],
        'ns_changed'      => ['name', 'old_ns', 'new_ns', 'group'],
        'domain_status'   => ['name', 'statuses', 'phase_text', 'expire_date', 'group'],
        'ssl_renewed'     => ['name', 'old_expire', 'new_expire', 'issuer', 'group'],
        'ssl_error'       => ['name', 'issue_text', 'error', 'expire_date', 'group'],
        'ssl_renew_stuck' => ['name', 'expire_date', 'days_left', 'issuer', 'group'],
        'detect_failed'   => ['name', 'type', 'error', 'fail_count', 'group'],
    ];

    public static function scenes(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public static function sceneForAsset(Asset $asset): string
    {
        return match ($asset->type) {
            'domain' => 'domain_expire',
            'ssl'    => 'ssl_expire',
            default  => 'asset_expire',
        };
    }

    public static function defaultContent(string $scene, string $locale): string
    {
        return trans('tpl_' . $scene, [], $locale);
    }

    /** 预设：详细（默认）/ 简洁 / 英文 */
    public static function presets(string $scene, string $locale): array
    {
        $list = ['detailed' => self::defaultContent($scene, $locale)];
        if (in_array($scene, self::SIMPLE_PRESETS, true)) {
            $list['simple'] = trans('tpl_' . $scene . '_simple', [], $locale);
        }
        if ($locale !== 'en') {
            $list['en'] = self::defaultContent($scene, 'en');
        }
        return $list;
    }

    public static function content(User $user, string $scene): string
    {
        static $cache = [];
        $key = $user->id . ':' . $scene;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = Template::where('user_id', $user->id)->where('scene', $scene)->value('content');
        }
        return $cache[$key] ?: self::defaultContent($scene, $user->locale);
    }

    public static function render(string $template, array $vars): string
    {
        $template = str_replace("\r\n", "\n", $template);

        // 条件块
        $template = preg_replace_callback('/\{([?!])(\w+)\}(.*?)\{\/\2\}/s', function ($m) use ($vars) {
            $filled = isset($vars[$m[2]]) && (string) $vars[$m[2]] !== '';
            return ($m[1] === '?') === $filled ? $m[3] : '';
        }, $template);

        $replace = [];
        foreach ($vars as $k => $v) {
            $replace['{' . $k . '}'] = htmlspecialchars((string) $v, ENT_NOQUOTES);
        }

        $lines = [];
        foreach (explode("\n", $template) as $line) {
            // 一行中的变量全部为空时（如未填写服务商），整行省略
            if (preg_match_all('/\{(\w+)\}/', $line, $m) && $m[1]) {
                $filled = array_filter($m[1], fn ($k) => isset($vars[$k]) && (string) $vars[$k] !== '');
                if (!$filled && array_intersect($m[1], array_keys($vars))) {
                    continue;
                }
            }
            $lines[] = strtr($line, $replace);
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    public static function statusText(int $daysLeft, string $locale): string
    {
        return match (true) {
            $daysLeft === 1  => trans('status_days_left_one', [], $locale),
            $daysLeft > 0    => trans('status_days_left', ['days' => $daysLeft], $locale),
            $daysLeft === 0  => trans('status_today', [], $locale),
            $daysLeft === -1 => trans('status_overdue_one', [], $locale),
            default          => trans('status_overdue', ['days' => -$daysLeft], $locale),
        };
    }

    /**
     * 紧急程度：[图标, 级别]
     * 赎回期/待删除 ⚫ critical；已过期 🔴 expired；今天 🔴 today；1–3 天 🟠 urgent；4–14 天 🟡 soon；更早 🟢 notice
     */
    public static function level(int $days, string $phase = 'active'): array
    {
        return match (true) {
            in_array($phase, ['redemption', 'pending_delete'], true) => ['⚫', 'critical'],
            $days < 0   => ['🔴', 'expired'],
            $days === 0 => ['🔴', 'today'],
            $days <= 3  => ['🟠', 'urgent'],
            $days <= 14 => ['🟡', 'soon'],
            default     => ['🟢', 'notice'],
        };
    }

    public static function assetVars(Asset $asset, User $user, DateTimeImmutable $now): array
    {
        $tz     = TimeHelper::tz($user->timezone);
        $locale = $user->locale;
        $expire = TimeHelper::fromDb($asset->expire_time)?->setTimezone($tz);
        $days   = $expire ? TimeHelper::daysBetween($now->setTimezone($tz), $expire) : 0;
        $meta   = (array) $asset->detect_meta;
        $phase  = $asset->type === 'domain' ? DomainDetector::phase($days, (array) ($meta['status'] ?? [])) : 'active';
        [$icon, $level] = self::level($days, $phase);

        $vars = [
            'name'        => $asset->name,
            'type'        => trans('asset_type_' . $asset->type, [], $locale),
            'identifier'  => $asset->identifier,
            'provider'    => $asset->provider,
            'expire_date' => $expire ? $expire->format('Y-m-d') : '-',
            'weekday'     => $expire ? trans('weekday_' . $expire->format('N'), [], $locale) : '',
            'days_left'   => $days,
            'status_text' => self::statusText($days, $locale),
            'level_icon'  => $icon,
            'level_text'  => trans('level_' . $level, [], $locale),
            'price'       => $asset->price > 0 ? rtrim(rtrim(number_format((float) $asset->price, 2, '.', ''), '0'), '.') . ' ' . $asset->currency : '',
            'currency'    => $asset->currency,
            'url'         => $asset->url,
            'notes'       => (string) $asset->notes,
            'group'       => self::groupName((int) $asset->group_id),
        ];

        if ($asset->type === 'domain') {
            $vars['phase_text']  = $phase !== 'active' ? trans('phase_' . $phase, [], $locale) : '';
            $vars['nameservers'] = implode(', ', array_slice((array) ($meta['nameservers'] ?? []), 0, 4));
        }
        if ($asset->type === 'ssl') {
            $vars['host']       = (string) ($meta['host'] ?? $asset->identifier);
            $vars['port']       = (string) ($meta['port'] ?? 443);
            $vars['issuer']     = trim(($asset->provider ?: '') . (!empty($meta['issuer_cn']) ? ' (' . $meta['issuer_cn'] . ')' : ''));
            $san                = (array) ($meta['san'] ?? []);
            $vars['san']        = implode(', ', array_slice($san, 0, 5)) . (count($san) > 5 ? ' …+' . (count($san) - 5) : '');
            $vars['issue_text'] = !empty($meta['issue']) ? trans('issue_' . $meta['issue'], [], $locale) : '';
            $vars['renew_tip']  = !empty($meta['short_lived']) && $days >= 0 && $days < 20 ? trans('ssl_renew_tip', [], $locale) : '';
        }
        return $vars;
    }

    public static function taskVars(Task $task, User $user, DateTimeImmutable $now): array
    {
        $tz     = TimeHelper::tz($user->timezone);
        $due    = TimeHelper::fromDb($task->next_due_time)?->setTimezone($tz);
        $days   = $due ? TimeHelper::daysBetween($now->setTimezone($tz), $due) : 0;
        $locale = $user->locale;
        $steps  = array_map(fn ($s) => '▫️ ' . (is_array($s) ? ($s['title'] ?? '') : $s), (array) $task->steps);

        return [
            'title'       => $task->title,
            'description' => (string) $task->description,
            'due_time'    => $due ? $due->format('Y-m-d H:i') : '-',
            'weekday'     => $due ? trans('weekday_' . $due->format('N'), [], $locale) : '',
            'days_left'   => $days,
            'status_text' => self::statusText($days, $locale),
            'level_icon'  => self::level($days)[0],
            'frequency'   => self::frequencyText($task, $locale),
            'group'       => self::groupName((int) $task->group_id),
            'steps'       => implode("\n", $steps),
        ];
    }

    public static function eventVars(Asset $asset, User $user, string $type, array $data): array
    {
        $tz     = TimeHelper::tz($user->timezone);
        $locale = $user->locale;
        $date   = fn (?string $v) => $v ? (TimeHelper::fromDb($v)?->setTimezone($tz)->format('Y-m-d') ?? '-') : '-';
        $expire = TimeHelper::fromDb($asset->expire_time);
        $days   = $expire ? TimeHelper::daysBetween(new DateTimeImmutable('now', $tz), $expire->setTimezone($tz)) : 0;
        $meta   = (array) $asset->detect_meta;

        return [
            'name'        => $asset->name,
            'type'        => trans('asset_type_' . $asset->type, [], $locale),
            'provider'    => $asset->provider,
            'group'       => self::groupName((int) $asset->group_id),
            'expire_date' => $date($asset->expire_time),
            'days_left'   => $days,
            'old_expire'  => $date($data['old_expire'] ?? null),
            'new_expire'  => $date($data['new_expire'] ?? null),
            'old_ns'      => implode(', ', (array) ($data['old'] ?? [])),
            'new_ns'      => implode(', ', (array) ($data['new'] ?? [])),
            'statuses'    => implode('、', array_map(fn ($st) => trans('domain_status_' . $st, [], $locale), (array) ($data['statuses'] ?? []))),
            'phase_text'  => trans('phase_' . ($data['phase'] ?? 'active'), [], $locale),
            'issuer'      => (string) ($data['issuer'] ?? $asset->provider),
            'issue_text'  => trans('issue_' . ($data['issue'] ?? 'unknown'), [], $locale),
            'error'       => mb_substr((string) ($data['error'] ?? ($meta['verify_error'] ?? '')), 0, 200),
            'fail_count'  => (int) ($data['fail_count'] ?? 0),
        ];
    }

    /**
     * 每日汇总
     * @param array $entries [['kind' => 'asset'|'task', 'item' => Asset|Task], ...]
     */
    public static function digestVars(User $user, array $entries, DateTimeImmutable $now): array
    {
        $locale = $user->locale;
        $rows   = [];
        foreach ($entries as $e) {
            if ($e['kind'] === 'asset') {
                $v      = self::assetVars($e['item'], $user, $now);
                $rows[] = [$v['days_left'], "{$v['level_icon']} {$v['name']} — {$v['status_text']}（{$v['expire_date']}）"];
            } else {
                $v      = self::taskVars($e['item'], $user, $now);
                $rows[] = [$v['days_left'], "{$v['level_icon']} ☑️ {$v['title']} — {$v['due_time']}"];
            }
        }
        usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);

        $max   = 20;
        $lines = array_map(fn ($r) => $r[1], array_slice($rows, 0, $max));
        if (count($rows) > $max) {
            $lines[] = trans('digest_more', ['n' => count($rows) - $max], $locale);
        }
        $local = $now->setTimezone(TimeHelper::tz($user->timezone));

        return [
            'count'         => count($rows),
            'overdue_count' => count(array_filter($rows, fn ($r) => $r[0] < 0)) ?: '',
            'date'          => $local->format('Y-m-d') . ' ' . trans('weekday_' . $local->format('N'), [], $locale),
            'items'         => implode("\n", $lines),
        ];
    }

    public static function frequencyText(Task $task, string $locale): string
    {
        $n = max(1, (int) $task->interval);
        return trans('freq_' . $task->freq . ($n > 1 ? '_n' : ''), ['n' => $n], $locale);
    }

    private static function groupName(int $groupId): string
    {
        static $cache = [];
        if (!$groupId) {
            return '';
        }
        return $cache[$groupId] ??= (string) Group::where('id', $groupId)->value('name');
    }

    /** 预览示例数据（与真实渲染走同一套变量逻辑） */
    public static function sampleVars(string $scene, User $user): array
    {
        $now = new DateTimeImmutable('now', TimeHelper::utc());

        $domain = new Asset([
            'type' => 'domain', 'name' => 'example.com', 'identifier' => 'example.com', 'provider' => 'Namecheap',
            'expire_time' => TimeHelper::toDb($now->modify('+7 days')), 'price' => 10.44, 'currency' => 'USD',
            'url' => 'https://ap.www.namecheap.com/domains/list', 'notes' => '', 'group_id' => 0,
            'detect_meta' => ['nameservers' => ['ns1.cloudflare.com', 'ns2.cloudflare.com'], 'status' => []],
        ]);
        $ssl = new Asset([
            'type' => 'ssl', 'name' => 'www.example.com', 'identifier' => 'www.example.com', 'provider' => "Let's Encrypt",
            'expire_time' => TimeHelper::toDb($now->modify('+3 days')), 'group_id' => 0, 'notes' => '',
            'detect_meta' => ['host' => 'www.example.com', 'port' => 443, 'issuer_cn' => 'R11', 'san' => ['example.com', 'www.example.com'], 'short_lived' => true, 'issue' => ''],
        ]);
        $task = new Task([
            'title' => trans('sample_task_title', [], $user->locale), 'description' => '', 'freq' => 'weekly', 'interval' => 1,
            'next_due_time' => TimeHelper::toDb($now), 'group_id' => 0, 'steps' => ['df -h', 'backup log'],
        ]);

        return match ($scene) {
            'domain_expire' => self::assetVars($domain, $user, $now),
            'ssl_expire'    => self::assetVars($ssl, $user, $now),
            'asset_expire'  => self::assetVars($domain, $user, $now),
            'task_due'      => self::taskVars($task, $user, $now),
            'digest'        => self::digestVars($user, [['kind' => 'asset', 'item' => $domain], ['kind' => 'asset', 'item' => $ssl], ['kind' => 'task', 'item' => $task]], $now),
            default         => self::eventVars(str_starts_with($scene, 'ssl') ? $ssl : $domain, $user, $scene, [
                'old_expire' => TimeHelper::toDb($now->modify('-358 days')), 'new_expire' => TimeHelper::toDb($now->modify('+372 days')),
                'old' => ['dns1.registrar-servers.com'], 'new' => ['ns1.cloudflare.com'], 'statuses' => ['redemptionperiod'],
                'phase' => 'redemption', 'issue' => 'mismatch', 'error' => 'peer certificate CN did not match', 'fail_count' => 3, 'issuer' => 'R11',
            ]),
        };
    }
}
