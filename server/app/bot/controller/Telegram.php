<?php
namespace app\bot\controller;

use app\common\controller\ApiController;
use app\common\model\Channel;
use app\common\telegram\BotRegistry;
use app\common\telegram\TelegramApi;
use app\common\telegram\UpdateHandler;

/**
 * Webhook 校验 X-Telegram-Bot-Api-Secret-Token
 */
class Telegram extends ApiController
{
    public function system()
    {
        $secret = BotRegistry::systemWebhookSecret();
        if (!$secret || !hash_equals($secret, (string) $this->request->header('x-telegram-bot-api-secret-token'))) {
            return json(['ok' => false], 403);
        }
        (new UpdateHandler(BotRegistry::systemApi()))->handle($this->update());
        return json(['ok' => true]);
    }

    public function channel(int $id)
    {
        $channel = Channel::find($id);
        $cfg     = $channel?->config ?? [];
        $secret  = (string) ($cfg['webhook_secret'] ?? '');
        if (!$channel || ($cfg['mode'] ?? '') !== 'custom' || !$secret
            || !hash_equals($secret, (string) $this->request->header('x-telegram-bot-api-secret-token'))) {
            return json(['ok' => false], 403);
        }
        (new UpdateHandler(new TelegramApi($cfg['bot_token']), (int) $cfg['bot_id']))->handle($this->update());
        return json(['ok' => true]);
    }

    private function update(): array
    {
        return json_decode($this->request->getContent(), true) ?: [];
    }
}
