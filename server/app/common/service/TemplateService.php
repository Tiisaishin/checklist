<?php
namespace app\common\service;

use app\common\model\Asset;
use app\common\model\Group;
use app\common\model\Task;
use app\common\model\Template;
use app\common\model\User;
use DateTimeImmutable;

/**
 * 通知模板：用户自定义 > 语言默认模板。变量写法 {name}
 */
class TemplateService
{
    public const VARIABLES = [
        'asset_expire' => ['name', 'type', 'identifier', 'provider', 'expire_date', 'days_left', 'status_text', 'price', 'currency', 'url', 'notes', 'group'],
        'task_due'     => ['title', 'description', 'due_time', 'days_left', 'status_text', 'frequency', 'group', 'steps'],
    ];

    public static function defaultContent(string $scene, string $locale): string
    {
        return trans('tpl_' . $scene, [], $locale);
    }

    public static function content(User $user, string $scene): string
    {
        $custom = Template::where('user_id', $user->id)->where('scene', $scene)->value('content');
        return $custom ?: self::defaultContent($scene, $user->locale);
    }

    /** 渲染：变量值做 HTML 转义，模板本身允许 Telegram 支持的 HTML 标签 */
    public static function render(string $template, array $vars): string
    {
        $replace = [];
        foreach ($vars as $k => $v) {
            $replace['{' . $k . '}'] = htmlspecialchars((string) $v, ENT_NOQUOTES);
        }

        $lines = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $template)) as $line) {
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
            $daysLeft > 0  => trans('status_days_left', ['days' => $daysLeft], $locale),
            $daysLeft == 0 => trans('status_today', [], $locale),
            default        => trans('status_overdue', ['days' => -$daysLeft], $locale),
        };
    }

    public static function assetVars(Asset $asset, User $user, DateTimeImmutable $now): array
    {
        $tz      = TimeHelper::tz($user->timezone);
        $expire  = TimeHelper::fromDb($asset->expire_time)?->setTimezone($tz);
        $days    = $expire ? TimeHelper::daysBetween($now->setTimezone($tz), $expire) : 0;
        $locale  = $user->locale;

        return [
            'name'        => $asset->name,
            'type'        => trans('asset_type_' . $asset->type, [], $locale),
            'identifier'  => $asset->identifier,
            'provider'    => $asset->provider,
            'expire_date' => $expire ? $expire->format('Y-m-d') : '-',
            'days_left'   => $days,
            'status_text' => self::statusText($days, $locale),
            'price'       => $asset->price > 0 ? rtrim(rtrim(number_format((float) $asset->price, 2, '.', ''), '0'), '.') . ' ' . $asset->currency : '',
            'currency'    => $asset->currency,
            'url'         => $asset->url,
            'notes'       => (string) $asset->notes,
            'group'       => $asset->group_id ? (string) Group::where('id', $asset->group_id)->value('name') : '',
        ];
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
            'days_left'   => $days,
            'status_text' => self::statusText($days, $locale),
            'frequency'   => self::frequencyText($task, $locale),
            'group'       => $task->group_id ? (string) Group::where('id', $task->group_id)->value('name') : '',
            'steps'       => implode("\n", $steps),
        ];
    }

    public static function frequencyText(Task $task, string $locale): string
    {
        $n = max(1, (int) $task->interval);
        return trans('freq_' . $task->freq . ($n > 1 ? '_n' : ''), ['n' => $n], $locale);
    }

    /** 预览示例数据 */
    public static function sampleVars(string $scene, string $locale): array
    {
        return $scene === 'asset_expire'
            ? [
                'name' => 'example.com', 'type' => trans('asset_type_domain', [], $locale), 'identifier' => 'example.com',
                'provider' => 'Cloudflare', 'expire_date' => date('Y-m-d', strtotime('+7 days')), 'days_left' => 7,
                'status_text' => self::statusText(7, $locale), 'price' => '10.44 USD', 'currency' => 'USD',
                'url' => 'https://dash.cloudflare.com', 'notes' => '', 'group' => 'Blog',
            ]
            : [
                'title' => trans('sample_task_title', [], $locale), 'description' => '', 'due_time' => date('Y-m-d') . ' 09:00',
                'days_left' => 0, 'status_text' => self::statusText(0, $locale), 'frequency' => trans('freq_weekly', [], $locale),
                'group' => 'Blog', 'steps' => "▫️ df -h\n▫️ backup log",
            ];
    }
}
