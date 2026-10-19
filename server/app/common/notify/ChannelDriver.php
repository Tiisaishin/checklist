<?php
namespace app\common\notify;

use app\common\model\Channel;

/**
 * 通知渠道驱动。新增渠道（邮件、Webhook、企业微信…）实现此接口并在 DriverFactory 注册。
 */
interface ChannelDriver
{
    /**
     * @param array $actions 可选的交互按钮 [['text' => '', 'data' => ''], ...]
     * @throws \RuntimeException 发送失败
     */
    public function send(Channel $channel, string $content, array $actions = []): void;
}
