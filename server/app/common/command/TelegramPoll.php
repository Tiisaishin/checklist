<?php
namespace app\common\command;

use app\common\model\Channel;
use app\common\model\Setting;
use app\common\telegram\BotRegistry;
use app\common\telegram\TelegramApi;
use app\common\telegram\UpdateHandler;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Cache;
use Throwable;

/**
 * 没有 HTTPS（无法使用 Webhook）时，以轮询方式接收 Telegram 消息。
 * 同时处理平台 Bot 与未启用 Webhook 的用户自建 Bot。
 */
class TelegramPoll extends Command
{
    protected function configure()
    {
        $this->setName('checklist:telegram-poll')->setDescription('Poll Telegram updates (when webhook is not available)');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('<info>telegram polling started</info>');
        $bots      = [];
        $refreshAt = 0;

        while (true) {
            if (time() >= $refreshAt) {
                $bots      = $this->collectBots();
                $refreshAt = time() + 30;
            }
            if (!$bots) {
                sleep(10);
                continue;
            }

            foreach ($bots as $key => [$token, $botId]) {
                $offsetKey = 'tg:offset:' . $key;
                $offset    = (int) Cache::get($offsetKey, 0);
                try {
                    $api     = new TelegramApi($token);
                    $updates = $api->getUpdates($offset, count($bots) === 1 ? 20 : 0);
                    $handler = new UpdateHandler($api, $botId);
                    foreach ($updates as $update) {
                        $handler->handle($update);
                        Cache::set($offsetKey, $update['update_id'] + 1);
                    }
                } catch (Throwable $e) {
                    $output->writeln('<comment>' . $key . ': ' . $e->getMessage() . '</comment>');
                }
            }

            if (count($bots) > 1) {
                sleep(2);
            }
        }
    }

    /** @return array<string, array{0:string,1:?int}> */
    private function collectBots(): array
    {
        $bots = [];
        Cache::delete('settings:all');
        if (BotRegistry::systemAvailable() && Setting::getValue('telegram_webhook') !== '1') {
            $bots['system'] = [BotRegistry::systemToken(), null];
        }
        foreach (Channel::where('type', 'telegram')->whereIn('status', ['pending', 'active'])->select() as $channel) {
            $cfg = $channel->config;
            if (($cfg['mode'] ?? '') === 'custom' && empty($cfg['webhook']) && !empty($cfg['bot_token'])) {
                $bots['bot' . $cfg['bot_id']] = [$cfg['bot_token'], (int) $cfg['bot_id']];
            }
        }
        return $bots;
    }
}
