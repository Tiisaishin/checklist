<?php
namespace app\common\telegram;

use app\common\model\Channel;
use app\common\model\Setting;
use app\common\service\Crypto;
use RuntimeException;

/**
 * 平台 Bot（系统设置）与用户自建 Bot（渠道配置）的统一入口
 */
class BotRegistry
{
    public static function systemToken(): string
    {
        $value = (string) Setting::getValue('telegram_bot_token', '');
        return $value ? (string) Crypto::decrypt($value) : '';
    }

    public static function systemUsername(): string
    {
        return (string) Setting::getValue('telegram_bot_username', '');
    }

    public static function systemAvailable(): bool
    {
        return self::systemToken() !== '' && self::systemUsername() !== '';
    }

    public static function systemWebhookSecret(): string
    {
        return (string) Setting::getValue('telegram_webhook_secret', '');
    }

    public static function systemApi(): TelegramApi
    {
        $token = self::systemToken();
        if ($token === '') {
            throw new RuntimeException('System bot is not configured');
        }
        return new TelegramApi($token);
    }

    /** 渠道对应的 Bot 客户端 */
    public static function forChannel(Channel $channel): TelegramApi
    {
        $cfg = $channel->config;
        if (($cfg['mode'] ?? 'system') === 'custom') {
            if (empty($cfg['bot_token'])) {
                throw new RuntimeException('Bot token missing');
            }
            return new TelegramApi($cfg['bot_token']);
        }
        return self::systemApi();
    }

    public static function botUsername(Channel $channel): string
    {
        $cfg = $channel->config;
        return ($cfg['mode'] ?? 'system') === 'custom' ? (string) ($cfg['bot_username'] ?? '') : self::systemUsername();
    }

    /** 是否可以使用 Webhook（Telegram 要求 HTTPS） */
    public static function webhookBase(): ?string
    {
        $url = (string) config('app.app_url');
        return str_starts_with($url, 'https://') ? $url : null;
    }
}
