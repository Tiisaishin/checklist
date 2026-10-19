<?php
namespace app\common\service;

use app\common\model\Asset;
use app\common\model\AssetEvent;

/**
 * 监控变化事件：记录并立即通知（续费成功、DNS 变化、状态异常、证书更换、证书异常、自动续期失败、连续探测失败）
 */
class EventService
{
    public static function fire(Asset $asset, string $type, array $data = []): AssetEvent
    {
        $event = AssetEvent::create([
            'tenant_id'   => $asset->tenant_id,
            'asset_id'    => $asset->id,
            'type'        => $type,
            'data'        => $data,
            'create_time' => now_utc(),
        ]);

        foreach (ChannelRouter::recipientsFor((int) $asset->tenant_id, (int) $asset->group_id, $asset->remind_channels) as [$channel, $user]) {
            $content = TemplateService::render(
                TemplateService::content($user, $type),
                TemplateService::eventVars($asset, $user, $type, $data)
            );
            $actions = [];
            if (in_array($type, ['ssl_error', 'ssl_renew_stuck', 'detect_failed', 'domain_status'], true) && $asset->auto_detect) {
                $actions[] = ['text' => trans('btn_detect', [], $user->locale), 'data' => 'a:det:' . $asset->id];
            }
            NotificationService::dispatch($channel, 'asset', (int) $asset->id, 'e:' . $event->id . ':' . $channel->id, $content, $actions);
        }

        return $event;
    }
}
