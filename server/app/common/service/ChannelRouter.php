<?php
namespace app\common\service;

use app\common\model\Group;

/**
 * 决定一条提醒发往哪些渠道：
 *   条目自身指定的渠道 > 所在分组的渠道 > 默认分组的渠道 > 空间内全部有效渠道
 */
class ChannelRouter
{
    /** @var array<int, array{channels: int[], default: bool, tenant: int}> */
    private array $groups = [];
    /** @var array<int, int[]> tenant_id => 默认分组渠道 */
    private array $defaults = [];

    /**
     * @return int[]|null 渠道 ID 列表；null 表示空间内全部有效渠道
     */
    public function resolve(int $tenantId, int $groupId, ?array $itemChannels): ?array
    {
        if ($itemChannels) {
            return array_map('intval', $itemChannels);
        }

        $this->loadTenant($tenantId);
        $group = $this->groups[$groupId] ?? null;
        if ($group && $group['tenant'] === $tenantId && $group['channels']) {
            return $group['channels'];
        }

        return $this->defaults[$tenantId] ?: null;
    }

    /**
     * 条目的接收渠道及渠道所属用户（事件通知用）
     * @return array<int, array{0: \app\common\model\Channel, 1: \app\common\model\User}>
     */
    public static function recipientsFor(int $tenantId, int $groupId, ?array $itemChannels): array
    {
        $ids      = (new self())->resolve($tenantId, $groupId, $itemChannels);
        $query    = \app\common\model\Channel::where('tenant_id', $tenantId)->where('status', 'active');
        if ($ids !== null) {
            $query->whereIn('id', $ids ?: [0]);
        }
        $channels = $query->select();
        $users    = [];
        foreach (\app\common\model\User::whereIn('id', array_unique($channels->column('user_id')) ?: [0])->where('status', 1)->select() as $u) {
            $users[$u->id] = $u;
        }
        $out = [];
        foreach ($channels as $c) {
            if (isset($users[$c->user_id])) {
                $out[] = [$c, $users[$c->user_id]];
            }
        }
        return $out;
    }

    private function loadTenant(int $tenantId): void
    {
        if (isset($this->defaults[$tenantId])) {
            return;
        }
        $this->defaults[$tenantId] = [];
        foreach (Group::where('tenant_id', $tenantId)->field('id,tenant_id,is_default,remind_channels')->select() as $g) {
            $channels = array_map('intval', (array) $g->remind_channels);
            $this->groups[(int) $g->id] = ['channels' => $channels, 'default' => (bool) $g->is_default, 'tenant' => $tenantId];
            if ($g->is_default) {
                $this->defaults[$tenantId] = $channels;
            }
        }
    }
}
