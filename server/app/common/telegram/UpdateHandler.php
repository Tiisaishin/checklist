<?php
namespace app\common\telegram;

use app\common\model\Asset;
use app\common\model\Channel;
use app\common\model\Task;
use app\common\model\User;
use app\common\service\AssetService;
use app\common\service\ChannelRouter;
use app\common\service\DetectService;
use app\common\service\TaskService;
use app\common\service\TemplateService;
use app\common\service\TimeHelper;
use DateTimeImmutable;
use Throwable;

/**
 * 处理 Telegram Update（Webhook 与轮询共用）
 *
 * $botId 为 null 时表示平台 Bot，否则为用户自建 Bot 的 ID（同一个 Bot 可被多个渠道使用）。
 */
class UpdateHandler
{
    public function __construct(private TelegramApi $api, private ?int $botId = null)
    {
    }

    /** 渠道是否属于当前 Bot */
    private function belongs(Channel $channel): bool
    {
        $cfg = $channel->config;
        if ($this->botId === null) {
            return ($cfg['mode'] ?? 'system') === 'system';
        }
        return ($cfg['mode'] ?? '') === 'custom' && (int) ($cfg['bot_id'] ?? 0) === $this->botId;
    }

    public function handle(array $update): void
    {
        try {
            if (isset($update['callback_query'])) {
                $this->onCallback($update['callback_query']);
            } elseif (isset($update['message']['text'])) {
                $this->onMessage($update['message']);
            } elseif (isset($update['my_chat_member'])) {
                $this->onMemberChange($update['my_chat_member']);
            }
        } catch (Throwable $e) {
            \think\facade\Log::warning('telegram update failed: ' . $e->getMessage());
        }
    }

    private function onMessage(array $message): void
    {
        $chatId = (string) $message['chat']['id'];
        $text   = trim($message['text']);
        if (!str_starts_with($text, '/')) {
            return;
        }

        [$command, $arg] = array_pad(preg_split('/\s+/', $text, 2), 2, '');
        $command = strtolower(explode('@', $command)[0]);
        $locale  = $this->localeFor($chatId, $message['from']['language_code'] ?? '');

        match ($command) {
            '/start' => $arg !== '' ? $this->bind($chatId, $arg, $message['chat']) : $this->reply($chatId, trans('tg_welcome', ['chat_id' => $chatId], $locale)),
            '/id'    => $this->reply($chatId, trans('tg_chat_id', ['chat_id' => $chatId], $locale)),
            '/today' => $this->listUpcoming($chatId, 7, $locale),
            '/soon'  => $this->listUpcoming($chatId, 30, $locale),
            '/help'  => $this->reply($chatId, trans('tg_help', [], $locale)),
            default  => null,
        };
    }

    private function bind(string $chatId, string $code, array $chat): void
    {
        $channel = Channel::where('type', 'telegram')->where('status', 'pending')->where('bind_code', $code)->find();
        if (!$channel || !$this->belongs($channel)) {
            $this->reply($chatId, trans('tg_bind_invalid', [], $this->localeFor($chatId)));
            return;
        }

        $cfg               = $channel->config;
        $cfg['chat_title'] = $chat['title'] ?? trim(($chat['first_name'] ?? '') . ' ' . ($chat['last_name'] ?? '')) ?: ($chat['username'] ?? '');
        $cfg['chat_type']  = $chat['type'] ?? 'private';
        $channel->save([
            'config'      => $cfg,
            'target'      => $chatId,
            'bind_code'   => '',
            'status'      => 'active',
            'last_error'  => '',
            'verify_time' => now_utc(),
        ]);

        $user = User::find($channel->user_id);
        $this->reply($chatId, trans('tg_bind_success', ['name' => $user?->name ?: $user?->email], $user?->locale));
    }

    private function onCallback(array $cb): void
    {
        $chatId = (string) ($cb['message']['chat']['id'] ?? '');
        if (($cb['data'] ?? '') === 'noop') {
            $this->api->answerCallback($cb['id']);
            return;
        }
        $parts  = explode(':', (string) ($cb['data'] ?? ''));
        [$kind, $action, $id] = array_pad($parts, 3, '');
        $param  = $parts[3] ?? '';

        $channel = $this->linkedChannels($chatId)[0] ?? null;
        $locale  = $channel ? (string) User::where('id', $channel->user_id)->value('locale') : null;
        if (!$channel) {
            $this->api->answerCallback($cb['id'], trans('tg_not_bound', [], $locale));
            return;
        }

        $tenantIds = array_unique(array_map(fn ($c) => (int) $c->tenant_id, $this->linkedChannels($chatId)));
        $item      = $kind === 'a' ? Asset::find((int) $id) : ($kind === 't' ? Task::find((int) $id) : null);
        if (!$item || !in_array((int) $item->tenant_id, $tenantIds, true)) {
            $this->api->answerCallback($cb['id'], trans('not_found', [], $locale));
            return;
        }

        $user      = User::find($channel->user_id);
        $messageId = (int) ($cb['message']['message_id'] ?? 0);
        $clear     = true;

        switch ("{$kind}:{$action}") {
            case 'a:ack':
                AssetService::ack($item);
                $text = trans('tg_acked', [], $locale);
                break;
            case 'a:snz':
            case 't:snz':
                AssetService::snooze($item, $param ?: '1d');
                $text = trans('tg_snoozed', [], $locale);
                break;
            case 'a:det':
                $ok    = DetectService::detect($item);
                $item  = Asset::find($item->id);
                $text  = $ok ? trans('tg_detected', ['date' => substr((string) $item->expire_time, 0, 10)], $locale) : trans('tg_detect_failed', [], $locale);
                $clear = false;
                break;
            case 't:done':
                TaskService::complete($item, $user, '', 'telegram');
                $text = trans('tg_done', [], $locale);
                break;
            default:
                $text  = '';
                $clear = false;
        }

        $this->api->answerCallback($cb['id'], $text);
        if ($clear && $messageId) {
            $this->api->editReplyMarkup($chatId, $messageId, [
                'inline_keyboard' => [[['text' => $text, 'callback_data' => 'noop']]],
            ]);
        }
    }

