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

        // 链接按钮一行，回调按钮一行
        $rows = [];
        $urls = array_values(array_filter($actions, fn ($a) => !empty($a['url'])));
        $cbs  = array_values(array_filter($actions, fn ($a) => !empty($a['data'])));
        if ($urls) {
            $rows[] = array_map(fn ($a) => ['text' => $a['text'], 'url' => $a['url']], $urls);
        }
        if ($cbs) {
            $rows[] = array_map(fn ($a) => ['text' => $a['text'], 'callback_data' => $a['data']], $cbs);
        }
        $extra = $rows ? ['reply_markup' => ['inline_keyboard' => $rows]] : [];

        BotRegistry::forChannel($channel)->sendMessage($channel->target, $content, $extra);
    }
}
