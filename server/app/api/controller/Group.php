<?php
namespace app\api\controller;

use app\common\controller\ApiController;
use app\common\exception\ApiException;
use app\common\model\Asset;
use app\common\model\Channel;
use app\common\model\Group as GroupModel;
use app\common\model\Task;
use app\common\model\VaultItem;
use app\common\service\AuthContext;

/**
 * 信息分组：每个分组可以单独指定接收提醒的渠道（Bot）
 */
class Group extends ApiController
{
    public function index()
    {
        $tid = $this->tenantId();
        GroupModel::defaultFor($tid, AuthContext::user()->locale);

        $groups   = GroupModel::where('tenant_id', $tid)->order(['is_default' => 'desc', 'sort' => 'asc', 'id' => 'asc'])->select();
        $assets   = Asset::where('tenant_id', $tid)->where('status', 'active')->group('group_id')->column('count(*)', 'group_id');
        $tasks    = Task::where('tenant_id', $tid)->where('status', 'active')->group('group_id')->column('count(*)', 'group_id');
        $channels = [];
        foreach (Channel::where('tenant_id', $tid)->select() as $c) {
            $channels[(int) $c->id] = ['id' => (int) $c->id, 'name' => $c->name, 'status' => $c->status, 'chat_title' => $c->config['chat_title'] ?? '', 'mine' => (int) $c->user_id === AuthContext::userId()];
        }

        $list = [];
        foreach ($groups as $g) {
            $ids    = array_map('intval', (array) $g->remind_channels);
            $list[] = $g->toArray() + [
                'asset_count'  => (int) ($assets[$g->id] ?? 0),
                'task_count'   => (int) ($tasks[$g->id] ?? 0),
                'channel_list' => array_values(array_filter(array_map(fn ($id) => $channels[$id] ?? null, $ids))),
            ];
        }
        return $this->ok($list);
    }

    public function save()
    {
        $data = $this->input();
        $data['tenant_id']  = $this->tenantId();
        $data['is_default'] = 0;
        return $this->ok(GroupModel::create($data), lang('saved'));
    }

    public function update(int $id)
    {
        $group = $this->find($id);
        $group->save($this->input());
        return $this->ok($group, lang('saved'));
    }

    public function delete(int $id)
    {
        $group = $this->find($id);
        if ($group->is_default) {
            throw new ApiException(lang('default_group_locked'), 403);
        }
        // 组内数据移回默认分组
        $default = GroupModel::defaultFor($this->tenantId());
        Asset::withTrashed()->where('group_id', $id)->update(['group_id' => $default->id]);
        Task::withTrashed()->where('group_id', $id)->update(['group_id' => $default->id]);
        VaultItem::where('group_id', $id)->update(['group_id' => $default->id]);
        $group->delete();
        return $this->ok(null, lang('deleted'));
    }

    private function find(int $id): GroupModel
    {
        return GroupModel::where('tenant_id', $this->tenantId())->findOrFail($id);
    }

    private function input(): array
    {
        $data = $this->validated([
            'name'            => 'require|max:128',
            'description'     => 'max:255',
            'color'           => 'max:16',
            'sort'            => 'integer',
            'remind_channels' => 'array',
        ]);
        if (array_key_exists('remind_channels', $data)) {
            // 只允许选择本空间的渠道
            $valid = Channel::where('tenant_id', $this->tenantId())->column('id');
            $ids   = array_values(array_intersect(array_map('intval', (array) $data['remind_channels']), array_map('intval', $valid)));
            $data['remind_channels'] = $ids ?: null;
        }
        return $data;
    }
}