    private function onMemberChange(array $update): void
    {
        $status = $update['new_chat_member']['status'] ?? '';
        if (in_array($status, ['kicked', 'left'], true)) {
            foreach ($this->linkedChannels((string) $update['chat']['id']) as $channel) {
                $channel->save(['status' => 'disabled', 'last_error' => 'Bot was removed from chat']);
            }
        }
    }

    private function listUpcoming(string $chatId, int $days, string $locale): void
    {
        $channels = $this->linkedChannels($chatId);
        if (!$channels) {
            $this->reply($chatId, trans('tg_not_bound', [], $locale));
            return;
        }

        $user      = User::find($channels[0]->user_id);
        $tz        = TimeHelper::tz($user->timezone);
        $now       = new DateTimeImmutable('now', TimeHelper::utc());
        $today     = $now->setTimezone($tz);
        $limit     = TimeHelper::toDb($now->modify("+{$days} days"));
        $tenantIds = array_unique(array_map(fn ($c) => (int) $c->tenant_id, $channels));
        $lines     = [];

        // 只列出会发到当前聊天的条目（按分组的渠道设置过滤）
        $router    = new ChannelRouter();
        $chatIds   = array_map(fn ($c) => (int) $c->id, $channels);
        $routed    = function ($item) use ($router, $chatIds): bool {
            $ids = $router->resolve((int) $item->tenant_id, (int) $item->group_id, $item->remind_channels);
            return $ids === null || (bool) array_intersect($ids, $chatIds);
        };

        $assets = Asset::whereIn('tenant_id', $tenantIds)->where('status', 'active')
            ->whereNotNull('expire_time')->where('expire_time', '<=', $limit)->order('expire_time')->limit(30)->select();
        foreach (array_filter($assets->all(), $routed) as $a) {
            $d       = TimeHelper::daysBetween($today, TimeHelper::fromDb($a->expire_time)->setTimezone($tz));
            $lines[] = ($d < 0 ? '🔴' : ($d <= 3 ? '🟠' : '🟡')) . ' ' . htmlspecialchars($a->name) . ' — ' . TemplateService::statusText($d, $locale);
        }

        $tasks = Task::whereIn('tenant_id', $tenantIds)->where('status', 'active')
            ->whereNotNull('next_due_time')->where('next_due_time', '<=', $limit)->order('next_due_time')->limit(30)->select();
        foreach (array_filter($tasks->all(), $routed) as $t) {
            $due     = TimeHelper::fromDb($t->next_due_time)->setTimezone($tz);
            $d       = TimeHelper::daysBetween($today, $due);
            $lines[] = ($due <= $now ? '🔴' : '☑️') . ' ' . htmlspecialchars($t->title) . ' — ' . $due->format('m-d H:i');
        }

        $this->reply($chatId, $lines
            ? '<b>' . trans('tg_upcoming_title', ['days' => $days], $locale) . "</b>\n\n" . implode("\n", $lines)
            : trans('tg_nothing', ['days' => $days], $locale));
    }

    /** @return Channel[] 与当前聊天绑定的有效渠道 */
    private function linkedChannels(string $chatId): array
    {
        if ($chatId === '') {
            return [];
        }
        $list = Channel::where('type', 'telegram')->where('status', 'active')->where('target', $chatId)->select()->all();
        return array_values(array_filter($list, fn (Channel $c) => $this->belongs($c)));
    }

    private function localeFor(string $chatId, string $languageCode = ''): string
    {
        $channel = $this->linkedChannels($chatId)[0] ?? null;
        if ($channel) {
            $locale = User::where('id', $channel->user_id)->value('locale');
            if ($locale) {
                return $locale;
            }
        }
        return str_starts_with(strtolower($languageCode), 'zh') ? 'zh-cn' : ($languageCode ? 'en' : (string) config('lang.default_lang'));
    }

    private function reply(string $chatId, string $text): void
    {
        try {
            $this->api->sendMessage($chatId, $text);
        } catch (Throwable) {
        }
    }
}
