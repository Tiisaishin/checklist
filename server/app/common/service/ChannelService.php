<?php
namespace app\common\service;

use app\common\exception\ApiException;
use app\common\model\Asset;
use app\common\model\Channel;
use app\common\model\Group;
use app\common\model\Task;
use app\common\model\User;
use app\common\telegram\BotRegistry;
use app\common\telegram\TelegramApi;
use RuntimeException;
use Throwable;

class ChannelService
{
    /**
     * 创建 Telegram 渠道
     *  mode=system：使用平台 Bot，通过 /start 绑定码绑定
     *  mode=custom：用户自己的 Bot Token；可通过 /start 绑定码绑定，也可直接填写 chat_id
     */
    public static function createTelegram(int $tenantId, User $user, array $input): Channel
    {
        $mode = ($input['mode'] ?? 'system') === 'custom' ? 'custom' : 'system';
        $cfg  = ['mode' => $mode];

        if ($mode === 'system') {
            if (!BotRegistry::systemAvailable()) {
                throw new ApiException(lang('system_bot_unavailable'));
            }
        } else {
            $cfg = self::verifyBotToken((string) ($input['bot_token'] ?? ''));
        }

        $channel = Channel::create([
            'tenant_id' => $tenantId,
            'user_id'   => $user->id,
            'type'      => 'telegram',
            'name'      => trim((string) ($input['name'] ?? '')) ?: 'Telegram',
            'config'    => $cfg,
            'bind_code' => Crypto::randomString(16),
            'status'    => 'pending',
        ]);

        if ($mode === 'custom') {
            self::syncWebhook($channel);
            $chatId = trim((string) ($input['chat_id'] ?? ''));
            if ($chatId !== '') {
                self::bindChatId($channel, $chatId);
            }
        }

        return $channel;
    }

    /** 校验自建 Bot Token，返回渠道配置 */
    public static function verifyBotToken(string $token): array
    {
        $token = trim($token);
        if (!preg_match('/^\d+:[\w-]{30,}$/', $token)) {
            throw new ApiException(lang('invalid_bot_token'));
        }
        try {
            $me = (new TelegramApi($token, 10))->getMe();
        } catch (RuntimeException $e) {
            throw new ApiException(lang('invalid_bot_token') . ' (' . $e->getMessage() . ')');
        }
        return [
            'mode'         => 'custom',
            'bot_token'    => $token,
            'bot_username' => $me['username'] ?? '',
            'bot_id'       => (int) ($me['id'] ?? 0),
        ];
    }

    /** 自建 Bot：HTTPS 环境下自动设置 Webhook，否则交给轮询进程 */
    public static function syncWebhook(Channel $channel): void
    {
        $cfg = $channel->config;
        if (($cfg['mode'] ?? '') !== 'custom') {
            return;
        }
        $base = BotRegistry::webhookBase();
        $api  = new TelegramApi($cfg['bot_token'], 10);
        try {
            if ($base) {
                $cfg['webhook_secret'] = $cfg['webhook_secret'] ?? Crypto::randomString(32);
                $api->setWebhook("{$base}/bot/telegram/{$channel->id}", $cfg['webhook_secret']);
                $cfg['webhook'] = true;
            } else {
                $api->deleteWebhook();
                $cfg['webhook'] = false;
            }
            $channel->save(['config' => $cfg]);
        } catch (Throwable $e) {
            $cfg['webhook'] = false;
            $channel->save(['config' => $cfg, 'last_error' => mb_substr($e->getMessage(), 0, 480)]);
        }
    }

    /** 手动填写 chat_id：发送一条测试消息确认可达 */
    public static function bindChatId(Channel $channel, string $chatId): void
    {
        if (!preg_match('/^-?\d{3,20}$|^@[\w]{4,}$/', $chatId)) {
            throw new ApiException(lang('invalid_chat_id'));
        }
        $locale = (string) User::where('id', $channel->user_id)->value('locale');
        try {
            BotRegistry::forChannel($channel)->sendMessage($chatId, trans('tg_test_message', [], $locale));
        } catch (Throwable $e) {
            throw new ApiException(lang('send_failed') . ': ' . $e->getMessage());
        }
        $channel->save([
            'target'      => $chatId,
            'status'      => 'active',
            'bind_code'   => '',
            'last_error'  => '',
            'verify_time' => now_utc(),
        ]);
    }

    public static function delete(Channel $channel): void
    {
        $cfg = $channel->config;
        if (($cfg['mode'] ?? '') === 'custom' && !empty($cfg['webhook']) && !empty($cfg['bot_token'])) {
            // 同一个 Bot 没有被其他渠道使用时才删除 Webhook
            $others = Channel::where('id', '<>', $channel->id)->where('type', 'telegram')->select()
                ->filter(fn ($c) => ($c->config['bot_token'] ?? '') === $cfg['bot_token'])->count();
            if (!$others) {
                try {
                    (new TelegramApi($cfg['bot_token'], 10))->deleteWebhook();
                } catch (Throwable) {
                }
            }
        }
        // 从分组与条目的渠道设置中移除
        $id = (int) $channel->id;
        foreach ([Group::class, Asset::class, Task::class] as $class) {
            foreach ($class::where('tenant_id', $channel->tenant_id)->whereNotNull('remind_channels')->select() as $row) {
                $ids = array_map('intval', (array) $row->remind_channels);
                if (in_array($id, $ids, true)) {
                    $row->save(['remind_channels' => array_values(array_diff($ids, [$id])) ?: null]);
                }
            }
        }
        $channel->delete();
    }

    /** 绑定链接 */
    public static function bindLinks(Channel $channel): array
    {
        $username = BotRegistry::botUsername($channel);
        if ($channel->status !== 'pending' || !$username || !$channel->bind_code) {
            return [];
        }
        return [
            'private' => "https://t.me/{$username}?start={$channel->bind_code}",
            'group'   => "https://t.me/{$username}?startgroup={$channel->bind_code}",
            'command' => "/start {$channel->bind_code}",
        ];
    }
}
