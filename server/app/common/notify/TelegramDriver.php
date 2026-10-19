<?php
namespace app\common\notify;

use app\common\model\Channel;
use app\common\telegram\BotRegistry;
use RuntimeException;

class TelegramDriver implements ChannelDriver
{
    public function send(Channel $channel, string $content, array $actions = []): void
    {
        if ($channel->target === '') {
            throw new RuntimeException('Chat is not bound');
        }

        $extra = [];
        if ($actions) {
            $extra['reply_markup'] = [
                'inline_keyboard' => [array_map(fn ($a) => ['text' => $a['text'], 'callback_data' => $a['data']], $actions)],
            ];
        }

        BotRegistry::forChannel($channel)->sendMessage($channel->target, $content, $extra);
    }
}
