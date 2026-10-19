<?php

use think\migration\Migrator;

/**
 * 项目 => 分组：每个空间有一个默认分组，每个分组可以单独指定通知渠道（Bot）
 */
class Groups extends Migrator
{
    public function up()
    {
        $this->table('projects')->rename('item_groups')->save();

        $this->table('item_groups')
            ->addColumn('description', 'string', ['limit' => 255, 'default' => '', 'after' => 'name'])
            ->addColumn('is_default', 'boolean', ['default' => false, 'after' => 'color'])
            ->addColumn('remind_channels', 'text', ['null' => true, 'comment' => 'JSON 渠道 ID；空=继承默认分组'])
            ->save();

        $this->table('assets')->renameColumn('project_id', 'group_id')->save();
        $this->table('tasks')->renameColumn('project_id', 'group_id')->save();

        // 为已有空间创建默认分组，并把未分组的数据归入默认分组
        $now = gmdate('Y-m-d H:i:s');
        foreach ($this->fetchAll('SELECT t.id, u.locale FROM tenants t LEFT JOIN users u ON u.id = t.owner_id') as $tenant) {
            $tid  = (int) $tenant['id'];
            $name = ($tenant['locale'] ?? '') === 'en' ? 'Default' : '默认分组';
            $this->table('item_groups')->insert([
                'tenant_id' => $tid, 'name' => $name, 'description' => '', 'color' => '#18a058',
                'is_default' => 1, 'sort' => 0, 'create_time' => $now, 'update_time' => $now,
            ])->saveData();
            $gid = (int) $this->fetchRow("SELECT id FROM item_groups WHERE tenant_id = {$tid} AND is_default = 1 ORDER BY id LIMIT 1")['id'];
            $this->execute("UPDATE assets SET group_id = {$gid} WHERE tenant_id = {$tid} AND group_id = 0");
            $this->execute("UPDATE tasks SET group_id = {$gid} WHERE tenant_id = {$tid} AND group_id = 0");
        }
    }

    public function down()
    {
        $this->table('assets')->renameColumn('group_id', 'project_id')->save();
        $this->table('tasks')->renameColumn('group_id', 'project_id')->save();
        $this->table('item_groups')->removeColumn('description')->removeColumn('is_default')->removeColumn('remind_channels')->save();
        $this->table('item_groups')->rename('projects')->save();
    }
}
